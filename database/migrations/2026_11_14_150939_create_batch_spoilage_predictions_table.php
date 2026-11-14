<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batch_spoilage_predictions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('batch_id')
                ->constrained('inventory_batches')
                ->restrictOnDelete();

            // Dates covered by this forecast.
            $table->date('as_of_date');
            $table->date('forecast_end_date');

            // Stock used as the prediction's starting point.
            $table->unsignedInteger('opening_quantity');
            $table->string('base_unit', 20);

            // Identify the algorithm implementation and input snapshot.
            $table->string('model_version', 50);
            $table->string('input_hash', 64);

            // Processing status is separate from the predicted risk level.
            $table->string('status', 30)->default('Pending');
            $table->string('method', 100)->nullable();

            // Keep results empty when no supported prediction is available.
            $table->decimal('predicted_fraction', 12, 10)->nullable();
            $table->decimal('predicted_quantity', 20, 6)->nullable();
            $table->string('risk_level', 20)->nullable();

            // Explain the history, fallback or unavailable result.
            $table->unsignedInteger('history_days')->default(0);
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();

            $table->dateTime('generated_at')->nullable();
            $table->timestamps();

            // Repeating an identical request must not duplicate its result.
            $table->unique(
                ['batch_id', 'as_of_date', 'model_version', 'input_hash'],
                'batch_predictions_input_unique'
            );

            $table->index(
                ['batch_id', 'as_of_date'],
                'batch_predictions_batch_date_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_spoilage_predictions');
    }
};