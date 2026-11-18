import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    // IPv4 explícito: la política de seguridad (CSP) no acepta direcciones IPv6 como [::1] (auditoría SE-08)
    server: {
        host: '127.0.0.1',
    },
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // Wijmo por página (auditoría OP-06)
                'resources/js/wijmo/grid.js',
                'resources/js/wijmo/chart.js',
                'resources/js/wijmo/kardex.js',
            ],
            refresh: true,
        }),
    ],
});
