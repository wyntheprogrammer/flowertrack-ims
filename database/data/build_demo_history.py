"""Reproduce the synthetic presentation history from the intact revised baseline.

The source has several batches per flower/day. Sum opening and spoilage first,
then scale spoiled counts to illustrate four historical loss regimes. ARIMA and
risk thresholds are never changed. The fourth regime derives from source flower3.
Three calendar years provide a longer context for explicitly uncertain annual
projections; they do not make nonseasonal ARIMA a validated seasonal model.
"""

import argparse
import collections
import csv
import datetime
import io
import math
from pathlib import Path


def build(source: Path) -> str:
    totals = collections.defaultdict(lambda: [0, 0])
    with source.open(newline="") as handle:
        for row in csv.DictReader(handle):
            opening, spoiled = int(row["opening_quantity"]), int(row["spoiled_quantity"])
            if not 0 <= spoiled <= opening or opening <= 0:
                raise ValueError("Invalid source observation")
            key = (int(row["flower_id"]), row["observation_date"][5:])
            totals[key][0] += opening
            totals[key][1] += spoiled
    start, end = datetime.date(2023, 11, 28), datetime.date(2026, 11, 27)
    result = io.StringIO(newline="")
    writer = csv.writer(result)
    writer.writerow(["flower_id", "observation_date", "opening_quantity", "spoiled_quantity"])
    day = start
    while day <= end:
        month_day = day.strftime("%m-%d")
        if month_day == "02-29":
            month_day = "02-28"
        for target, origin, scale in [(1, 1, .15), (2, 2, .50), (3, 3, 1.70), (4, 3, 3.45)]:
            opening, spoiled = totals[(origin, month_day)]
            wave = 1 + .035 * math.sin((day - start).days / 81)
            lost = max(0, min(opening, round(spoiled * scale * wave)))
            writer.writerow([target, day.isoformat(), opening, lost])
        day += datetime.timedelta(days=1)
    return result.getvalue()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true", help="Verify byte-identical derivation without writing")
    args = parser.parse_args()
    directory = Path(__file__).resolve().parent
    output = directory / "flowertrack_demo_history.csv"
    derived = build(directory / "flowertrack_dataset_REVISED.csv").encode()
    if args.check:
        if output.read_bytes() != derived:
            raise SystemExit("Generated history differs from the committed CSV")
        print("Verified byte-identical demo history: 4384 observations, 1096 days per flower")
    else:
        output.write_bytes(derived)
        print(output)
