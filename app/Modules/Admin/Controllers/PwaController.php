<?php

namespace App\Modules\Admin\Controllers;

use Illuminate\Http\Response;

/**
 * Serves the admin PWA's web app manifest and service worker.
 *
 * Both are exposed under `/admin/` (no auth — the browser fetches them
 * independently of the session) so the service worker's default scope is
 * `/admin/`. The SW cache version is derived from the Vite build manifest, so
 * a new asset build automatically invalidates the old cache on the next visit.
 */
class PwaController
{
    /**
     * Web app manifest (`application/manifest+json`).
     *
     * @return Response
     */
    public function manifest(): Response
    {
        $manifest = [
            'name' => 'TG Support — Админка',
            'short_name' => 'TG Support',
            'description' => 'Рабочее место поддержки: чаты и настройки',
            'lang' => 'ru',
            'start_url' => '/admin/chats',
            'scope' => '/admin/',
            'display' => 'standalone',
            'orientation' => 'any',
            'theme_color' => '#1B1F2E',
            'background_color' => '#FFFFFF',
            'icons' => [
                [
                    'src' => '/icons/icon-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => '/icons/icon-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
            ],
        ];

        $json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return response((string) $json, 200)
            ->header('Content-Type', 'application/manifest+json; charset=utf-8')
            ->header('Cache-Control', 'no-cache');
    }

    /**
     * Service worker script (`application/javascript`).
     *
     * @return Response
     */
    public function serviceWorker(): Response
    {
        return response($this->serviceWorkerScript($this->buildVersion()), 200)
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            ->header('Service-Worker-Allowed', '/admin/')
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    /**
     * Cache-busting version string, tied to the current asset build.
     *
     * Hash of the Vite build manifest so a rebuild changes the SW cache name;
     * falls back to a static token when assets are not built (dev).
     *
     * @return string
     */
    private function buildVersion(): string
    {
        $manifestPath = public_path('build/manifest.json');
        $seed = is_file($manifestPath) ? (string) @file_get_contents($manifestPath) : 'dev';

        return substr(md5($seed), 0, 12);
    }

    /**
     * The service worker source, with the build version baked into the cache name.
     *
     * Strategy:
     *  - navigations (HTML): network-first, fall back to the precached offline
     *    shell — authenticated HTML is NEVER written to the cache;
     *  - static assets (`/build/`, `/icons/`, manifest): cache-first;
     *  - everything else (Livewire/AJAX/POST, cross-origin): passthrough.
     *
     * @param string $version
     *
     * @return string
     */
    private function serviceWorkerScript(string $version): string
    {
        return <<<JS
const CACHE = 'admin-pwa-{$version}';
const OFFLINE_URL = '/offline.html';
const PRECACHE = [OFFLINE_URL, '/icons/icon-192.png', '/icons/icon-512.png'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

function isStaticAsset(url) {
    return url.pathname.startsWith('/build/')
        || url.pathname.startsWith('/icons/')
        || url.pathname.endsWith('manifest.webmanifest');
}

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') { return; }

    const url = new URL(req.url);
    if (url.origin !== self.location.origin) { return; }

    // HTML navigations: network-first, offline shell as fallback.
    // The authenticated response is intentionally NOT cached.
    if (req.mode === 'navigate') {
        event.respondWith(fetch(req).catch(() => caches.match(OFFLINE_URL)));
        return;
    }

    // Static assets: cache-first, populate on first fetch.
    if (isStaticAsset(url)) {
        event.respondWith(
            caches.match(req).then((hit) => hit || fetch(req).then((res) => {
                const copy = res.clone();
                caches.open(CACHE).then((c) => c.put(req, copy));
                return res;
            }).catch(() => hit))
        );
        return;
    }

    // Everything else (Livewire, AJAX, cross-path) — leave to the network.
});
JS;
    }
}
