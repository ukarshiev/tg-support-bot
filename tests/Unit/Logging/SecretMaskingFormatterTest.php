<?php

namespace Tests\Unit\Logging;

use App\Logging\SecretMaskingFormatter;
use DateTimeImmutable;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verify sanitization after formatting, including normalized exceptions.
 */
class SecretMaskingFormatterTest extends TestCase
{
    private const TOKEN = '123456789:TEST_fake-token_for_unit_tests_0000000';

    /**
     * Remove secrets from message, context and exception stack output.
     */
    public function test_masks_line_formatter_output_with_exception(): void
    {
        $inner = new LineFormatter(null, null, true, true);
        $inner->includeStacktraces();
        $formatter = new SecretMaskingFormatter($inner);
        $formatted = $formatter->format($this->record());

        $this->assertIsString($formatted);
        $this->assertStringNotContainsString(self::TOKEN, $formatted);
        $this->assertStringNotContainsString(explode(':', self::TOKEN, 2)[1], $formatted);
        $this->assertStringContainsString('bot[hidden]', $formatted);
        $this->assertStringContainsString('RuntimeException', $formatted);
        $this->assertSame($inner, $formatter->getInner());
    }

    /**
     * Recursively mask array values while retaining keys and scalar types.
     */
    public function test_masks_nested_array_output(): void
    {
        $inner = $this->createMock(FormatterInterface::class);
        $inner->expects($this->once())->method('format')->willReturn([
            'message' => 'bot' . self::TOKEN,
            'nested' => ['error' => self::TOKEN, 'status' => 500, 'retry' => false, 'empty' => null],
        ]);

        $this->assertSame([
            'message' => 'bot[hidden]',
            'nested' => ['error' => '[hidden]', 'status' => 500, 'retry' => false, 'empty' => null],
        ], (new SecretMaskingFormatter($inner))->format($this->record()));
    }

    /**
     * Batch line output must be sanitized as well.
     */
    public function test_masks_line_batch_output(): void
    {
        $formatter = new SecretMaskingFormatter(new LineFormatter(null, null, true, true));
        $formatted = $formatter->formatBatch([$this->record(), $this->record()]);

        $this->assertIsString($formatted);
        $this->assertStringNotContainsString(self::TOKEN, $formatted);
        $this->assertStringContainsString('bot[hidden]', $formatted);
    }

    /**
     * Batch arrays must retain their structure and hide nested credentials.
     */
    public function test_masks_array_batch_output(): void
    {
        $records = [$this->record()];
        $inner = $this->createMock(FormatterInterface::class);
        $inner->expects($this->once())->method('formatBatch')->with($records)->willReturn([
            ['context' => ['error' => 'bot' . self::TOKEN]],
        ]);

        $this->assertSame([
            ['context' => ['error' => 'bot[hidden]']],
        ], (new SecretMaskingFormatter($inner))->formatBatch($records));
    }

    /**
     * Non-string, non-array output passes through unchanged.
     */
    public function test_preserves_other_output_types(): void
    {
        $inner = $this->createMock(FormatterInterface::class);
        $inner->method('format')->willReturn(42);

        $this->assertSame(42, (new SecretMaskingFormatter($inner))->format($this->record()));
    }

    /**
     * Existing formatter configuration methods remain available.
     */
    public function test_forwards_formatter_configuration(): void
    {
        $inner = new LineFormatter(null, null, true, true);
        $formatter = new SecretMaskingFormatter($inner);
        $formatter->includeStacktraces();

        $this->assertSame($inner, $formatter->getInner());
        $formatted = $formatter->format($this->record());
        $this->assertIsString($formatted);
        $this->assertStringContainsString('trace', $formatted);
        $this->assertStringNotContainsString(self::TOKEN, $formatted);
    }

    private function record(): LogRecord
    {
        $url = 'https://api.telegram.org/file/bot' . self::TOKEN . '/photos/avatar.jpg';

        return new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'app',
            level: Level::Error,
            message: $url,
            context: ['error' => $url, 'exception' => new RuntimeException($url)],
        );
    }
}
