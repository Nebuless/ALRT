<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_watches', function (Blueprint $table) {
            $table->string('target');
            $table->string('rule');
            $table->string('state');
            $table->unsignedBigInteger('last_generation')->nullable();
            $table->timestamp('last_sample_at')->nullable();
            $table->timestamp('last_contact_at')->nullable();
            $table->string('healthy_anchor')->nullable();
            $table->timestamp('condition_since')->nullable();
            $table->timestamp('clear_since')->nullable();
            $table->string('incident_id', 64)->nullable();
            $table->unsignedSmallInteger('recovery_count')->default(0);
            $table->primary(['target', 'rule']);
        });
        Schema::create('incidents', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('target')->index();
            $table->string('target_name');
            $table->string('kind');
            $table->string('rule');
            $table->string('anchor');
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable()->index();
        });
        Schema::create('incident_transitions', function (Blueprint $table) {
            $table->id();
            $table->string('incident_id', 64)->index();
            $table->string('state');
            $table->timestamp('observed_at');
        });
        Schema::create('incident_outbox', function (Blueprint $table) {
            $table->string('incident_id', 64)->primary();
            $table->timestamp('due_at')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('lease_until')->nullable();
            $table->string('lease_token', 64)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('last_error')->nullable();
        });
        Schema::create('hub_heartbeats', function (Blueprint $table) {
            $table->string('service')->primary();
            $table->timestamp('observed_at');
        });
        Schema::create('peer_hub_observations', function (Blueprint $table) {
            $table->uuid('hub_id')->primary();
            $table->string('name');
            $table->string('http_state');
            $table->timestamp('first_observed_at');
            $table->timestamp('observed_at');
            $table->timestamp('peer_observed_at')->nullable();
            $table->unsignedInteger('scheduler_age_seconds')->nullable();
            $table->unsignedInteger('urgent_worker_age_seconds')->nullable();
            $table->json('receipts');
        });
    }

    public function down(): void
    {
        foreach (['peer_hub_observations', 'hub_heartbeats', 'incident_outbox', 'incident_transitions', 'incidents', 'incident_watches'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
