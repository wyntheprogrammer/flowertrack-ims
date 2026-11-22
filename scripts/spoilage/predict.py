import json
import sys
import warnings
from datetime import date, timedelta

import numpy as np
import pandas as pd
from statsmodels.tsa.arima.model import ARIMA
from statsmodels.tsa.stattools import adfuller


def daily_series(history):
    dates, rates = [], []
    for row in history:
        opening, spoiled = row["opening_quantity"], row["spoiled_quantity"]
        if type(opening) is not int or type(spoiled) is not int or not 0 <= spoiled <= opening or opening == 0:
            raise ValueError("Invalid daily spoilage quantities.")
        dates.append(date.fromisoformat(row["observation_date"]))
        rates.append(spoiled / opening)

    spoilage_rate = pd.Series(rates, index=pd.DatetimeIndex(dates), dtype=float).sort_index()
    if spoilage_rate.index.has_duplicates:
        raise ValueError("Expected one aggregated row per flower and date.")
    return spoilage_rate.asfreq("D")


def determine_d(train):
    adf_p_value = float(adfuller(train, autolag="AIC", result_object=False)[1])
    # ARIMA applies first differencing during fitting when d = 1.
    d = 0 if adf_p_value < 0.05 else 1
    return d, adf_p_value


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
    errors = actual - predicted
    mae = float(np.mean(np.abs(errors)))
    mse = float(np.mean(errors ** 2))
    rmse = mse ** 0.5
    return {"mae": mae, "mse": mse, "rmse": rmse}


def select_order(train, validation, d):
    candidates = []
    actual = validation.to_numpy()

    for p in range(5):
        for q in range(5):
            if p == q == 0:
                continue
            order = (p, d, q)
            try:
                candidate_model = fit(train, order)
                predicted = forecast(candidate_model, len(validation))
                metrics = error_metrics(actual, predicted)
                aic, bic = float(candidate_model.aic), float(candidate_model.bic)
                if np.isfinite([aic, bic]).all():
                    candidates.append({"order": order, **metrics, "aic": aic, "bic": bic})
            except (ValueError, RuntimeError, ArithmeticError, np.linalg.LinAlgError):
                continue

    if not candidates:
        raise RuntimeError("No ARIMA candidate could be fitted.")

    # Validation RMSE selects the order. MAE, AIC and BIC break ties.
    return min(candidates, key=lambda candidate: tuple(
        candidate[metric] for metric in ("rmse", "mae", "aic", "bic")
    ))


# ============================================================
# TRAINING AND TEST EVALUATION (NOTEBOOK METHODOLOGY)
# ============================================================
def train_model(payload):
    # STEP 1: HISTORICAL DATA COLLECTION
    # Laravel supplies completed batch quantities pooled by flower and date.
    history = payload["history"]

    # STEP 2: TIME SERIES DATA PREPARATION
    series = daily_series(history)
    if series.isna().any():
        return {"status": "InsufficientData", "message": "Flower history has missing calendar days."}

    # STEP 3: CHRONOLOGICAL 70% TRAIN / 10% VALIDATION / 20% TEST SPLIT
    days = len(series)
    train_end = days * 70 // 100
    validation_end = days * 80 // 100
    train = series.iloc[:train_end]
    validation = series.iloc[train_end:validation_end]
    test = series.iloc[validation_end:]
    if len(train) < 30 or validation.empty or test.empty:
        return {"status": "InsufficientData", "message": "Training needs at least 43 completed daily observations."}

    # STEP 4: STATIONARITY TESTING / DETERMINE d FROM TRAIN
    if train.nunique() < 2:
        return {"status": "InsufficientData", "message": "ADF is undefined for constant training history."}
    d, adf_p_value = determine_d(train)

    # STEP 5: ARIMA ORDER SELECTION USING TRAIN AND VALIDATION
    best = select_order(train, validation, d)
    order = best["order"]

    # STEP 6: FINAL MODEL FITTING
    # fit() creates a new ARIMA model and estimates it on Train + Validation.
    pre_test = pd.concat([train, validation])
    final_model = fit(pre_test, order)

    # STEP 7: FORECAST THE UNTOUCHED TEST PERIOD
    predicted = forecast(final_model, len(test))

    # STEPS 8–9: COMPARE TEST ACTUAL VS PREDICTED AND CALCULATE MAE / MSE / RMSE
    # These are errors on the spoilage-rate scale. Smaller values are better.
    actual = test.to_numpy()
    test_errors = error_metrics(actual, predicted)
    return {
        "status": "Trained",
        "order": order,
        "adf_p_value": adf_p_value,
        "split_days": {"train": len(train), "validation": len(validation), "test": len(test)},
        "validation": {key: best[key] for key in ("mae", "mse", "rmse", "aic", "bic")},
        "test": {
            "MAE_rate": test_errors["mae"],
            "MSE_rate": test_errors["mse"],
            "RMSE_rate": test_errors["rmse"],
        },
    }


# ============================================================
# LIVE PRODUCTION FORECAST USING THE SAVED ORDER
# ============================================================
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
            daily_rates = forecast(fitted, horizon)
            return inventory_result(daily_rates, "Flower ARIMA")
        except (ValueError, RuntimeError, ArithmeticError, np.linalg.LinAlgError) as error:
            reason = f"Flower ARIMA unavailable: {error}"

    # Historical fallback: use one weighted rate for the same flower across the horizon.
    total_spoiled = sum(row["spoiled_quantity"] for row in history)
    total_opening = sum(row["opening_quantity"] for row in history)
    historical_rate = total_spoiled / total_opening
    return inventory_result([historical_rate] * horizon, "Flower historical fallback", reason)


def inventory_result(daily_rates, method, message=None):
    # Inventory calculation: apply each daily rate to surviving stock.
    # Laravel uses the cumulative fraction for each batch's predicted quantity and risk.
    predicted_fraction = 0.0
    for rate in daily_rates:
        predicted_fraction += (1 - predicted_fraction) * float(rate)
    return {
        "status": "Predicted", "predicted_fraction": predicted_fraction,
        "method": method, "message": message,
    }


if __name__ == "__main__":
    try:
        payload = json.load(sys.stdin)
        action = train_model if "--train" in sys.argv else predict
        print(json.dumps(action(payload), allow_nan=False))
    except Exception as error:
        print(json.dumps({"status": "Error", "message": str(error)}))
        sys.exit(1)
