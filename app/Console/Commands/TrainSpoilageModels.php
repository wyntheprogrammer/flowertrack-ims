<?php

namespace App\Console\Commands;

use App\Models\Flower;
use App\Services\SpoilagePredictionService;
use Illuminate\Console\Command;
use Throwable;

class TrainSpoilageModels extends Command
{
    protected $signature = 'spoilage:train {flower_id? : Train a single flower by ID}';

    protected $description = 'Select and evaluate a spoilage ARIMA order using completed history for each flower';

    public function handle(SpoilagePredictionService $predictions): int
    {
        $id = $this->argument('flower_id');
        if ($id !== null && (! ctype_digit((string) $id) || (int) $id < 1)) {
            $this->error('The flower ID must be a positive integer.');

            return self::FAILURE;
        }
        $flowers = Flower::query()->when($id !== null, fn ($query) => $query->whereKey((int) $id))
            ->orderBy('id')->get();
        if ($flowers->isEmpty()) {
            $this->error('No matching flowers were found.');

            return self::FAILURE;
        }

        $trained = 0;
        $failed = false;
        foreach ($flowers as $flower) {
            try {
                $result = $predictions->train($flower);
                if ($result['status'] === 'InsufficientData') {
                    $this->warn("[{$flower->id}] {$flower->name} — {$result['message']}");

                    continue;
                }
                $order = implode(', ', $result['order']);
                $this->info("[{$flower->id}] {$flower->name} — ARIMA({$order}), test RMSE {$result['test']['RMSE_rate']}");
                $trained++;
            } catch (Throwable $exception) {
                report($exception);
                $this->error("[{$flower->id}] {$flower->name} — {$exception->getMessage()}");
                $failed = true;
            }
        }

        return $trained > 0 && ! $failed ? self::SUCCESS : self::FAILURE;
    }
}
