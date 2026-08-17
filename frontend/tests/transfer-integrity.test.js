const fs = require('fs');
const vm = require('vm');
const path = require('path');

const context = {
    window: {},
    document: {},
    navigator: { onLine: true },
    console,
    setTimeout,
    clearTimeout,
    Math,
    Date,
};
vm.createContext(context);
const source = fs.readFileSync(path.join(__dirname, '../assets/js/transactions.js'), 'utf8');
vm.runInContext(source, context);

const invalidated = [];
context.window.Api = {
    constructor: {
        invalidateCache(pattern) { invalidated.push(pattern); }
    }
};
const manager = new context.window.TransactionsManager();
manager._invalidateTransferCaches();

for (const required of ['/transactions', '/accounts', '/dashboard', '/ledger', '/reports', '/budgets']) {
    if (!invalidated.includes(required)) throw new Error(`Missing transfer cache invalidation: ${required}`);
}
const requestId = manager._createTransferRequestId();
if (!/^(?:[0-9a-f-]{36}|req_[A-Za-z0-9_]{10,60})$/i.test(requestId)) {
    throw new Error(`Generated transfer request ID is invalid: ${requestId}`);
}

console.log('PASS: transfer request IDs and targeted cache invalidation are wired');
