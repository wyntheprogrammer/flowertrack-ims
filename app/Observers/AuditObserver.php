<?php

namespace App\Observers;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Model;

class AuditObserver
{
    public const MODULES = [
        'User' => 'User Management',
        'Client' => 'Client Management',
        'Flower' => 'Flower Management',
        'InventoryBatch' => 'Batch Management',
        'InventoryUnitSetting' => 'Inventory Management',
        'InventoryReservation' => 'Inventory Management',
        'InventoryMovement' => 'Inventory Management',
        'Order' => 'Order Management',
        'OrderItem' => 'Order Management',
        'OrderPayment' => 'Sales & Payments',
        'PaymentRefund' => 'Sales & Payments',
        'FlowerPrice' => 'Sales & Payments',
        'SalesRecord' => 'Sales & Payments',
        'Delivery' => 'Delivery Management',
    ];

    public function __construct(
        private readonly AuditService $audit
    ) {}

    /**
     * Wait for final codes instead of logging temporary TMP codes.
     */
    public function created(Model $model): void
    {
        $code = (string) ($model->getAttributes()['code'] ?? '');

        if (str_starts_with($code, 'TMP-')) {
            return;
        }

        $this->capture($model, 'Created');
    }

    public function updated(Model $model): void
    {
        $previousCode = $model->getRawOriginal('code');

        if (
            is_string($previousCode)
            && str_starts_with($previousCode, 'TMP-')
        ) {
            $this->capture($model, 'Created');

            return;
        }

        $this->capture($model, 'Updated');
    }

    public function deleted(Model $model): void
    {
        $this->capture($model, 'Deleted');
    }

    /**
     * Capture approved values without storing credentials or file paths.
     */
    private function capture(Model $model, string $action): void
    {
        if (! $this->audit->enabled($model)) {
            return;
        }

        $name = class_basename($model);
        $module = self::MODULES[$name] ?? null;

        if (! $module) {
            return;
        }

        if ($model instanceof User && $model->role === User::ROLE_CLIENT) {
            $module = 'Client Management';
        }

        $before = $action === 'Created'
            ? []
            : $this->audit->snapshot(
                $model,
                $action !== 'Deleted'
            );

        $after = $action === 'Deleted'
            ? []
            : $this->audit->snapshot($model);

        if ($action === 'Updated') {
            $changed = array_flip(array_keys($model->getChanges()));

            $before = array_intersect_key($before, $changed);
            $after = array_intersect_key($after, $changed);

            if ($model->wasChanged('password')) {
                $before['credential_changed'] = false;
                $after['credential_changed'] = true;
                $action = 'Credentials Changed';
            }

            if ($model->wasChanged('image_path')) {
                $before['image_changed'] = false;
                $after['image_changed'] = true;
            }

            if ($model->wasChanged('proof_path')) {
                $before['proof_recorded'] = false;
                $after['proof_recorded'] = true;
            }

            // Ignore timestamp-only and other non-audited changes.
            if ($before === $after) {
                return;
            }
        }

        if ($name === 'InventoryMovement') {
            $action = (string) $model->type;
        }

        $this->audit->record(
            module: $module,
            action: $action,
            record: $model,
            before: $before,
            after: $after,
            description: "{$name} {$action}."
        );
    }
}