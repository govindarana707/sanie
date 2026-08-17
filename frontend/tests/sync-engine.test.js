const fs = require('fs');
const vm = require('vm');

const events = new Map();
const records = [
    { localId: 'local_1', clientRequestId: '11111111-1111-4111-a111-111111111111', userId: '7', action: 'create', endpoint: '/transactions', payload: { type: 'expense', amount: 10 }, status: 'pending', retryCount: 0, createdAt: '2026-01-01T00:00:00Z' },
    { localId: 'local_2', clientRequestId: '22222222-2222-4222-a222-222222222222', userId: '7', action: 'create', endpoint: '/transactions', payload: { type: 'income', amount: 20 }, status: 'pending', retryCount: 0, createdAt: '2026-01-02T00:00:00Z' }
];
const submitted = [];
let nextId = 100;

class MockApi {
    static invalidateCache() {}
    isAuthenticated() { return true; }
    async post(endpoint, payload) {
        submitted.push({ endpoint, clientRequestId: payload.client_request_id });
        return { success: true, data: { id: ++nextId } };
    }
    async get(endpoint) {
        if (/^\/transactions\/\d+$/.test(endpoint)) return { success: true, data: { id: Number(endpoint.split('/').pop()) } };
        return { success: true, data: [{ id: nextId }] };
    }
}

const windowMock = {
    crypto: { randomUUID: () => '33333333-3333-4333-a333-333333333333' },
    Api: new MockApi(),
    authManager: { getCurrentUser: () => ({ id: 7 }), isAuthenticated: () => true },
    OfflineStorage: {
        resetStaleSyncingActions: async () => 0,
        countPendingActions: async () => records.length,
        getPendingActions: async () => [...records].sort((a, b) => a.createdAt.localeCompare(b.createdAt)),
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
    addEventListener: (name, handler) => events.set(name, handler),
    dispatchEvent: () => true,
    setTimeout,
    clearTimeout
};

const context = {
    window: windowMock,
    navigator: { onLine: true, locks: { request: async (name, options, callback) => callback({ name }) } },
    authAPI: { getMe: async () => ({ success: true, data: { id: 7 } }) },
    dashboardAPI: { getData: async () => ({ success: true, data: { statistics: {} } }) },
    document: { dispatchEvent: () => true },
    CustomEvent: class CustomEvent { constructor(type, init) { this.type = type; this.detail = init?.detail; } },
    BroadcastChannel: undefined,
    console,
    setTimeout,
    clearTimeout
};

vm.runInNewContext(fs.readFileSync('frontend/assets/js/sync-engine.js', 'utf8'), context);

(async () => {
    await windowMock.SanIESync.syncPendingActions({ trigger: 'test' });
    if (submitted.length !== 2) throw new Error(`Expected 2 submissions, received ${submitted.length}`);
    if (submitted[0].clientRequestId !== '11111111-1111-4111-a111-111111111111') throw new Error('Oldest action was not submitted first');
    if (submitted[1].clientRequestId !== '22222222-2222-4222-a222-222222222222') throw new Error('Second action order changed');
    if (records.length !== 0) throw new Error('Reconciled actions were not removed');
    process.stdout.write('PASS: sequential sync preserves IDs, reconciles, and clears confirmed actions\n');
})().catch(error => {
    process.stderr.write(`FAIL: ${error.message}\n`);
    process.exitCode = 1;
});
