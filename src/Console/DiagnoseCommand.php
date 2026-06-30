<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Console;

use Illuminate\Http\Client\Factory as HttpFactory;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;
use SidewalkDevelopers\PlatformAgent\Credentials\DatabaseCredentialStore;
use SidewalkDevelopers\PlatformAgent\Http\PlatformClient;

/**
 * `platform-agent:diagnose` — onboarding/health aid (PA1).
 *
 * Prints resolved config (tokens REDACTED), whether a durable runtime token is
 * present, and a connectivity probe to the Cloud Hub. No secrets in output
 * (ADR-0007 §2.4). Richer checks (last heartbeat/backup, live version verdict)
 * extend here as PA2+ endpoints land.
 */
final class DiagnoseCommand extends AbstractAgentCommand
{
    protected $signature = 'platform-agent:diagnose';

    protected $description = 'Print resolved agent config (token redacted), connectivity and version status.';

    protected string $implementedInPhase = 'PA1';

    public function handle(PlatformClient $client, CredentialStore $credentials, HttpFactory $http): int
    {
        $this->components->info('Platform Agent diagnostics');

        $this->components->twoColumnDetail('Hub URL', (string) (config('platform-agent.url') ?: '<not set>'));
        $this->components->twoColumnDetail('API base', $client->baseUrl());
        $this->components->twoColumnDetail('Application UUID', (string) (config('platform-agent.application_uuid') ?: '<not set>'));
        $this->components->twoColumnDetail('Agent version', $client->agentVersion());
        $this->components->twoColumnDetail('Min Hub contract', (string) config('platform-agent.compatibility.min_hub_contract_version'));

        $enrollment = $credentials->enrollmentToken();
        $this->components->twoColumnDetail(
            'Enrollment token (PLATFORM_TOKEN)',
            $enrollment ? $this->redact($enrollment) : '<not set>',
        );

        $hasRuntime = $credentials->hasRuntimeToken();
        $this->components->twoColumnDetail(
            'Runtime token',
            $hasRuntime ? '<present, encrypted at rest>' : '<absent — run platform-agent:install>',
        );

        if ($hasRuntime) {
            $this->printRuntimeMeta($credentials);
        }

        $this->probeConnectivity($http);

        if (! $hasRuntime) {
            $this->newLine();
            $this->components->warn('No runtime token yet. Run `php artisan platform-agent:install` to enroll.');
        }

        return self::SUCCESS;
    }

    private function printRuntimeMeta(CredentialStore $credentials): void
    {
        if (! $credentials instanceof DatabaseCredentialStore) {
            return;
        }

        $meta = $credentials->runtimeMeta();

        if (isset($meta['token_id'])) {
            $this->components->twoColumnDetail('  token_id', (string) $meta['token_id']);
        }

        if (isset($meta['abilities']) && is_array($meta['abilities'])) {
            $this->components->twoColumnDetail('  abilities', implode(', ', $meta['abilities']));
        }

        $this->components->twoColumnDetail('  expires_at', (string) ($meta['expires_at'] ?? 'never'));
    }

    private function probeConnectivity(HttpFactory $http): void
    {
        $url = (string) config('platform-agent.url');

        if ($url === '') {
            $this->components->twoColumnDetail('Connectivity', '<skipped — no URL>');

            return;
        }

        try {
            $response = $http->timeout((int) config('platform-agent.http.connect_timeout', 10))
                ->get(rtrim($url, '/'));

            $this->components->twoColumnDetail('Connectivity', 'reachable (HTTP '.$response->status().')');
        } catch (\Throwable $e) {
            $this->components->twoColumnDetail('Connectivity', 'UNREACHABLE');
            $this->components->warn('Hub probe failed: '.$e->getMessage());
        }
    }

    /**
     * Redact a Sanctum-style "{id}|{secret}" token: keep the non-secret id
     * segment, mask the secret. Never print the secret half.
     */
    private function redact(string $token): string
    {
        if (str_contains($token, '|')) {
            [$id] = explode('|', $token, 2);

            return $id.'|'.str_repeat('*', 8);
        }

        return str_repeat('*', 8);
    }
}
