<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Credentials;

/**
 * Abstraction over where the agent's tokens live.
 *
 * Two tokens, separated abilities (ADR-0007 Addendum D, enrollment-exchange):
 *
 *  - ENROLLMENT token: short-lived, single-use, ability `agent:register` ONLY.
 *    Pasted into `.env` (PLATFORM_TOKEN) for the one onboarding handoff. Used
 *    only by `platform-agent:install` to call POST /api/v1/agent/register.
 *
 *  - RUNTIME PAT: durable, abilities app:backup + app:heartbeat + app:restore
 *    (NEVER agent:register). Issued ONCE by the Hub in the register response and
 *    stored ENCRYPTED in the customer app's own database — NEVER written back to
 *    `.env`. Used for every subsequent /api/v1/agent/* call.
 *
 * PA0 ships this interface plus a config/array-backed stub
 * ({@see ConfigCredentialStore}). The encrypted-DB implementation AND the
 * enrollment -> runtime exchange itself land at PA1; this contract is the seam
 * they plug into. No exchange logic lives here.
 */
interface CredentialStore
{
    /**
     * The one-time enrollment token (ability `agent:register` only), or null.
     */
    public function enrollmentToken(): ?string;

    /**
     * The durable runtime PAT (app:* abilities), or null when enrollment has not
     * yet been performed (the default state at PA0).
     */
    public function runtimeToken(): ?string;

    /**
     * Whether a durable runtime PAT has been persisted.
     */
    public function hasRuntimeToken(): bool;

    /**
     * Whether the durable store is ready to PERSIST a runtime token (e.g. its
     * backing table exists). Checked BEFORE the single-use enrollment exchange
     * so a storage failure never consumes the one-time token with nowhere to
     * store the result (v1.0.3).
     */
    public function isReady(): bool;

    /**
     * Persist the durable runtime PAT returned by the Hub register exchange.
     *
     * The PA1 encrypted-DB implementation encrypts at rest (Laravel Crypt /
     * customer APP_KEY). The PA0 stub keeps it in memory only.
     *
     * @param  array<string, mixed>  $meta  token_id, abilities, expires_at, ...
     */
    public function putRuntimeToken(string $token, array $meta = []): void;

    /**
     * Forget the persisted runtime PAT (rotation / re-enrollment).
     */
    public function forgetRuntimeToken(): void;
}
