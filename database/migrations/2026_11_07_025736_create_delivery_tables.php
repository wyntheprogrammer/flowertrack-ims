<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();

            $table->foreignId('order_id')
                ->unique()
                ->constrained('orders')
                ->restrictOnDelete();

            $table->foreignId('assigned_to')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('status', 30)->default('Pending');
            $table->dateTime('scheduled_at')->nullable();

            $table->string('recipient_name', 120)->nullable();
            $table->string('proof_path')->nullable();
            $table->text('remarks')->nullable();

            $table->dateTime('dispatched_at')->nullable();
            $table->dateTime('delivered_at')->nullable();

            $table->timestamps();
            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('delivery_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('delivery_id')
                ->constrained('deliveries')
                ->restrictOnDelete();

            $table->uuid('request_key')->unique();
            $table->string('request_hash', 64);
            $table->string('action', 30);
            $table->string('status', 30);
            $table->text('note');

            $table->foreignId('actor_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('actor_name');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_histories');
        Schema::dropIfExists('deliveries');
    }
};