<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /** Simulate the host exactly as it arrives through the trusted Caddy proxy. */
    protected function fromHost(string $host): static
    {
        return $this
            ->withServerVariables([
                'HTTP_HOST' => $host,
                'HTTP_X_FORWARDED_HOST' => $host,
                'SERVER_NAME' => $host,
                'SERVER_PORT' => 443,
                'HTTPS' => 'on',
            ])
            ->withHeader('Host', $host)
            ->withHeader('X-Forwarded-Host', $host)
            ->withHeader('X-Forwarded-Proto', 'https');
    }

    /** Never allow an application test to boot with production DB/cache configuration. */
    public function createApplication(): Application
    {
        $testEnvironment = [
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => sys_get_temp_dir().'/solarnet-phpunit-config.php',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
        ];

        foreach ($testEnvironment as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! app()->environment('testing')
            || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('Unsafe test database configuration blocked before test execution.');
        }
    }
}
