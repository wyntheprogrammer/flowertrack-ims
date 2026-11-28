# FlowerTrack forecasting presentation

## Existing configured machine

Start MariaDB in XAMPP and confirm that the local `.env` targets `flowertrack_ims`. Run these commands from the project root after the dependencies and frontend assets are ready. The first command drops all tables in the configured database and replaces the local data with the presentation dataset.

```powershell
php artisan migrate:fresh --seed
php artisan spoilage:train
php artisan serve
```

Training saves the selected ARIMA orders on the flowers. Opening Spoilage Prediction calculates and saves the forecasts. Open the URL printed by `php artisan serve` to sign in.

## Fresh-machine dependency installation and setup

Clone the repository, switch to `feature/flower-forecast-presentation`, and open PowerShell in the project root. Install XAMPP with MariaDB, PHP 8.5 with the MySQL PDO extension, Composer, Node.js 22.12 or later with npm, and Python 3.14 with its Windows launcher. Make sure the PHP on your PATH is the intended version.

Start MariaDB and create a database named `flowertrack_ims` if it does not already exist. Copy `.env.example` to `.env` if you do not have one. The template sets `APP_ENV=local`, `DB_CONNECTION=mysql`, and `DB_DATABASE=flowertrack_ims`. Adjust the host, port, username, and password for your local MariaDB installation.

```powershell
composer install
php artisan key:generate
npm.cmd ci
npm.cmd run build
py -3.14 -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r requirements.txt
```

Generate the key only for a new `.env`. If an existing checkout has cached configuration after a database setting changes, run `php artisan config:clear`. Then follow the existing-machine commands above. After pulling updates on a configured machine, refresh dependencies or rebuild frontend assets if those files changed.

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

The supplied synthetic baseline covers November 1, 2025 through October 31, 2026. It contains 5,002 rows across three flower IDs. Pooling sums opening stock and spoiled stock per flower and day before calculating rates. The reproducible presentation transformation extends those patterns to 1,096 days for each of four flowers, November 28, 2023 through November 27, 2026. The seeder shifts the dates together to end on yesterday in Manila. Run `.\.venv\Scripts\python.exe database/data/build_demo_history.py --check` to verify the derived CSV.

Three years provide room for a long history and a chronological holdout. The extra years reuse synthetic patterns, so they do not supply independent evidence of real seasonality. Training uses 70% train, 10% validation and 20% test. Validation selects the order, and the held-out test measures error on daily spoilage rates. Production uses the saved order with completed flower history to forecast one daily path. Insufficient history or an unavailable model is reported through the existing fallback rules.

For each daily rate, the inventory calculation applies spoilage to the surviving stock. All views display risk using the same seven-day reference. Low is below 10%, Medium is 10% to below 25%, High is 25% to below 50%, and Critical is 50% or above. Annual monthly summaries describe conditional exposure over each month. They assume continuing exposure and do not estimate annual sales, replenishment or actual stems lost during the year.

| Synthetic flower | Observed demonstration run, seven-day forecast | Reference risk |
| --- | ---: | --- |
| Red Rose | 4.83% | Low |
| White Rose | 12.94% | Medium |
| Sunflower | 29.31% | High |
| Pink Tulip | 52.11% | Critical |

Results above were calculated on the seeded November 28 reference history. Future observations and model refits can change them. The held-out period is 220 days per flower, shorter than the annual view. Annual forecast accuracy remains unverified.
