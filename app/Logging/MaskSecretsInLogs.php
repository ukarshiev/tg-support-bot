<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Logger as MonologLogger;

/**
 * Install secret masking on the formatters of a Laravel logging channel.
 */
final class MaskSecretsInLogs
{
    /**
     * Wrap each supported handler's formatter at most once.
     */
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! $monolog instanceof MonologLogger) {
            return;
        }

        foreach ($monolog->getHandlers() as $handler) {
            if (! $handler instanceof FormattableHandlerInterface) {
                continue;
            }

            $formatter = $handler->getFormatter();

            if (! $formatter instanceof SecretMaskingFormatter) {
                $handler->setFormatter(new SecretMaskingFormatter($formatter));
            }
        }
    }
}
