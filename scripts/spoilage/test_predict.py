import json
import subprocess
import sys
import unittest
from datetime import date, timedelta
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

import numpy as np

from predict import daily_series, determine_d, inventory_result, predict, select_order, train_model
from predict_many import predict_many


def history(spoiled_quantities, start=date(2026, 1, 1)):
    return [
        {
            "observation_date": (start + timedelta(days=day)).isoformat(),
            "opening_quantity": 100,
            "spoiled_quantity": spoiled,
        }
        for day, spoiled in enumerate(spoiled_quantities)
    ]


class SpoilageArimaTest(unittest.TestCase):
    def test_daily_series_sorts_pooled_flower_rates_and_marks_missing_dates(self):
        first, _, last = history([10, 0, 20])
        last["opening_quantity"] = 50
        series = daily_series([last, first])

        self.assertEqual(["2026-01-01", "2026-01-02", "2026-01-03"], series.index.strftime("%Y-%m-%d").tolist())
        np.testing.assert_allclose(series.to_numpy(), [0.1, np.nan, 0.4], equal_nan=True)

    def test_adf_keeps_stationary_data_or_limits_d_to_one(self):
        series = daily_series(history([3, 5, 4, 8, 5, 2]))
        with patch("predict.adfuller", return_value=(0, 0.01)) as adf:
            self.assertEqual((0, 0.01), determine_d(series))
            adf.assert_called_once()
        with patch("predict.adfuller", return_value=(0, 0.4)) as adf:
            self.assertEqual((1, 0.4), determine_d(series))
            adf.assert_called_once()
            self.assertIs(series, adf.call_args.args[0])

    def test_validation_ranking_and_tie_breakers(self):
        series = daily_series(history([20, 40, 50, 50, 50, 50]))
        train, validation = series.iloc[:2], series.iloc[2:]
        candidates = {
            (0, 1, 1): ([0.8, 0.5, 0.5, 0.5], -100, -100),
            (0, 1, 2): ([0.625] * 4, -100, -100),
            (0, 1, 3): ([0.75, 0.5, 0.5, 0.5], -1, -100),
            (0, 1, 4): ([0.75, 0.5, 0.5, 0.5], -2, -1),
            (1, 1, 0): ([0.75, 0.5, 0.5, 0.5], -2, -2),
        }

        def candidate_fit(values, order):
            self.assertTrue(values.equals(train))
            if order not in candidates:
                raise ValueError("Candidate did not fit.")
            predicted, aic, bic = candidates[order]
            return SimpleNamespace(aic=aic, bic=bic, forecast=lambda steps: predicted)

        with patch("predict.fit", side_effect=candidate_fit) as fit:
            best = select_order(train, validation, d=1)

        self.assertEqual(24, fit.call_count)
        self.assertEqual(
            {(p, 1, q) for p in range(5) for q in range(5) if p or q},
            {call.args[1] for call in fit.call_args_list},
        )
        self.assertEqual((1, 1, 0), best["order"])

    def test_training_uses_train_for_selection_and_refits_before_test(self):
        rows = history(([5, 8, 9, 6, 4] * 8) + [10] * 10)
        fitted = SimpleNamespace(forecast=lambda steps: [0.2] * steps)
        with patch("predict.adfuller", return_value=(0, 0.02)) as adf, patch(
            "predict.select_order",
            return_value={"order": (1, 0, 0), "mae": 0.1, "mse": 0.01, "rmse": 0.1, "aic": 1, "bic": 2},
        ) as select, patch("predict.fit", return_value=fitted) as fit:
            result = train_model({"history": rows})

        series = daily_series(rows)
        adf.assert_called_once()
        self.assertTrue(adf.call_args.args[0].equals(series.iloc[:35]))
        self.assertTrue(select.call_args.args[0].equals(series.iloc[:35]))
        self.assertTrue(select.call_args.args[1].equals(series.iloc[35:40]))
        self.assertEqual(0, select.call_args.args[2])
        fit.assert_called_once()
        self.assertTrue(fit.call_args.args[0].equals(series.iloc[:40]))
        self.assertEqual((1, 0, 0), fit.call_args.args[1])
        self.assertEqual({"train": 35, "validation": 5, "test": 10}, result["split_days"])
        self.assertAlmostEqual(0.1, result["test"]["MAE_rate"])
        self.assertAlmostEqual(0.01, result["test"]["MSE_rate"])
        self.assertAlmostEqual(0.1, result["test"]["RMSE_rate"])

    def test_training_rejects_short_gapped_and_constant_history(self):
        rows = history([5, 6] * 22)
        for invalid, message in [
            ([], "at least 43"),
            (rows[:42], "at least 43"),
            (rows[:20] + rows[21:], "missing calendar days"),
            (history([5] * 43), "constant training history"),
        ]:
            with self.subTest(message=message):
                result = train_model({"history": invalid})
                self.assertEqual("InsufficientData", result["status"])
                self.assertIn(message, result["message"])

    def test_live_uses_saved_order_and_all_history_and_clips_each_day(self):
        rows = history([12, 20] * 22)
        payload = {"history": rows, "order": [3, 1, 2], "as_of_date": "2026-02-14", "horizon_days": 4}

        fitted = SimpleNamespace(forecast=lambda steps: [-0.1, 0.10, 0.20, 1.1])
        with patch("predict.fit", return_value=fitted) as fit, patch("predict.select_order") as select, patch(
            "predict.inventory_result", wraps=inventory_result
        ) as inventory:
            result = predict(payload)

        select.assert_not_called()
        fit.assert_called_once()
        self.assertTrue(fit.call_args.args[0].equals(daily_series(rows)))
        self.assertEqual((3, 1, 2), fit.call_args.args[1])
        self.assertEqual("Flower ARIMA", result["method"])
        np.testing.assert_allclose(inventory.call_args.args[0], [0, 0.10, 0.20, 1])
        self.assertEqual(1.0, result["predicted_fraction"])

    def test_live_uses_weighted_flower_fallback_and_isolates_errors(self):
        rows = history([10, 20])
        rows[1]["opening_quantity"] = 50
        payload = {"history": rows, "order": None, "as_of_date": "2026-01-03", "horizon_days": 2}
        result = predict_many([
            {"key": "1", "payload": payload},
            {"key": "2", "payload": {**payload, "history": []}},
            {"key": "3", "payload": {**payload, "as_of_date": "2026-01-02"}},
        ])["results"]

        self.assertEqual("Flower historical fallback", result["1"]["method"])
        self.assertAlmostEqual(0.36, result["1"]["predicted_fraction"])
        self.assertEqual("InsufficientData", result["2"]["status"])
        self.assertEqual("Error", result["3"]["status"])

    def test_live_gaps_and_fit_failures_use_the_historical_fallback(self):
        rows = history([8, 12] * 22)
        payload = {"history": rows, "order": [1, 0, 0], "as_of_date": "2026-02-14", "horizon_days": 1}
        with patch("predict.fit", side_effect=ValueError("Fit failed.")) as fit:
            gapped = predict({**payload, "history": rows[:20] + rows[21:]})
            stale = predict({**payload, "as_of_date": "2026-02-15"})
            fit.assert_not_called()
            failed = predict(payload)
            fit.assert_called_once()

        for result in (gapped, stale, failed):
            self.assertEqual("Flower historical fallback", result["method"])
        self.assertIn("Fit failed.", failed["message"])

    def test_real_statsmodels_training_and_forecast_over_json(self):
        rows = history(np.random.default_rng(9).integers(2, 30, size=50).tolist())
        scripts = Path(__file__).parent
        training = subprocess.run(
            [sys.executable, str(scripts / "predict.py"), "--train"],
            input=json.dumps({"history": rows}), text=True, capture_output=True, check=True,
        )
        trained = json.loads(training.stdout)
        payload = {
            "history": rows, "order": trained["order"], "as_of_date": "2026-02-20", "horizon_days": 7,
        }
        live = subprocess.run(
            [sys.executable, str(scripts / "predict_many.py")],
            input=json.dumps({"items": [{"key": "1", "payload": payload}]}),
            text=True, capture_output=True, check=True,
        )
        result = json.loads(live.stdout)["results"]["1"]

        self.assertEqual("Trained", trained["status"])
        self.assertEqual("Flower ARIMA", result["method"])
        self.assertTrue(0 <= result["predicted_fraction"] <= 1)


if __name__ == "__main__":
    unittest.main()
