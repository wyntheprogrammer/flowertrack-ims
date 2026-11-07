<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flower_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flower_id')->unique()->constrained('flowers')->restrictOnDelete();
            $table->unsignedBigInteger('price_cents');
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->uuid('request_key')->unique();
            $table->string('request_hash', 64);
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('client_code', 30);
            $table->string('client_name', 255);
            $table->string('client_email', 254);
            $table->string('method', 30);
            $table->string('address', 500)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->string('status', 30)->default('Pending');
            $table->unsignedBigInteger('subtotal_cents');
            $table->unsignedBigInteger('fee_cents');
            $table->unsignedBigInteger('total_cents');
            $table->boolean('stocked_out')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'id']);
        });
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('flower_id')->constrained('flowers')->restrictOnDelete();
            $table->string('flower_code', 30);
            $table->string('name', 150);
            $table->string('product_type', 20);
            $table->string('base_unit', 20);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_cents');
            $table->unsignedBigInteger('subtotal_cents');
            $table->uuid('fulfillment_key')->unique();
            $table->timestamps();
            $table->unique(['order_id', 'flower_id']);
        });
        Schema::create('order_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('status', 30);
            $table->string('note', 1000);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 255);
            $table->timestamps();
        });
        // Payment writes belong to the later Sales & Payments workflow.
        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->string('code', 30)->unique();
            $table->string('method', 30);
            $table->unsignedBigInteger('amount_cents');
            $table->string('status', 20)->default('Pending');
            $table->string('reference', 100)->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
        // One immutable commercial snapshot per completed order.
        Schema::create('sales_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('code', 30)->unique();
            $table->string('client_name', 255);
            $table->string('client_code', 30);
            $table->json('items');
            $table->unsignedBigInteger('subtotal_cents');
            $table->unsignedBigInteger('fee_cents');
            $table->unsignedBigInteger('total_cents');
            $table->timestamp('completed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_records');
        Schema::dropIfExists('order_payments');
        Schema::dropIfExists('order_histories');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('flower_prices');
    }
};
