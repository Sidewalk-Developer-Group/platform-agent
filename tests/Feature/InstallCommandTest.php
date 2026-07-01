<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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

it('auto-prepares credential storage before the exchange when the table is missing', function () {
    // Simulate a customer who installed the package but never ran `migrate`:
    // neither the table nor its migration-ledger row exists.
    Schema::dropIfExists('platform_agent_credentials');
    if (Schema::hasTable('migrations')) {
        DB::table('migrations')->where('migration', 'like', '%create_platform_agent_credentials_table')->delete();
    }
    expect(Schema::hasTable('platform_agent_credentials'))->toBeFalse();

    fakeRegister();

    $this->artisan('platform-agent:install')
        ->expectsOutputToContain('Preparing credential storage')
        ->assertExitCode(0);

    // The package migration ran and the runtime token persisted.
    expect(Schema::hasTable('platform_agent_credentials'))->toBeTrue();
    expect(app(CredentialStore::class)->hasRuntimeToken())->toBeTrue();
});

it('does not consume the enrollment token when storage cannot be prepared', function () {
    // Store that can never persist — mirrors an unrecoverable DB/table failure.
    // The exchange must NOT run, so the single-use enrollment token survives.
    app()->instance(CredentialStore::class, new class implements CredentialStore
    {
        public function enrollmentToken(): ?string
        {
            return 'enrollment-token-fixture';
        }

        public function runtimeToken(): ?string
        {
            return null;
        }

        public function hasRuntimeToken(): bool
        {
            return false;
        }

        public function isReady(): bool
        {
            return false;
        }

        public function putRuntimeToken(string $token, array $meta = []): void {}

        public function forgetRuntimeToken(): void {}
    });

    fakeRegister();

    $this->artisan('platform-agent:install')
        ->expectsOutputToContain('Credential storage is not ready')
        ->assertExitCode(1);

    // The enrollment exchange never fired → the one-time token is not burned.
    Http::assertNothingSent();
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
