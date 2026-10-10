<?php

namespace Tests\Unit\Support;

use App\Support\SecretMasker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verify secret removal from text without changing ordinary diagnostics.
 */
class SecretMaskerTest extends TestCase
{
    private const TOKEN = '123456789:TEST_fake-token_for_unit_tests_0000000';

    /**
     * Mask every supported credential representation.
     */
    #[DataProvider('maskCases')]
    public function test_masks_secrets(string $input, string $expected): void
    {
        $this->assertSame($expected, SecretMasker::mask($input));
    }

    /**
     * Provide diagnostic strings with independently specified safe output.
     *
     * @return array<string, array{string, string}>
     */
    public static function maskCases(): array
    {
        return [
            'api' => ['https://api.telegram.org/bot' . self::TOKEN . '/getUpdates', 'https://api.telegram.org/bot[hidden]/getUpdates'],
            'file' => ['https://api.telegram.org/file/bot' . self::TOKEN . '/path.jpg', 'https://api.telegram.org/file/bot[hidden]/path.jpg'],
            'encoded api' => ['https://API.TELEGRAM.ORG/bot' . str_replace(':', '%3A', self::TOKEN) . '/getUpdates', 'https://API.TELEGRAM.ORG/bot[hidden]/getUpdates'],
            'encoded file' => ['https://api.telegram.org/file/bot' . str_replace(':', '%3A', self::TOKEN) . '/path.jpg', 'https://api.telegram.org/file/bot[hidden]/path.jpg'],
            'prefixed' => ['failed bot' . self::TOKEN . ' retry', 'failed bot[hidden] retry'],
            'bare' => ['credential ' . self::TOKEN . ' rejected', 'credential [hidden] rejected'],
            'proxies' => ['HTTP http://user:pass@proxy.local:10809 SOCKS socks5h://user:pass@proxy.local:10808', 'HTTP http://[hidden]@proxy.local:10809 SOCKS socks5h://[hidden]@proxy.local:10808'],
            'multiple' => ['https://api.telegram.org/bot' . self::TOKEN . '/getUpdates https://api.telegram.org/file/bot' . self::TOKEN . '/path.jpg bot' . self::TOKEN . ' ' . self::TOKEN, 'https://api.telegram.org/bot[hidden]/getUpdates https://api.telegram.org/file/bot[hidden]/path.jpg bot[hidden] [hidden]'],
            'ordinary text' => ['time 12:34:56 id 42:short status: failed', 'time 12:34:56 id 42:short status: failed'],
            'empty' => ['', ''],
        ];
    }

    /**
     * Repeated sanitization must preserve the first safe result.
     */
    #[DataProvider('maskCases')]
    public function test_masking_is_idempotent(string $input, string $expected): void
    {
        $masked = SecretMasker::mask($input);

        $this->assertSame($expected, $masked);
        $this->assertSame($masked, SecretMasker::mask($masked));
    }
}
