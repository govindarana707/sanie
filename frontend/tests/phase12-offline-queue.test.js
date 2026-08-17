const fs = require('fs');
const vm = require('vm');
const source = fs.readFileSync('frontend/assets/js/sync-engine.js', 'utf8');

async function runScenario(thrownError, initial = {}) {
    let calls = 0;
    let staleRecoveries = 0;
    const record = {
        localId: 'local_phase12', clientRequestId: 'stable-request-id', userId: '7', entityType: 'transaction',
        action: 'create', endpoint: '/transactions', method: 'POST', payload: { type: 'expense', amount: 50 },
        status: 'pending', retryCount: 0, createdAt: '2026-08-17T00:00:00Z', ...initial
    };
    const records = [record];
    class MockApi {
        static invalidateCache() {}
        isAuthenticated() { return true; }
        async post(endpoint, payload) {
            calls++;
            if (payload.client_request_id !== 'stable-request-id') throw new Error('Stable request ID changed during replay');
            if (thrownError) throw thrownError;
            return { success: true, data: { id: 91 } };
        }
        async get(endpoint) {
            if (endpoint === '/transactions?') return { success: true, data: [{ id: 91 }] };
            return { success: true, data: { id: 91 } };
        }
    }
    const offlineStorage = {
        resetStaleSyncingActions: async () => {
            if (record.status === 'syncing') { record.status = 'pending'; staleRecoveries++; }
            return staleRecoveries;
        },
        countPendingActions: async () => records.length,
        getPendingActions: async () => [...records],
        getPendingAction: async id => records.find(item => item.localId === id),
        updatePendingAction: async (id, update) => { const item = records.find(row => row.localId === id); if (!item) return false; Object.assign(item, update); return true; },
        deletePendingAction: async id => { const index = records.findIndex(item => item.localId === id); if (index < 0) return false; records.splice(index, 1); return true; },
        saveTransactionSnapshot: async () => true,
        saveDashboardSnapshot: async () => true,
        saveMetadata: async () => true
    };
    const windowMock = {
        Api: new MockApi(), OfflineStorage: offlineStorage,
        authManager: { getCurrentUser: () => ({ id: 7 }), isAuthenticated: () => true },
        crypto: { randomUUID: () => 'new-id-must-not-be-used' },
        addEventListener() {}, dispatchEvent() {}, refreshPendingSyncStatus() {}
    };
    const context = {
        window: windowMock, navigator: { onLine: true, locks: { request: async (name, options, callback) => callback({ name }) } },
        authAPI: { getMe: async () => ({ success: true, data: { id: 7 } }) },
        document: { dispatchEvent() {} }, CustomEvent: class CustomEvent {},
        setTimeout: () => 1, clearTimeout() {}, console
    };
    vm.runInNewContext(source, context);
    await windowMock.SanIESync.syncPendingActions({ trigger: 'phase12-test' });
    return { record, records, calls, staleRecoveries, state: windowMock.SanIESync.getSyncState(), engine: windowMock.SanIESync };
}

(async () => {
    const timeout = await runScenario(Object.assign(new Error('timeout'), { category: 'timeout_error', code: 'TIMEOUT_ERROR' }));
    if (timeout.record.status !== 'pending' || timeout.record.retryCount !== 1) throw new Error('Timed-out replay remained processing or lost retry state');
    if (!timeout.record.nextAttemptAt) throw new Error('Timed-out replay has no bounded backoff');

    const validation = await runScenario(Object.assign(new Error('invalid'), { status: 422, category: 'validation_error' }));
    if (validation.record.status !== 'failed' || validation.record.retryCount !== 1) throw new Error('Permanent validation failure was not quarantined');
    await validation.engine.syncPendingActions({ trigger: 'automatic-repeat' });
    if (validation.calls !== 1) throw new Error('Permanent validation failure retried automatically');

    const auth = await runScenario(Object.assign(new Error('expired'), { status: 401, category: 'auth_error' }));
    if (auth.record.status !== 'pending' || auth.records.length !== 1) throw new Error('Auth failure discarded a queued financial operation');
    if (auth.state.state !== 'auth-required') throw new Error('Auth failure did not pause the queue');

    const recovered = await runScenario(null, { status: 'syncing', lastAttemptAt: '2026-01-01T00:00:00Z' });
    if (recovered.staleRecoveries !== 1 || recovered.records.length !== 0) throw new Error('Stale processing item was not recovered and replayed');

    const aborted = await runScenario(Object.assign(new Error('cancelled'), { category: 'aborted_error', code: 'ABORTED_ERROR' }));
    if (aborted.record.status !== 'pending' || aborted.record.retryCount !== 0 || aborted.record.lastError) throw new Error('Intentional cancellation was treated as a failed replay');

    process.stdout.write('PASS: Phase 12 queue timeout, permanent failure, auth pause, restart recovery, abort, FIFO ID invariants\n');
})().catch(error => {
    process.stderr.write(`FAIL: ${error.message}\n`);
    process.exitCode = 1;
});
