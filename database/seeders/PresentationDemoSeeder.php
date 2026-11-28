<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Delivery;
use App\Models\Flower;
use App\Models\FlowerPrice;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use App\Services\AuditService;
use App\Services\DeliveryService;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\SalesPaymentService;
use App\Support\Money;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Reproducible synthetic data for a fresh local or testing database. */
class PresentationDemoSeeder extends Seeder
{
    private const MARKER = 'Presentation Demo v3 Seeded';

    private User $admin;

    private User $supplier;

    private User $driver;

    /** @var list<Client> */
    private array $clients = [];

    /** @var array<int, Flower> */
    private array $flowers = [];

    public function run(): void
    {
        $this->guardEnvironment();
        if (DB::table('audit_events')->where('source', 'PresentationSeed')->where('action', self::MARKER)->exists()) {
            $this->command?->info('Presentation data already seeded; nothing changed.');

            return;
        }
        foreach (['users', 'flowers', 'clients', 'inventory_batches', 'orders', 'batch_daily_observations', 'audit_events'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Presentation seeding requires a freshly migrated database.');
            }
        }
        $oldCarbon = Carbon::getTestNow();
        $oldImmutable = CarbonImmutable::getTestNow();
        $oldUser = Auth::user();
        try {
            DB::transaction(function (): void {
                Model::withoutEvents(function (): void {
                    $this->accounts();
                    Auth::setUser($this->admin);
                    $this->catalog();
                    $this->history();
                    DB::table('delivery_fee_settings')->where('id', 1)->update(['fee_cents' => 10000]);
                    $this->operations();
                    app(AuditService::class)->record(
                        module: 'Presentation Setup', action: self::MARKER,
                        description: 'Synthetic revised-dataset presentation import completed; no actual business activity is represented.',
                        actor: $this->admin, source: 'PresentationSeed',
                    );
                });
            });
        } finally {
            Carbon::setTestNow($oldCarbon);
            CarbonImmutable::setTestNow($oldImmutable);
            if ($oldUser !== null) {
                Auth::setUser($oldUser);
            } else {
                Auth::forgetUser();
            }
        }
        $this->command?->info('Synthetic presentation data seeded. Demo login password: 123. Run php artisan spoilage:train to train the models.');
    }

    private function guardEnvironment(): void
    {
        $database = DB::connection()->getDatabaseName();
        $isolatedTest = app()->environment('testing') && $database === ':memory:';
        if (! app()->environment('local', 'testing') || ($database !== 'flowertrack_ims' && ! $isolatedTest)) {
            throw new RuntimeException('Presentation seeding requires flowertrack_ims in a local or testing environment, or an in-memory test database.');
        }
    }

    private function accounts(): void
    {
        foreach (['admin' => User::ROLE_ADMIN, 'supplier' => User::ROLE_SUPPLIER, 'driver' => User::ROLE_DELIVERY] as $login => $role) {
            $this->{$login} = User::forceCreate([
                'name' => 'Demo '.ucfirst($login), 'email' => $login.'@demo.example.test',
                'password' => '123', 'role' => $role, 'is_active' => true,
                'must_change_password' => false, 'email_verified_at' => now(),
            ]);
        }
        foreach (['Ana Flores', 'Ben Mercado', 'Clara Reyes', 'Diego Cruz'] as $index => $name) {
            $user = User::forceCreate([
                'name' => 'Demo '.$name, 'email' => 'client'.($index + 1).'@demo.example.test',
                'password' => '123', 'role' => User::ROLE_CLIENT, 'is_active' => true,
                'must_change_password' => false, 'email_verified_at' => now(),
            ]);
            $this->clients[] = Client::create([
                'code' => 'CLI-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'user_id' => $user->id, 'phone' => '0900000000'.($index + 1),
                'address' => 'Synthetic presentation address '.($index + 1).', Philippines',
            ]);
        }
    }

    private function catalog(): void
    {
        foreach ([
            ['Red Rose', 'Roses', 'Stem', 3500], ['White Rose', 'Roses', 'Stem', 4000],
            ['Sunflower', 'Sunflowers', 'Stem', 5500], ['Pink Tulip', 'Tulips', 'Stem', 6500],
            ['Classic Rose Bouquet', 'Roses', 'Bouquet', 65000], ['Mixed Bouquet', 'Others', 'Bouquet', 90000],
        ] as $index => [$name, $category, $type, $price]) {
            $flower = Flower::create([
                'code' => 'FLW-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'name' => $name, 'category' => $category, 'product_type' => $type,
                'minimum_stock' => $type === 'Stem' ? 40 : 5,
                'description' => 'Synthetic presentation product; no actual stock or sales are represented.',
                'status' => 'Active',
            ]);
            $this->flowers[$index + 1] = $flower;
            FlowerPrice::create(['flower_id' => $flower->id, 'price_cents' => $price]);
        }
    }

