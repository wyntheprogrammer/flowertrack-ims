<?php

use Carbon\CarbonImmutable;
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
        Schema::create('batch_observation_closing_state', function (Blueprint $table) {
            $table->id();
            $table->date('started_on');
            $table->date('next_date');
            $table->timestamps();
        });

        $today = CarbonImmutable::now(config('spoilage.observation_timezone'))->toDateString();
        DB::table('batch_observation_closing_state')->insert([
            'id' => 1, 'started_on' => $today, 'next_date' => $today,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('batch_observation_closing_state');
    }
};
