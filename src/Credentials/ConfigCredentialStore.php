<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Credentials;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * PA0 default {@see CredentialStore} seam.
 *
 * - The enrollment token resolves from config (`platform-agent.token` ->
 *   PLATFORM_TOKEN).
 * - The runtime PAT is held IN MEMORY only and is NOT persisted anywhere yet.
 *   The durable, encrypted-at-rest persistence (Laravel Crypt in the customer
 *   DB) and the enrollment -> runtime exchange that populates it are PA1 work
 *   ({@see CredentialStore} docblock). This stub exists so PlatformClient,
 *   commands and tests have a working binding now.
 *
 * Binding rule honored: the durable runtime secret is NEVER written back to
 * `.env`. This stub deliberately does not persist it at all (PA1 supplies the
 * encrypted-DB store); it only mirrors the contract shape.
 */
final class ConfigCredentialStore implements CredentialStore
{
    private ?string $runtimeToken = null;

    /** @var array<string, mixed> */
    private array $runtimeMeta = [];

    public function __construct(private readonly ConfigRepository $config)
    {
    }

    public function enrollmentToken(): ?string
    {
        $token = $this->config->get('platform-agent.token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function runtimeToken(): ?string
    {
        return $this->runtimeToken;
    }

    public function hasRuntimeToken(): bool
    {
        return $this->runtimeToken !== null;
    }

    public function putRuntimeToken(string $token, array $meta = []): void
    {
        // PA0: in-memory only. PA1 replaces this implementation with an
        // encrypted-DB store. Never written to .env (ADR-0007 Addendum D).
        $this->runtimeToken = $token;
        $this->runtimeMeta = $meta;
    }

    public function forgetRuntimeToken(): void
    {
        $this->runtimeToken = null;
        $this->runtimeMeta = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function runtimeMeta(): array
    {
        return $this->runtimeMeta;
    }
}
