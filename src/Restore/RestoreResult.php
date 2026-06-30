<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Restore;

/**
 * Outcome of an agent-PULL restore (PA4 / ADR-0011): the manifest fetch, the
 * byte download, the SHA256 verify (Rule 4) and the non-destructive deposit.
 *
 * `ok` is true only when the bytes were pulled, the checksum matched the
 * manifest, and the verified `backup.zip` + `.sha256` sidecar were deposited at
 * the target. A checksum mismatch is the Rule-4 abort: `ok=false`,
 * `checksumMismatch=true`, the partial download is removed, and `reason` carries
 * the actionable detail reported back to the Hub.
 */
final class RestoreResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $depositedPath = null,
        public readonly ?string $filename = null,
        public readonly ?string $expectedSha256 = null,
        public readonly ?string $actualSha256 = null,
        public readonly ?int $sizeBytes = null,
        public readonly bool $checksumMismatch = false,
        public readonly ?string $reason = null,
    ) {
    }

    public static function success(
        string $depositedPath,
        string $filename,
        string $sha256,
        int $sizeBytes,
    ): self {
        return new self(
            ok: true,
            depositedPath: $depositedPath,
            filename: $filename,
            expectedSha256: $sha256,
            actualSha256: $sha256,
            sizeBytes: $sizeBytes,
        );
    }

    /**
     * Rule 4 abort: the pulled bytes did not match the manifest SHA256.
     */
    public static function mismatch(string $filename, string $expected, string $actual): self
    {
        return new self(
            ok: false,
            filename: $filename,
            expectedSha256: $expected,
            actualSha256: $actual,
            checksumMismatch: true,
            reason: sprintf(
                'SHA256 mismatch for %s — expected %s, got %s. Restore aborted (Rule 4).',
                $filename,
                $expected,
                $actual,
            ),
        );
    }

    /**
     * A non-checksum failure (manifest/download/deposit) — still reported so the
     * Hub never sees a silent failure.
     */
    public static function failed(string $reason, ?string $filename = null): self
    {
        return new self(ok: false, filename: $filename, reason: $reason);
    }
}
