<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Límites de peticiones por usuario (o por IP mientras no haya login). Auditoría SE-07.
     */
    private function configureRateLimiting(): void
    {
        $tooMany = function (Request $request, array $headers) {
            $message = 'Demasiadas solicitudes. Espera un momento e intenta de nuevo.';

            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => $message], 429, $headers)
                : response($message, 429, $headers);
        };

        // Acciones que llaman a la terminal Hikvision (sincronizar, alta/baja en el reloj, captura de huella)
        RateLimiter::for('device', fn (Request $request) => Limit::perMinute(10)
            ->by('device|'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
            ->response($tooMany));

        // Envíos del formulario de login por IP; el límite por correo + IP está en LoginRequest
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(20)
            ->by('login|'.$request->ip())
            ->response($tooMany));

        // Estado de la terminal: la pantalla lo pide cada 30 s por pestaña (con caché, OP-05)
        RateLimiter::for('device-status', fn (Request $request) => Limit::perMinute(30)
            ->by('device-status|'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
            ->response($tooMany));

        // Reportes que consultan rangos de fechas
        RateLimiter::for('reports', fn (Request $request) => Limit::perMinute(30)
            ->by('reports|'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
            ->response($tooMany));
    }
}
