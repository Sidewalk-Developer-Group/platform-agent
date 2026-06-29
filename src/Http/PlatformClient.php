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
     * Issue a request and return the parsed envelope, applying the 426 and
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
        $http = $this->config['http'] ?? [];

        return $this->http
            ->baseUrl($this->baseUrl())
            ->withToken($bearer)
            ->acceptJson()
            ->asJson()
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
