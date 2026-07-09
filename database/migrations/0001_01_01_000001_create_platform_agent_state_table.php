<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Package migration (ships in the package repo, governed by PACKAGE migration
 * discipline — additive, forward-only).
 *
 * Small NON-SECRET key/value operational state for the agent: last backup-run
 * outcome per kind (feeds `last_backup_at` + the computed heartbeat status) and
 * the last scheduled-heartbeat timestamp (feeds the diagnose scheduler-freshness
 * check). Plaintext JSON — secrets NEVER live here (they belong to the encrypted
 * `platform_agent_credentials` store). The Hub remains the authoritative run
 * catalog; this table only caches the latest local facts so telemetry works
 * without a Hub round-trip.
 */
return new class extends Migration
{
    private function connection(): ?string
    {
        return config('platform-agent.store.connection');
    }

    private function table(): string
    {
        return (string) config('platform-agent.store.state_table', 'platform_agent_state');
    }

    public function up(): void
    {
        Schema::connection($this->connection())->create($this->table(), function (Blueprint $table) {
            $table->string('key')->primary(); // e.g. "backup_run.database", "scheduled_heartbeat"
            $table->json('value')->nullable(); // non-secret operational facts
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->dropIfExists($this->table());
    }
};
