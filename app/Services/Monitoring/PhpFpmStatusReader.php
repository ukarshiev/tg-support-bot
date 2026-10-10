<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use RuntimeException;

/**
 * Читает только сводный JSON статуса FPM через внутренний FastCGI, без HTTP и зависимостей.
 * JSON даёт типизированные счётчики; full не нужен и раскрывает URI активных запросов.
 * Каждый запрос ограничен двумя секундами и 64 КиБ ответа, включая FastCGI-заголовки.
 *
 * @see https://www.php.net/manual/en/fpm.status.php
 * @see https://fastcgi-archives.github.io/FastCGI_Specification.html
 */
class PhpFpmStatusReader
{
    /**
     * Возвращает проверенные счётчики пула; ошибки транспорта и формата считаются недоступностью.
     *
     * @return array{pool: string, idle: int, queue: int}
     *
     * @throws RuntimeException
     */
    public function read(string $pool): array
    {
        $endpoint = $this->endpoint($pool);
        $deadline = microtime(true) + 2;
        $socket = @stream_socket_client($endpoint, $errorCode, $errorMessage, 2);

        if ($socket === false) {
            throw new RuntimeException('Status connection failed.');
        }

        try {
            $params = '';
            foreach ([
                'REQUEST_METHOD' => 'GET',
                'SCRIPT_FILENAME' => '/fpm-status',
                'SCRIPT_NAME' => '/fpm-status',
                'REQUEST_URI' => '/fpm-status?json',
                'QUERY_STRING' => 'json',
                'SERVER_PROTOCOL' => 'HTTP/1.1',
            ] as $key => $value) {
                // All names and values above are shorter than 128 bytes.
                $params .= chr(strlen($key)) . chr(strlen($value)) . $key . $value;
            }
            $request = $this->record(1, pack('nCxxxxx', 1, 0))
                . $this->record(4, $params) . $this->record(4, '') . $this->record(5, '');

            while ($request !== '') {
                $this->setTimeout($socket, $deadline);
                $written = @fwrite($socket, $request);
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Status request could not be written.');
                }
                $request = substr($request, $written);
            }

            return $this->readResponse($socket, $pool, $deadline);
        } finally {
            fclose($socket);
        }
    }

    /** Возвращает внутренний адрес из инфраструктурной конфигурации, без runtime-секретов. */
    private function endpoint(string $pool): string
    {
        if (! in_array($pool, ['www', 'webhook'], true)) {
            throw new RuntimeException('Unknown PHP-FPM pool.');
        }

        $host = (string) config('php-fpm.status.host');
        $port = (int) config('php-fpm.status.ports.' . $pool);

        return "tcp://{$host}:{$port}";
    }

    /**
     * @param resource $socket
     *
     * @return array{pool: string, idle: int, queue: int}
     */
    private function readResponse($socket, string $pool, float $deadline): array
    {
        $stdout = '';
        $remaining = 65536;
        while (true) {
            $header = unpack('Cversion/Ctype/nid/nlength/Cpadding/Creserved', $this->readBytes($socket, 8, $deadline));
            if ($header === false || $header['version'] !== 1 || $header['id'] !== 1) {
                throw new RuntimeException('Invalid FastCGI status header.');
            }
            $remaining -= 8 + $header['length'] + $header['padding'];
            if ($remaining < 0) {
                throw new RuntimeException('Status response exceeds 64 KiB.');
            }
            $content = $this->readBytes($socket, $header['length'], $deadline);
            $this->readBytes($socket, $header['padding'], $deadline);
            if ($header['type'] === 6) {
                $stdout .= $content;
            } elseif ($header['type'] === 3) {
                $end = strlen($content) === 8 ? unpack('Napp/Cprotocol', $content) : false;
                if ($end === false || $end['app'] !== 0 || $end['protocol'] !== 0) {
                    throw new RuntimeException('FastCGI status request failed.');
                }

                return $this->parseResponse($stdout, $pool);
            }
        }
    }

    private function record(int $type, string $content): string
    {
        return pack('CCnnCC', 1, $type, 1, strlen($content), 0, 0) . $content;
    }

    /** @param resource $socket */
    private function setTimeout($socket, float $deadline): void
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new RuntimeException('Status request timed out.');
        }
        $seconds = (int) $remaining;
        stream_set_timeout($socket, $seconds, (int) (($remaining - $seconds) * 1000000));
    }

    /** @param resource $socket */
    private function readBytes($socket, int $length, float $deadline): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $this->setTimeout($socket, $deadline);
            $chunk = @fread($socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Status response is incomplete or timed out.');
            }
            $data .= $chunk;
        }

        return $data;
    }

    /** @return array{pool: string, idle: int, queue: int} */
    private function parseResponse(string $response, string $pool): array
    {
        $parts = preg_split('/\r?\n\r?\n/', $response, 2);
        if ($parts === false || count($parts) !== 2
            || preg_match('/^Status:\s*(?!200\b)\d+/mi', $parts[0]) === 1) {
            throw new RuntimeException('Invalid CGI status response.');
        }
        $status = json_decode($parts[1], true);
        if (! is_array($status) || ($status['pool'] ?? null) !== $pool
            || ! is_int($status['idle processes'] ?? null) || $status['idle processes'] < 0
            || ! is_int($status['listen queue'] ?? null) || $status['listen queue'] < 0) {
            throw new RuntimeException('Invalid PHP-FPM status counters or pool identity.');
        }

        return ['pool' => $pool, 'idle' => $status['idle processes'], 'queue' => $status['listen queue']];
    }
}
