<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuditAuthentication
{
    /**
     * Register authentication events.
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'login',
            Failed::class => 'failed',
            Logout::class => 'logout',
        ];
    }

    /**
     * Credentials passed authentication.
     * Portal role restrictions still apply afterward.
     */
    public function login(Login $event): void
    {
        $user = $event->user instanceof User
            ? $event->user
            : null;

        $allowed = $user
            && $user->is_active
            && in_array($user->role, [
                User::ROLE_ADMIN,
                User::ROLE_SUPPLIER,
                User::ROLE_DELIVERY,
            ], true);

        $this->write(
            action: $allowed ? 'Login Accepted' : 'Login Rejected',
            result: $allowed ? 'Success' : 'Failed',
            description: $allowed
                ? 'Credentials accepted; account access rules still apply.'
                : 'Account cannot access the operational portal.',
            record: $user,
            actor: $user
        );
    }

    /**
     * Never read or store the event's credentials.
     */
    public function failed(Failed $event): void
    {
        $this->write(
            action: 'Login Failed',
            result: 'Failed',
            description: 'Login credentials were not accepted.',
            record: $event->user instanceof User
                ? $event->user
                : null
        );
    }

    /**
     * Record session termination.
     */
    public function logout(Logout $event): void
    {
        $user = $event->user instanceof User
            ? $event->user
            : null;

        if (! $user) {
            return;
        }

        $this->write(
            action: 'Session Ended',
            result: 'Success',
            description: 'The authenticated session ended.',
            record: $user,
            actor: $user
        );
    }

    /**
     * Audit failures must not replace the authentication response.
     */
    private function write(
        string $action,
        string $result,
        string $description,
        ?User $record = null,
        ?User $actor = null
    ): void {
        try {
            $audit = app(AuditService::class);

            if (! $audit->enabled($record ?? new User())) {
                return;
            }

            if (app()->bound('request') && $result === 'Failed') {
                request()->attributes->set('audit_auth_failed', true);
            }

            $audit->record(
                module: 'Authentication',
                action: $action,
                record: $record,
                result: $result,
                description: $description,
                actor: $actor,
                source: 'Security'
            );
        } catch (Throwable) {
            // Do not include credentials or exception details.
            Log::warning(
                'Authentication audit record could not be saved.'
            );
        }
    }
}