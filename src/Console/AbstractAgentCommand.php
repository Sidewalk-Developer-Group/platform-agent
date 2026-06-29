<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Console;

use Illuminate\Console\Command;

/**
 * Base for the PA0 command stubs. The command SURFACE (signatures + discovery)
 * exists now so onboarding docs and `php artisan list` are complete; the real
 * implementations land in PA1-PA4 (noted per command). No business logic here.
 */
abstract class AbstractAgentCommand extends Command
{
    /**
     * The build phase that implements this command (for the not-yet-implemented
     * notice).
     */
    protected string $implementedInPhase = 'a later phase';

    protected function notImplemented(): int
    {
        $this->components->warn(sprintf(
            '%s is not yet implemented — it lands at %s. (PA0 ships the command surface only.)',
            $this->getName(),
            $this->implementedInPhase,
        ));

        return self::SUCCESS;
    }
}
