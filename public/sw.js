/**
 * ClawYard Service Worker — offline read-only para os dashboards principais.
 *
 * Estratégia:
 *   • App shell (CSS/JS/fonts/icons) → cache-first, network fallback
 *   • Dashboards (/dashboard, /tenders, /marine, /suppliers) → network-first,
 *     cache fallback. Garante que o user vê SEMPRE os dados mais recentes
 *     quando online; em offline cai para a última versão cacheada.
 *   • Tudo o resto (POST, APIs, /chat) → fetch directo, NUNCA cacheado.
 *     Não queremos servir estado stale em endpoints transaccionais.
 *
 * Versionamento por nome do cache: bump CACHE_VERSION quando há
 * mudança de assets para forçar reinstalação.
 */

const CACHE_VERSION = 'clawyard-v2-2026-05-20-delete-fix';
const SHELL_CACHE   = `${CACHE_VERSION}-shell`;
const PAGE_CACHE    = `${CACHE_VERSION}-pages`;

// Pages que vale a pena tentar servir em offline (lista curta —
// outros pedidos GET caem para network passthrough).
const OFFLINE_PAGES = [
    '/dashboard',
    '/tenders',
    '/marine',
    '/suppliers',
];

self.addEventListener('install', (event) => {
    // Apenas pre-cache do offline fallback HTML simples. Os assets
    // do vite têm hash no nome — são cacheados ao serem fetched
    // pela primeira vez (runtime cache).
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const names = await caches.keys();
        await Promise.all(
            names
                .filter((n) => !n.startsWith(CACHE_VERSION))
                .map((n) => caches.delete(n))
        );
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (event) => {
    const req = event.request;

    // Só GETs entram em qualquer estratégia de cache. POST/PUT/DELETE
    // têm de ir directo à rede (estado transaccional).
    if (req.method !== 'GET') return;

    const url = new URL(req.url);

    // Same-origin only — não interferir com Anthropic API, fonts.bunny.net, etc.
    if (url.origin !== self.location.origin) return;

    // App shell: assets do vite (/build/**), fonts locais, imagens estáticas
    if (
        url.pathname.startsWith('/build/') ||
        url.pathname.startsWith('/images/') ||
        url.pathname.startsWith('/fonts/') ||
        /\.(css|js|woff2?|svg|png|jpg|jpeg|ico)$/i.test(url.pathname)
    ) {
        event.respondWith(cacheFirst(req, SHELL_CACHE));
        return;
    }

    // Dashboards principais: network-first com cache fallback
    if (OFFLINE_PAGES.some((p) => url.pathname === p || url.pathname.startsWith(p + '/'))) {
        // Excepção: /tenders/{id} edits etc. — só os list views.
        // Detecção simples: se o path tem mais segmentos que o base,
        // não cacheia.
        const base = OFFLINE_PAGES.find((p) => url.pathname.startsWith(p));
        if (base && url.pathname !== base) {
            return; // network passthrough
        }
        event.respondWith(networkFirst(req, PAGE_CACHE));
        return;
    }

    // Outros pedidos: passa directo à rede
});

async function cacheFirst(request, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);
    if (cached) return cached;
    try {
        const res = await fetch(request);
        // Só cacheia 2xx
        if (res.ok) cache.put(request, res.clone());
        return res;
    } catch (e) {
        // Sem rede e sem cache — devolve qualquer match parcial
        return cached || new Response('Offline', { status: 503 });
    }
}

async function networkFirst(request, cacheName) {
    const cache = await caches.open(cacheName);
    try {
        const res = await fetch(request);
        if (res.ok) cache.put(request, res.clone());
        return res;
    } catch (e) {
        const cached = await cache.match(request);
        if (cached) return cached;
        return new Response(
            '<h1>ClawYard offline</h1><p>Sem net e sem versão cacheada deste dashboard. Liga 4G/Wi-Fi.</p>',
            { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
        );
    }
}

// ── Web Push handlers ────────────────────────────────────────────────
// 'push' dispara quando o vendor entrega uma notification do nosso server.
// Payload é JSON com {title, body, url, tag, icon, badge}.
self.addEventListener('push', (event) => {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch (e) {
        payload = { title: 'ClawYard', body: event.data?.text?.() ?? '' };
    }

    const title = payload.title || 'ClawYard';
    const options = {
        body:    payload.body  || '',
        icon:    payload.icon  || '/images/clawyard-icon.svg',
        badge:   payload.badge || '/images/clawyard-icon.svg',
        tag:     payload.tag   || 'clawyard-notification',
        data:    { url: payload.url || '/' },
        // Vibrate em mobile (não interactivo em desktop).
        vibrate: [180, 80, 180],
    };
    event.waitUntil(self.registration.showNotification(title, options));
});

// 'notificationclick' — abre o URL do payload ou foca uma tab já aberta.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = (event.notification.data && event.notification.data.url) || '/';
    event.waitUntil((async () => {
        const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const c of all) {
            // Se já existe uma tab da app aberta, foca-a e navega.
            if (c.url.includes(self.location.origin)) {
                await c.focus();
                if ('navigate' in c) {
                    try { await c.navigate(target); } catch (_) { /* cross-origin block */ }
                }
                return;
            }
        }
        await self.clients.openWindow(target);
    })());
});
