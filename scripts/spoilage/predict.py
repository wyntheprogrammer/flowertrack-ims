import json
import math
import sys
import warnings
from datetime import date, timedelta

import numpy as np
from statsmodels.tsa.arima.model import ARIMA


def rate(value):
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        raise ValueError("Rates must be numbers.")
    if not math.isfinite(value) or not 0 <= value <= 1:
        raise ValueError("Rates must be finite fractions between zero and one.")
    return float(value)


def rates_to_fraction(spoil_rates, release_rates):
    alive, total = 1.0, 0.0
    for spoil, release in zip(spoil_rates, release_rates):
        loss = alive * float(np.clip(spoil, 0, 1))
        total += loss
        alive = (alive - loss) * (1 - float(np.clip(release, 0, 1)))
    return float(np.clip(total, 0, 1))


def fit_rate_arima(values, order):
    with warnings.catch_warnings(record=True) as captured:
        warnings.simplefilter("always")
        model = ARIMA(np.asarray(values, float), order=order).fit(
            method_kwargs={"maxiter": 100}
        )
    if not model.mle_retvals.get("converged", True):
        raise RuntimeError("ARIMA did not converge.")
    return model, " | ".join(sorted({str(w.message) for w in captured}))


def predict(payload):
    # Laravel supplies one batch snapshot and its completed daily rates.
    as_of = date.fromisoformat(payload["as_of_date"])
    opening = payload["opening_quantity"]
    if isinstance(opening, bool) or not isinstance(opening, int) or opening <= 0:
        raise ValueError("Opening quantity must be a positive integer.")

    # Laravel will pass config/spoilage.php settings; defaults match the notebook.
    settings = payload.get("settings", {})
    horizon = settings.get("horizon_days", 7)
    minimum = settings.get("minimum_history_days", 6)
    order = tuple(settings.get("arima_order", [0, 1, 0]))
    for value, lower in [(horizon, 1), (minimum, 2)]:
        if type(value) is not int or value < lower:
            raise ValueError("Invalid horizon or minimum history setting.")
    if len(order) != 3 or any(type(value) is not int or value < 0 for value in order):
        raise ValueError("ARIMA order must contain three nonnegative integers.")
    thresholds = settings.get("risk_thresholds", {"medium": 0.10, "high": 0.25, "critical": 0.50})
    medium, high, critical = [rate(thresholds[key]) for key in ["medium", "high", "critical"]]
    if not 0 < medium < high < critical <= 1:
        raise ValueError("Risk thresholds must be strictly increasing.")
    history = sorted(payload.get("history", []), key=lambda row: row["observation_date"])
    past_spoilage, past_releases, dates = [], [], []

    for row in history:
        observed = date.fromisoformat(row["observation_date"])
        if observed >= as_of:
            raise ValueError("History must be strictly before the forecast date.")
        if dates and observed != dates[-1] + timedelta(days=1):
            raise ValueError("Daily history must be continuous without duplicates.")
        dates.append(observed)
        past_spoilage.append(rate(row["spoil_rate_today"]))
        past_releases.append(rate(row["release_rate_today"]))

    if dates and dates[-1] != as_of - timedelta(days=1):
        raise ValueError("History must end on the day before the forecast.")

    # Laravel must select a supported historical profile before calling this script.
    fallback = payload.get("fallback")
    if fallback is None:
        return {
            "status": "InsufficientData",
            "history_days": len(history),
            "message": "No supported historical reference is available.",
        }

    spoil_rates = [rate(value) for value in fallback["spoil_rates"]]
    release_rates = [rate(value) for value in fallback["release_rates"]]
    if len(spoil_rates) != horizon or len(release_rates) != horizon:
        raise ValueError(f"Fallback must contain {horizon} rates for each series.")
    method = fallback["method"]
    if method not in ["Flower/storage/age fallback", "Global profile fallback"]:
        raise ValueError("Unknown fallback method.")

    notes = []
    if len(history) >= minimum:
        try:
            # Fit once on the first six days, then update with later actual rates.
            spoil_model, spoil_warning = fit_rate_arima(past_spoilage[:minimum], order)
            release_model, release_warning = fit_rate_arima(past_releases[:minimum], order)
            notes.extend(text for text in [spoil_warning, release_warning] if text)

            with warnings.catch_warnings(record=True) as captured:
                warnings.simplefilter("always")
                for spoil, release in zip(past_spoilage[minimum:], past_releases[minimum:]):
                    spoil_model = spoil_model.extend([spoil])
                    release_model = release_model.extend([release])
                predicted_spoil = np.asarray(spoil_model.forecast(horizon), float)
                predicted_release = np.asarray(release_model.forecast(horizon), float)
            notes.extend(str(w.message) for w in captured)

            if not (np.isfinite(predicted_spoil).all() and np.isfinite(predicted_release).all()):
                raise RuntimeError("ARIMA returned a nonfinite forecast.")
            spoil_rates, release_rates = predicted_spoil, predicted_release
            method = "ARIMA own-batch history"
        except Exception as exception:
            # Keep the validated fallback rates if fitting or forecasting fails.
            notes.append(f"ARIMA unavailable: {exception}")

    fraction = rates_to_fraction(spoil_rates, release_rates)
    risk = "Low" if fraction < medium else "Medium" if fraction < high else "High" if fraction < critical else "Critical"

    return {
        "status": "Predicted",
        "as_of_date": as_of.isoformat(),
        "forecast_end_date": (as_of + timedelta(days=horizon - 1)).isoformat(),
        "opening_quantity": opening,
        "predicted_fraction": fraction,
        "predicted_quantity": opening * fraction,
        "risk_level": risk,
        "method": method,
        "history_days": len(history),
        "message": " | ".join(notes) or None,
    }


def self_test():
    # Fixed examples test the calculation; they are not real inventory records.
    example = {
        "as_of_date": "2026-11-14",
        "opening_quantity": 100,
        "history": [],
        "fallback": {
            "spoil_rates": [0.10] * 7,
            "release_rates": [0.20] * 7,
            "method": "Flower/storage/age fallback",
        },
    }
    result = predict(example)
    assert math.isclose(result["predicted_fraction"], 0.3213192392704)
    assert result["risk_level"] == "High"
    assert result["forecast_end_date"] == "2026-11-20"
    assert predict({**example, "fallback": None})["status"] == "InsufficientData"
    assert rates_to_fraction([0] * 7, [0.2] * 7) == 0
    assert rates_to_fraction([1] * 7, [0] * 7) == 1

    for day in range(6):
        example["history"].append({
            "observation_date": (date(2026, 11, 8) + timedelta(days=day)).isoformat(),
            "spoil_rate_today": [0.02, 0.03, 0.04, 0.03, 0.05, 0.06][day],
            "release_rate_today": [0.10, 0.12, 0.11, 0.15, 0.13, 0.14][day],
        })
    own_batch = predict(example)
    assert own_batch["history_days"] == 6
    assert own_batch["status"] == "Predicted"
    assert 0 <= own_batch["predicted_fraction"] <= 1
    if own_batch["method"] != "ARIMA own-batch history":
        assert "ARIMA unavailable:" in own_batch["message"]
    try:
        predict({**example, "as_of_date": "2026-11-13"})
        raise AssertionError("Future history was accepted.")
    except ValueError:
        pass
    return {"status": "ok", "message": "Forecast self-test passed."}


if __name__ == "__main__":
    try:
        result = self_test() if "--self-test" in sys.argv else predict(json.load(sys.stdin))
        print(json.dumps(result, allow_nan=False))
    except Exception as exception:
        print(json.dumps({"status": "Error", "message": str(exception)}))
        sys.exit(1)
