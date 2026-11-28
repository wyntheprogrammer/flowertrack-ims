import json
import subprocess
import sys
import unittest
from datetime import date, timedelta
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock, patch

import numpy as np

from predict import daily_series, determine_d, inventory_result, predict, select_best_order, train_model
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
        for p_diff in (0.01, 0.4):
            with self.subTest(p_diff=p_diff), patch(
                "predict.adfuller", side_effect=[(0, 0.4), (0, p_diff)]
            ) as adf:
                self.assertEqual((1, 0.4), determine_d(series))
                self.assertEqual(2, adf.call_count)
                self.assertIs(series, adf.call_args_list[0].args[0])
                self.assertTrue(adf.call_args_list[1].args[0].equals(series.diff().dropna()))

    def test_adf_keeps_d_at_one_when_first_differences_are_constant(self):
        series = daily_series(history([0, 25, 50, 75, 100]))
        with patch("predict.adfuller", return_value=(0, 0.4)) as adf:
            self.assertEqual((1, 0.4), determine_d(series))
            adf.assert_called_once()

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

        def candidate_model(values, *, order, enforce_stationarity, enforce_invertibility):
            self.assertTrue(values.equals(train))
            if order not in candidates:
                raise ValueError("Candidate did not fit.")
            predicted, aic, bic = candidates[order]
            fitted = SimpleNamespace(aic=aic, bic=bic, forecast=lambda steps: predicted)
            return Mock(fit=Mock(return_value=fitted))

        with patch("predict.determine_d", return_value=(1, 0.4)), patch(
            "predict.ARIMA", side_effect=candidate_model
        ) as arima:
            order, _, best_candidate = select_best_order(train, validation)

        self.assertEqual(24, arima.call_count)
        self.assertEqual(
            {(p, 1, q) for p in range(5) for q in range(5) if p or q},
            {call.kwargs["order"] for call in arima.call_args_list},
        )
        self.assertEqual((1, 1, 0), order)
        self.assertAlmostEqual(0.0625, best_candidate["validation_MAE"])
        self.assertAlmostEqual(0.015625, best_candidate["validation_MSE"])
        self.assertAlmostEqual(0.125, best_candidate["validation_RMSE"])

    def test_training_uses_train_for_selection_and_refits_before_test(self):
        rows = history(([5, 8, 9, 6, 4] * 8) + [10] * 10)
        candidate = Mock(aic=1, bic=2, forecast=Mock(return_value=[0.06] * 5))
        final = Mock(forecast=Mock(return_value=[0.2] * 10))
        candidate_model = Mock(fit=Mock(return_value=candidate))
        final_model = Mock(fit=Mock(return_value=final))
        with patch("predict.adfuller", return_value=(0, 0.02)) as adf, patch(
            "predict.ARIMA", side_effect=[candidate_model] * 24 + [final_model]
        ) as arima:
            result = train_model({"history": rows})

        series = daily_series(rows)
        adf.assert_called_once()
        self.assertTrue(adf.call_args.args[0].equals(series.iloc[:35]))
        for call in arima.call_args_list[:-1]:
            self.assertTrue(call.args[0].equals(series.iloc[:35]))
            self.assertEqual(0, call.kwargs["order"][1])
        self.assertTrue(arima.call_args.args[0].equals(series.iloc[:40]))
        self.assertEqual(result["order"], arima.call_args.kwargs["order"])
        self.assertEqual(24, candidate_model.fit.call_count)
        final_model.fit.assert_called_once_with()
        final.forecast.assert_called_once_with(steps=10)
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
        model = Mock(fit=Mock(return_value=fitted))
        with patch("predict.ARIMA", return_value=model) as arima, patch("predict.select_best_order") as select, patch(
            "predict.inventory_result", wraps=inventory_result
        ) as inventory:
            result = predict(payload)

        select.assert_not_called()
        arima.assert_called_once()
        model.fit.assert_called_once_with()
        self.assertTrue(arima.call_args.args[0].equals(daily_series(rows)))
        self.assertEqual((3, 1, 2), arima.call_args.kwargs["order"])
        self.assertEqual("Flower ARIMA", result["method"])
        np.testing.assert_allclose(inventory.call_args.args[0], [0, 0.10, 0.20, 1])
        self.assertEqual(1.0, result["predicted_fraction"])
        self.assertEqual([0.0, 0.1, 0.2, 1.0], result["daily_rate_forecasts"])
        np.testing.assert_allclose(result["cumulative_fractions"], [0, 0.1, 0.28, 1])

    def test_one_annual_live_fit_supplies_all_shorter_forecast_periods(self):
        rows = history([8, 12] * 22)
        rates = np.linspace(0.001, 0.01, 366)
        fitted = Mock(forecast=Mock(return_value=rates))
        model = Mock(fit=Mock(return_value=fitted))
        payload = {"history": rows, "order": [1, 0, 0], "as_of_date": "2026-02-14", "horizon_days": 366}
        with patch("predict.ARIMA", return_value=model) as arima, patch("predict.select_best_order") as select:
            result = predict(payload)

        arima.assert_called_once()
        model.fit.assert_called_once_with()
        fitted.forecast.assert_called_once_with(steps=366)
        select.assert_not_called()
        self.assertEqual(366, len(result["daily_rate_forecasts"]))
        self.assertEqual(366, len(result["cumulative_fractions"]))
        for days in (1, 7, 30, 366):
            expected = 1 - float(np.prod(1 - rates[:days]))
            self.assertAlmostEqual(expected, result["cumulative_fractions"][days - 1])
        self.assertAlmostEqual(result["cumulative_fractions"][-1], result["predicted_fraction"])
        self.assertTrue(all(0 <= point <= 1 for point in result["cumulative_fractions"]))

    def test_cumulative_inventory_math_applies_daily_rates_to_surviving_stock(self):
        result = inventory_result([0.1, 0.2, 0.05], "Flower ARIMA")
        self.assertEqual([0.1, 0.2, 0.05], result["daily_rate_forecasts"])
        np.testing.assert_allclose(result["cumulative_fractions"], [0.1, 0.28, 0.316])
        self.assertAlmostEqual(0.316, result["predicted_fraction"])
        self.assertNotAlmostEqual(sum(result["daily_rate_forecasts"]), result["predicted_fraction"])

    def test_live_horizon_is_bounded_to_one_rolling_year(self):
        payload = {"history": history([5, 10]), "order": None, "as_of_date": "2026-01-03"}
        for horizon in (0, -1, 367, True, 1.5, "7"):
            with self.subTest(horizon=horizon), self.assertRaises(ValueError):
                predict({**payload, "horizon_days": horizon})

    def test_fallback_returns_the_same_complete_daily_vector_contract(self):
        rows = history([10, 20])
        rows[1]["opening_quantity"] = 50
        result = predict({"history": rows, "order": None, "as_of_date": "2026-01-03", "horizon_days": 366})
        self.assertEqual("Flower historical fallback", result["method"])
        np.testing.assert_allclose(result["daily_rate_forecasts"], [0.2] * 366)
        self.assertEqual(366, len(result["cumulative_fractions"]))
        self.assertAlmostEqual(1 - 0.8 ** 7, result["cumulative_fractions"][6])
        self.assertIn("No trained ARIMA order", result["message"])

    def test_forecast_vectors_clip_rates_and_reject_nonfinite_or_invalid_shapes(self):
        result = inventory_result([-0.5, 1.5], "Flower ARIMA")
        self.assertEqual([0.0, 1.0], result["daily_rate_forecasts"])
        self.assertEqual([0.0, 1.0], result["cumulative_fractions"])
        for rates in ([], [[0.1]], [float("nan")], [float("inf")], [0.1] * 367):
            with self.subTest(rates=rates[:2]), self.assertRaises(ValueError):
                inventory_result(rates, "Flower ARIMA")

    def test_daily_series_rejects_invalid_quantities_and_duplicate_dates(self):
        for opening, spoiled in ((0, 0), (-1, 0), (100, -1), (100, 101), (True, 0), (100, 1.5)):
            with self.subTest(opening=opening, spoiled=spoiled), self.assertRaises(ValueError):
                daily_series([{"observation_date": "2026-01-01", "opening_quantity": opening, "spoiled_quantity": spoiled}])
        row = history([1])[0]
        with self.assertRaisesRegex(ValueError, "one aggregated row"):
            daily_series([row, row])

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
        with patch("predict.ARIMA", side_effect=ValueError("Fit failed.")) as fit:
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
            "history": rows, "order": trained["order"], "as_of_date": "2026-02-20", "horizon_days": 366,
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
        self.assertEqual(366, len(result["daily_rate_forecasts"]))
        self.assertEqual(366, len(result["cumulative_fractions"]))
        expected_weekly_fraction = 1 - np.prod(1 - np.asarray(result["daily_rate_forecasts"][:7]))
        self.assertAlmostEqual(expected_weekly_fraction, result["cumulative_fractions"][6])


if __name__ == "__main__":
    unittest.main()
