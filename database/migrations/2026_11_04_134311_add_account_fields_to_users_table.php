<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 30)
                ->default('client')
                ->after('email');

            $table->boolean('is_active')
                ->default(true)
                ->after('role');

            $table->boolean('must_change_password')
                ->default(true)
                ->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'role',
                'is_active',
                'must_change_password',
            ]);
        });
    }
};