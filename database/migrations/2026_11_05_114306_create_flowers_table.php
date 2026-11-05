<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flowers', function (Blueprint $table) {
            $table->id();

            $table->string('code', 30)->unique();
            $table->string('name', 150);

            $table->enum('product_type', ['Stem', 'Bouquet'])
                ->default('Stem');

            $table->string('category', 100);

            $table->unsignedInteger('minimum_stock')->default(1);

            $table->text('description');
            $table->string('image_path')->nullable();

            $table->enum('status', ['Active', 'Inactive'])
                ->default('Active');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flowers');
    }
};