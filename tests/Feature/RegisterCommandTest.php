<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;

// fakeRegister() / registerRuntimeTokenBody() are defined in InstallCommandTest
// (Pest loads all test files; the global helpers are shared).

it('re-pairs and rotates the runtime token', function () {
    app(CredentialStore::class)->putRuntimeToken('1|old-runtime-token');

    fakeRegister();

    $this->artisan('platform-agent:register')
        ->expectsOutputToContain('will be ROTATED')
        ->assertExitCode(0);

    app()->forgetInstance(CredentialStore::class);
    expect(app(CredentialStore::class)->runtimeToken())
        ->toBe('2|aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789aBcDeFgHiJkL');
});

it('sends the enrollment bearer and never an application_id', function () {
    fakeRegister();

    $this->artisan('platform-agent:register')->assertExitCode(0);

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('Authorization', 'Bearer enrollment-token-fixture')
            && ! array_key_exists('application_id', $request->data())
            && str_starts_with((string) $request['fingerprint'], 'sha256:');
    });
});

it('fails when no enrollment token is configured for the re-pair', function () {
    config()->set('platform-agent.token', null);

    $this->artisan('platform-agent:register')
        ->expectsOutputToContain('PLATFORM_TOKEN is not set')
        ->assertExitCode(1);
});

it('hard-blocks on a 426 upgrade-required and stores nothing', function () {
    fakeRegister(426, [
        'success' => false,
        'message' => 'Agent version 1.4.0 is below the minimum compatible version 2.0.0. Upgrade required.',
        'data' => null,
        'errors' => null,
        'meta' => [],
    ]);

    $this->artisan('platform-agent:register')
        ->expectsOutputToContain('upgrade required')
        ->assertExitCode(1);

    expect(app(CredentialStore::class)->hasRuntimeToken())->toBeFalse();
});
