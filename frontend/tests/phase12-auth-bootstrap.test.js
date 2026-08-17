const fs = require('fs');
const vm = require('vm');
const source = fs.readFileSync('frontend/assets/js/auth.js', 'utf8');

function element() {
    const listeners = {};
    return {
        hidden: true, disabled: false, textContent: '', innerHTML: '',
        style: { display: '', removeProperty(name) { if (name === 'display') this.display = ''; } },
        classList: { add() {}, remove() {}, toggle() {} },
        setAttribute(name) { if (name === 'hidden') this.hidden = true; },
        removeAttribute(name) { if (name === 'hidden') this.hidden = false; },
        addEventListener(name, handler) { listeners[name] = handler; },
        querySelector() { return element(); }, replaceChildren() {}, appendChild() {}, focus() {}
    };
}

async function scenario(initialBehavior) {
    let behavior = initialBehavior;
    const elements = new Map();
    for (const id of ['loading-screen', 'auth-screen', 'main-app', 'auth-availability-screen', 'auth-availability-message',
        'auth-availability-retry', 'login-form', 'register-form', 'logout-btn', 'user-name', 'user-email', 'sidebar-user-avatar']) {
        elements.set(id, element());
    }
    let clearCount = 0;
    let remembered = null;
    const windowListeners = new Map();
    const api = {
        token: 'saved-token', setToken(value) { this.token = value; },
        clearToken() { this.token = null; clearCount++; }, isAuthenticated() { return Boolean(this.token); }
    };
    const windowMock = {
        APP_CONFIG: { API_BASE: '/api' }, Api: api, OfflineStorage: {
            rememberIdentity(user) { remembered = user; }, clearRememberedIdentity() { remembered = null; }
        },
        addEventListener(name, handler) { windowListeners.set(name, handler); },
        dispatchEvent() {}, NotificationService: null
    };
    const context = {
        window: windowMock, api, authAPI: { getMe: () => behavior() }, navigator: { onLine: true },
        document: { getElementById: id => elements.get(id) || element(), querySelectorAll: () => [], createElement: () => element() },
        CustomEvent: class CustomEvent { constructor(type, init) { this.type = type; this.detail = init?.detail; } },
        APIError: class APIError extends Error { constructor(message, data = {}) { super(message); Object.assign(this, data); } },
        Swal: { fire: async () => ({ isConfirmed: false }) }, console, setTimeout, clearTimeout
    };
    vm.runInNewContext(source, context);
    await windowMock.authManager.initPromise;
    return {
        manager: windowMock.authManager, api, elements, getClearCount: () => clearCount,
        getRemembered: () => remembered, setBehavior: next => { behavior = next; }, windowListeners
    };
}

(async () => {
    const healthy = await scenario(async () => ({ success: true, data: { id: 7, first_name: 'A', last_name: 'B', email: 'a@b.test' } }));
    if (healthy.manager.getAuthState() !== 'AUTHENTICATED' || healthy.api.token !== 'saved-token') throw new Error('Healthy startup did not authenticate and retain token');

    const invalid = await scenario(async () => { throw Object.assign(new Error('expired'), { status: 401, category: 'auth_error' }); });
    if (invalid.manager.getAuthState() !== 'UNAUTHENTICATED' || invalid.api.token !== null || invalid.getClearCount() < 1) throw new Error('Invalid startup token was not cleared');

    const timeout = await scenario(async () => { throw Object.assign(new Error('timeout'), { code: 'TIMEOUT_ERROR', category: 'timeout_error' }); });
    if (timeout.manager.getAuthState() !== 'UNKNOWN' || timeout.api.token !== 'saved-token') throw new Error('Startup timeout falsely logged the user out');
    if (timeout.elements.get('auth-availability-screen').hidden) throw new Error('Startup timeout did not show a recoverable state');
    timeout.setBehavior(async () => ({ success: true, data: { id: 7, first_name: 'A', last_name: 'B', email: 'a@b.test' } }));
    await timeout.manager.verifySavedSession();
    if (timeout.manager.getAuthState() !== 'AUTHENTICATED') throw new Error('Session did not recover after temporary outage');

    const network = await scenario(async () => { throw Object.assign(new TypeError('network'), { code: 'NETWORK_ERROR', category: 'network_error' }); });
    if (network.manager.getAuthState() !== 'UNKNOWN' || network.api.token !== 'saved-token') throw new Error('Network failure falsely cleared the saved token');
    network.setBehavior(async () => { throw Object.assign(new Error('revoked'), { status: 401, category: 'auth_error' }); });
    await network.manager.verifySavedSession();
    if (network.manager.getAuthState() !== 'UNAUTHENTICATED' || network.api.token !== null) throw new Error('Revoked token was not cleared after connectivity recovery');

    const unavailable = await scenario(async () => { throw Object.assign(new Error('unavailable'), { status: 503, category: 'server_error' }); });
    if (unavailable.manager.getAuthState() !== 'UNKNOWN' || unavailable.api.token !== 'saved-token') throw new Error('503 falsely cleared the saved token');

    process.stdout.write('PASS: Phase 12 startup authentication preserves uncertain sessions and recovers correctly\n');
})().catch(error => {
    process.stderr.write(`FAIL: ${error.message}\n`);
    process.exitCode = 1;
});
