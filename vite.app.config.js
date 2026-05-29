// vite.app.config.js
// Standard Laravel asset build (app.css / app.js) -> public/build with manifest.
// The live-chat widget has its own build in vite.config.js (outDir public/live_chat/dist).
// `npm run build` runs both; `npm run dev` serves app assets via the dev server.
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
