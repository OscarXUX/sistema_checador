import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
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
