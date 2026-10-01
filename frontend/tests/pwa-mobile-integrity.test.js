const assert = require('assert');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = relativePath => fs.readFileSync(path.join(root, relativePath), 'utf8');
const index = read('frontend/index.html');
const worker = read('frontend/service-worker.js');
const offline = read('frontend/offline.html');
const css = read('frontend/assets/css/styles.css');

for (const manifestPath of ['frontend/manifest.webmanifest', 'frontend/manifest.json']) {
    const manifest = JSON.parse(read(manifestPath));
    assert.strictEqual(manifest.start_url, './', `${manifestPath} needs a deployment-relative start URL`);
    assert.strictEqual(manifest.scope, './', `${manifestPath} needs a deployment-relative scope`);
    assert.strictEqual(manifest.display, 'standalone', `${manifestPath} must launch as a standalone app`);
    assert.strictEqual(manifest.orientation, 'portrait', `${manifestPath} must preserve mobile portrait launch`);
    assert.strictEqual(manifest.theme_color, '#0f172a', `${manifestPath} must match the status-bar theme`);
    assert.strictEqual(manifest.background_color, '#0f172a', `${manifestPath} must avoid a light dark-mode launch flash`);
    for (const icon of manifest.icons) {
        assert(fs.existsSync(path.join(root, 'frontend', icon.src)), `${manifestPath} is missing ${icon.src}`);
    }
}

assert(index.indexOf("localStorage.getItem('theme')") < index.indexOf('assets/css/styles.css'), 'Saved theme must apply before the app stylesheet loads');
assert(index.includes('viewport-fit=cover'), 'Main PWA entry needs viewport safe-area support');
assert(worker.includes("const CACHE_VERSION = 'v109'"), 'Service worker cache version must advance with the PWA release');
assert(worker.includes("if (isNetworkOnlyDataPath(requestURL.pathname)) return;"), 'Financial/API routes must remain network-only');
assert(worker.includes("return pathname.includes('/backend/') || pathname.includes('/api/');"), 'Service worker must not cache backend or API responses');
assert(worker.includes("filter(name => name.startsWith(SANIE_CACHE_PREFIX))"), 'Old app-shell caches must be cleared by prefix');
assert(!worker.includes("cache.put(request, response.clone())\n        }\n        return response;\n    } catch"), 'Unexpected broad cache write contract');
assert(offline.includes('color-scheme: light dark'), 'Offline page needs light and dark color-scheme support');
assert(offline.includes("localStorage.getItem('theme') === 'dark'"), 'Offline page must respect the saved dark theme');
assert(offline.includes('viewport-fit=cover'), 'Offline page needs safe-area support');
assert(offline.includes('safe-area-inset-bottom'), 'Offline page must keep actions above the gesture safe area');
assert(css.includes('env(safe-area-inset-bottom'), 'Mobile navigation must respect the gesture safe area');
assert(css.includes('max-height: 100dvh'), 'Mobile modals must remain within the dynamic viewport');
assert(css.includes('.pwa-update-actions .btn { min-height: 44px'), 'PWA update actions need a mobile-safe touch target');

console.log('PWA mobile integrity checks passed');
