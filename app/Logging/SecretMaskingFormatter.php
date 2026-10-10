<?php

namespace App\Logging;

use App\Support\SecretMasker;
use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * Sanitize the final output of any Monolog formatter before it is written.
 */
final class SecretMaskingFormatter implements FormatterInterface
{
    /**
     * Preserve the channel's existing formatter and its configuration.
     */
    public function __construct(private readonly FormatterInterface $inner)
    {
    }

    /**
     * Format one record, then mask secrets in the resulting output.
     */
    public function format(LogRecord $record): mixed
    {
        return $this->maskOutput($this->inner->format($record));
    }

    /**
     * Format a batch, then mask secrets in the resulting output.
     *
     * @param array<LogRecord> $records
     */
    public function formatBatch(array $records): mixed
    {
        return $this->maskOutput($this->inner->formatBatch($records));
    }

    /**
     * Expose the original formatter for configuration and inspection.
     */
    public function getInner(): FormatterInterface
    {
        return $this->inner;
    }

    /**
     * Forward formatter-specific calls to the original formatter.
     *
     * @param array<mixed> $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->inner->{$name}(...$arguments);
    }

    private function maskOutput(mixed $output): mixed
    {
        if (is_string($output)) {
            return SecretMasker::mask($output);
        }

        if (is_array($output)) {
            foreach ($output as $key => $value) {
                $output[$key] = $this->maskOutput($value);
            }
        }

        return $output;
    }
}
