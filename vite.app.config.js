// vite.app.config.js
// Standard Laravel asset build (app.css / app.js) -> public/build with manifest.
// The live-chat widget (public/widget/widget.js) is plain JS served as-is — it
// needs no build step. `npm run build` builds the app; `npm run dev` serves it.
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
