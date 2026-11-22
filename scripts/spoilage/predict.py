import json
import sys
import warnings
from datetime import date, timedelta

import numpy as np
import pandas as pd
from statsmodels.tsa.arima.model import ARIMA
from statsmodels.tsa.stattools import adfuller


def daily_series(history):
    # Laravel pools completed batch quantities per flower and calendar date.
    dates, rates = [], []
    for row in history:
        opening, spoiled = row["opening_quantity"], row["spoiled_quantity"]
        if type(opening) is not int or type(spoiled) is not int or not 0 <= spoiled <= opening or opening == 0:
            raise ValueError("Invalid daily spoilage quantities.")
        dates.append(date.fromisoformat(row["observation_date"]))
        rates.append(spoiled / opening)

    series = pd.Series(rates, index=pd.DatetimeIndex(dates), dtype=float).sort_index()
    if series.index.has_duplicates:
        raise ValueError("Expected one aggregated row per flower and date.")
    return series.asfreq("D")


def determine_d(train):
    # ADF uses only Train. A constant series has no defined ADF statistic.
    p_value = float(adfuller(train, autolag="AIC", result_object=False)[1])
    if p_value < 0.05:
        return 0, p_value

    differenced = train.diff().dropna()
    if differenced.nunique() > 1:
        adfuller(differenced, autolag="AIC", result_object=False)
    return 1, p_value


def fit(series, order):
    with warnings.catch_warnings():
        warnings.simplefilter("ignore")
        return ARIMA(
            series, order=order, enforce_stationarity=False, enforce_invertibility=False
        ).fit()


def forecast(fitted, steps):
    values = np.asarray(fitted.forecast(steps=steps), dtype=float)
    if not np.isfinite(values).all():
        raise ValueError("ARIMA returned a nonfinite forecast.")
    return np.clip(values, 0, 1)


def error_metrics(actual, predicted):
    # Errors are measured in spoilage-rate units.
    errors = actual.to_numpy() - predicted
    mse = float(np.mean(errors ** 2))
    return {"mae": float(np.mean(np.abs(errors))), "mse": mse, "rmse": mse ** 0.5}


def select_order(train, validation):
    d, adf_p_value = determine_d(train)
    candidates = []

    # Fit each candidate on Train and rank by Validation RMSE, MAE, AIC, then BIC.
    for p in range(5):
        for q in range(5):
            if p == q == 0:
                continue
            order = (p, d, q)
            try:
                fitted = fit(train, order)
                metrics = error_metrics(validation, forecast(fitted, len(validation)))
                aic, bic = float(fitted.aic), float(fitted.bic)
                if np.isfinite([aic, bic]).all():
                    candidates.append({"order": order, **metrics, "aic": aic, "bic": bic})
            except (ValueError, RuntimeError, ArithmeticError, np.linalg.LinAlgError):
                continue

    if not candidates:
        raise RuntimeError("No ARIMA candidate could be fitted.")

    best = min(candidates, key=lambda candidate: tuple(
        candidate[metric] for metric in ("rmse", "mae", "aic", "bic")
    ))
    return best, adf_p_value


def train_model(payload):
    history = daily_series(payload["history"])
    # Chronological 70/10/20 split at the floor of the 70% and 80% boundaries.
    train_end, validation_end = len(history) * 70 // 100, len(history) * 80 // 100
    train = history.iloc[:train_end]
    validation = history.iloc[train_end:validation_end]
    test = history.iloc[validation_end:]

    if history.isna().any():
        return {"status": "InsufficientData", "message": "Flower history has missing calendar days."}
    if len(train) < 30 or validation.empty or test.empty:
        return {"status": "InsufficientData", "message": "Training needs at least 43 completed daily observations."}
    if train.nunique() < 2:
        return {"status": "InsufficientData", "message": "ADF is undefined for constant training history."}

    best, adf_p_value = select_order(train, validation)
    order = best["order"]

    # Refit on Train + Validation, then forecast and score the untouched Test period.
    fitted = fit(history.iloc[:validation_end], order)
    test_errors = error_metrics(test, forecast(fitted, len(test)))
    return {
        "status": "Trained",
        "order": order,
        "adf_p_value": adf_p_value,
        "split_days": {"train": len(train), "validation": len(validation), "test": len(test)},
        "validation": {key: best[key] for key in ("mae", "mse", "rmse", "aic", "bic")},
        "test": {f"{metric.upper()}_rate": value for metric, value in test_errors.items()},
    }


def predict(payload):
    history = payload["history"]
    if not history:
        return {"status": "InsufficientData", "message": "No completed spoilage history for this flower."}

    series = daily_series(history)
    as_of = date.fromisoformat(payload["as_of_date"])
    latest = series.index[-1].date()
    horizon = payload["horizon_days"]
    order = payload["order"]
    if latest >= as_of or type(horizon) is not int or horizon < 1:
        raise ValueError("Invalid forecast date or horizon.")

    # Refit the saved order on available live history, including evaluated observations.
    if order is None:
        reason = "No trained ARIMA order for this flower."
    elif series.isna().any() or latest != as_of - timedelta(days=1):
        reason = "Flower ARIMA requires continuous history through yesterday."
    elif len(series) < 43:
        reason = "Flower ARIMA requires at least 43 completed daily observations."
    else:
        try:
            p, d, q = order
            if any(type(value) is not int for value in order) or not (
                0 <= p <= 4 and d in (0, 1) and 0 <= q <= 4 and p + q > 0
            ):
                raise ValueError("Invalid selected ARIMA order.")
            fitted = fit(series, tuple(order))
            return result(forecast(fitted, horizon), "Flower ARIMA")
        except (ValueError, RuntimeError, ArithmeticError, np.linalg.LinAlgError) as error:
            reason = f"Flower ARIMA unavailable: {error}"

    # A labeled same-flower baseline keeps risk estimates available with sparse history.
    rate = sum(row["spoiled_quantity"] for row in history) / sum(row["opening_quantity"] for row in history)
    return result([rate] * horizon, "Flower historical fallback", reason)


def result(daily_rates, method, message=None):
    # Apply daily spoilage to the surviving stock over the forecast window.
    fraction = 0.0
    for rate in daily_rates:
        fraction += (1 - fraction) * float(rate)
    return {"status": "Predicted", "predicted_fraction": fraction, "method": method, "message": message}


if __name__ == "__main__":
    try:
        payload = json.load(sys.stdin)
        action = train_model if "--train" in sys.argv else predict
        print(json.dumps(action(payload), allow_nan=False))
    except Exception as error:
        print(json.dumps({"status": "Error", "message": str(error)}))
        sys.exit(1)
