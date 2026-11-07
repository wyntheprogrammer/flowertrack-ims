<?php

namespace App\Console\Commands;

use App\Models\Flower;
use App\Models\FlowerPrice;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SetSellingPrice extends Command
{
    protected $signature = 'sales:set-price {flowerCode} {amount}';
    protected $description = 'Set the current Sales-owned selling price for one catalog item';

    /** Trusted CLI setup only; no public order screen may assign selling prices. */
    public function handle(): int
    {
        try {
            $cents = Money::parse((string) $this->argument('amount'));
            if ($cents < 1) {
                throw new InvalidArgumentException('Selling price must be greater than zero.');
            }
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $code = (string) $this->argument('flowerCode');
        $found = DB::transaction(function () use ($code, $cents): bool {
            $flower = Flower::query()->where('code', $code)->lockForUpdate()->first();
            if ($flower === null) {
                return false;
            }
            FlowerPrice::updateOrCreate(['flower_id' => $flower->id], ['price_cents' => $cents]);

            return true;
        });
        if (! $found) {
            $this->error('Catalog item not found.');

            return self::FAILURE;
        }
        $this->info('Selling price saved: '.$code.' '.Money::format($cents));

        return self::SUCCESS;
    }
}
