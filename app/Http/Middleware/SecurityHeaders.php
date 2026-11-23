<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad en todas las respuestas web (auditoría SE-08, guía 4.10).
 *
 * La CSP solo permite scripts propios y los bloques en línea que llevan el nonce de Vite
 * (@nonce en las vistas). En desarrollo agrega el servidor de Vite (npm run dev).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Genera el nonce antes de pintar la vista para que @vite y los <script> lo usen
        Vite::useCspNonce();

        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        $headers->remove('X-Powered-By');

        // HSTS solo sobre HTTPS: en http://127.0.0.1 no tiene efecto y en producción obliga HTTPS
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
        }

        // Páginas con datos personales: que el navegador no las guarde en caché
        if ($request->user() !== null) {
            $headers->set('Cache-Control', 'no-store, private');
        }

        if (function_exists('header_remove')) {
            header_remove('X-Powered-By');
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        $nonce = Vite::cspNonce();
        $devServer = $this->viteDevServer();

        $script = trim("'self' 'nonce-{$nonce}' {$devServer}");
        // Bootstrap y Wijmo ponen estilos en línea; el riesgo de 'unsafe-inline' en estilos es bajo
        $style = trim("'self' 'unsafe-inline' {$devServer}");
        $connect = trim("'self' ".($devServer !== '' ? $devServer.' '.str_replace('http', 'ws', $devServer) : ''));

        return implode('; ', [
            "default-src 'self'",
            "script-src {$script}",
            "style-src {$style}",
            "img-src 'self' data:",
            "font-src 'self' data: ".$devServer,
            "connect-src {$connect}",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]);
    }

    /**
     * URL del servidor de Vite si está corriendo npm run dev (archivo public/hot).
     */
    private function viteDevServer(): string
    {
        if (! Vite::isRunningHot()) {
            return '';
        }

        $hot = trim((string) @file_get_contents(public_path('hot')));

        return rtrim($hot, '/');
    }
}
