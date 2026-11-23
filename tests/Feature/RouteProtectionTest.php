<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Falla si alguien agrega una ruta sin el middleware auth (auditoría SE-01, guía 4.4) o sin su
 * permiso `can:` (SE-02: perfiles y permisos).
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

    /**
     * Rutas con sesión que no necesitan un permiso del catálogo.
     */
    private const WITHOUT_PERMISSION = [
        'logout',           // cerrar la propia sesión
        'inicio',           // manda a la primera pantalla que el usuario puede ver
        'device/status',    // estado de la terminal en la barra superior, sin datos de personas
    ];

    public function test_every_authenticated_route_declares_its_permission(): void
    {
        $withoutPermission = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (in_array($uri, self::PUBLIC_URIS, true) || in_array($uri, self::WITHOUT_PERMISSION, true)) {
                continue;
            }

            $hasCan = collect($route->gatherMiddleware())
                ->contains(fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'can:'));

            if (! $hasCan) {
                $withoutPermission[] = implode('|', $route->methods()).' /'.$uri;
            }
        }

        $this->assertSame([], $withoutPermission, "Rutas sin 'can:':\n".implode("\n", $withoutPermission));
    }
}
