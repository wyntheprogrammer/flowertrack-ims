<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Add financial retry/audit and refund ledgers; retain existing payment/sale tables. */
    public function up(): void
    {
        Schema::create('finance_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->string('request_hash', 64);
            $table->string('reference_key', 64)->nullable()->unique();
            $table->string('kind', 30);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('order_payments')->restrictOnDelete();
            $table->foreignId('flower_id')->nullable()->constrained('flowers')->restrictOnDelete();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('payment_id')->constrained('order_payments')->restrictOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->string('reason', 500);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
        Schema::dropIfExists('finance_operations');
    }
};