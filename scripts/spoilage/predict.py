import json
import sys
import warnings
from datetime import date, timedelta

import numpy as np
import pandas as pd
from statsmodels.tools.eval_measures import meanabs as mean_absolute_error
from statsmodels.tools.eval_measures import mse as mean_squared_error
from statsmodels.tsa.arima.model import ARIMA
from statsmodels.tsa.stattools import adfuller

P_VALUES = range(5)
Q_VALUES = range(5)
MAX_FORECAST_DAYS = 366


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
    p_original = float(adfuller(train, autolag="AIC", result_object=False)[1])
    if p_original < 0.05:
        return 0, p_original

    # ADF is undefined for constant first differences.
    first_difference = train.diff().dropna()
    if first_difference.nunique() > 1:
        p_diff = adfuller(first_difference, autolag="AIC", result_object=False)[1]
        if p_diff < 0.05:
            return 1, p_original

    # Keep d bounded at first differencing even if it does not establish stationarity.
    return 1, p_original


def select_best_order(train, validation):
    # STEP 4: STATIONARITY TESTING / DETERMINE d FROM TRAIN
    d, adf_p_value = determine_d(train)

    # STEP 5: ARIMA ORDER SELECTION USING TRAIN AND VALIDATION
    candidates = []
    for p in P_VALUES:
        for q in Q_VALUES:
            if p == 0 and q == 0:
                continue

            order = (p, d, q)
            try:
                with warnings.catch_warnings():
                    warnings.simplefilter("ignore")
                    model = ARIMA(
                        train, order=order,
                        enforce_stationarity=False, enforce_invertibility=False,
                    )
                    fitted = model.fit()

                forecast = np.asarray(fitted.forecast(steps=len(validation)), dtype=float)
                if not np.isfinite(forecast).all() or not np.isfinite([fitted.aic, fitted.bic]).all():
                    continue
                forecast = np.clip(forecast, 0, 1)

                mae = mean_absolute_error(validation.values, forecast)
                mse = mean_squared_error(validation.values, forecast)
                rmse = np.sqrt(mse)

                candidates.append({
                    "order": order,
                    "validation_MAE": mae,
                    "validation_MSE": mse,
                    "validation_RMSE": rmse,
                    "AIC": fitted.aic,
                    "BIC": fitted.bic,
                })
            except (ValueError, RuntimeError, ArithmeticError, np.linalg.LinAlgError):
                continue

    if not candidates:
        raise RuntimeError("No ARIMA candidate could be fitted.")

    candidate_df = pd.DataFrame(candidates)
    # Validation RMSE selects the order. MAE, AIC and BIC break ties.
    candidate_df = candidate_df.sort_values(["validation_RMSE", "validation_MAE", "AIC", "BIC"])
    best_candidate = candidate_df.iloc[0]
    return tuple(best_candidate["order"]), adf_p_value, best_candidate


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

    if train.nunique() < 2:
        return {"status": "InsufficientData", "message": "ADF is undefined for constant training history."}

    # STEPS 4 AND 5: DETERMINE d AND SELECT THE BEST ORDER
    order, adf_p_value, best_candidate = select_best_order(train, validation)

    # STEP 6: FINAL MODEL FITTING
    pre_test = pd.concat([train, validation])
    with warnings.catch_warnings():
        warnings.simplefilter("ignore")
        final_model = ARIMA(
            pre_test, order=order,
            enforce_stationarity=False, enforce_invertibility=False,
        ).fit()

    # STEP 7: FORECAST THE UNTOUCHED TEST PERIOD
    predicted = np.asarray(final_model.forecast(steps=len(test)), dtype=float)
    if not np.isfinite(predicted).all():
        raise ValueError("ARIMA returned a nonfinite forecast.")
    predicted = np.clip(predicted, 0, 1)

    # STEPS 8–9: COMPARE TEST ACTUAL VS PREDICTED AND CALCULATE MAE / MSE / RMSE
    # These are errors on the spoilage-rate scale. Smaller values are better.
    mae = mean_absolute_error(test.values, predicted)
    mse = mean_squared_error(test.values, predicted)
    rmse = np.sqrt(mse)
    return {
        "status": "Trained",
        "order": order,
        "adf_p_value": adf_p_value,
        "split_days": {"train": len(train), "validation": len(validation), "test": len(test)},
        "validation": {
            "mae": float(best_candidate["validation_MAE"]),
            "mse": float(best_candidate["validation_MSE"]),
            "rmse": float(best_candidate["validation_RMSE"]),
            "aic": float(best_candidate["AIC"]),
            "bic": float(best_candidate["BIC"]),
        },
        "test": {
            "MAE_rate": float(mae),
            "MSE_rate": float(mse),
            "RMSE_rate": float(rmse),
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
    if latest >= as_of or type(horizon) is not int or not 1 <= horizon <= MAX_FORECAST_DAYS:
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
            with warnings.catch_warnings():
                warnings.simplefilter("ignore")
                model = ARIMA(
                    series, order=tuple(order),
                    enforce_stationarity=False, enforce_invertibility=False,
                )
                fitted = model.fit()

            daily_rates = np.asarray(fitted.forecast(steps=horizon), dtype=float)
            if not np.isfinite(daily_rates).all():
                raise ValueError("ARIMA returned a nonfinite forecast.")
            daily_rates = np.clip(daily_rates, 0, 1)
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
    # One daily forecast supports every presentation period; Laravel selects and
    # groups these points without refitting ARIMA or multiplying rates by days.
    rates = np.asarray(daily_rates, dtype=float)
    if rates.ndim != 1 or not 1 <= len(rates) <= MAX_FORECAST_DAYS or not np.isfinite(rates).all():
        raise ValueError("Expected a finite daily forecast of 1 to 366 days.")
    rates = np.clip(rates, 0, 1)
    predicted_fraction = 0.0
    cumulative_fractions = []
    for rate in rates:
        predicted_fraction += (1 - predicted_fraction) * float(rate)
        cumulative_fractions.append(predicted_fraction)
    return {
        "status": "Predicted", "predicted_fraction": predicted_fraction,
        "daily_rate_forecasts": rates.tolist(),
        "cumulative_fractions": cumulative_fractions,
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
