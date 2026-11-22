<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flowers', function (Blueprint $table) {
            $table->json('spoilage_model')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('flowers', function (Blueprint $table) {
            $table->dropColumn('spoilage_model');
        });
    }
};
