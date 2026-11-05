<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Conversion settings affect future receipts only; batches retain their factors.
        Schema::create('inventory_unit_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flower_id')->unique()->constrained('flowers')->restrictOnDelete();
            $table->unsignedInteger('bunch_factor')->default(10);
            $table->unsignedInteger('bundle_factor')->default(20);
            $table->unsignedInteger('box_factor')->default(100);
            $table->timestamps();
        });

        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flower_id')->constrained('flowers')->restrictOnDelete();
            $table->string('code', 30)->unique();
            $table->date('received_date');
            $table->date('harvest_date');
            $table->date('expiry_date')->nullable();
            $table->unsignedInteger('received_quantity');
            $table->string('receiving_unit', 20);
            $table->unsignedInteger('conversion_factor');
            $table->unsignedInteger('initial_quantity');
            $table->unsignedInteger('remaining_quantity');
            $table->boolean('spoiled')->default(false);
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index(['flower_id', 'received_date', 'id'], 'inventory_batches_fifo_index');
        });

        // Unique request keys and payload hashes protect retried stock writes.
        Schema::create('inventory_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->string('payload_hash', 64);
            $table->foreignId('flower_id')->constrained('flowers')->restrictOnDelete();
            $table->string('kind', 30);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 255);
            $table->json('result')->nullable();
            $table->timestamps();
        });

        // Append-only ledger: correction entries preserve the original movement history.
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_id')->constrained('inventory_operations')->restrictOnDelete();
            $table->foreignId('batch_id')->constrained('inventory_batches')->restrictOnDelete();
            $table->foreignId('flower_id')->constrained('flowers')->restrictOnDelete();
            $table->string('type', 30);
            $table->bigInteger('quantity');
            $table->text('remarks')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['flower_id', 'created_at', 'id'], 'inventory_movements_history_index');
            $table->index('operation_id');
            $table->index('batch_id');
        });

        // Orders integration will own these writes; no public reservation endpoint yet.
        Schema::create('inventory_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flower_id')->constrained('flowers')->restrictOnDelete();
            $table->string('reference', 100);
            $table->unsignedInteger('quantity');
            $table->enum('status', ['Active', 'Consumed', 'Released'])->default('Active');
            $table->timestamps();
            $table->unique(['flower_id', 'reference'], 'inventory_reservations_flower_reference_unique');
            $table->index(['flower_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reservations');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_operations');
        Schema::dropIfExists('inventory_batches');
        Schema::dropIfExists('inventory_unit_settings');
    }
};
