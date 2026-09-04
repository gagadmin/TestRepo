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
        /*
         * `vue3-apexcharts` ships its own pre-bundled copy of the chart engine
         * for server-side rendering and dynamically imports it from two
         * components: ApexChartsServer, which runs only in `onServerPrefetch`,
         * and ApexChartsHydrate, which rehydrates server-rendered markup. This
         * application is a client-only SPA that registers neither - it wires up
         * the plain `apexchart` component alone - so that copy is never
         * executed in a browser.
         *
         * It is still emitted, because the package sets `install` on the
         * default export and that installer references both components, which
         * defeats tree-shaking. The result was a ~518 kB chunk of dead weight
         * shipped on every deploy alongside the ~805 kB chunk that is actually
         * used - the same engine, twice.
         *
         * Replacing it with a stub removes the duplicate from the build. The
         * stub throws rather than returning something plausible: if this
         * application ever adopts server-side rendering or uses
         * <ApexChartsHydrate>, that must fail loudly here rather than silently
         * render nothing. Both call sites already catch and log.
         *
         * The vendored filename carries a content hash, so it is matched by
         * pattern - a version bump changes the hash but not the shape.
         */
        {
            name: 'drop-vendored-apexcharts-ssr-bundle',
            apply: 'build',
            enforce: 'pre',
            resolveId(source) {
                return /apexcharts\.ssr\.esm(-[A-Za-z0-9]+)?\.js$/.test(source)
                    ? '\0apexcharts-ssr-stub'
                    : null;
            },
            load(id) {
                if (id !== '\0apexcharts-ssr-stub') {
                    return null;
                }

                const message = 'ApexCharts server-side rendering is not available in this build. '
                    + 'See the drop-vendored-apexcharts-ssr-bundle plugin in vite.config.js.';

                return `const unavailable = () => { throw new Error(${JSON.stringify(message)}); };
export default { renderToHTML: unavailable, hydrateAll: unavailable };
`;
            },
        },
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
