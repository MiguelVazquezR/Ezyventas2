import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import Components from 'unplugin-vue-components/vite';
import { PrimeVueResolver } from '@primevue/auto-import-resolver';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/js/app.js',
            ],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        Components({
            resolvers: [
                PrimeVueResolver()
            ]
        })
    ],
    // The inference Web Worker imports ONNX Runtime Web and zxing-wasm, so it is
    // built as an ES module (code splitting is required for that). Nothing else
    // in the app changes: the main bundle stays an ES module as before.
    worker: {
        format: 'es',
    },
    //comentar los siguientes bloques si no se usa el dominio ezyventas2.test
    // server: {
    //     detectTls: 'ezyventas2.test',
    //     // 💡 Añade este bloque hmr para corregir el WebSocket de Vite:
    //     hmr: {
    //         host: 'ezyventas2.test',
    //         protocol: 'wss',
    //     },
    // },
});
