<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Package migration (ships in the package repo, governed by PACKAGE migration
 * discipline — distinct from the Hub Database Migration Discipline hard rule).
 *
 * Stores the durable runtime PAT ENCRYPTED at rest (Laravel Crypt / the customer
 * APP_KEY) inside the customer application's own database. The token is NEVER
 * written back to `.env` (ADR-0007 Addendum D). A small key/value shape keeps the
 * package portable across arbitrary customer schemas.
 */
return new class extends Migration
{
    private function connection(): ?string
    {
        return config('platform-agent.store.connection');
    }

    private function table(): string
    {
        return (string) config('platform-agent.store.table', 'platform_agent_credentials');
    }

    public function up(): void
    {
        Schema::connection($this->connection())->create($this->table(), function (Blueprint $table) {
            $table->string('key')->primary();   // e.g. "runtime_token"
            $table->text('value');              // encrypted ciphertext (Crypt)
            $table->json('meta')->nullable();   // token_id, abilities, expires_at, application_uuid (non-secret)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->dropIfExists($this->table());
    }
};
