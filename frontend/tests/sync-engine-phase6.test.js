const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync('frontend/assets/js/sync-engine.js', 'utf8');
const utilityContext = { window: {}, Intl, Date, Object, String, Number, TypeError };
vm.createContext(utilityContext);
vm.runInContext(fs.readFileSync('frontend/assets/js/utils.js', 'utf8'), utilityContext);

async function runScenario(action, behavior) {
    const records = [{ ...action }];
    const requests = [];

    class MockApi {
        static invalidateCache() {}
        isAuthenticated() { return true; }
        async post(endpoint, payload) { return behavior('POST', endpoint, payload, requests); }
        async put(endpoint, payload) { return behavior('PUT', endpoint, payload, requests); }
        async delete(endpoint, payload) { return behavior('DELETE', endpoint, payload, requests); }
        async get(endpoint) {
            if (endpoint === '/transactions?') {
                return { success: true, data: action.action === 'delete' ? [] : [{ id: action.serverId, version: action.baseVersion + 1 }] };
            }
            return { success: true, data: { id: action.serverId, version: action.baseVersion + 1 } };
        }
    }

    const windowMock = {
        Api: new MockApi(),
        crypto: { randomUUID: () => '99999999-9999-4999-a999-999999999999' },
        authManager: { getCurrentUser: () => ({ id: 7 }), isAuthenticated: () => true },
        OfflineStorage: {
            resetStaleSyncingActions: async () => 0,
            countPendingActions: async () => records.length,
            getPendingActions: async () => [...records],
            getPendingAction: async localId => records.find(record => record.localId === localId),
            updatePendingAction: async (localId, updates) => {
                const record = records.find(item => item.localId === localId);
                if (!record) return false;
                Object.assign(record, updates);
                return true;
            },
            deletePendingAction: async localId => {
                const index = records.findIndex(record => record.localId === localId);
                if (index < 0) return false;
                records.splice(index, 1);
                return true;
            },
            saveTransactionSnapshot: async () => true,
            saveDashboardSnapshot: async () => true
        },
        addEventListener: () => {},
        dispatchEvent: () => true
    };
    const context = {
        window: windowMock,
        navigator: { onLine: true, locks: { request: async (name, options, callback) => callback({ name }) } },
        authAPI: { getMe: async () => ({ success: true, data: { id: 7 } }) },
        dashboardAPI: { getData: async () => ({ success: true, data: {} }) },
        document: { dispatchEvent: () => true },
        CustomEvent: class CustomEvent {},
        DateUtils: utilityContext.window.DateUtils,
        console,
        setTimeout,
        clearTimeout
    };
    vm.runInNewContext(source, context);
    await windowMock.SanIESync.syncPendingActions({ trigger: 'test' });
    return { records, requests };
}

(async () => {
    const base = {
        localId: 'local_update', userId: '7', serverId: 45, baseVersion: 3,
        clientRequestId: '44444444-4444-4444-a444-444444444444', endpoint: '/transactions/45',
        payload: { type: 'expense', amount: 700 }, status: 'pending', retryCount: 0
    };
    const updated = await runScenario({ ...base, action: 'update', method: 'PUT' }, async (method, endpoint, payload, requests) => {
        requests.push({ method, endpoint, payload });
        return { success: true, data: { id: 45, version: 4 } };
    });
    if (updated.requests[0].method !== 'PUT' || updated.requests[0].payload.base_version !== 3) throw new Error('Update did not send its base version');
    if (updated.records.length !== 0) throw new Error('Reconciled update remained queued');

    const deleted = await runScenario({ ...base, localId: 'local_delete', action: 'delete', method: 'DELETE', payload: {} }, async (method, endpoint, payload, requests) => {
        requests.push({ method, endpoint, payload });
        return { success: true, data: { id: 45 } };
    });
    if (deleted.requests[0].method !== 'DELETE' || deleted.requests[0].payload.base_version !== 3) throw new Error('Delete did not send its base version');
    if (deleted.records.length !== 0) throw new Error('Reconciled delete remained queued');

    const conflicted = await runScenario({ ...base, action: 'update', method: 'PUT' }, async () => {
        const error = new Error('This transaction was changed elsewhere.');
        error.status = 409;
        error.apiCode = 'CONFLICT';
        error.serverData = { id: 45, version: 4, amount: 900, type: 'expense', date: '2026-01-01' };
        throw error;
    });
    if (conflicted.records.length !== 1 || conflicted.records[0].status !== 'conflict') throw new Error('409 did not preserve a conflict action');
    if (conflicted.records[0].conflictData.version !== 4) throw new Error('Conflict server version was not retained');

    process.stdout.write('PASS: update/delete versions sync and 409 conflicts remain recoverable\n');
})().catch(error => {
    process.stderr.write(`FAIL: ${error.message}\n`);
    process.exitCode = 1;
});
