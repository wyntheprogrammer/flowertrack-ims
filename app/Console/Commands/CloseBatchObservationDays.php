<?php

namespace App\Console\Commands;

use App\Services\BatchDayClosingService;
use Illuminate\Console\Command;

class CloseBatchObservationDays extends Command
{
    protected $signature = 'spoilage:close-days {--preview : Show pending days without writing observations or advancing the checkpoint}';

    protected $description = 'Manually preview or recover completed days; normal catch-up is triggered by login/logout';

    public function handle(BatchDayClosingService $closing): int
    {
        $result = $closing->close((bool) $this->option('preview'));
        $failed = in_array($result['status'], ['failed', 'unavailable'], true);
        foreach ($result['messages'] as $message) {
            if ($failed) {
                $this->error($message);
            } else {
                $this->info($message);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
