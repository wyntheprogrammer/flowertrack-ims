<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();

            // Actor identity and historical display snapshots.
            $table->foreignId('actor_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('actor_name', 255);
            $table->string('actor_role', 30)->nullable();

            // Business action and affected record.
            $table->string('module', 80);
            $table->string('action', 100);
            $table->string('record_type', 120)->nullable();
            $table->string('record_id', 100)->nullable();
            $table->string('record_code', 100)->nullable();

            $table->enum('result', ['Success', 'Failed']);
            $table->string('description', 1000);

            // Only sanitized, permitted values belong here.
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();

            // Request context; no cookies, tokens or raw request body.
            $table->uuid('request_id')->nullable()->index();
            $table->ipAddress('ip_address')->nullable();
            $table->string('route_name', 150)->nullable();
            $table->string('http_method', 10)->nullable();
            $table->string('source', 30)->default('Application');

            // Audit entries have no editable updated_at timestamp.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['created_at', 'id']);
            $table->index(['module', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['result', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};