<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('batch_daily_observations', function (Blueprint $table) {
            $table->string('source', 40)->nullable();
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('batch_daily_observations', function (Blueprint $table) {
            $table->dropForeign(['collected_by']);
            $table->dropColumn(['source', 'collected_by', 'verified_at']);
        });
    }
};
