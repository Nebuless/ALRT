<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Keep this historical expression independent of future read-model edits.
        // SQLite's migrator does not wrap schema changes in a transaction.
        DB::transaction(function (): void {
            DB::statement("CREATE INDEX telemetry_samples_timeline_cover_index ON telemetry_samples(machine_id, sampled_at, id, json_remove(payload, '$.processes.top_cpu.items', '$.processes.top_rss.items', '$.processes.orphans.items', '$.processes.zombies.items', '$.processes.longest_running.items'), stream_id, sequence, scope, received_at)");
            DB::statement('DROP INDEX telemetry_samples_machine_id_sampled_at_index');
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('CREATE INDEX telemetry_samples_machine_id_sampled_at_index ON telemetry_samples(machine_id, sampled_at)');
            DB::statement('DROP INDEX telemetry_samples_timeline_cover_index');
        });
    }
};
