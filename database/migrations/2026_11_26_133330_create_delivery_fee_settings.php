<?php

use App\Support\Money;
use Dotenv\Dotenv;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $legacyFee = env('ORDER_DELIVERY_FEE');
        if ($legacyFee === null && app()->configurationIsCached()) {
            $legacyFee = config('orders.delivery_fee')
                ?? (Dotenv::createArrayBacked(app()->environmentPath(), app()->environmentFile())
                    ->safeLoad()['ORDER_DELIVERY_FEE'] ?? null);
        }

        try {
            if ($legacyFee !== null && ! is_string($legacyFee) && ! is_int($legacyFee)) {
                throw new InvalidArgumentException('Fee must be an exact decimal amount.');
            }

            $fee = $legacyFee === null || $legacyFee === '' ? null : Money::parse((string) $legacyFee);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('Set ORDER_DELIVERY_FEE to a valid monetary amount before migrating.', 0, $exception);
        }

        Schema::create('delivery_fee_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('fee_cents')->nullable();
        });

        DB::table('delivery_fee_settings')->insert(['id' => 1, 'fee_cents' => $fee]);

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('delivery_fee_snapshot_cents')->nullable();
        });

        $editable = DB::table('orders')->whereIn('status', ['Pending', 'Confirmed']);
        (clone $editable)->where('method', 'Delivery')
            ->update(['delivery_fee_snapshot_cents' => DB::raw('fee_cents')]);
        $editable->where('method', 'Customer Pickup')->update(['delivery_fee_snapshot_cents' => $fee]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('delivery_fee_snapshot_cents');
        });
        Schema::dropIfExists('delivery_fee_settings');
    }
};
