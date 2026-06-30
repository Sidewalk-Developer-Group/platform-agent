<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Http;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Psr\Log\LoggerInterface;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;
use SidewalkDevelopers\PlatformAgent\Exceptions\AgentUpgradeRequiredException;
use SidewalkDevelopers\PlatformAgent\Exceptions\MissingCredentialException;

/**
 * Thin, typed HTTP client over the Hub agent surface (/api/v1/agent/*).
 *
 * Responsibilities (the REAL, tested core of PA0):
 *  - Bearer auth: the durable RUNTIME PAT for operational calls; the ENROLLMENT
 *    token for `register` (ADR-0007 Addendum D). Tokens come from the
 *    {@see CredentialStore} and travel ONLY as the Authorization header — never
 *    in a payload, never logged (ADR-0007 §2.2).
 *  - Configurable timeout + retries (a 426 is never retried).
 *  - Canonical parse of the Hub `ApiResponse` envelope into {@see AgentResponse}.
 *  - Soft-lag `version_warning` -> log + continue (never throw).
 *  - HTTP 426 -> {@see AgentUpgradeRequiredException} (compatible_floor block).
 *
 * Endpoint methods that target not-yet-built Hub items (backup-runs ingest,
 * tus uploads, restore discovery) are intentionally absent at PA0; they land in
 * PA2-PA4. The envelope/version/426 handling here is final and shared by all.
 */
