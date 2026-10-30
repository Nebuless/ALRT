<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('auth_provider')->nullable();
            $table->string('auth_subject')->nullable();
            $table->unique(['auth_provider', 'auth_subject']);
            $table->dropColumn(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'email_verified_at']);
        });
        Schema::dropIfExists('password_reset_tokens');
    }

    public function down(): void
    {
        throw new RuntimeException('Owner provider replacement cannot be rolled back. Restore both hub and auth database backups together.');
    }
};
