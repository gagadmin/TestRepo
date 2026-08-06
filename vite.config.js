import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { rmSync } from 'node:fs';
import { resolve } from 'node:path';

export default defineConfig({
    resolve: {
        alias: {
            '@': resolve('resources/js'),
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        include: ['resources/js/**/*.spec.js'],
        setupFiles: ['resources/js/tests/setup.js'],
    },
    plugins: [
        {
            name: 'remove-stale-laravel-hot-file',
            apply: 'build',
            buildStart() {
                rmSync(resolve('public/hot'), { force: true });
            },
        },
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
    ],
    server: {
        host: '127.0.0.1',
    },
    build: {
        // The only large chunk is the chart engine and it is loaded on demand.
        chunkSizeWarningLimit: 850,
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (id.includes('node_modules/primevue') || id.includes('node_modules/@primeuix')) {
                        return 'prime-ui';
                    }

                    if (id.includes('node_modules/vue/')) {
                        return 'vue-runtime';
                    }

                    if (id.includes('node_modules/axios/')) {
                        return 'http-client';
                    }

                    if (id.includes('node_modules/vue-router') || id.includes('node_modules/pinia')) {
                        return 'app-core';
                    }

                    return undefined;
                },
            },
        },
    },
});
