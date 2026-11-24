<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->dropColumn(['harvest_date', 'expiry_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->date('harvest_date')->nullable();
            $table->date('expiry_date')->nullable();
        });

        DB::table('inventory_batches')->update(['harvest_date' => DB::raw('received_date')]);

        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->date('harvest_date')->nullable(false)->change();
        });
    }
};