class PlatformClient
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly CredentialStore $credentials,
        array $config,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->config = $config;
    }

    /**
     * POST /api/v1/agent/register — enrollment-exchange entry point.
     *
     * Authenticated with the ENROLLMENT token (ability agent:register). The PA1
     * install flow persists the runtime PAT returned in `data.runtime_token`.
     *
     * @param  array<string, mixed>  $payload  e.g. agent_version, hostname, fingerprint, metadata
     */
    public function register(array $payload): AgentResponse
    {
        return $this->send('post', 'agent/register', $payload, $this->enrollmentBearer());
    }

    /**
     * POST /api/v1/agent/heartbeat (ability app:heartbeat). Bytes only (Rule 1).
     *
     * @param  array<string, mixed>  $payload
     */
    public function heartbeat(array $payload): AgentResponse
    {
        return $this->send('post', 'agent/heartbeat', $payload, $this->runtimeBearer());
    }

    /**
     * POST /api/v1/agent/report (ability app:heartbeat) — richer telemetry.
     *
     * @param  array<string, mixed>  $payload
     */
    public function report(array $payload): AgentResponse
    {
        return $this->send('post', 'agent/report', $payload, $this->runtimeBearer());
    }

    /**
     * POST /api/v1/agent/backup-runs (ability app:backup) — the run-log ingest
     * (ADR-0008). Reports the `running` START + terminal `success`/`failed`; the
     * Hub upserts on `(application_id, agent_run_uuid)`. JSON envelope (PA3).
     *
     * @param  array<string, mixed>  $payload
     */
    public function backupRun(array $payload): AgentResponse
    {
        return $this->send('post', 'agent/backup-runs', $payload, $this->runtimeBearer());
    }

    /**
     * POST /api/v1/agent/archives (ability app:backup) — the SINGLE-POST archive
     * upload + catalog path for small/below-threshold archives (Rule 3 + Rule 4).
     * Sends `backup.zip` + the `.sha256` sidecar as multipart; the Hub recomputes
     * the checksum from disk (authoritative) and returns the catalog row (PA3).
     *
     * Large/growing archives use the tus surface instead — see {@see TusUploadClient}.
     *
     * @param  array<string, scalar|null>  $fields  filename, kind, checksum, uploaded_at, ...
     * @param  array<string, string>  $files   form field => absolute local path (file, sidecar)
     */
    public function uploadArchive(array $fields, array $files): AgentResponse
    {
        $request = $this->configuredRequest($this->runtimeBearer())->acceptJson();

        foreach ($files as $field => $path) {
            $request = $request->attach($field, (string) file_get_contents($path), basename($path));
        }

        return $this->handle($request->post('agent/archives', $fields), 'agent/archives');
    }

    /**
     * GET /api/v1/agent/restore-jobs (ability app:restore) — discover the
     * Application's downloadable restore jobs (poll fallback, Rule 6; PA4).
     */
    public function restoreJobs(): AgentResponse
    {
        return $this->send('get', 'agent/restore-jobs', [], $this->runtimeBearer());
    }

    /**
     * GET /api/v1/agent/restore-jobs/{id}/download (ability app:restore) — the
     * NON-MUTATING manifest: archive filename + sha256 (Rule 4) + size + a signed
     * byte-egress `download_url` (ADR-0011; PA4).
     */
    public function restoreManifest(string $restoreJobId): AgentResponse
    {
        return $this->send('get', "agent/restore-jobs/{$restoreJobId}/download", [], $this->runtimeBearer());
    }

    /**
     * POST /api/v1/agent/restore-jobs/{id}/report (ability app:restore) — the
     * AUTHORITATIVE restore outcome after the agent verifies the SHA256 and
     * deposits. `success=false` carries the abort `reason` (e.g. a checksum
     * mismatch, Rule 4) so no failure is silent (PA4).
     *
     * @param  array<string, mixed>  $payload  success, reason?, log?
     */
    public function reportRestore(string $restoreJobId, array $payload): AgentResponse
    {
        return $this->send('post', "agent/restore-jobs/{$restoreJobId}/report", $payload, $this->runtimeBearer());
    }

    /**
     * Pull the archive BYTES from the signed egress `download_url` straight to a
     * local sink path (memory-safe for GB-scale archives — ADR-0011). The URL is
     * absolute + signed; the runtime PAT still travels as the bearer (defence in
     * depth: the Hub requires BOTH the signature and the `app:restore` token).
     * Returns the HTTP status; a 426 hard-block is thrown like every other
     * surface. Bytes are NOT parsed as the JSON envelope.
     */
    public function downloadArchive(string $url, string $sinkPath): int
    {
        $timeout = (int) (($this->config['restore']['download_timeout'] ?? null) ?: 600);

        $response = $this->configuredRequest($this->runtimeBearer())
            ->timeout($timeout)
            ->sink($sinkPath)
            ->get($url);

        if ($response->status() === 426) {
            $message = $this->extractMessage($response, 'Platform Agent upgrade required.');

            $this->logger?->error('platform-agent.upgrade_required', [
                'endpoint' => 'agent/restore-jobs/archive',
                'status' => 426,
                'message' => $message,
            ]);

            throw new AgentUpgradeRequiredException($message, 'agent/restore-jobs/archive');
        }

        if ($response->failed()) {
            $this->logger?->warning('platform-agent.restore_download_failed', [
                'status' => $response->status(),
            ]);
        }

        return $response->status();
    }

    /**
     * POST /api/v1/agent/broadcasting/auth (ability app:restore) — authorize the
     * agent's subscription to its per-Application private restore channel for the
     * restore-push subscriber (PA5 / ADR-0007 Addendum B.5). The Hub authorizes
     * by the runtime PAT's bound Application — the channel id is never trusted
     * from the client. Returns the decoded body so BOTH the canonical envelope
     * (`data.auth`) and a raw Pusher auth shape (`{"auth": "..."}`) are accepted.
     *
     * @return array<string, mixed>
     */
    public function broadcastingAuth(string $channelName, string $socketId): array
    {
        $response = $this->request($this->runtimeBearer())
            ->post('agent/broadcasting/auth', [
                'channel_name' => $channelName,
                'socket_id' => $socketId,
            ]);

        if ($response->status() === 426) {
            $message = $this->extractMessage($response, 'Platform Agent upgrade required.');

            $this->logger?->error('platform-agent.upgrade_required', [
                'endpoint' => 'agent/broadcasting/auth',
                'status' => 426,
                'message' => $message,
            ]);

            throw new AgentUpgradeRequiredException($message, 'agent/broadcasting/auth');
        }

        if ($response->failed()) {
            $this->logger?->warning('platform-agent.channel_auth_failed', [
                'channel' => $channelName,
                'status' => $response->status(),
            ]);

            return [];
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * Issue a JSON request and return the parsed envelope, applying the 426 and
     * version_warning rules. Public so PA2+ command/service code can reach the
     * shipped endpoints not yet wrapped by a dedicated method above.
     *
     * @param  array<string, mixed>  $payload
     */
    public function send(string $method, string $uri, array $payload, ?string $token = null): AgentResponse
    {
        $bearer = $token ?? $this->runtimeBearer();

        $response = $this->request($bearer)->{$method}($uri, $payload);

        return $this->handle($response, $uri);
    }

    private function request(string $bearer): PendingRequest
    {
        return $this->configuredRequest($bearer)
            ->acceptJson()
            ->asJson();
    }

    /**
     * Shared base request: baseUrl, bearer, timeouts, retries and User-Agent —
     * WITHOUT a content-type so callers pick JSON ({@see request()}) or multipart
     * ({@see uploadArchive()}).
     */
    private function configuredRequest(string $bearer): PendingRequest
    {
        $http = $this->config['http'] ?? [];

        return $this->http
            ->baseUrl($this->baseUrl())
            ->withToken($bearer)
            ->timeout((int) ($http['timeout'] ?? 30))
            ->connectTimeout((int) ($http['connect_timeout'] ?? 10))
            ->retry(
                (int) ($http['retries'] ?? 2),
                (int) ($http['retry_delay_ms'] ?? 250),
                throw: false,
            )
            ->withHeaders([
                'User-Agent' => 'platform-agent/'.$this->agentVersion(),
            ]);
    }

    private function handle(Response $response, string $uri): AgentResponse
    {
        // HARD-BLOCK: HTTP 426 Upgrade Required (below compatible_floor).
        // Never retried, always thrown (ADR-0007 §2.5).
        if ($response->status() === 426) {
            $message = $this->extractMessage($response, 'Platform Agent upgrade required.');

            $this->logger?->error('platform-agent.upgrade_required', [
                'endpoint' => $uri,
                'status' => 426,
                'message' => $message,
            ]);

            throw new AgentUpgradeRequiredException($message, $uri);
        }

        $body = $response->json();
        $result = AgentResponse::fromEnvelope(
            $response->status(),
            is_array($body) ? $body : [],
        );

        // Soft-lag warning -> log + continue. NEVER a failure, NEVER thrown.
        if ($result->hasVersionWarning()) {
            $this->logger?->warning('platform-agent.version_warning', [
                'endpoint' => $uri,
                'status' => $result->status,
                'warning' => $result->versionWarning,
            ]);
        }

        // Surface non-2xx envelopes as failed results (caller decides) — no
        // silent failure: log them. Secrets never appear in the envelope.
        if ($result->failed()) {
            $this->logger?->warning('platform-agent.request_failed', [
                'endpoint' => $uri,
                'status' => $result->status,
                'message' => $result->message,
                'errors' => $result->errors,
            ]);
        }

        return $result;
    }

    private function extractMessage(Response $response, string $default): string
    {
        $body = $response->json();

        if (is_array($body) && isset($body['message']) && is_string($body['message']) && $body['message'] !== '') {
            return $body['message'];
        }

        return $default;
    }

    private function enrollmentBearer(): string
    {
        return $this->credentials->enrollmentToken()
            ?? throw new MissingCredentialException(
                'No enrollment token configured (PLATFORM_TOKEN). Set it before running platform-agent:install.'
            );
    }

    private function runtimeBearer(): string
    {
        // Prefer the durable runtime PAT; before the PA1 exchange persists one,
        // fall back to the enrollment token so PA0 surfaces remain exercisable.
        return $this->credentials->runtimeToken()
            ?? $this->credentials->enrollmentToken()
            ?? throw new MissingCredentialException(
                'No runtime token available. Run platform-agent:install to enroll and obtain a runtime PAT.'
            );
    }

    /**
     * The durable runtime PAT used as the Bearer for operational calls. Exposed
     * for the tus upload client, which speaks the raw tus protocol (not the JSON
     * envelope) but shares the same per-application Authorization. Never logged.
     */
    public function authToken(): string
    {
        return $this->runtimeBearer();
    }

    public function baseUrl(): string
    {
        $url = rtrim((string) ($this->config['url'] ?? ''), '/');
        $prefix = trim((string) ($this->config['api_prefix'] ?? 'api/v1'), '/');

        return $prefix === '' ? $url.'/' : $url.'/'.$prefix.'/';
    }

    public function agentVersion(): string
    {
        return (string) ($this->config['agent_version'] ?? '0.0.0');
    }
}
