<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test bootstrap — force the isolated testing environment
|--------------------------------------------------------------------------
|
| The Docker container exports runtime config (APP_ENV, DB_CONNECTION,
| CACHE_STORE, …) as real OS environment variables, which land in
| $_SERVER / $_ENV. Laravel reads env() from those, and dotenv is
| immutable (it never overwrites an existing entry). As a result the
| <env> values in phpunit.xml are ignored and tests would otherwise run
| against the production Postgres database and the persistent file cache.
|
| Setting the overrides directly in $_SERVER / $_ENV here — before the
| framework boots — guarantees a fully isolated test environment
| (sqlite :memory: + array cache, sync queue, array session/mail).
|
*/

require __DIR__ . '/../vendor/autoload.php';

use Tests\Support\DatabaseSafetyGuard;

$defaultConfigCache = dirname(__DIR__) . '/bootstrap/cache/config.php';
$configuredConfigCache = getenv('APP_CONFIG_CACHE');

DatabaseSafetyGuard::assertCachedConfigIsSafe($defaultConfigCache);

if (is_string($configuredConfigCache) && $configuredConfigCache !== '' && $configuredConfigCache !== $defaultConfigCache) {
    DatabaseSafetyGuard::assertCachedConfigIsSafe($configuredConfigCache);
}

$isolatedConfigCache = sys_get_temp_dir() . '/tg-support-bot-phpunit-' . getmypid() . '-config.php';

$testEnvironment = [
    'APP_ENV' => 'testing',
    'APP_CONFIG_CACHE' => $isolatedConfigCache,
    'APP_MAINTENANCE_DRIVER' => 'file',
    'BCRYPT_ROUNDS' => '4',
    'CACHE_STORE' => 'array',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'MAIL_MAILER' => 'array',
    'LOG_CHANNEL' => 'null',
    'PULSE_ENABLED' => 'false',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'TELESCOPE_ENABLED' => 'false',
];

foreach ($testEnvironment as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv("{$key}={$value}");
}
