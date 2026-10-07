<?php

declare(strict_types=1);

namespace Tests;

use Database\Seeders\AccessCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = AccessCatalogSeeder::class;

    /**
     * In production every HTTP request starts with fresh guards and fresh scoped state
     * (tenant + actor context). Within one test the app instance is reused, so reset that
     * state before each API request; otherwise a revoked token could look "still logged in".
     * Web tests keep their guards because they rely on actingAs().
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        if (str_starts_with(ltrim((string) $uri, '/'), 'api/')) {
            $this->app['auth']->forgetGuards();
        }
        $this->app->forgetScopedInstances();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function isPostgres(): bool
    {
        return DB::getDriverName() === 'pgsql';
    }
}
