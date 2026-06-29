<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Exceptions;

use RuntimeException;

/**
 * Thrown when the Hub responds HTTP 426 Upgrade Required — the agent's reported
 * version is below the Hub's `compatible_floor` (HARD-BLOCK; ADR-0007 §2.5).
 *
 * The operation cannot interoperate with the Hub contract and MUST be aborted.
 * Callers MUST NOT retry-loop a 426; they surface a clear, actionable
 * "Platform Agent upgrade required" error.
 */
final class AgentUpgradeRequiredException extends RuntimeException
{
    public function __construct(
        string $message = 'Platform Agent upgrade required.',
        public readonly ?string $endpoint = null,
    ) {
        parent::__construct($message);
    }
}
