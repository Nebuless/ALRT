<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', fn (Blueprint $table) => $table->boolean('reboot_enabled')->default(false));
        Schema::create('reboot_capabilities', function (Blueprint $table) {
            $table->foreignUuid('machine_id')->primary()->constrained();
            $table->uuid('boot_id');
            $table->boolean('available');
            $table->string('reason')->nullable();
            $table->timestamp('observed_at');
        });
        Schema::create('reboot_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('machine_id')->constrained();
            $table->uuid('boot_id');
            $table->string('state');
            $table->timestamp('queued_at');
            $table->timestamp('expires_at');
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('cancel_until')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('last_permit_expires_at')->nullable();
            $table->string('result')->nullable();
            $table->timestamp('result_at')->nullable();
            $table->uuid('observed_boot_id')->nullable();
            $table->timestamp('observed_boot_at')->nullable();
            $table->index(['machine_id', 'queued_at']);
        });
        DB::statement("CREATE UNIQUE INDEX reboot_one_pending ON reboot_actions(machine_id) WHERE state IN ('queued','picked_up','authorized','attempted')");
    }

    public function down(): void
    {
        Schema::dropIfExists('reboot_actions');
        Schema::dropIfExists('reboot_capabilities');
        Schema::table('machines', fn (Blueprint $table) => $table->dropColumn('reboot_enabled'));
    }
};
