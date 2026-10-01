const fs = require('fs');
const assert = require('assert');

const ui = fs.readFileSync('frontend/assets/js/recurring-transactions.js', 'utf8');
const api = fs.readFileSync('frontend/assets/js/api.js', 'utf8');
const notifications = fs.readFileSync('frontend/assets/js/notifications.js', 'utf8');
const app = fs.readFileSync('frontend/assets/js/app.js', 'utf8');
const html = fs.readFileSync('frontend/index.html', 'utf8');
const worker = fs.readFileSync('frontend/service-worker.js', 'utf8');
const offline = fs.readFileSync('frontend/assets/js/offline-storage.js', 'utf8');

for (const endpoint of ['/recurring-transactions', '/process', '/reconcile', '/activate', '/deactivate', '/review']) {
    assert(api.includes(endpoint), `Missing recurring API path: ${endpoint}`);
}
assert(ui.includes("['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday']"), 'ISO weekday controls are missing or reordered');
assert(ui.includes('data-review-date') && ui.includes("action: select.value"), 'Authoritative Generate/Skip review UI is missing');
assert(ui.includes('confirm_schedule_change'), 'Schedule edits do not send explicit confirmation');
assert(ui.includes('Process Due Transactions') || html.includes('Process Due Transactions'), 'Manual process action is missing');
assert(notifications.includes('recurringTransactionsAPI.processDue()'), 'Authenticated notification polling does not invoke the session processor');
assert(app.includes("registerRoute('recurring-transactions'"), 'Recurring route is not registered');
assert(html.includes('recurring-transactions-page') && html.includes('recurring-transactions.js'), 'Recurring page or script is missing');
assert(worker.includes("'assets/js/recurring-transactions.js'"), 'Recurring UI is absent from the application shell');
assert(!offline.includes('recurring-transactions') && !offline.includes('recurring_occurrence'), 'Recurring financial execution was added to offline storage');

console.log('PASS: recurring UI, authoritative review, session/manual processing, and offline exclusion are intact');
