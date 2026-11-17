<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\BatchObservationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CollectBatchObservations extends Command
{
    protected $signature = 'spoilage:collect-observations
        {from : First completed Philippine date (YYYY-MM-DD)}
        {--to= : Last date, inclusive; defaults to from}
        {--batch= : Limit to a numeric batch database ID}
        {--confirm-complete : Save after verifying all actual spoilage and releases have been entered}
        {--actor= : Active Admin or Supplier email required when saving}';

    protected $description = 'Preview or manually confirm daily observations; normal catch-up runs on login/logout';

    public function handle(BatchObservationService $service): int
    {
        if (! Schema::hasColumn('batch_daily_observations', 'source')) {
            $this->error('Observation collection requires the new migration. Run php artisan migrate first.');

            return self::FAILURE;
        }

        $actor = null;
        if ($this->option('confirm-complete')) {
            $actor = User::query()->where('email', $this->option('actor'))->first();
            if (! $actor) {
                $this->error('Saving requires --actor with an active Admin or Supplier email.');

                return self::FAILURE;
            }
        }
        $batch = $this->option('batch');
        if ($batch !== null && (! ctype_digit($batch) || (int) $batch < 1)) {
            $this->error('--batch must be a positive numeric database ID.');

            return self::FAILURE;
        }
        try {
            $rows = $service->collect(
                $this->argument('from'), $this->option('to') ?? $this->argument('from'),
                $batch !== null ? (int) $batch : null, $actor,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }
        $this->line($actor === null
            ? 'PREVIEW ONLY: no observations were written. Confirm all actual movements before saving.'
            : 'Confirmed collection finished. Excluded and preserved rows are not newly verified history.');
        $this->table(['Batch', 'Date', 'Unit', 'Opening', 'Spoiled', 'Released', 'Closing', 'Status', 'Reason'],
            array_map(fn ($row) => [
                $row['batch'], $row['date'], $row['base_unit'] ?? '-',
                $row['opening_quantity'] ?? '-', $row['spoiled_quantity'] ?? '-',
                $row['released_quantity'] ?? '-', $row['closing_quantity'] ?? '-',
                $row['status'], $row['exclusion_reason'] ?? '',
            ], $rows));
        $this->info(count($rows).' batch-days reviewed. No stock quantities were changed.');

        return self::SUCCESS;
    }
}
