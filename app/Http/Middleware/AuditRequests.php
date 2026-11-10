<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AuditService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AuditRequests
{
    private const MODULES = [
        'dashboard' => 'Dashboard',
        'flowers' => 'Flower Management',
        'inventory' => 'Inventory Management',
        'batches' => 'Batch Management',
        'spoilage-prediction' => 'Spoilage Prediction',
        'clients' => 'Client Management',
        'orders' => 'Order Management',
        'deliveries' => 'Delivery Management',
        'sales-payments' => 'Sales & Payments',
        'reports' => 'Reports',
        'users' => 'User Management',
        'audit-trail' => 'Audit Trail',
    ];

    /**
     * Give each request an internal tracking ID.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(
            'audit_request_id',
            (string) Str::uuid()
        );

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->fromException($request, $exception);

            throw $exception;
        }

        $this->capture($request, $response->getStatusCode());

        return $response;
    }

    /**
     * Capture exceptions before Laravel converts them into
     * JSON responses, error pages, or validation redirects.
     */
    public function fromException(
        Request $request,
        Throwable $exception
    ): void {
        $status = match (true) {
            $exception instanceof AuthenticationException => 401,
            $exception instanceof AuthorizationException => 403,
            $exception instanceof TokenMismatchException => 419,
            $exception instanceof ValidationException => 422,
            $exception instanceof HttpExceptionInterface =>
                $exception->getStatusCode(),
            default => 500,
        };

        $this->capture($request, $status);
    }

    /**
     * Store a fixed description without copying submitted data.
     */
    private function capture(Request $request, int $status): void
    {
        if (
            !in_array($status, [401, 403, 419, 422, 429], true)
            && $status < 500
        ) {
            return;
        }

        if ($request->attributes->get('audit_rejection_recorded')) {
            return;
        }

        try {
            $module = $this->module($request);

            if (!$module) {
                return;
            }

            // The authentication listener already records bad credentials.
            if (
                $module === 'Authentication'
                && $request->attributes->get('audit_auth_failed')
            ) {
                return;
            }

            $audit = app(AuditService::class);

            if (!$audit->enabled(new User())) {
                return;
            }

            $action = match ($status) {
                401 => 'Authentication Required',
                403 => 'Access Denied',
                419 => 'Session Verification Failed',
                422 => 'Validation Rejected',
                429 => 'Rate Limit Rejected',
                default => 'Request Failed',
            };

            $audit->record(
                module: $module,
                action: $action,
                result: 'Failed',
                description: "{$action} (HTTP {$status}).",
                actor: Auth::user(),
                source: $status >= 500 ? 'Application' : 'Security'
            );

            // Prevent duplicate logging by middleware and exception handling.
            $request->attributes->set(
                'audit_rejection_recorded',
                true
            );
        } catch (Throwable) {
            // Keep the original response; never log raw exception details.
            Log::warning(
                'Rejected request audit record could not be saved.',
                ['status' => $status]
            );
        }
    }

    /**
     * Resolve only known modules; do not save raw URLs or query strings.
     */
    private function module(Request $request): ?string
    {
        $route = $request->route();

        $name = $route instanceof Route
            ? (string) $route->getName()
            : '';

        if (
            in_array($name, ['login.store', 'password.update'], true)
            || $request->is('login', 'change-password')
        ) {
            return 'Authentication';
        }

        $parts = explode('.', $name);

        if (in_array($parts[0] ?? '', ['supplier', 'delivery'], true)) {
            return self::MODULES[$parts[1] ?? ''] ?? null;
        }

        // Some failures happen before the route name is available.
        if (in_array($request->segment(1), ['supplier', 'delivery'], true)) {
            return self::MODULES[$request->segment(2) ?? ''] ?? null;
        }

        return null;
    }
}

