const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync('frontend/assets/js/api.js', 'utf8');
const karobarSource = fs.readFileSync('frontend/assets/js/karobar.js', 'utf8');

function response(status, body) {
    return {
        ok: status >= 200 && status < 300,
        status,
        text: async () => JSON.stringify(body)
    };
}

function makeClient(fetchImpl, token = 'saved-token') {
    const stored = new Map(token ? [['token', token]] : []);
    const events = [];
    const storage = {
        getItem: key => stored.get(key) || null,
        setItem: (key, value) => stored.set(key, value),
        removeItem: key => stored.delete(key)
    };
    const legacyStorage = { getItem: () => null, setItem() {}, removeItem() {} };
    const windowMock = {
        APP_CONFIG: { API_BASE: 'http://test/api' },
        sessionStorage: storage,
        localStorage: legacyStorage,
        dispatchEvent: event => events.push(event.type)
    };
    const context = {
        window: windowMock, fetch: fetchImpl, AbortController, FormData, URLSearchParams,
        CustomEvent: class CustomEvent { constructor(type) { this.type = type; } }, TypeError,
        setTimeout, clearTimeout, console: { error() {} }
    };
    vm.runInNewContext(source, context);
    return { client: windowMock.Api, stored, events };
}

(async () => {
    let attempts = 0;
    const retry = makeClient(async () => {
        attempts++;
        if (attempts === 1) throw new TypeError('temporary network failure');
        return response(200, { success: true, data: { ok: true } });
    });
    const retried = await retry.client.get('/read');
    if (!retried.success || attempts !== 2) throw new Error('Transient GET was not retried exactly once');

    const timeout = makeClient((url, config) => new Promise((resolve, reject) => {
        config.signal.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })));
    }));
    const timeoutStarted = Date.now();
    const timeoutError = await timeout.client.request('/hang', { method: 'GET', timeoutMs: 25, retries: 0 }).catch(error => error);
    if (timeoutError.category !== 'timeout_error' || timeoutError.code !== 'TIMEOUT_ERROR') throw new Error('Hung request was not classified as timeout');
    if (Date.now() - timeoutStarted > 500) throw new Error('Hung request did not settle promptly');
    if (!timeout.stored.has('token')) throw new Error('Timeout cleared the saved token');

    const cancelled = makeClient((url, config) => new Promise((resolve, reject) => {
        config.signal.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })));
    }));
    const controller = new AbortController();
    const cancelledRequest = cancelled.client.request('/cancel', { method: 'GET', signal: controller.signal, timeoutMs: 1000 });
    controller.abort();
    const abortError = await cancelledRequest.catch(error => error);
    if (abortError.category !== 'aborted_error' || abortError.retryable) throw new Error('Intentional abort was treated as a network retry');
    if (!cancelled.stored.has('token')) throw new Error('Intentional abort cleared authentication');

    const unavailable = makeClient(async () => response(503, { message: 'maintenance' }));
    const unavailableError = await unavailable.client.request('/auth/me', { method: 'GET', retries: 0 }).catch(error => error);
    if (unavailableError.category !== 'server_error' || !unavailableError.retryable) throw new Error('503 was not classified as retryable server availability failure');
    if (!unavailable.stored.has('token')) throw new Error('503 cleared the saved token');

    const invalid = makeClient(async () => response(401, { message: 'Invalid or expired token' }));
    const invalidError = await invalid.client.request('/auth/me', { method: 'GET', retries: 0 }).catch(error => error);
    if (invalidError.category !== 'auth_error' || invalid.stored.has('token')) throw new Error('Definitive 401 did not clear invalid authentication');
    if (!invalid.events.includes('auth:session-expired')) throw new Error('Definitive 401 did not trigger the auth lifecycle');

    let authorization = null;
    const rotated = makeClient(async (url, config) => {
        authorization = config.headers.Authorization;
        return response(200, { success: true, data: { id: 1 } });
    }, 'old-token');
    rotated.client.setToken('rotated-token');
    await rotated.client.post('/transactions', { client_request_id: 'stable-id' });
    if (authorization !== 'Bearer rotated-token') throw new Error('Request replay used stale authentication after token rotation');
    if (!karobarSource.includes('this._paymentRequestId || (this._paymentRequestId = this.createClientRequestId())')) throw new Error('Karobar retries do not retain their logical request ID');
    if (!karobarSource.includes("if (!this._editingTxId && ['repaid', 'returned'].includes(data.type)) this._paymentRequestId = null")) throw new Error('Karobar request ID does not rotate after confirmed success');

    process.stdout.write('PASS: Phase 12 API timeout, abort, retry, auth classification, and token rotation invariants\n');
})().catch(error => {
    process.stderr.write(`FAIL: ${error.stack || error.message}\n`);
    process.exitCode = 1;
});
