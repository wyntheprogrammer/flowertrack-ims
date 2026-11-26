<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DeliveryFeeService
{
    public function __construct(private readonly AuditService $audit) {}

    /** Use a current read that holds the fee stable through order creation. */
    public function currentFee(bool $lockForOrder = false): ?int
    {
        $setting = $this->setting();
        if ($lockForOrder) {
            $setting->sharedLock();
        }

        $fee = $setting->sole()->fee_cents;

        return $fee === null ? null : (int) $fee;
    }

    public function update(int $fee, User $actor): void
    {
        DB::transaction(function () use ($fee, $actor): void {
            $setting = $this->setting();
            $previous = (clone $setting)->lockForUpdate()->sole()->fee_cents;
            $previous = $previous === null ? null : (int) $previous;
            if ($previous === $fee) {
                return;
            }

            $setting->update(['fee_cents' => $fee]);
            $this->audit->record(
                module: 'Delivery Management',
                action: 'Delivery Fee Updated',
                before: ['fee_cents' => $previous],
                after: ['fee_cents' => $fee],
                description: 'Updated the delivery fee for new orders.',
                actor: $actor,
            );
        }, 3);
    }

    private function setting(): Builder
    {
        return DB::table('delivery_fee_settings')->where('id', 1);
    }
}
