<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('owner_slot')->default(1)->unique();
            $table->unsignedInteger('auth_version')->default(1);
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
        });
        Schema::create('hub_identities', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->uuid('hub_uuid')->unique();
            $table->string('name');
            $table->string('role');
            $table->json('peers');
            $table->text('telegram_bot_token')->nullable();
            $table->string('telegram_chat_id')->nullable();
            $table->text('primary_signing_public_key')->nullable();
            $table->timestamps();
        });
        Schema::create('machines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('data_policy');
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('token_revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('action_confirmation_proofs', function (Blueprint $table) {
            $table->string('token_hash', 64)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('auth_version');
            $table->uuid('target');
            $table->string('session_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_confirmation_proofs');
        Schema::dropIfExists('machines');
        Schema::dropIfExists('hub_identities');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['owner_slot']);
            $table->dropColumn(['owner_slot', 'auth_version', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
