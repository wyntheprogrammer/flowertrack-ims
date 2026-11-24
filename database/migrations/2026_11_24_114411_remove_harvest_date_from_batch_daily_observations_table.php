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
        Schema::table('batch_daily_observations', function (Blueprint $table) {
            $table->dropColumn('harvest_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('batch_daily_observations', function (Blueprint $table) {
            $table->date('harvest_date')->nullable();
        });

        DB::table('batch_daily_observations')->update(['harvest_date' => DB::raw('received_date')]);

        Schema::table('batch_daily_observations', function (Blueprint $table) {
            $table->date('harvest_date')->nullable(false)->change();
        });
    }
};
