<?php

namespace App\Console\Commands;

use App\Models\Delivery;
use App\Models\Order;
use App\Models\User;
use App\Services\DeliveryService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class SyncReadyDeliveries extends Command
{
    protected $signature = 'deliveries:sync-ready {actor : Admin or Supplier email}';

    protected $description = 'Create missing delivery records for ready orders';

    public function handle(DeliveryService $service): int
    {
        $actor = User::query()
            ->where('email', $this->argument('actor'))
            ->whereIn('role', [
                User::ROLE_ADMIN,
                User::ROLE_SUPPLIER,
            ])
            ->where('is_active', true)
            ->where('must_change_password', false)
            ->first();

        if (! $actor) {
            $this->error('Use an active Admin or Supplier account with an updated password.');

            return self::FAILURE;
        }

        $processed = 0;
        $skipped = 0;

        Order::query()
            ->where('method', 'Delivery')
            ->where('status', 'Ready for Delivery')
            ->whereNotIn('id', Delivery::query()->select('order_id'))
            ->chunkById(100, function ($orders) use (
                $service,
                $actor,
                &$processed,
                &$skipped
            ) {
                foreach ($orders as $order) {
                    try {
                        $service->createForOrder($order, $actor);
                        $processed++;
                    } catch (ValidationException $exception) {
                        $skipped++;

                        $this->warn(
                            $order->code.': '.$exception->getMessage()
                        );
                    }
                }
            });

        $this->info("Processed: {$processed}. Skipped: {$skipped}.");

        return self::SUCCESS;
    }
}