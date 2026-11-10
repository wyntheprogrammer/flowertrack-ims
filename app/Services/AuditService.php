<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AuditService
{
    private const SAFE_FIELDS = [
        'id', 'code', 'name', 'email', 'role', 'status',
        'product_type', 'category', 'description',
        'method', 'receiving_unit', 'reference',
        'reason', 'remarks', 'address', 'phone',
        'recipient_name', 'received_date', 'harvest_date',
        'expiry_date', 'scheduled_at', 'completed_at',
        'dispatched_at', 'delivered_at',
        'flower_code', 'client_code', 'client_name', 'base_unit',
        'user_id', 'flower_id', 'client_id', 'order_id',
        'payment_id', 'batch_id', 'assigned_to',
        'minimum_stock', 'quantity', 'received_quantity',
        'initial_quantity', 'remaining_quantity',
        'conversion_factor', 'price_cents', 'amount_cents',
        'subtotal_cents', 'fee_cents', 'total_cents',
        'bunch_factor', 'bundle_factor', 'box_factor',
        'is_active', 'must_change_password', 'stocked_out',
        'spoiled', 'credential_changed', 'image_changed',
        'proof_recorded',
    ];

    /**
     * Existing isolated tests may not create the audit table.
     * Normal application requests always require the migrated table.
     */
    public function enabled(Model $model): bool
    {
        if (! app()->runningUnitTests()) {
            return true;
        }

        return Schema::connection($model->getConnectionName())
            ->hasTable('audit_events');
    }

    /**
     * Read approved fields from the previous or current model state.
     */
    public function snapshot(Model $model, bool $original = false): array
    {
        return $this->sanitize(
            $original
                ? $model->getRawOriginal()
                : $model->getAttributes()
        );
    }

    /**
     * Write one event using the business record's database connection.
     */
    public function record(
        string $module,
        string $action,
        ?Model $record = null,
        array $before = [],
        array $after = [],
        string $result = 'Success',
        ?string $description = null,
        ?User $actor = null,
        string $source = 'Application'
    ): AuditEvent {
        if (! in_array($result, ['Success', 'Failed'], true)) {
            throw new InvalidArgumentException('Invalid audit result.');
        }

        $actor ??= Auth::user();

        $request = app()->bound('request') ? request() : null;
        $route = $request?->route();
        $hasRoute = $route instanceof Route;

        $requestId = null;

        if ($request) {
            $requestId = $request->attributes->get('audit_request_id');

            if (! $requestId) {
                $requestId = (string) Str::uuid();

                $request->attributes->set(
                    'audit_request_id',
                    $requestId
                );
            }
        }

        $attributes = $record?->getAttributes() ?? [];

        $event = new AuditEvent();

        $event->setConnection(
            $record?->getConnectionName()
                ?? config('database.default')
        );

        $event->fill([
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name
                ?? ($source === 'Security' ? 'Unauthenticated' : 'System'),
            'actor_role' => $actor?->role,
            'module' => Str::limit($module, 80, ''),
            'action' => Str::limit($action, 100, ''),
            'record_type' => $record ? get_class($record) : null,
            'record_id' => $record
                ? Str::limit((string) $record->getKey(), 100, '')
                : null,
            'record_code' => isset($attributes['code'])
                ? Str::limit((string) $attributes['code'], 100, '')
                : null,
            'result' => $result,
            'description' => Str::limit(
                $description ?? "{$action} in {$module}.",
                1000,
                ''
            ),
            'before_values' => $this->sanitize($before),
            'after_values' => $this->sanitize($after),
            'request_id' => $requestId,
            'ip_address' => $hasRoute ? $request->ip() : null,
            'route_name' => $hasRoute
                ? Str::limit((string) $route->getName(), 150, '')
                : null,
            'http_method' => $hasRoute ? $request->method() : null,
            'source' => Str::limit($source, 30, ''),
        ]);

        $event->save();

        return $event;
    }

    /**
     * Exclude passwords, tokens, paths, and nested payloads.
     */
    private function sanitize(array $values): array
    {
        $safe = [];

        foreach (self::SAFE_FIELDS as $field) {
            if (! array_key_exists($field, $values)) {
                continue;
            }

            $value = $values[$field];

            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            $safe[$field] = is_string($value)
                ? Str::limit($value, 1000, '')
                : $value;
        }

        return $safe;
    }
}