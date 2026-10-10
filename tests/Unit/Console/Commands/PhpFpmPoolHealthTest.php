<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands;

use App\Console\Commands\PhpFpmPoolHealth;
use App\Services\Monitoring\PhpFpmStatusReader;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** Проверяет сигнал насыщения без приложения, БД и сетевых запросов. */
class PhpFpmPoolHealthTest extends TestCase
{
    protected function tearDown(): void
    {
        Log::clearResolvedInstance('log');
        Mockery::close();
        parent::tearDown();
    }

    /** Только webhook имеет пороги нагрузки; насыщенный www не вытесняет его. */
    public function test_healthy_webhook_succeeds_even_when_www_is_saturated(): void
    {
        $logger = Mockery::mock();
        $logger->shouldNotReceive('warning');
        $manager = Mockery::mock();
        $manager->shouldReceive('channel')->with('app')->andReturn($logger);
        Log::swap($manager);
        $reader = $this->createMock(PhpFpmStatusReader::class);
        $reader->expects($this->exactly(2))->method('read')->willReturnCallback(
            fn (string $pool): array => ['pool' => $pool, 'idle' => $pool === 'webhook' ? 2 : 0, 'queue' => $pool === 'webhook' ? 0 : 5],
        );
        [$command, $output] = $this->command();

        $this->assertSame(0, $command->handle($reader));
        $this->assertStringContainsString('webhook: idle=2, queue=0', $output->fetch());
    }

    /** Каждый порог и их сочетание дают warning и ненулевой код. */
    #[DataProvider('saturationCases')]
    public function test_saturation_reports_its_reason(int $idle, int $queue, string $reason): void
    {
        $manager = Mockery::mock();
        $manager->shouldReceive('channel')->once()->with('app')->andReturnSelf();
        $manager->shouldReceive('warning')->once()->with('php_fpm_pool_unhealthy', Mockery::on(
            fn (array $context): bool => $context['pool'] === 'webhook' && $context['idle'] === $idle
                && $context['queue'] === $queue && str_contains($context['reason'], $reason),
        ));
        Log::swap($manager);
        $reader = $this->createMock(PhpFpmStatusReader::class);
        $reader->expects($this->exactly(2))->method('read')->willReturnCallback(
            fn (string $pool): array => ['pool' => $pool, 'idle' => $pool === 'webhook' ? $idle : 2, 'queue' => $pool === 'webhook' ? $queue : 0],
        );
        [$command, $output] = $this->command();

        $this->assertSame(1, $command->handle($reader));
        $this->assertStringContainsString($reason, $output->fetch());
    }

    /** @return array<string, array{int, int, string}> */
    public static function saturationCases(): array
    {
        return [
            'one idle' => [1, 0, 'свободных процессов меньше 2'],
            'none idle' => [0, 0, 'свободных процессов меньше 2'],
            'queued' => [3, 1, 'очередь ожидания больше 0'],
            'both' => [0, 3, 'свободных процессов меньше 2; очередь ожидания больше 0'],
        ];
    }

    /** Отказ любого пула не мешает проверке второго. */
    #[DataProvider('unavailablePools')]
    public function test_unavailable_status_warns_and_still_checks_both_pools(string $unavailable): void
    {
        $manager = Mockery::mock();
        $manager->shouldReceive('channel')->once()->with('app')->andReturnSelf();
        $manager->shouldReceive('warning')->once()->with('php_fpm_pool_unhealthy', [
            'pool' => $unavailable, 'reason' => 'статус недоступен: Status connection failed.',
        ]);
        Log::swap($manager);
        $reader = $this->createMock(PhpFpmStatusReader::class);
        $reader->expects($this->exactly(2))->method('read')->willReturnCallback(function (string $pool) use ($unavailable): array {
            if ($pool === $unavailable) {
                throw new RuntimeException('Status connection failed.');
            }

            return ['pool' => $pool, 'idle' => 6, 'queue' => 0];
        });
        [$command, $output] = $this->command();

        $this->assertSame(1, $command->handle($reader));
        $text = $output->fetch();
        $this->assertStringContainsString("{$unavailable}: статус недоступен", $text);
        $this->assertStringContainsString(($unavailable === 'www' ? 'webhook' : 'www') . ': idle=6', $text);
    }

    /** @return array<string, array{string}> */
    public static function unavailablePools(): array
    {
        return ['www' => ['www'], 'webhook' => ['webhook']];
    }

    /** @return array{PhpFpmPoolHealth, BufferedOutput} */
    private function command(): array
    {
        $command = new PhpFpmPoolHealth();
        $output = new BufferedOutput();
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

        return [$command, $output];
    }
}
