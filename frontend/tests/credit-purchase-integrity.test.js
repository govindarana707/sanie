const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', 'assets', 'js');
const transactions = fs.readFileSync(path.join(root, 'transactions.js'), 'utf8');
const karobar = fs.readFileSync(path.join(root, 'karobar.js'), 'utf8');
const api = fs.readFileSync(path.join(root, 'karobar-api.js'), 'utf8');

if (!transactions.includes('_createCreditPurchaseRequestId') || !transactions.includes('data.client_request_id')) {
    throw new Error('Credit purchase creation does not send an idempotency key');
}
if (!transactions.includes('t.creditor_id') || !transactions.includes('t.due_date')) {
    throw new Error('Credit purchase editor does not restore linked creditor/due-date fields');
}
if (!transactions.includes('_invalidateCreditPurchaseCaches') || !api.includes('invalidateCreditPurchaseCaches')) {
    throw new Error('Credit purchase lifecycle does not invalidate targeted financial caches');
}
if (!karobar.includes('data.base_version = this._editingTxVersion') || !api.includes('{ base_version: version }')) {
    throw new Error('Karobar linked edits/deletes do not send optimistic versions');
}
if (!karobar.includes("data.payment_method = 'credit'") || !karobar.includes('data.account_id = null')) {
    throw new Error('Karobar editor does not preserve no-cash credit semantics');
}

console.log('PASS: credit purchase UI uses idempotency, paired versions, no-cash semantics, and targeted cache invalidation');
