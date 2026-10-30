<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_boots', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('machine_id')->constrained()->cascadeOnDelete();
            $table->uuid('boot_id');
            $table->boolean('retired')->default(false);
            $table->unique(['machine_id', 'boot_id']);
        });
        Schema::create('telemetry_streams', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('machine_id')->constrained()->cascadeOnDelete();
            $table->string('stream_id', 64);
            $table->uuid('boot_id');
            $table->boolean('retired')->default(false);
            $table->unsignedBigInteger('highest_sequence');
            $table->timestamp('first_received_at');
            $table->timestamp('last_received_at');
            $table->unique(['machine_id', 'stream_id']);
        });
        // Kept after raw pruning. U8 may compact accepted identities, never dedupe from raw samples.
        Schema::create('telemetry_accepted', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('machine_id')->constrained()->cascadeOnDelete();
            $table->string('stream_id', 64);
            $table->unsignedBigInteger('sequence');
            $table->string('content_hash', 64);
            $table->timestamp('accepted_at');
            $table->unique(['machine_id', 'stream_id', 'sequence']);
        });
        Schema::create('telemetry_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accepted_id')->unique()->constrained('telemetry_accepted')->cascadeOnDelete();
            $table->foreignUuid('machine_id')->constrained()->cascadeOnDelete();
            $table->string('stream_id', 64);
            $table->unsignedBigInteger('sequence');
            $table->uuid('boot_id');
            $table->timestamp('sampled_at');
            $table->timestamp('received_at');
            $table->string('scope');
            $table->double('cpu_percent')->nullable();
            $table->double('memory_used_bytes')->nullable();
            $table->double('memory_total_bytes')->nullable();
            $table->double('psi_memory_some_avg10')->nullable();
            $table->double('psi_io_some_avg10')->nullable();
            $table->json('payload');
            $table->index(['machine_id', 'id']);
            $table->index(['machine_id', 'sampled_at']);
        });
        // Projection is independent of raw retention. Only RecordContact can write it.
        Schema::create('telemetry_contacts', function (Blueprint $table) {
            $table->foreignUuid('machine_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('generation');
            $table->string('stream_id', 64);
            $table->uuid('boot_id');
            $table->unsignedBigInteger('sequence');
            $table->timestamp('last_contact_at');
            $table->timestamp('sampled_at');
            $table->json('payload');
        });
        // One outstanding challenge per machine bounds storage and supersedes delayed requests.
        Schema::create('telemetry_challenges', function (Blueprint $table) {
            $table->foreignUuid('machine_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('nonce_hash', 64);
            $table->unsignedBigInteger('generation');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['telemetry_challenges', 'telemetry_contacts', 'telemetry_samples', 'telemetry_accepted', 'telemetry_streams', 'telemetry_boots'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
