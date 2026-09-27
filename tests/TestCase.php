<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");

        if ($app->configurationIsCached() || ! $app->environment('testing') || $database !== ':memory:') {
            throw new RuntimeException(
                "Tests refused to start: config cache is on, APP_ENV is not testing, or the default database is '{$database}' instead of :memory:. Run 'php artisan config:clear' first."
            );
        }

        return $app;
    }
}
