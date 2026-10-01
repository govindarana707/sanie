const fs = require('fs');
const vm = require('vm');
const assert = require('assert');

const LOCAL_PATH = '/sanie/frontend/';
const LAN_ORIGIN = 'http://192.168.1.10';
const manifestPaths = [
    'frontend/manifest.webmanifest',
    'frontend/manifest.json',
    'manifest.webmanifest'
];

for (const path of manifestPaths) {
    const manifest = JSON.parse(fs.readFileSync(path, 'utf8'));
    assert.strictEqual(manifest.id, './', `${path} has a deployment-specific PWA identity`);
    assert.strictEqual(manifest.start_url, './', `${path} has a deployment-specific start_url`);
    assert.strictEqual(manifest.scope, './', `${path} has a deployment-specific scope`);
    assert.strictEqual(manifest.display, 'standalone', `${path} is not standalone`);
    assert(manifest.icons.length >= 2, `${path} is missing install icons`);
    for (const icon of manifest.icons) {
        assert(icon.src.startsWith('assets/icons/'), `${path} has an icon outside the deployed frontend`);
        assert(fs.existsSync(`frontend/${icon.src}`), `${path} references a missing icon: ${icon.src}`);
    }
}

const index = fs.readFileSync('frontend/index.html', 'utf8');
const transaction = fs.readFileSync('frontend/transaction.php', 'utf8');
assert(index.includes('rel="manifest" href="manifest.webmanifest"'), 'Main entry manifest is not deployment-relative');
assert(transaction.includes('rel="manifest" href="manifest.webmanifest"'), 'Transaction entry manifest is not deployment-relative');
assert(index.includes('assets/js/pwa-controller.js?v=6'), 'PWA controller cache buster was not advanced');

const controllerSource = fs.readFileSync('frontend/assets/js/pwa-controller.js', 'utf8');

async function registrationFor(baseURI) {
    const registrations = [];
    const document = {
        baseURI,
        readyState: 'complete',
        getElementById: () => null,
        querySelector: () => null,
        createElement: () => ({ setAttribute() {}, querySelector: () => null, hidden: true }),
        body: { appendChild() {} },
        head: { appendChild() {} }
    };
    const registration = { waiting: null, addEventListener() {}, update: async () => undefined };
    const navigator = {
        onLine: true,
        userAgent: 'Android', platform: 'Linux', maxTouchPoints: 1,
        serviceWorker: {
            addEventListener() {},
            register: async (url, options) => {
                registrations.push({ url, options });
                return registration;
            }
        }
    };
    const window = {
        document, navigator, console,
        location: { href: baseURI, origin: new URL(baseURI).origin, reload() {} },
        sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
        matchMedia: () => ({ matches: false, addEventListener() {} }),
        addEventListener() {},
        setTimeout: () => 1
    };
    vm.runInNewContext(controllerSource, { window, document, navigator, console, URL }, { filename: 'pwa-controller.js' });
    await new Promise(resolve => setImmediate(resolve));
    return registrations[0];
}

(async () => {
    const local = await registrationFor(`${LAN_ORIGIN}${LOCAL_PATH}`);
    assert.strictEqual(local.url, `${LAN_ORIGIN}${LOCAL_PATH}service-worker.js`);
    assert.strictEqual(local.options.scope, LOCAL_PATH);

    const production = await registrationFor('https://sanie.govindarana.com.np/');
    assert.strictEqual(production.url, 'https://sanie.govindarana.com.np/service-worker.js');
    assert.strictEqual(production.options.scope, '/');

    const workerSource = fs.readFileSync('frontend/service-worker.js', 'utf8');
    assert(workerSource.includes("const CACHE_VERSION = 'v109'"), 'Root-compatible release did not advance cache version');

    const configSource = fs.readFileSync('frontend/assets/js/config.js', 'utf8');
    const localWindow = { location: { protocol: 'http:', host: '192.168.1.10', pathname: LOCAL_PATH } };
    vm.runInNewContext(configSource, { window: localWindow });
    assert.strictEqual(localWindow.APP_CONFIG.API_BASE, `${LAN_ORIGIN}/sanie/backend/api`, 'Local subdirectory derives the wrong API base');

    const productionWindow = { location: { protocol: 'https:', host: 'sanie.govindarana.com.np', pathname: '/' } };
    vm.runInNewContext(configSource, { window: productionWindow });
    assert.strictEqual(productionWindow.APP_CONFIG.API_BASE, 'https://sanie.govindarana.com.np/backend/api', 'Production root derives the wrong API base');

    process.stdout.write('PASS: root production and local subdirectory PWA/API paths are deployment-safe\n');
})().catch(error => {
    console.error(`FAIL: ${error.message}`);
    process.exit(1);
});
