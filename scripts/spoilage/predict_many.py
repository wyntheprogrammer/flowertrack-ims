"""Forecast each requested flower once in a shared Python process."""

import json
import sys

from predict import predict


def predict_many(items):
    results = {}
    for item in items:
        key = str(item["key"])
        if key in results:
            raise ValueError("Duplicate flower key.")
        try:
            results[key] = predict(item["payload"])
        except Exception as error:
            results[key] = {"status": "Error", "message": str(error)}
    return {"status": "ok", "results": results}


if __name__ == "__main__":
    try:
        print(json.dumps(predict_many(json.load(sys.stdin)["items"]), allow_nan=False))
    except Exception as error:
        print(json.dumps({"status": "Error", "message": str(error)}))
        sys.exit(1)
