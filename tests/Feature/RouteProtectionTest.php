<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Falla si alguien agrega una ruta sin el middleware auth (auditoría SE-01, guía 4.4).
 */
class RouteProtectionTest extends TestCase
{
    /**
     * Rutas que deben quedar abiertas a propósito.
     */
    private const PUBLIC_URIS = [
        'login',            // formulario e inicio de sesión
        'up',               // revisión de salud del servidor (sin datos)
        'storage/{path}',   // archivos locales de Laravel, solo con URL firmada
    ];

    public function test_every_route_requires_authentication(): void
    {
        $unprotected = [];

        foreach (Route::getRoutes() as $route) {
            if (in_array($route->uri(), self::PUBLIC_URIS, true)) {
                continue;
            }

            if (! in_array('auth', $route->gatherMiddleware(), true)) {
                $unprotected[] = implode('|', $route->methods()).' /'.$route->uri();
            }
        }

        $this->assertSame([], $unprotected, "Rutas sin 'auth':\n".implode("\n", $unprotected));
    }
}
