<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Exceptions;

use RuntimeException;

/**
 * Thrown when no token is available for an agent call — e.g. an operational
 * call (heartbeat/report/archives/restore) is attempted before the
 * enrollment -> runtime PAT exchange has persisted a runtime token (PA1), and
 * no enrollment fallback is configured either.
 */
final class MissingCredentialException extends RuntimeException
{
}
