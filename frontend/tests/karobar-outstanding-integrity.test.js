const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', 'assets', 'js');
const karobar = fs.readFileSync(path.join(root, 'karobar.js'), 'utf8');
const api = fs.readFileSync(path.join(root, 'karobar-api.js'), 'utf8');

if (!karobar.includes('tx.outstanding_amount') || !karobar.includes('tx.original_amount')) {
    throw new Error('Karobar reports do not distinguish original and outstanding amounts');
}
if (karobar.includes("tx.due_date && new Date(tx.due_date) < new Date()")) {
    throw new Error('A report still computes overdue status in the browser');
}
if (!karobar.includes('tx.is_overdue')) {
    throw new Error('Reports do not consume the authoritative server overdue flag');
}
if (!karobar.includes('person.receivable_outstanding') || !karobar.includes('person.payable_outstanding')) {
    throw new Error('Person views do not show receivable and payable separately');
}
if (!karobar.includes("karobarAPI.getCreditReports({ report_type: 'all' })")) {
    throw new Error('General reports still use unallocated transaction principals');
}
for (const pattern of ['/karobar', '/people', '/dashboard', '/reports']) {
    if (!api.includes(`'${pattern}'`)) throw new Error(`Mutation cache invalidation is missing ${pattern}`);
}

console.log('PASS: Karobar UI uses authoritative remaining amounts, overdue flags, separate positions, and cache invalidation');
