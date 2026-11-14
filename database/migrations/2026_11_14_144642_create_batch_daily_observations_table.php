<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing batches remain unknown until storage is recorded.
        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->string('storage_condition', 30)->nullable();
        });

        // One daily observation per existing batch.
        Schema::create('batch_daily_observations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('batch_id')
                ->constrained('inventory_batches')
                ->restrictOnDelete();

            $table->foreignId('flower_id')
                ->constrained('flowers')
                ->restrictOnDelete();

            // Preserve batch details used for this observation.
            $table->date('observation_date');
            $table->date('harvest_date');
            $table->date('received_date');
            $table->string('storage_condition', 30)->nullable();
            $table->string('base_unit', 20);

            // Quantities for this observation day.
            $table->unsignedInteger('opening_quantity');
            $table->unsignedInteger('spoiled_quantity')->default(0);
            $table->unsignedInteger('released_quantity')->default(0);
            $table->unsignedInteger('closing_quantity');

            // Only verified, completed days may enter model history.
            $table->boolean('is_complete')->default(false);
            $table->string('exclusion_reason')->nullable();

            $table->timestamps();

            // A batch can have many dates, but only one row per date.
            $table->unique(
                ['batch_id', 'observation_date'],
                'batch_observations_batch_date_unique'
            );

            $table->index(
                ['flower_id', 'observation_date'],
                'batch_observations_flower_date_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_daily_observations');

        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->dropColumn('storage_condition');
        });
    }
};