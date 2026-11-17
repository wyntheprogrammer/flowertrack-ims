<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\BatchDayClosingService;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

class CatchUpBatchObservations
{
    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [Login::class => 'login', Logout::class => 'logout'];
    }

    public function login(Login $event): void
    {
        $this->catchUp($event->user);
    }

    public function logout(Logout $event): void
    {
        $this->catchUp($event->user);
    }

    /**
     * Authentication must succeed independently of catch-up availability.
     * Temporary, inactive and non-operational accounts cannot trigger writes.
     */
    private function catchUp(mixed $user): void
    {
        if (! $user instanceof User || ! $user->is_active || $user->must_change_password
            || ! in_array($user->role, [User::ROLE_ADMIN, User::ROLE_SUPPLIER], true)) {
            return;
        }
        try {
            $result = app(BatchDayClosingService::class)->close(actor: $user);
            if (in_array($result['status'], ['failed', 'unavailable'], true)) {
                Log::warning('Observation catch-up is pending; authentication was not interrupted.');
            }
        } catch (Throwable $exception) {
            report($exception);
            Log::warning('Observation catch-up is pending; authentication was not interrupted.');
        }
    }
}
