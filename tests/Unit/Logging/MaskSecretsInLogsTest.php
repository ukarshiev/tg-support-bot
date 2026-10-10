<?php

namespace Tests\Unit\Logging;

use App\Logging\MaskSecretsInLogs;
use App\Logging\SecretMaskingFormatter;
use Illuminate\Log\Logger as LaravelLogger;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use RuntimeException;
use Tests\TestCase;

/**
 * Verify masking on handlers and real Laravel channels using temporary logs.
 */
class MaskSecretsInLogsTest extends TestCase
{
    /**
     * Mask formatted message and context at the handler boundary.
     */
    public function test_masks_handler_output_and_does_not_wrap_twice(): void
    {
        $token = '123456789:TEST_fake-token_for_unit_tests_0000000';
        $handler = new TestHandler();
        $inner = $handler->getFormatter();
        $logger = new LaravelLogger(new Logger('app', [$handler]));
        $tap = new MaskSecretsInLogs();
        $tap($logger);
        $formatter = $handler->getFormatter();

        $this->assertInstanceOf(SecretMaskingFormatter::class, $formatter);
        $this->assertSame($inner, $formatter->getInner());
        $tap($logger);
        $this->assertSame($formatter, $handler->getFormatter());

        $logger->error('bot' . $token, ['error' => 'https://api.telegram.org/file/bot' . $token . '/path.jpg']);
        $formatted = $handler->getRecords()[0]->formatted;
        $this->assertIsString($formatted);
        $this->assertStringNotContainsString($token, $formatted);
        $this->assertStringNotContainsString(explode(':', $token, 2)[1], $formatted);
        $this->assertStringContainsString('bot[hidden]', $formatted);
        $this->assertStringContainsString('/file/bot[hidden]/path.jpg', $formatted);
    }

    /**
     * Handlers without formatter support must be skipped safely.
     */
    public function test_skips_non_formattable_handler(): void
    {
        $handler = new NullHandler();
        $monolog = new Logger('app', [$handler]);
        $logger = new LaravelLogger($monolog);

        (new MaskSecretsInLogs())($logger);
        $logger->error('ordinary diagnostic');

        $this->assertSame([$handler], $monolog->getHandlers());
    }

    /**
     * Every required output channel must install the safety net.
     */
    public function test_required_channels_register_the_tap(): void
    {
        foreach (['single', 'daily', 'app', 'telegram', 'stderr'] as $channel) {
            $taps = config('logging.channels.' . $channel . '.tap', []);
            $this->assertIsArray($taps);
            $this->assertContains(MaskSecretsInLogs::class, $taps, $channel);
        }
    }

    /**
     * A real daily app channel must write masked exceptions without fallback.
     */
    public function test_real_app_channel_writes_masked_file(): void
    {
        $directory = storage_path('logs');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $prefix = $directory . '/secret-masking-test-' . uniqid();
        $token = '123456789:TEST_fake-token_for_unit_tests_0000000';
        $url = 'https://api.telegram.org/file/bot' . $token . '/photos/avatar.jpg';
        $originalPath = config('logging.channels.app.path');

        try {
            config(['logging.channels.app.path' => $prefix . '.log']);
            Log::forgetChannel('app');
            Log::channel('app')->error($url, [
                'error' => $url,
                'exception' => new RuntimeException($url),
            ]);

            $files = glob($prefix . '*.log');
            $this->assertIsArray($files);
            $this->assertCount(1, $files);
            $contents = file_get_contents($files[0]);
            $this->assertIsString($contents);
            $this->assertStringNotContainsString($token, $contents);
            $this->assertStringNotContainsString(explode(':', $token, 2)[1], $contents);
            $this->assertStringContainsString('/file/bot[hidden]/photos/avatar.jpg', $contents);
            $this->assertStringContainsString('RuntimeException', $contents);
            $this->assertStringNotContainsString('Unable to create configured logger', $contents);
        } finally {
            Log::forgetChannel('app');
            config(['logging.channels.app.path' => $originalPath]);
            foreach (glob($prefix . '*.log') ?: [] as $file) {
                unlink($file);
            }
        }
    }

    /**
     * Real file channels and their stack must retain exactly one masking layer.
     */
    public function test_real_channels_use_masking_formatter_without_double_wrapping(): void
    {
        $directory = storage_path('logs');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $prefix = $directory . '/secret-masking-test-' . uniqid();
        $channels = ['app', 'daily', 'single', 'stack'];
        $originalConfig = [];
        foreach (['app', 'daily', 'single'] as $channel) {
            $key = 'logging.channels.' . $channel . '.path';
            $originalConfig[$key] = config($key);
        }
        $originalConfig['logging.channels.stack.channels'] = config('logging.channels.stack.channels');

        try {
            foreach (['app', 'daily', 'single'] as $channel) {
                config(['logging.channels.' . $channel . '.path' => $prefix . '-' . $channel . '.log']);
            }
            config(['logging.channels.stack.channels' => ['daily']]);
            foreach ($channels as $channel) {
                Log::forgetChannel($channel);
            }

            foreach ($channels as $channel) {
                $monolog = Log::channel($channel)->getLogger();
                $this->assertInstanceOf(Logger::class, $monolog);
                $formattableHandlers = 0;

                foreach ($monolog->getHandlers() as $handler) {
                    if (! $handler instanceof FormattableHandlerInterface) {
                        continue;
                    }

                    $formattableHandlers++;
                    $formatter = $handler->getFormatter();
                    $this->assertInstanceOf(SecretMaskingFormatter::class, $formatter, $channel);
                    $this->assertNotInstanceOf(SecretMaskingFormatter::class, $formatter->getInner(), $channel);
                    $this->assertInstanceOf(LineFormatter::class, $formatter->getInner(), $channel);
                }

                $this->assertGreaterThan(0, $formattableHandlers, $channel);
            }
        } finally {
            foreach ($channels as $channel) {
                Log::forgetChannel($channel);
            }
            config($originalConfig);
            foreach (glob($prefix . '*.log') ?: [] as $file) {
                unlink($file);
            }
        }
    }
}
