<?php

namespace JarredCain\CanvasLms\Tests;

use JarredCain\CanvasLms\CanvasServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [CanvasServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('canvas.base_url', 'https://canvas.example.com');
        $app['config']->set('canvas.auth_driver', 'token');
        $app['config']->set('canvas.token', 'test-token');
        $app['config']->set('canvas.account_id', 1);
    }
}
