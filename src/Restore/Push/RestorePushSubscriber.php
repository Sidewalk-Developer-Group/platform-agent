<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Restore\Push;

/**
 * Long-lived restore-discovery push subscriber (PA5 / ADR-0007 Addendum B.5).
 *
 * Implementations keep a Reverb/Pusher subscription to the Hub's per-Application
 * private restore channel and invoke `$onSignal` with a {@see RestoreSignal}
 * whenever the Hub broadcasts (RESTORE) or the connection idles / drops (POLL —
 * the Rule-6 fallback tick). The callback returns `true` to keep listening or
 * `false` to stop; `listen()` returns when the callback stops it, the transport
 * closes, or the process is interrupted.
 *
 * The subscriber NEVER performs the restore itself — it only signals. The
 * authoritative pull → verify (Rule 4) → deposit → report stays in the restore
 * pipeline, identical to the poll path.
 */
interface RestorePushSubscriber
{
    /**
     * @param  callable(RestoreSignal): bool  $onSignal  return false to stop listening
     */
    public function listen(callable $onSignal): void;
}
