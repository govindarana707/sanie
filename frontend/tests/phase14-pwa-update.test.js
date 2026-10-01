const fs = require('fs');
const vm = require('vm');
const assert = require('assert');

const workerSource = fs.readFileSync('frontend/service-worker.js', 'utf8');
const controllerSource = fs.readFileSync('frontend/assets/js/pwa-controller.js', 'utf8');

function createWorkerHarness() {
    const listeners = {};
    const deletedCaches = [];
    const cacheData = new Map();
    let claimed = 0;
    let skipped = 0;

    function cache(name) {
        if (!cacheData.has(name)) cacheData.set(name, new Map());
        const entries = cacheData.get(name);
        return {
            async addAll(urls) {
                urls.forEach(url => entries.set(String(url), { release: name, url: String(url) }));
            },
            async match(request, options = {}) {
                const key = typeof request === 'string' ? request : request.url;
                if (entries.has(key)) return entries.get(key);
                if (options.ignoreSearch) {
                    const target = String(key).split('?')[0];
                    for (const [stored, value] of entries) {
                        if (stored.split('?')[0] === target) return value;
                    }
                }
                return undefined;
            },
            async put(request, response) {
                entries.set(request.url || String(request), response);
            }
        };
    }

    cacheData.set('sanie-static-v22', new Map([
        ['https://example.test/sanie/frontend/assets/js/app.js', { release: 'v22' }]
    ]));
    cacheData.set('sanie-runtime-v21', new Map());
    cacheData.set('other-product-cache', new Map());

    const context = {
        URL, AbortController, setTimeout, clearTimeout,
        Response: global.Response,
        fetch: async request => ({ ok: true, type: 'basic', request }),
        caches: {
            open: async name => cache(name),
            keys: async () => [...cacheData.keys()],
            delete: async name => { deletedCaches.push(name); cacheData.delete(name); return true; }
        },
        self: {
            location: new URL('https://example.test/sanie/frontend/service-worker.js'),
            addEventListener: (name, handler) => { listeners[name] = handler; },
            skipWaiting: () => { skipped++; },
            clients: { claim: async () => { claimed++; } }
        }
    };
    vm.runInNewContext(workerSource, context, { filename: 'service-worker.js' });
    return { listeners, deletedCaches, cacheData, get claimed() { return claimed; }, get skipped() { return skipped; } };
}

async function dispatchWaitable(handler, event = {}) {
    let task;
    handler({ ...event, waitUntil: promise => { task = Promise.resolve(promise); } });
    await task;
}

function createControllerHarness() {
    const windowEvents = {};
    const serviceWorkerEvents = {};
    const session = new Map([['auth_token', 'valid-jwt'], ['unrelated-state', 'keep-me']]);
    let reloads = 0;
    let posted = 0;
    const queued = { localId: 'local-1', requestId: 'idem-1', status: 'pending' };

    const document = {
        readyState: 'loading',
        baseURI: 'https://example.test/sanie/frontend/',
        getElementById: () => null,
        querySelector: () => null,
        createElement: () => ({
            setAttribute() {}, hidden: true, className: '', id: '', innerHTML: '',
            querySelector: () => null
        }),
        body: { appendChild() {} },
        head: { appendChild() {} }
    };
    const window = {
        document,
        navigator: null,
        location: { reload: () => { reloads++; } },
        sessionStorage: {
            getItem: key => session.has(key) ? session.get(key) : null,
            setItem: (key, value) => session.set(key, String(value)),
            removeItem: key => session.delete(key)
        },
        matchMedia: () => ({ matches: false, addEventListener() {} }),
        addEventListener: (name, handler) => { (windowEvents[name] ||= []).push(handler); },
        setTimeout: () => 1,
        OfflineStorage: {
            countPendingActions: async () => 1,
            getPendingAction: async () => queued
        },
        SanIESync: { getSyncState: () => ({ state: 'idle', isSyncing: false }) },
        console
    };
    const navigator = {
        onLine: false,
        userAgent: 'test', platform: 'test', maxTouchPoints: 0,
        serviceWorker: { addEventListener: (name, handler) => { serviceWorkerEvents[name] = handler; } }
    };
    window.navigator = navigator;
    const context = { window, document, navigator, console, URL };
    vm.runInNewContext(controllerSource, context, { filename: 'pwa-controller.js' });
    const controller = window.SanIEPWA;
    const worker = { postMessage: message => { if (message?.type === 'SKIP_WAITING') posted++; } };
    controller.registration = { waiting: worker };
    controller.waitingWorker = worker;
    return {
        controller, queued, session, serviceWorkerEvents,
        get reloads() { return reloads; }, get posted() { return posted; }
    };
}

(async () => {
    const sw = createWorkerHarness();
    await dispatchWaitable(sw.listeners.install);
    assert(sw.cacheData.has('sanie-static-v109'), 'Version B static cache was not created');
    assert.strictEqual(sw.skipped, 0, 'Worker activated immediately instead of waiting for user approval');

    sw.listeners.message({ data: { type: 'SKIP_WAITING' } });
    assert.strictEqual(sw.skipped, 1, 'Approved update did not trigger skipWaiting');

    await dispatchWaitable(sw.listeners.activate);
    assert.deepStrictEqual(sw.deletedCaches.sort(), ['sanie-runtime-v21', 'sanie-static-v22']);
    assert(sw.cacheData.has('other-product-cache'), 'Activation deleted an unrelated cache');
    assert.strictEqual(sw.claimed, 1, 'Activated worker did not claim clients');

    let staticResponse;
    sw.listeners.fetch({
        request: { method: 'GET', url: 'https://example.test/sanie/frontend/assets/js/app.js?v=8', destination: 'script', signal: null },
        respondWith: promise => { staticResponse = Promise.resolve(promise); }
    });
    assert.strictEqual((await staticResponse).release, 'sanie-static-v109', 'Version B served an old static asset');

    for (const url of [
        'https://example.test/sanie/backend/api/accounts',
        'https://example.test/sanie/backend/backups/sanie-db-test.sql',
        'https://example.test/sanie/frontend/export.backup'
    ]) {
        let intercepted = false;
        sw.listeners.fetch({
            request: { method: 'GET', url, mode: 'navigate', destination: 'document' },
            respondWith: () => { intercepted = true; }
        });
        assert.strictEqual(intercepted, false, `${url} was intercepted by the service worker`);
    }

    const ui = createControllerHarness();
    const queuedBefore = JSON.stringify(ui.queued);
    await ui.controller.applyUpdate();
    assert.strictEqual(ui.posted, 1, 'Offline installed update was not user-activatable');
    assert.strictEqual(JSON.stringify(ui.queued), queuedBefore, 'Update changed the queued idempotent mutation');
    assert.strictEqual(ui.session.get('auth_token'), 'valid-jwt', 'Update cleared valid authentication state');

    ui.serviceWorkerEvents.controllerchange();
    ui.serviceWorkerEvents.controllerchange();
    assert.strictEqual(ui.reloads, 1, 'A single activation caused more than one reload');
    assert.strictEqual(ui.session.get('auth_token'), 'valid-jwt', 'Reload guard altered authentication state');
    assert.strictEqual(ui.queued.requestId, 'idem-1', 'Idempotency ID changed during update');

    process.stdout.write('PASS: Phase 14 waiting update, cache cleanup, reload guard, API/backup exclusion, auth, and offline queue preservation\n');
})().catch(error => {
    console.error(`FAIL: ${error.message}`);
    process.exit(1);
});