    /** Import explicit synthetic history with receipts, spoilage and releases reconciling to each closed batch. */
    private function history(): void
    {
        $file = fopen(database_path('data/flowertrack_demo_history.csv'), 'r');
        if ($file === false) {
            throw new RuntimeException('The presentation forecasting CSV is missing.');
        }
        try {
            if (fgetcsv($file, escape: '') !== ['flower_id', 'observation_date', 'opening_quantity', 'spoiled_quantity']) {
                throw new RuntimeException('Unexpected presentation CSV columns.');
            }
            $shift = CarbonImmutable::parse('2026-11-27', 'Asia/Manila')->diffInDays(CarbonImmutable::now('Asia/Manila')->subDay()->startOfDay(), false);
            $observations = [];
            while (($row = fgetcsv($file, escape: '')) !== false) {
                [$flowerKey, $date, $opening, $spoiled] = $row;
                $flower = $this->flowers[(int) $flowerKey] ?? throw new RuntimeException('Unknown CSV flower.');
                $opening = (int) $opening;
                $spoiled = (int) $spoiled;
                if ($opening <= 0 || $spoiled < 0 || $spoiled > $opening) {
                    throw new RuntimeException('Invalid synthetic history quantities.');
                }
                $day = CarbonImmutable::parse($date, 'Asia/Manila')->addDays((int) $shift);
                $receivedAt = $day->setTime(8, 0)->utc()->format('Y-m-d H:i:s');
                $closedAt = $day->setTime(18, 0)->utc()->format('Y-m-d H:i:s');
                $batchId = DB::table('inventory_batches')->insertGetId([
                    'flower_id' => $flower->id, 'code' => 'HIS-'.$flowerKey.'-'.$day->format('Ymd'),
                    'received_date' => $day->toDateString(), 'received_quantity' => $opening,
                    'receiving_unit' => 'Stem', 'conversion_factor' => 1, 'initial_quantity' => $opening,
                    'remaining_quantity' => 0, 'spoiled' => false, 'storage_condition' => 'Chilled',
                    'remarks' => 'Synthetic dataset import; same-day wholesale releases close this historical cohort.',
                    'created_at' => $receivedAt, 'updated_at' => $closedAt,
                ]);
                $operationId = DB::table('inventory_operations')->insertGetId([
                    'request_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', implode('|', $row)),
                    'flower_id' => $flower->id, 'kind' => 'SyntheticHistoryImport',
                    'actor_id' => $this->admin->id, 'actor_name' => $this->admin->name,
                    'result' => json_encode(['batch_id' => $batchId], JSON_THROW_ON_ERROR),
                    'created_at' => $receivedAt, 'updated_at' => $closedAt,
                ]);
                $movements = [['Stock-In', $opening, null, $receivedAt]];
                if ($spoiled > 0) {
                    $movements[] = ['Stock-Out', -$spoiled, 'Spoilage', $closedAt];
                }
                if ($opening > $spoiled) {
                    $movements[] = ['Stock-Out', -($opening - $spoiled), 'Other use', $closedAt];
                }
                foreach ($movements as [$type, $quantity, $reason, $at]) {
                    DB::table('inventory_movements')->insert([
                        'operation_id' => $operationId, 'batch_id' => $batchId, 'flower_id' => $flower->id,
                        'type' => $type, 'quantity' => $quantity, 'remarks' => 'Synthetic historical import; '.($reason ?? 'receipt'),
                        'metadata' => json_encode(['reason' => $reason, 'synthetic' => true], JSON_THROW_ON_ERROR),
                        'created_at' => $at, 'updated_at' => $at,
                    ]);
                }
                $observations[] = [
                    'batch_id' => $batchId, 'flower_id' => $flower->id, 'observation_date' => $day->toDateString(),
                    'received_date' => $day->toDateString(), 'storage_condition' => 'Chilled', 'base_unit' => 'Stem',
                    'opening_quantity' => $opening, 'spoiled_quantity' => $spoiled,
                    'released_quantity' => $opening - $spoiled, 'closing_quantity' => 0,
                    'is_complete' => true, 'exclusion_reason' => null, 'source' => 'SyntheticPresentationImport',
                    'collected_by' => $this->admin->id, 'verified_at' => $closedAt,
                    'created_at' => $closedAt, 'updated_at' => $closedAt,
                ];
                if (count($observations) >= 100) {
                    DB::table('batch_daily_observations')->insert($observations);
                    $observations = [];
                }
            }
            if ($observations !== []) {
                DB::table('batch_daily_observations')->insert($observations);
            }
        } finally {
            fclose($file);
        }
    }

    /** All current operational scenarios use the application's real stock, order and finance services. */
    private function operations(): void
    {
        foreach ($this->flowers as $flower) {
            app(InventoryService::class)->stockIn([
                'request_key' => (string) Str::uuid(), 'flower_id' => $flower->id,
                'quantity' => $flower->product_type === 'Stem' ? 600 : 60,
                'unit' => $flower->product_type, 'received' => CarbonImmutable::now('Asia/Manila')->toDateString(),
                'storage_condition' => 'Chilled', 'remarks' => 'Synthetic current presentation receipt.',
            ], $this->supplier);
        }
        for ($index = 0; $index < 15; $index++) {
            $method = $index >= 12 ? 'Delivery' : 'Customer Pickup';
            $order = app(OrderService::class)->store([
                'request_key' => (string) Str::uuid(), 'client_id' => $this->clients[$index % 4]->id,
                'method' => $method, 'address' => $method === 'Delivery' ? 'Synthetic delivery address, Philippines' : null,
                'notes' => 'Synthetic presentation order '.($index + 1),
                'items' => [['flower_id' => $this->flowers[1 + $index % 4]->id, 'quantity' => 3 + $index % 4],
                    ['flower_id' => $this->flowers[5 + $index % 2]->id, 'quantity' => 1]],
            ], $this->supplier);
            if ($index === 8) {
                continue;
            }
            $this->transition($order, 'Confirmed');
            if ($index === 9) {
                continue;
            }
            if ($index === 11) {
                $this->transition($order, 'Cancelled', ['reason' => 'Synthetic customer cancellation before fulfillment.']);

                continue;
            }
            $this->transition($order, 'Processing');
            if ($index === 10) {
                continue;
            }
            $this->transition($order, $method === 'Delivery' ? 'Ready for Delivery' : 'Ready for Pickup');
            if ($index < 7) {
                $this->payment($order, true);
            } elseif ($index === 7) {
                $this->payment($order, false);
            } elseif ($index >= 13) {
                $delivery = Delivery::where('order_id', $order->id)->firstOrFail();
                $this->deliveryAction($delivery, 'schedule', [
                    'scheduled_at' => CarbonImmutable::now('Asia/Manila')->addHour()->toIso8601String(),
                    'reason' => 'Synthetic scheduled route.',
                ]);
                $this->deliveryAction($delivery, 'assign', ['assigned_to' => $this->driver->id, 'reason' => 'Synthetic driver assignment.']);
                if ($index === 14) {
                    $this->deliveryAction($delivery, 'dispatch');
                }
            }
        }
    }

    /** @param array<string, mixed> $extra */
    private function transition(Order $order, string $status, array $extra = []): void
    {
        $order = $order->fresh();
        app(OrderService::class)->action($order, [
            'status' => $status, 'version' => app(OrderService::class)->row($order, $this->admin)['version'], ...$extra,
        ], $this->admin);
    }

    private function payment(Order $order, bool $confirm): void
    {
        $finance = app(SalesPaymentService::class);
        $order = $order->fresh();
        $dto = collect($finance->data($this->admin)['orders'])->firstWhere('dbId', $order->id);
        $finance->recordPayment([
            'request_key' => (string) Str::uuid(), 'version' => $dto['version'], 'order_id' => $order->id,
            'method' => 'Cash', 'amount' => Money::format((int) $order->total_cents),
            'remarks' => 'Synthetic demo collection; no real money transferred.',
        ], $this->admin, true);
        if ($confirm) {
            $payment = OrderPayment::where('order_id', $order->id)->firstOrFail();
            $dto = collect($finance->data($this->admin)['payments'])->firstWhere('dbId', $payment->id);
            $finance->confirm($payment, [
                'request_key' => (string) Str::uuid(), 'version' => $dto['version'],
                'reason' => 'Synthetic demo receipt verification.',
            ], $this->admin);
        }
    }

    /** @param array<string, mixed> $extra */
    private function deliveryAction(Delivery $delivery, string $action, array $extra = []): void
    {
        $delivery = $delivery->fresh();
        app(DeliveryService::class)->action($delivery, [
            'action' => $action, 'request_key' => (string) Str::uuid(),
            'version' => app(DeliveryService::class)->row($delivery, $this->admin)['version'], ...$extra,
        ], $this->admin);
    }
}
