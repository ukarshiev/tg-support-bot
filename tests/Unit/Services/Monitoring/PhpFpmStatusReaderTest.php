<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Monitoring;

use App\Services\Monitoring\PhpFpmStatusReader;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/** Проверяет разбор FastCGI-ответа на фиксированных данных, без сети. */
class PhpFpmStatusReaderTest extends TestCase
{
    /** Узел и порты читаются из config; проверка не открывает соединений. */
    public function test_status_endpoints_use_default_and_overridden_configuration(): void
    {
        $previousContainer = Container::getInstance();
        $container = new Container();
        $configuration = require dirname(__DIR__, 4) . '/config/php-fpm.php';
        $container->instance('config', new Repository(['php-fpm' => $configuration]));
        Container::setInstance($container);

        try {
            $endpoint = new ReflectionMethod(PhpFpmStatusReader::class, 'endpoint');
            $reader = new PhpFpmStatusReader();
            $this->assertSame('tcp://app:9101', $endpoint->invoke($reader, 'www'));
            $this->assertSame('tcp://app:9102', $endpoint->invoke($reader, 'webhook'));

            config([
                'php-fpm.status.host' => 'fpm.internal.test',
                'php-fpm.status.ports.www' => 9201,
                'php-fpm.status.ports.webhook' => 9202,
            ]);
            $this->assertSame('tcp://fpm.internal.test:9201', $endpoint->invoke($reader, 'www'));
            $this->assertSame('tcp://fpm.internal.test:9202', $endpoint->invoke($reader, 'webhook'));
        } finally {
            Container::setInstance($previousContainer);
        }
    }

    /** Сводный JSON содержит типизированные счётчики, full не требуется. */
    public function test_parses_summary_json_with_cgi_headers(): void
    {
        $response = "Content-type: application/json\r\n\r\n" . json_encode([
            'pool' => 'webhook', 'idle processes' => 6, 'listen queue' => 0,
        ]);

        $this->assertSame(['pool' => 'webhook', 'idle' => 6, 'queue' => 0], $this->parse($response));
    }

    /** Ошибочный ответ нельзя принять за здоровый пул. */
    #[DataProvider('invalidResponses')]
    public function test_rejects_invalid_or_wrong_pool_status(string $response): void
    {
        $this->expectException(RuntimeException::class);
        $this->parse($response);
    }

    /** @return array<string, array{string}> */
    public static function invalidResponses(): array
    {
        $header = "Content-type: application/json\r\n\r\n";

        return [
            'no CGI headers' => ['{}'],
            'not JSON' => [$header . 'unavailable'],
            'missing counters' => [$header . '{"pool":"webhook"}'],
            'wrong pool' => [$header . '{"pool":"www","idle processes":6,"listen queue":0}'],
            'string counter' => [$header . '{"pool":"webhook","idle processes":"6","listen queue":0}'],
            'negative counter' => [$header . '{"pool":"webhook","idle processes":6,"listen queue":-1}'],
            'CGI failure' => ["Status: 503 Service Unavailable\r\n" . $header . '{"pool":"webhook","idle processes":6,"listen queue":0}'],
        ];
    }

    private function parse(string $response): mixed
    {
        return (new ReflectionMethod(PhpFpmStatusReader::class, 'parseResponse'))
            ->invoke(new PhpFpmStatusReader(), $response, 'webhook');
    }

    /** FastCGI объединяет несколько STDOUT-записей и учитывает padding. */
    public function test_reads_fragmented_fastcgi_response_with_padding(): void
    {
        $cgi = "Content-type: application/json\r\n\r\n" . '{"pool":"webhook","idle processes":6,"listen queue":0}';
        $wire = $this->record(6, substr($cgi, 0, 20), 3)
            . $this->record(6, substr($cgi, 20)) . $this->record(6, '')
            . $this->record(3, pack('NCxxx', 0, 0));

        $this->assertSame(['pool' => 'webhook', 'idle' => 6, 'queue' => 0], $this->readWire($wire));
    }

    /** Обрезанные записи, чужой request-id, отказ и превышение лимита дают недоступность. */
    #[DataProvider('invalidWireResponses')]
    public function test_rejects_invalid_fastcgi_response(string $wire): void
    {
        $this->expectException(RuntimeException::class);
        $this->readWire($wire);
    }

    /** @return array<string, array{string}> */
    public static function invalidWireResponses(): array
    {
        return [
            'truncated header' => ["\x01\x06"],
            'wrong request' => [pack('CCnnCC', 1, 6, 2, 0, 0, 0)],
            'truncated body' => [pack('CCnnCC', 1, 6, 1, 10, 0, 0) . 'x'],
            'application failure' => [pack('CCnnCC', 1, 3, 1, 8, 0, 0) . pack('NCxxx', 1, 0)],
            'protocol failure' => [pack('CCnnCC', 1, 3, 1, 8, 0, 0) . pack('NCxxx', 0, 2)],
            'oversized response' => [pack('CCnnCC', 1, 6, 1, 65535, 0, 0)],
        ];
    }

    private function record(int $type, string $content, int $padding = 0): string
    {
        return pack('CCnnCC', 1, $type, 1, strlen($content), $padding, 0) . $content . str_repeat("\0", $padding);
    }

    private function readWire(string $wire): mixed
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertIsResource($stream);
        fwrite($stream, $wire);
        rewind($stream);
        try {
            return (new ReflectionMethod(PhpFpmStatusReader::class, 'readResponse'))
                ->invoke(new PhpFpmStatusReader(), $stream, 'webhook', microtime(true) + 2);
        } finally {
            fclose($stream);
        }
    }
}
