const fs = require('fs');
const vm = require('vm');
const assert = require('assert');

const APP_PATH = '/sanie/frontend/';
const LAN_ORIGIN = 'http://192.168.1.10';
const manifestPaths = [
    'frontend/manifest.webmanifest',
    'frontend/manifest.json',
    'manifest.webmanifest'
];

for (const path of manifestPaths) {
    const manifest = JSON.parse(fs.readFileSync(path, 'utf8'));
    assert.strictEqual(manifest.id, APP_PATH, `${path} has the wrong PWA identity`);
    assert.strictEqual(manifest.start_url, APP_PATH, `${path} has the wrong start_url`);
    assert.strictEqual(manifest.scope, APP_PATH, `${path} has the wrong scope`);
    assert.strictEqual(manifest.display, 'standalone', `${path} is not standalone`);
    assert(manifest.icons.length >= 2, `${path} is missing install icons`);
    for (const icon of manifest.icons) {
        assert(icon.src.startsWith(`${APP_PATH}assets/icons/`), `${path} has an icon outside the frontend path`);
        assert(fs.existsSync(icon.src.replace(/^\/sanie\//, '')), `${path} references a missing icon: ${icon.src}`);
    }
}

const index = fs.readFileSync('frontend/index.html', 'utf8');
const transaction = fs.readFileSync('frontend/transaction.php', 'utf8');
assert(index.includes('rel="manifest" href="/sanie/frontend/manifest.webmanifest"'), 'Main entry links the wrong manifest URL');
assert(transaction.includes('rel="manifest" href="/sanie/frontend/manifest.webmanifest"'), 'Transaction entry links the wrong manifest URL');
assert(index.includes('assets/js/pwa-controller.js?v=4'), 'PWA controller cache buster was not advanced');

const controllerSource = fs.readFileSync('frontend/assets/js/pwa-controller.js', 'utf8');
const registrations = [];
const windowEvents = {};
const serviceWorkerEvents = {};
const document = {
    readyState: 'complete',
    getElementById: () => null,
    querySelector: () => null,
    createElement: () => ({ setAttribute() {}, querySelector: () => null, hidden: true }),
    body: { appendChild() {} },
    head: { appendChild() {} }
};
const registration = {
    waiting: null,
    addEventListener() {},
    update: async () => undefined
};
const navigator = {
    onLine: true,
    userAgent: 'Android', platform: 'Linux', maxTouchPoints: 1,
    serviceWorker: {
        addEventListener: (name, handler) => { serviceWorkerEvents[name] = handler; },
        register: async (url, options) => {
            registrations.push({ url, options });
            return registration;
        }
    }
};
const window = {
    document, navigator, console,
    location: { origin: LAN_ORIGIN, reload() {} },
    sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    matchMedia: () => ({ matches: false, addEventListener() {} }),
    addEventListener: (name, handler) => { (windowEvents[name] ||= []).push(handler); },
    setTimeout: () => 1
};
vm.runInNewContext(controllerSource, { window, document, navigator, console, URL }, { filename: 'pwa-controller.js' });

(async () => {
    // Registration is asynchronous even when document.readyState is complete.
    await new Promise(resolve => setImmediate(resolve));
    assert.strictEqual(registrations.length, 1, 'Service worker was not registered exactly once');
    assert.strictEqual(registrations[0].url, `${LAN_ORIGIN}${APP_PATH}service-worker.js`);
    assert.strictEqual(registrations[0].options.scope, APP_PATH);

    const workerSource = fs.readFileSync('frontend/service-worker.js', 'utf8');
    assert(workerSource.includes("const CACHE_VERSION = 'v24'"), 'Corrected launch release did not advance cache version');

    const configSource = fs.readFileSync('frontend/assets/js/config.js', 'utf8');
    const configWindow = { location: { protocol: 'http:', host: '192.168.1.10', pathname: APP_PATH } };
    vm.runInNewContext(configSource, { window: configWindow });
    assert.strictEqual(configWindow.APP_CONFIG.API_BASE, `${LAN_ORIGIN}/sanie/backend/api`, 'LAN launch derives the wrong API base');

    process.stdout.write('PASS: PWA manifest, icons, LAN start URL, worker URL/scope, cache version, and API base are subdirectory-safe\n');
})().catch(error => {
    console.error(`FAIL: ${error.message}`);
    process.exit(1);
});
