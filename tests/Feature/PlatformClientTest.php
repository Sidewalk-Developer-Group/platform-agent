<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;
use SidewalkDevelopers\PlatformAgent\Exceptions\AgentUpgradeRequiredException;
use SidewalkDevelopers\PlatformAgent\Http\AgentResponse;
use SidewalkDevelopers\PlatformAgent\Http\PlatformClient;

function client(): PlatformClient
{
    return app(PlatformClient::class);
}

it('builds the version-prefixed base url and never hardcodes an unversioned path', function () {
    expect(client()->baseUrl())->toBe('https://hub.platform.test/api/v1/');
});

it('parses a success envelope into a typed AgentResponse', function () {
    Http::fake([
        '*/api/v1/agent/register' => Http::response($this->fixtureBody('register.success.json'), 201),
    ]);

    $result = client()->register(['agent_version' => '1.4.0']);

    expect($result)->toBeInstanceOf(AgentResponse::class)
        ->and($result->status)->toBe(201)
        ->and($result->success)->toBeTrue()
        ->and($result->failed())->toBeFalse()
        ->and($result->message)->toBe('Agent Registered')
        ->and($result->errors)->toBeNull()
        ->and($result->hasVersionWarning())->toBeFalse()
        ->and($result->get('registration.application_id'))
        ->toBe('9d1f2c34-5b6a-4c7d-8e9f-0a1b2c3d4e5f');
});

it('sends the enrollment token as the bearer for register and never in the body', function () {
    Http::fake([
        '*/api/v1/agent/register' => Http::response($this->fixtureBody('register.success.json'), 201),
    ]);

    client()->register(['agent_version' => '1.4.0']);

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('Authorization', 'Bearer enrollment-token-fixture')
            && ! array_key_exists('application_id', $request->data())
            && ! str_contains(json_encode($request->data()), 'enrollment-token-fixture');
    });
});

it('parses a validation-error envelope (422) as a failed result with field errors', function () {
    Http::fake([
        '*/api/v1/agent/register' => Http::response($this->fixtureBody('register.validation-error.json'), 422),
    ]);

    $result = client()->register([]);

    expect($result->status)->toBe(422)
        ->and($result->success)->toBeFalse()
        ->and($result->failed())->toBeTrue()
        ->and($result->dataWasNull)->toBeTrue()
        ->and($result->message)->toBe('Validation failed.')
        ->and($result->errors)->toHaveKey('agent_version')
        ->and($result->errors['agent_version'][0])->toBe('The agent version field is required.');
});

it('parses a server-error envelope (500) as a failed result without throwing', function () {
    Http::fake([
        '*/api/v1/agent/register' => Http::response([
            'success' => false,
            'message' => 'Server Error',
            'data' => null,
            'errors' => null,
            'meta' => [],
        ], 500),
    ]);

    $result = client()->register(['agent_version' => '1.4.0']);

    expect($result->status)->toBe(500)
        ->and($result->failed())->toBeTrue()
        ->and($result->message)->toBe('Server Error');
});

it('surfaces a soft version_warning, logs it, and continues without throwing', function () {
    Log::spy();

    $body = $this->fixtureBody('register.success.json');
    $body['data']['version_warning'] = 'Agent 1.4.0 is below the recommended minimum 1.6.0.';
    $body['data']['registration']['version_warning'] = $body['data']['version_warning'];

    Http::fake([
        '*/api/v1/agent/register' => Http::response($body, 201),
    ]);

    $result = client()->register(['agent_version' => '1.4.0']);

    expect($result->success)->toBeTrue()
        ->and($result->failed())->toBeFalse()
        ->and($result->hasVersionWarning())->toBeTrue()
        ->and($result->versionWarning)->toBe('Agent 1.4.0 is below the recommended minimum 1.6.0.');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($event, $context = []) => $event === 'platform-agent.version_warning')
        ->once();
});

it('throws AgentUpgradeRequiredException on HTTP 426 and never retries', function () {
    Http::fake([
        '*/api/v1/agent/register' => Http::response([
            'success' => false,
            'message' => 'Agent version 1.0.0 is below the minimum compatible version 1.2.0. Upgrade required.',
            'data' => null,
            'errors' => null,
            'meta' => [],
        ], 426),
    ]);

    expect(fn () => client()->register(['agent_version' => '1.0.0']))
        ->toThrow(
            AgentUpgradeRequiredException::class,
            'Agent version 1.0.0 is below the minimum compatible version 1.2.0. Upgrade required.',
        );

    // 426 must NOT be retry-looped (single attempt despite retries configured).
    Http::assertSentCount(1);
});

it('uses the runtime token for operational calls once one is persisted', function () {
    app(CredentialStore::class)->putRuntimeToken('runtime-pat-fixture', [
        'abilities' => ['app:backup', 'app:heartbeat', 'app:restore'],
    ]);

    Http::fake([
        '*/api/v1/agent/heartbeat' => Http::response($this->fixtureBody('heartbeat.success.json'), 200),
    ]);

    $result = client()->heartbeat(['agent_version' => '1.4.0', 'storage_usage_bytes' => 5368709120]);

    expect($result->success)->toBeTrue()
        ->and($result->message)->toBe('Agent Heartbeat Received');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer runtime-pat-fixture'));
});

it('falls back to the enrollment token for operational calls before the runtime exchange', function () {
    Http::fake([
        '*/api/v1/agent/heartbeat' => Http::response($this->fixtureBody('heartbeat.success.json'), 200),
    ]);

    client()->heartbeat(['agent_version' => '1.4.0']);

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer enrollment-token-fixture'));
});
