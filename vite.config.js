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
                'resources/css/client/editorial.css',
                'resources/css/client/product.css',
                'resources/css/client/cart.css',
                'resources/css/client/checkout.css',
                'resources/css/client/sign-in.css',
                'resources/js/client/product.js',
                'resources/js/client/cart.js',
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
