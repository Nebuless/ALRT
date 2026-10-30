<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_watches', fn (Blueprint $table) => $table->timestamp('evaluated_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('incident_watches', fn (Blueprint $table) => $table->dropColumn('evaluated_at'));
    }
};
