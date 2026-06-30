<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;
use SidewalkDevelopers\PlatformAgent\Credentials\DatabaseCredentialStore;

it('binds the encrypted DB-backed credential store over the interface', function () {
    expect(app(CredentialStore::class))->toBeInstanceOf(DatabaseCredentialStore::class);
});

it('resolves the enrollment token from config', function () {
    expect(app(CredentialStore::class)->enrollmentToken())->toBe('enrollment-token-fixture');
});

it('persists the runtime token encrypted at rest and round-trips via decrypt', function () {
    $store = app(CredentialStore::class);

    $store->putRuntimeToken('2|super-secret-runtime-pat', [
        'token_id' => '4d5e6f7a-8b9c-4d0e-1f2a-3b4c5d6e7f8a',
        'abilities' => ['app:backup', 'app:heartbeat', 'app:restore'],
        'expires_at' => null,
    ]);

    // The stored column is ciphertext — never the plaintext token.
    $raw = DB::table('platform_agent_credentials')->where('key', 'runtime_token')->value('value');
    expect($raw)->toBeString()
        ->and($raw)->not->toContain('super-secret-runtime-pat');

    // A fresh instance forces a DB read + decrypt (not the in-memory cache).
    app()->forgetInstance(CredentialStore::class);
    $fresh = app(CredentialStore::class);

    expect($fresh->runtimeToken())->toBe('2|super-secret-runtime-pat')
        ->and($fresh->hasRuntimeToken())->toBeTrue();
});

it('never persists a plaintext token inside the unencrypted meta column', function () {
    $store = app(CredentialStore::class);

    $store->putRuntimeToken('2|the-real-secret', [
        'token_id' => 'tid',
        'token' => '2|the-real-secret', // defensively stripped
    ]);

    $rawMeta = DB::table('platform_agent_credentials')->where('key', 'runtime_token')->value('meta');
    expect($rawMeta)->toBeString()
        ->and($rawMeta)->not->toContain('the-real-secret');
});

it('forgets the runtime token', function () {
    $store = app(CredentialStore::class);
    $store->putRuntimeToken('2|to-be-forgotten');

    $store->forgetRuntimeToken();

    app()->forgetInstance(CredentialStore::class);
    expect(app(CredentialStore::class)->runtimeToken())->toBeNull()
        ->and(app(CredentialStore::class)->hasRuntimeToken())->toBeFalse();
});
