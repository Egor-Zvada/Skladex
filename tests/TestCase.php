<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $database = (string) $app['db']->connection()->getDatabaseName();
        if (! preg_match('/(?:^|_)(?:tests?|testing)(?:_|$)/i', $database)) {
            throw new \RuntimeException('Tests require a dedicated *_test database. Refusing to touch the configured database.');
        }

        return $app;
    }
}
