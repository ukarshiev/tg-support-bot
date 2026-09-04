<?php

namespace Tests\Mocks;

final class PayloadIdentifier
{
    private static int $next = 1_000_000_000;

    public static function next(): int
    {
        self::$next += 1_000;

        return self::$next;
    }
}
