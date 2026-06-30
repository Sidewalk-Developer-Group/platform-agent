<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;

function enrolAgent(): void
{
    app(CredentialStore::class)->putRuntimeToken('runtime-pat-fixture', [
        'abilities' => ['app:backup', 'app:heartbeat', 'app:restore'],
    ]);
}

it('refuses to heartbeat before enrollment (no runtime token)', function () {
    $this->artisan('platform-agent:heartbeat')
        ->expectsOutputToContain('Not enrolled')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('sends a bytes-only heartbeat with the runtime bearer', function () {
    enrolAgent();

    Http::fake([
        '*/api/v1/agent/heartbeat' => Http::response($this->fixtureBody('heartbeat.success.json'), 200),
    ]);

    $this->artisan('platform-agent:heartbeat')
        ->expectsOutputToContain('Heartbeat delivered')
        ->assertExitCode(0);

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('Authorization', 'Bearer runtime-pat-fixture')
            && $request['status'] === 'healthy'
            && $request['agent_version'] === '1.4.0'
            // Rule 1: bytes only — never a usage percentage on the wire.
            && ! array_key_exists('usage_percentage', $request->data())
            && ! array_key_exists('storage_usage_percentage', $request->data());
    });
});

it('warns but still succeeds on a soft version_warning', function () {
    enrolAgent();

    $body = $this->fixtureBody('heartbeat.success.json');
    $body['data']['version_warning'] = 'Agent 1.4.0 is below the recommended minimum 1.6.0.';

    Http::fake([
        '*/api/v1/agent/heartbeat' => Http::response($body, 200),
    ]);

    $this->artisan('platform-agent:heartbeat')
        ->expectsOutputToContain('Version notice')
        ->assertExitCode(0);
});

it('fails on a 422 validation error', function () {
    enrolAgent();

    Http::fake([
        '*/api/v1/agent/heartbeat' => Http::response($this->fixtureBody('heartbeat.validation-error.json'), 422),
    ]);

    $this->artisan('platform-agent:heartbeat')
        ->expectsOutputToContain('Heartbeat rejected')
        ->assertExitCode(1);
});

it('hard-blocks on a 426 upgrade-required', function () {
    enrolAgent();

    Http::fake([
        '*/api/v1/agent/heartbeat' => Http::response([
            'success' => false,
            'message' => 'Agent version 1.4.0 is below the minimum compatible version 2.0.0. Upgrade required.',
            'data' => null,
            'errors' => null,
            'meta' => [],
        ], 426),
    ]);

    $this->artisan('platform-agent:heartbeat')
        ->expectsOutputToContain('upgrade required')
        ->assertExitCode(1);
});
