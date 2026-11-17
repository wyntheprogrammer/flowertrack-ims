<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Throwable;

class BatchDayClosingService
{
    /**
     * Shared by eligible authentication activity and explicit recovery commands.
     * Dates bound the observations, but there is no calendar-triggered execution.
     *
     * @return array{status: string, messages: array<int, string>}
     */
    public function close(bool $preview = false, ?User $actor = null): array
    {
        try {
            return $this->run($preview, $actor);
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => 'failed', 'messages' => [
                'Day catch-up failed; the unfinished day remains pending for retry.',
            ]];
        }
    }

    /** @return array{status: string, messages: array<int, string>} */
    private function run(bool $preview, ?User $actor): array
    {
        if (! config('spoilage.activity_collection')) {
            return ['status' => 'disabled', 'messages' => ['Activity-based observation collection is disabled.']];
        }
        if (! Schema::hasColumn('batch_observation_closing_state', 'revision')
            || ! Schema::hasColumn('batch_daily_observations', 'source')) {
            return ['status' => 'unavailable', 'messages' => ['Day catch-up requires its migrations. Run php artisan migrate first.']];
        }
        $lock = null;
        if (! $preview) {
            $key = 'spoilage-day-closing:'.hash('sha256', config('database.default').':'.DB::connection()->getDatabaseName());
            $lock = Cache::lock($key, 3600);
            if (! $lock->get()) {
                return ['status' => 'busy', 'messages' => ['Another day-closing run is active; this run was skipped.']];
            }
        }
        try {
            $state = DB::table('batch_observation_closing_state')->where('id', 1)->first();
            if (! $state) {
                return ['status' => 'failed', 'messages' => ['Day-closing activation checkpoint is missing; restore it before closing days.']];
            }
            Validator::make((array) $state, [
                'started_on' => ['required', 'date_format:Y-m-d'],
                'next_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:started_on'],
            ])->validate();
            $through = CarbonImmutable::now(config('spoilage.observation_timezone'))->startOfDay()->subDay()->toDateString();
            $limit = max(1, min(31, (int) config('spoilage.closing_catchup_days', 7)));
            $date = CarbonImmutable::parse($state->next_date);
            $processed = 0;
            $messages = [$preview ? 'PREVIEW ONLY: no observations or checkpoint changes will be written.'
                : 'Day catch-up verifies recorded inventory movements through yesterday.'];
            $collector = app(BatchObservationService::class);
            while ($date->toDateString() <= $through && $processed < $limit) {
                $day = $date->toDateString();
                $checkpoint = DB::table('batch_observation_closing_state')->where('id', 1)->first();
                if (! $checkpoint || (! $preview && $checkpoint->next_date !== $day)) {
                    throw new \RuntimeException('Inventory changed the pending closing date; retry this run.');
                }
                $rows = $collector->collect($day, $day,
                    actor: $preview ? null : $actor,
                    automatic: ! $preview,
                    origin: $actor !== null ? 'User Activity' : 'Console');
                $counts = array_count_values(array_column($rows, 'status'));
                $messages[] = $day.': '.count($rows).' batch-days; '.json_encode($counts);
                if (! $preview) {
                    $advanced = DB::table('batch_observation_closing_state')
                        ->where('id', 1)->where('next_date', $day)->where('revision', $checkpoint->revision)
                        ->update(['next_date' => $date->addDay()->toDateString(),
                            'revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
                    if ($advanced !== 1) {
                        throw new \RuntimeException('The closing checkpoint changed during this run.');
                    }
                }
                $date = $date->addDay();
                $processed++;
            }
            if ($processed === 0) {
                $messages[] = 'No completed days are pending. Collection starts on '.$state->started_on.'.';
            } elseif ($date->toDateString() <= $through) {
                $messages[] = 'More days remain; the next eligible login/logout or recovery run continues from '.$date->toDateString().'.';
            }

            return ['status' => 'success', 'messages' => $messages];
        } finally {
            $lock?->release();
        }
    }
}
