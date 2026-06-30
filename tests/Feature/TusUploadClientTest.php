<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;
use SidewalkDevelopers\PlatformAgent\Http\TusUploadClient;

function tusEnrol(): void
{
    app(CredentialStore::class)->putRuntimeToken('runtime-pat-fixture', [
        'abilities' => ['app:backup'],
    ]);
}

function tusTempFile(int $bytes): string
{
    $path = tempnam(sys_get_temp_dir(), 'pa-tus-').'.zip';
    file_put_contents($path, str_repeat('Z', $bytes));

    return $path;
}

it('creates the upload with base64 metadata then patches the chunk and returns the verdict', function () {
    tusEnrol();
    $path = tusTempFile(64);
    $checksum = hash_file('sha256', $path);

    Http::fake([
        '*/api/v1/agent/uploads' => Http::response('', 201, ['Location' => 'https://hub.platform.test/api/v1/agent/uploads/upl_9']),
        'https://hub.platform.test/api/v1/agent/uploads/upl_9' => Http::response('', 204, [
            'Upload-Offset' => '64',
            'X-Backup-Archive-Id' => 'arch-9',
            'X-Backup-Checksum-Status' => 'verified',
        ]),
    ]);

    $result = app(TusUploadClient::class)->upload(
        $path, 'abc-erp-files-2026.zip', 'files', $checksum, '6f7a8b9c-0d1e-4f2a-3b4c-5d6e7f8a9b0c',
    );

    expect($result->archiveId)->toBe('arch-9');
    expect($result->isVerified())->toBeTrue();
    expect($result->via)->toBe('tus');

    // creation carries the tus headers + base64 Upload-Metadata (no application_id)
    Http::assertSent(function (Request $r) {
        if (! str_ends_with($r->url(), '/agent/uploads') || $r->method() !== 'POST') {
            return false;
        }
        $meta = $r->header('Upload-Metadata')[0] ?? '';

        return $r->hasHeader('Tus-Resumable', '1.0.0')
            && $r->hasHeader('Upload-Length', '64')
            && str_contains($meta, 'filename '.base64_encode('abc-erp-files-2026.zip'))
            && str_contains($meta, 'kind '.base64_encode('files'))
            && ! str_contains($meta, 'application_id');
    });

    // chunk PATCH carries per-chunk checksum + the runtime bearer
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
        && $r->hasHeader('Content-Type', 'application/offset+octet-stream')
        && $r->hasHeader('Upload-Offset', '0')
        && str_starts_with($r->header('Upload-Checksum')[0] ?? '', 'sha256 ')
        && $r->hasHeader('Authorization', 'Bearer runtime-pat-fixture'));

    @unlink($path);
});
