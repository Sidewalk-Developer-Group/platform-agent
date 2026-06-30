<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Credentials;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Psr\Log\LoggerInterface;

/**
 * Durable {@see CredentialStore} (PA1).
 *
 * - Enrollment token: still resolved from config (`platform-agent.token` ->
 *   PLATFORM_TOKEN). It is the one-time, register-only secret used by
 *   `platform-agent:install`.
 * - Runtime PAT: persisted ENCRYPTED at rest (Laravel Crypt / customer APP_KEY)
 *   in the customer DB, in the package table. NEVER written back to `.env`
 *   (ADR-0007 Addendum D). The plaintext exists only transiently in memory.
 *
 * Binding invariants honored: the durable secret never touches `.env`; the
 * stored column is ciphertext (verified by tests); the ability split is enforced
 * Hub-side at the route boundary (this store only persists what the Hub issued).
 */
final class DatabaseCredentialStore implements CredentialStore
{
    private const RUNTIME_KEY = 'runtime_token';

    private bool $loaded = false;

    private ?string $runtimeToken = null;

    /** @var array<string, mixed> */
    private array $runtimeMeta = [];

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly ConnectionResolverInterface $db,
        private readonly Encrypter $crypt,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function enrollmentToken(): ?string
    {
        $token = $this->config->get('platform-agent.token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function runtimeToken(): ?string
    {
        $this->load();

        return $this->runtimeToken;
    }

    public function hasRuntimeToken(): bool
    {
        return $this->runtimeToken() !== null;
    }

    public function putRuntimeToken(string $token, array $meta = []): void
    {
        $now = now();

        $this->table()->updateOrInsert(
            ['key' => self::RUNTIME_KEY],
            [
                'value' => $this->crypt->encrypt($token),
                'meta' => json_encode($this->sanitizeMeta($meta), JSON_THROW_ON_ERROR),
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        // Refresh the in-memory cache without re-reading the DB.
        $this->runtimeToken = $token;
        $this->runtimeMeta = $this->sanitizeMeta($meta);
        $this->loaded = true;
    }

    public function forgetRuntimeToken(): void
    {
        $this->table()->where('key', self::RUNTIME_KEY)->delete();

        $this->runtimeToken = null;
        $this->runtimeMeta = [];
        $this->loaded = true;
    }

    /**
     * Non-secret metadata about the persisted runtime token (token_id,
     * abilities, expires_at, application_uuid) — safe to display in diagnose.
     *
     * @return array<string, mixed>
     */
    public function runtimeMeta(): array
    {
        $this->load();

        return $this->runtimeMeta;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        try {
            $row = $this->table()->where('key', self::RUNTIME_KEY)->first();
        } catch (\Throwable $e) {
            // Table may not exist yet (pre-migration). Treat as no runtime token.
            $this->logger?->warning('platform-agent.credential_store.unavailable', [
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        if ($row === null) {
            return;
        }

        try {
            $this->runtimeToken = $this->crypt->decrypt($row->value);
        } catch (DecryptException $e) {
            // APP_KEY rotated or tampered ciphertext — the token is unusable.
            $this->logger?->error('platform-agent.credential_store.decrypt_failed', [
                'reason' => $e->getMessage(),
            ]);
            $this->runtimeToken = null;

            return;
        }

        $meta = is_string($row->meta ?? null) ? json_decode($row->meta, true) : ($row->meta ?? []);
        $this->runtimeMeta = is_array($meta) ? $meta : [];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function sanitizeMeta(array $meta): array
    {
        // Defensive: never persist a plaintext token inside the (unencrypted)
        // meta JSON column. Only non-secret descriptors belong here.
        unset($meta['token']);

        return $meta;
    }

    private function table(): Builder
    {
        $connection = $this->config->get('platform-agent.store.connection');
        $table = (string) $this->config->get('platform-agent.store.table', 'platform_agent_credentials');

        return $this->db->connection($connection)->table($table);
    }
}
