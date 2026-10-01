const fs = require('fs');
const vm = require('vm');
const assert = require('assert');

const read = path => fs.readFileSync(path, 'utf8');
const cssFiles = [
    'frontend/assets/css/styles.css',
    'frontend/assets/css/category.css',
    'frontend/assets/css/karobar.css',
    'frontend/assets/css/transaction.css'
];
const css = cssFiles.map(read).join('\n');

assert(!/transition\s*:\s*all\b/i.test(css), 'A transition: all declaration remains');
assert(!/(?:backdrop-)?filter\s*:\s*blur\(/i.test(css), 'A runtime blur effect remains');
assert(css.includes('--motion-fast: 150ms') && css.includes('--motion-normal: 190ms'), 'Global motion tokens are missing');
assert(css.includes('@media (prefers-reduced-motion: reduce)') && css.includes('*::after'), 'Reduced-motion coverage is not global');

const index = read('frontend/index.html');
const externalScripts = [...index.matchAll(/<script\b[^>]*\bsrc="https?:\/\/[^>]+>/g)].map(match => match[0]);
assert(externalScripts.length > 0 && externalScripts.every(tag => /\bdefer\b/.test(tag)), 'A third-party script still blocks parsing');
for (const removed of ['countUp.umd.js', 'jszip.min.js', 'dataTables.buttons.min.js', 'buttons.html5.min.js']) {
    assert(!index.includes(removed), `${removed} is still loaded globally`);
}

const modal = read('frontend/assets/js/services/modal.js');
const app = read('frontend/assets/js/app.js');
const dashboard = read('frontend/assets/js/dashboard.js');
const notifications = read('frontend/assets/js/notifications.js');
const sync = read('frontend/assets/js/sync-engine.js');
const worker = read('frontend/service-worker.js');

assert(modal.includes('Modal.getOrCreateInstance'), 'Shared modal does not reuse its Bootstrap instance');
assert(!app.includes('}, 1000);'), 'Artificial one-second startup delay remains');
assert(dashboard.includes("removeEventListener('resize', this._viewportHandler)"), 'Dashboard resize listener is not removed');
assert(notifications.includes('this._pageController = new AbortController()'), 'Notification page listeners lack an abortable lifecycle');
assert(notifications.includes("document.visibilityState === 'visible'"), 'Notification polling is not visibility-aware');
assert(sync.includes('this._uiRefreshPending = true') && !sync.includes('if (window.transactionsManager) await window.transactionsManager.loadTransactions()'), 'Sync still refreshes hidden transaction UI per item');
assert(worker.indexOf('staticCache.match') < worker.indexOf('caches.open(RUNTIME_CACHE)'), 'Static cache hits still open the runtime cache');

async function verifyInflightGetCoalescing() {
    const apiSource = read('frontend/assets/js/api.js');
    let fetchCount = 0;
    const context = {
        window: {
            APP_CONFIG: { API_BASE: 'https://example.test/api' },
            sessionStorage: { getItem: () => 'token', setItem() {}, removeItem() {} },
            localStorage: { getItem: () => null, removeItem() {} },
            dispatchEvent() {}
        },
        fetch: async () => {
            fetchCount++;
            await new Promise(resolve => setTimeout(resolve, 10));
            return new Response(JSON.stringify({ success: true, data: [] }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' }
            });
        },
        console,
        Response,
        FormData,
        AbortController,
        CustomEvent: class CustomEvent {},
        setTimeout,
        clearTimeout,
        URLSearchParams
    };
    vm.runInNewContext(apiSource, context, { filename: 'api.js' });
    await Promise.all([
        context.window.Api.get('/accounts'),
        context.window.Api.get('/accounts')
    ]);
    assert.strictEqual(fetchCount, 1, 'Simultaneous identical GET requests were not coalesced');
    await context.window.Api.get('/accounts');
    assert.strictEqual(fetchCount, 1, 'Completed GET response cache was bypassed');
}

verifyInflightGetCoalescing()
    .then(() => process.stdout.write('PASS: full-system CSS, lifecycle, request, modal, sync, asset, and PWA smoothness invariants\n'))
    .catch(error => {
        console.error(`FAIL: ${error.message}`);
        process.exitCode = 1;
    });
