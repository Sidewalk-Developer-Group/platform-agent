<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;

function registerRuntimeTokenBody(): array
{
    $path = __DIR__.'/../Fixtures/hub-contract/register.success.with-runtime-token.planned.json';

    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['body'];
}

function fakeRegister(int $status = 201, ?array $body = null): void
{
    Http::fake([
        '*/api/v1/agent/register' => Http::response($body ?? registerRuntimeTokenBody(), $status),
    ]);
}

it('runs the enrollment exchange and persists the runtime token', function () {
    fakeRegister();

    $this->artisan('platform-agent:install')->assertExitCode(0);

    $store = app(CredentialStore::class);
    expect($store->hasRuntimeToken())->toBeTrue()
        ->and($store->runtimeToken())->toBe('2|aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789aBcDeFgHiJkL');

    // Enrollment token (not the runtime token) authenticates register; no
    // application_id is ever sent (token-derived identity).
    Http::assertSent(function (Request $request) {
        return $request->hasHeader('Authorization', 'Bearer enrollment-token-fixture')
            && ! array_key_exists('application_id', $request->data())
            && $request['agent_version'] === '1.4.0'
            && str_starts_with((string) $request['fingerprint'], 'sha256:');
    });
});

it('fails loudly when PLATFORM_URL is missing', function () {
    config()->set('platform-agent.url', null);

    $this->artisan('platform-agent:install')
        ->expectsOutputToContain('PLATFORM_URL is not set')
        ->assertExitCode(1);

    expect(app(CredentialStore::class)->hasRuntimeToken())->toBeFalse();
});

it('fails loudly when PLATFORM_TOKEN (enrollment) is missing', function () {
    config()->set('platform-agent.token', null);

    $this->artisan('platform-agent:install')
        ->expectsOutputToContain('PLATFORM_TOKEN is not set')
        ->assertExitCode(1);
});

it('fails loudly when PLATFORM_APPLICATION_UUID is missing', function () {
    config()->set('platform-agent.application_uuid', null);

    $this->artisan('platform-agent:install')
        ->expectsOutputToContain('PLATFORM_APPLICATION_UUID is not set')
        ->assertExitCode(1);
});

it('surfaces an auth failure (401) and stores nothing', function () {
    fakeRegister(401, [
        'success' => false,
        'message' => 'Unauthenticated agent.',
        'data' => null,
        'errors' => null,
        'meta' => [],
    ]);

    $this->artisan('platform-agent:install')
        ->expectsOutputToContain('Enrollment failed')
        ->assertExitCode(1);

    expect(app(CredentialStore::class)->hasRuntimeToken())->toBeFalse();
});

it('surfaces a 426 upgrade-required hard block and stores nothing', function () {
    fakeRegister(426, [
        'success' => false,
        'message' => 'Agent version 1.4.0 is below the minimum compatible version 2.0.0. Upgrade required.',
        'data' => null,
        'errors' => null,
        'meta' => [],
    ]);

    $this->artisan('platform-agent:install')
        ->expectsOutputToContain('upgrade required')
        ->assertExitCode(1);

    expect(app(CredentialStore::class)->hasRuntimeToken())->toBeFalse();
});

it('rotates the runtime token on re-install', function () {
    // Simulate a prior install.
    app(CredentialStore::class)->putRuntimeToken('1|old-runtime-token');

    fakeRegister();

    $this->artisan('platform-agent:install')
        ->expectsOutputToContain('runtime token already exists')
        ->assertExitCode(0);

    app()->forgetInstance(CredentialStore::class);
    expect(app(CredentialStore::class)->runtimeToken())
        ->toBe('2|aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789aBcDeFgHiJkL');
});
