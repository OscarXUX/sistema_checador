<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Candado de seguridad: las pruebas solo corren contra SQLite en memoria.
     *
     * Si la configuración está en caché (php artisan optimize / config:cache), Laravel ignora
     * las variables de phpunit.xml y RefreshDatabase borraría la base real del .env.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        if ($app->configurationIsCached() || ! $app->environment('testing') || $connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(
                "Pruebas detenidas para no borrar tu base de datos real (conexión: {$connection}, base: {$database}). "
                .'Corre "php artisan optimize:clear" y vuelve a ejecutar "php artisan test".'
            );
        }

        return $app;
    }
}
