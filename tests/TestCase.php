<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        if (!is_file(dirname(__DIR__) . '/.env.testing')) {
            throw new \RuntimeException('Create .env.testing with dedicated test database credentials first.');
        }
        $app = parent::createApplication();
        if ($app['config']->get('database.default') !== 'mysql'
            || !str_ends_with((string) $app['config']->get('database.connections.mysql.database'), '_testing')) {
            throw new \RuntimeException('Feature tests require a dedicated MySQL/MariaDB database ending in _testing.');
        }
        return $app;
    }
}
