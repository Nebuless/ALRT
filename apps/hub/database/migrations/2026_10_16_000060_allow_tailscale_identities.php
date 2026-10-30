<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('owner_slot')->nullable()->default(null)->change();
        });
    }

    public function down(): never
    {
        throw new RuntimeException('Retain this additive migration when rolling back application code. Database disaster recovery requires coordinated action-state reconciliation.');
    }
};
