<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_delivery', function (Blueprint $table) {
            $table->foreignUuid('machine_id')->primary()->constrained()->cascadeOnDelete();
            $table->timestamp('observed_at');
            $table->json('payload');
        });
        Schema::create('telemetry_delivery_acks', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('machine_id')->constrained()->cascadeOnDelete();
            $table->uuid('hub_id');
            $table->string('stream_id', 64);
            $table->unsignedBigInteger('sequence');
            $table->timestamp('acknowledged_at');
            $table->timestamp('observed_at');
            $table->unique(['machine_id', 'hub_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_delivery_acks');
        Schema::dropIfExists('telemetry_delivery');
    }
};
