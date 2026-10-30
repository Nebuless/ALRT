<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telemetry_accepted', function (Blueprint $table) {
            $table->timestamp('sampled_at')->nullable();
            $table->index(['sampled_at', 'id']);
        });
        DB::statement("UPDATE telemetry_accepted SET sampled_at = strftime('%Y-%m-%dT%H:%M:%S+00:00', COALESCE((SELECT sampled_at FROM telemetry_samples WHERE telemetry_samples.accepted_id = telemetry_accepted.id), accepted_at))");
        Schema::create('history_buckets', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('machine_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('resolution');
            $table->timestamp('bucket_at');
            $table->json('aggregate');
            $table->unique(['machine_id', 'resolution', 'bucket_at']);
            $table->index(['resolution', 'bucket_at', 'id']);
        });
        Schema::create('history_accepted_intervals', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('machine_id')->constrained()->cascadeOnDelete();
            $table->string('stream_id', 64);
            $table->unsignedBigInteger('first_sequence');
            $table->unsignedBigInteger('last_sequence');
            $table->index(['machine_id', 'stream_id', 'first_sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('history_accepted_intervals');
        Schema::dropIfExists('history_buckets');
        Schema::table('telemetry_accepted', function (Blueprint $table) {
            $table->dropIndex(['sampled_at', 'id']);
            $table->dropColumn('sampled_at');
        });
    }
};
