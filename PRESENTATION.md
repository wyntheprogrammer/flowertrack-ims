# FlowerTrack forecasting presentation

## Local XAMPP setup

Use an empty MariaDB database named `flowertrack_ims_demo` on your local device. Set `APP_ENV=local`, `DB_CONNECTION=mysql`, and `DB_DATABASE=flowertrack_ims_demo` in the local .env with your XAMPP connection settings. Keep your normal inventory database available separately. From the project root in PowerShell, run each line separately.

```powershell
composer install
npm.cmd ci
npm.cmd run build
py -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r requirements.txt
php artisan config:clear
php artisan migrate
php artisan db:seed --class=PresentationDemoSeeder
php artisan spoilage:train
php artisan serve
```

A new checkout needs a local .env copied from .env.example and an application key generated with `php artisan key:generate`. An existing configured installation can keep its key. The seeder accepts only named local presentation databases or an isolated test database. It runs in a transaction and recognizes a completed import. For another clean run, select a new empty presentation database. Training stores model orders on the flowers. Opening Spoilage Prediction subsequently calculates and saves the current forecasts.

## Presentation sequence

All seeded accounts use the local demo password `123`. Laravel hashes each password and the seeded accounts have completed their initial password change. The normal password policy continues to apply to password changes.

| Portal | Email | Demonstration |
| --- | --- | --- |
| /login | admin@demo.example.test | Dashboard alerts, inventory, forecasting and reports |
| /login | supplier@demo.example.test | Batch stock, orders and fulfillment |
| /login | driver@demo.example.test | Assigned deliveries |
| /shop/sign-in | client1@demo.example.test through client4@demo.example.test | Catalog, checkout, own order history, tracking and payments |

1. Open Spoilage Prediction as Admin. Start with Weekly and compare the four stem flowers. The bouquets demonstrate missing history.
2. Select one flower and switch periods. Daily covers today. Weekly covers seven days. Monthly covers the next 30 days in seven-day groups with a shorter final group. Yearly covers twelve rolling months and shows one row per month.
3. Open the supplier inventory and orders for the same catalog. Inspect an assigned delivery as the driver. Use a client account to check its own orders, tracking and payments.

## Data and interpretation

The supplied synthetic baseline covers November 1, 2025 through October 31, 2026. It contains 5,002 rows across three flower IDs. Pooling sums opening stock and spoiled stock per flower and day before calculating rates. The reproducible presentation transformation extends those patterns to 1,096 days for each of four flowers, November 28, 2023 through November 27, 2026. The seeder shifts the dates together to end on yesterday in Manila. Run `python database/data/build_demo_history.py --check` to verify the derived CSV.

Three years provide room for a long history and a chronological holdout. The extra years reuse synthetic patterns, so they do not supply independent evidence of real seasonality. Training uses 70% train, 10% validation and 20% test. Validation selects the order, and the held-out test measures error on daily spoilage rates. Production uses the saved order with completed flower history to forecast one daily path. Insufficient history or an unavailable model is reported through the existing fallback rules.

For each daily rate, the inventory calculation applies spoilage to the surviving stock. All views display risk using the same seven-day reference. Low is below 10%, Medium is 10% to below 25%, High is 25% to below 50%, and Critical is 50% or above. Annual monthly summaries describe conditional exposure over each month. They assume continuing exposure and do not estimate annual sales, replenishment or actual stems lost during the year.

| Synthetic flower | Observed demonstration run, seven-day forecast | Reference risk |
| --- | ---: | --- |
| Red Rose | 4.83% | Low |
| White Rose | 12.94% | Medium |
| Sunflower | 29.31% | High |
| Pink Tulip | 52.11% | Critical |

Results above were calculated on the seeded November 28 reference history. Future observations and model refits can change them. The held-out period is 220 days per flower, shorter than the annual view. Annual forecast accuracy remains unverified.
