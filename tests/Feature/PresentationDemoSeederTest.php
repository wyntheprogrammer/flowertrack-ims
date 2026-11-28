<?php

namespace Tests\Feature;

use App\Models\BatchDailyObservation;
use App\Models\InventoryBatch;
use App\Models\Order;
use App\Models\SalesRecord;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class PresentationDemoSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'presentation_test',
            'database.connections.presentation_test' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
            ],
            'session.driver' => 'array', 'cache.default' => 'array',
            'hashing.bcrypt.rounds' => 4, 'spoilage.activity_collection' => false,
        ]);
        DB::purge('presentation_test');
        Carbon::setTestNow('2026-11-28 05:00:00 UTC');
        CarbonImmutable::setTestNow('2026-11-28 05:00:00 UTC');
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        DB::purge('presentation_test');
        parent::tearDown();
    }

    public function test_seed_is_reproducible_coherent_and_demo_accounts_use_normal_login(): void
    {
        $this->artisan('migrate:fresh', ['--seed' => true])->assertSuccessful();
        $this->assertSame(7, User::count());
        $this->assertSame(4384, BatchDailyObservation::count());
        $this->assertSame(15, Order::count());
        $this->assertSame(7, SalesRecord::count());
        $this->assertSame(7, Order::where('status', 'Completed')->count());
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame(0, DB::table('batch_spoilage_predictions')->count());
        $this->assertSame(0, DB::table('flowers')->whereNotNull('spoilage_model')->count());
        $this->assertSame(0, BatchDailyObservation::where('opening_quantity', '<=', 0)
            ->orWhereColumn('spoiled_quantity', '>', 'opening_quantity')->count());
        $this->assertSame(0, BatchDailyObservation::whereRaw('opening_quantity != spoiled_quantity + released_quantity + closing_quantity')->count());
        foreach (InventoryBatch::all() as $batch) {
            $this->assertSame((int) $batch->remaining_quantity,
                (int) DB::table('inventory_movements')->where('batch_id', $batch->id)->sum('quantity'));
        }
        foreach (DB::table('batch_daily_observations')->select('flower_id')
            ->selectRaw('COUNT(*) AS days, MIN(observation_date) AS first_day, MAX(observation_date) AS last_day')
            ->groupBy('flower_id')->get() as $series) {
            $this->assertSame(1096, (int) $series->days);
            $this->assertSame('2023-11-28', $series->first_day);
            $this->assertSame('2026-11-27', $series->last_day);
        }
        $counts = [InventoryBatch::count(), DB::table('inventory_movements')->count(), Order::count()];
        DB::table('cache')->delete();
        $this->seed();
        $this->assertSame($counts, [InventoryBatch::count(), DB::table('inventory_movements')->count(), Order::count()]);
        foreach (['admin', 'supplier', 'client1'] as $login) {
            $user = User::where('email', $login.'@demo.example.test')->firstOrFail();
            $this->assertNotSame('123', $user->getRawOriginal('password'));
            $this->assertTrue(Hash::check('123', $user->password));
            $this->assertFalse($user->must_change_password);
            $route = $login === 'client1' ? 'client.login.store' : 'login.store';
            $this->post(route($route), ['email' => $user->email, 'password' => '123'])->assertRedirect();
            $this->assertAuthenticatedAs($user);
            if ($login === 'client1') {
                $this->get(route('supplier.inventory'))->assertForbidden();
            } else {
                $this->get(route('supplier.inventory'))->assertOk();
            }
            $this->post(route('logout'))->assertRedirect();
            $this->assertGuest();
        }
    }

    public function test_seed_refuses_nonempty_database_without_deleting_anything(): void
    {
        $this->artisan('migrate')->assertSuccessful();
        $user = User::factory()->create();
        try {
            $this->seed();
            $this->fail('Existing accounts must never be overwritten.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('freshly migrated database', $exception->getMessage());
        }
        $this->assertSame(1, User::count());
        $this->assertSame($user->email, $user->fresh()->email);
        $this->assertSame(0, InventoryBatch::count());
    }

    public function test_presentation_csv_is_continuous_and_retains_original_dataset(): void
    {
        $file = fopen(database_path('data/flowertrack_demo_history.csv'), 'r');
        $this->assertSame(['flower_id', 'observation_date', 'opening_quantity', 'spoiled_quantity'], fgetcsv($file, escape: ''));
        $previous = [];
        $count = 0;
        while (($row = fgetcsv($file, escape: '')) !== false) {
            [$flower, $date, $opening, $spoiled] = $row;
            $this->assertGreaterThan(0, (int) $opening);
            $this->assertGreaterThanOrEqual(0, (int) $spoiled);
            $this->assertLessThanOrEqual((int) $opening, (int) $spoiled);
            if (isset($previous[$flower])) {
                $this->assertSame($date, CarbonImmutable::parse($previous[$flower])->addDay()->toDateString());
            }
            $previous[$flower] = $date;
            $count++;
        }
        fclose($file);
        $this->assertSame(4384, $count);
        $this->assertCount(5003, file(database_path('data/flowertrack_dataset_REVISED.csv')));
    }
}
