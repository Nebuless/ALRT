<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('citizen_contacts', function (Blueprint $table) {
            $table->uuid('machine_id')->primary();
            $table->unsignedBigInteger('generation');
            $table->uuid('boot_id');
            $table->uuid('bridge_id');
            $table->unsignedBigInteger('sequence');
            $table->string('sampled_at', 32);
            $table->string('last_contact_at', 32);
            $table->string('coverage');
        });
        Schema::create('citizen_challenges', function (Blueprint $table) {
            $table->uuid('machine_id')->primary();
            $table->string('nonce_hash', 64);
            $table->unsignedBigInteger('generation');
            $table->string('expires_at', 32);
            $table->string('consumed_at', 32)->nullable();
        });
        // Replay safety outlives observation retention.
        Schema::create('citizen_incarnations', function (Blueprint $table) {
            $table->uuid('machine_id');
            $table->uuid('bridge_id');
            $table->uuid('boot_id');
            $table->unsignedBigInteger('sequence');
            $table->boolean('retired')->default(false);
            $table->primary(['machine_id', 'bridge_id']);
            $table->index(['machine_id', 'boot_id']);
        });
        Schema::create('citizen_observations', function (Blueprint $table) {
            $table->uuid('machine_id');
            $table->string('citizen_id', 64);
            $table->unsignedBigInteger('generation');
            $table->string('observed_at', 32)->index();
            $table->string('revision', 64);
            $table->text('payload');
            $table->primary(['machine_id', 'citizen_id']);
        });
        // Durable action receipts must never be pruned as observations.
        Schema::create('citizen_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('machine_id');
            $table->string('citizen_id', 64);
            $table->string('revision', 64);
            $table->uuid('boot_id');
            $table->uuid('bridge_id');
            $table->unsignedBigInteger('pid');
            $table->unsignedBigInteger('start_ticks');
            $table->string('status');
            $table->string('requested_at', 32);
            $table->string('expires_at', 32);
            $table->string('dispatched_at', 32)->nullable();
            $table->string('result_at', 32)->nullable();
            $table->index(['machine_id', 'citizen_id', 'requested_at']);
            $table->index(['machine_id', 'boot_id', 'pid', 'start_ticks']);
        });
    }

    public function down(): void
    {
        foreach (['citizen_actions', 'citizen_observations', 'citizen_incarnations', 'citizen_challenges', 'citizen_contacts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
