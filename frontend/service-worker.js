// Deployment contract: bump this explicit version whenever any precached shell
// file changes. A new worker precaches the complete release before it waits.
const CACHE_VERSION = 'v109';
const STATIC_CACHE = `sanie-static-${CACHE_VERSION}`;
const RUNTIME_CACHE = `sanie-runtime-${CACHE_VERSION}`;
const SANIE_CACHE_PREFIX = 'sanie-';

const APP_ROOT = new URL('./', self.location);
const APP_ROOT_PATH = APP_ROOT.pathname;
const INDEX_PATH = new URL('index.html', APP_ROOT).pathname;
const OFFLINE_URL = new URL('offline.html', APP_ROOT).href;
const NETWORK_TIMEOUT_MS = 15000;

// Local application-shell files only. API responses and third-party
// resources are deliberately excluded.
const PRECACHE_URLS = [
    './',
    'index.html',
    'offline.html',
    'manifest.webmanifest',
    'assets/css/styles.css',
    'assets/css/karobar.css',
    'assets/css/category.css',
    'assets/favicon/favicon.ico',
    'assets/favicon/favicon-16x16.png',
    'assets/favicon/favicon-32x32.png',
    'assets/icons/apple-touch-icon.png',
    'assets/icons/icon-192.png',
    'assets/icons/icon-512.png',
    'assets/icons/icon-maskable-192.png',
    'assets/icons/icon-maskable-512.png',
    'assets/js/config.js',
    'assets/js/utils.js',
    'assets/js/offline-storage.js',
    'assets/js/services/notification.js',
    'assets/js/services/modal.js',
    'assets/js/services/datatable.js',
    'assets/js/services/chart.js',
    'assets/js/api.js',
    'assets/js/ajax.js',
    'assets/js/router.js',
    'assets/js/auth.js',
    'assets/js/sync-engine.js',
    'assets/js/pwa-controller.js',
    'assets/js/dashboard.js',
    'assets/js/transactions.js',
    'assets/js/recurring-transactions.js',
    'assets/js/budgets.js',
    'assets/js/goals.js',
    'assets/js/tasks.js',
    'assets/js/savings.js',
    'assets/js/categories.js',
    'assets/js/subcategories.js',
    'assets/js/analysis.js',
    'assets/js/reports.js',
    'assets/js/settings.js',
    'assets/js/notifications.js',
    'assets/js/karobar-api.js',
    'assets/js/karobar.js',
    'assets/js/account-details.js',
    'assets/js/accounts.js',
    'assets/js/app.js'
].map(path => new URL(path, APP_ROOT).href);

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(STATIC_CACHE).then(cache => cache.addAll(PRECACHE_URLS))
    );
});

self.addEventListener('message', event => {
    if (event.data?.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys()
            .then(cacheNames => Promise.all(
                cacheNames
                    .filter(name => name.startsWith(SANIE_CACHE_PREFIX))
                    .filter(name => name !== STATIC_CACHE && name !== RUNTIME_CACHE)
                    .map(name => caches.delete(name))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', event => {
    const request = event.request;

    // Mutations, API traffic, and cross-origin resources use the network
    // directly and are never written to SanIE caches.
    if (request.method !== 'GET') return;

    const requestURL = new URL(request.url);
    if (requestURL.origin !== self.location.origin) return;
    // Backup-like files and authenticated/data routes always stay outside the
    // service worker. In particular, a denied backup response must never be
    // replaced by a navigation fallback or written to Cache Storage.
    if (isSensitiveBackupPath(requestURL.pathname)) return;
    if (isNetworkOnlyDataPath(requestURL.pathname)) return;

    if (request.mode === 'navigate') {
        // Keep the active release's HTML paired with its cached JS/CSS until
        // the user explicitly activates a fully precached replacement worker.
        if (isAppEntry(requestURL.pathname)) {
            event.respondWith(handleAppEntry(request));
            return;
        }
        event.respondWith(handleNavigation(request, requestURL));
        return;
    }

    if (isStaticAsset(request, requestURL)) {
        event.respondWith(cacheFirst(request));
    }
});

async function handleAppEntry(request) {
    try {
        const staticCache = await caches.open(STATIC_CACHE);
        const cachedPage = await staticCache.match(request, { ignoreSearch: true })
            || await staticCache.match(new URL('./', APP_ROOT).href);
        if (cachedPage) return cachedPage;
    } catch (cacheError) {
        // Continue to the network when cache storage is unavailable.
    }

    try {
        return await fetchWithTimeout(request);
    } catch (error) {
        return await caches.match(OFFLINE_URL) || Response.error();
    }
}

async function handleNavigation(request, requestURL) {
    let response;

    try {
        response = await fetchWithTimeout(request);
    } catch (error) {
        try {
            return await caches.match(OFFLINE_URL) || Response.error();
        } catch (cacheError) {
            return Response.error();
        }
    }

    return response;
}

async function cacheFirst(request) {
    try {
        const staticCache = await caches.open(STATIC_CACHE);
        const staticResponse = await staticCache.match(request, { ignoreSearch: true });
        if (staticResponse) return staticResponse;
        const runtimeCache = await caches.open(RUNTIME_CACHE);
        const runtimeResponse = await runtimeCache.match(request, { ignoreSearch: true });
        if (runtimeResponse) return runtimeResponse;
    } catch (cacheError) {
        // Continue to the network when cache storage is unavailable.
    }

    try {
        const response = await fetchWithTimeout(request);
        if (response.ok && response.type === 'basic') {
            try {
                const cache = await caches.open(RUNTIME_CACHE);
                await cache.put(request, response.clone());
            } catch (cacheError) {
                // Continue with the successful network response.
            }
        }
        return response;
    } catch (error) {
        return Response.error();
    }
}

async function fetchWithTimeout(request) {
    const controller = new AbortController();
    const abortFromRequest = () => controller.abort(request.signal?.reason);
    if (request.signal?.aborted) abortFromRequest();
    else request.signal?.addEventListener?.('abort', abortFromRequest, { once: true });
    const timer = setTimeout(() => controller.abort(), NETWORK_TIMEOUT_MS);
    try {
        return await fetch(request, { signal: controller.signal });
    } finally {
        clearTimeout(timer);
        request.signal?.removeEventListener?.('abort', abortFromRequest);
    }
}

function isAppEntry(pathname) {
    return pathname === APP_ROOT_PATH || pathname === INDEX_PATH;
}

function isNetworkOnlyDataPath(pathname) {
    return pathname.includes('/backend/') || pathname.includes('/api/');
}

function isSensitiveBackupPath(pathname) {
    return /\.(?:sql|dump|backup|bak|gz|zip|tar)(?:$|[?#])/i.test(pathname)
        || /\.tar\.gz(?:$|[?#])/i.test(pathname);
}

function isStaticAsset(request, requestURL) {
    if (['style', 'script', 'image', 'font'].includes(request.destination)) {
        return true;
    }

    return /\.(?:css|js|png|jpg|jpeg|gif|svg|webp|ico|woff2?|webmanifest)$/i.test(requestURL.pathname);
}
