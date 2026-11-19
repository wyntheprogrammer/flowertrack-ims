import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/supplier/flowers.js',
                'resources/css/storefront.css',
                'resources/css/client/home.css',
                'resources/css/client/catalog.css',
                'resources/js/client/home.js',
                'resources/js/client/navigation.js',
            ],
            refresh: true,
        }),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
