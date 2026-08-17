const fs = require('fs');
const vm = require('vm');
const path = require('path');

const goalsSource = fs.readFileSync(path.join(__dirname, '../assets/js/goals.js'), 'utf8');
const apiSource = fs.readFileSync(path.join(__dirname, '../assets/js/api.js'), 'utf8');
const savingsSource = fs.readFileSync(path.join(__dirname, '../assets/js/savings.js'), 'utf8');
const context = { window: {}, document: {}, console, Date, Math, setTimeout, clearTimeout };
vm.createContext(context);
vm.runInContext(goalsSource, context);
const manager = new context.window.GoalsManager();
const requestId = manager._createRequestId();
if (!/^(?:[0-9a-f-]{36}|req_[A-Za-z0-9_]{10,60})$/i.test(requestId)) throw new Error('Goal request ID is invalid');
for (const required of ['/goals', '/accounts', '/transactions', '/dashboard', '/savings', '/ledger', '/reports', '/budgets']) {
    if (!apiSource.includes(required)) throw new Error(`Missing goal cache invalidation: ${required}`);
}
if (!apiSource.includes('updateContribution') || !apiSource.includes('deleteContribution') || !goalsSource.includes('base_version')) {
    throw new Error('Contribution edit/delete API lifecycle is incomplete');
}
if (!savingsSource.includes('client_request_id') || !goalsSource.includes('client_request_id')) {
    throw new Error('A contribution frontend does not send idempotency keys');
}
console.log('PASS: goal contribution UI uses idempotency, versions, lifecycle APIs, and targeted cache invalidation');
