const fs = require('fs');
const assert = require('assert');

const page = fs.readFileSync('frontend/index.html', 'utf8');
const transactions = fs.readFileSync('frontend/assets/js/transactions.js', 'utf8');
const styles = fs.readFileSync('frontend/assets/css/styles.css', 'utf8');

const header = page.slice(page.indexOf('id="transactions-datatable"'), page.indexOf('id="transactions-table-body"'));
for (const label of ['Date', 'Type', 'Category', 'Subcategory', 'Account / Party', 'Description', 'Amount', 'Actions']) {
    assert(header.includes(`>${label}</th>`), `missing ${label} table column`);
}
assert((header.match(/scope="col"/g) || []).length === 9, 'table headers are not semantic');
assert(page.includes('tx-table-toolbar') && page.includes('id="tx-scope-filter"'), 'scope is not aligned in the table toolbar');
assert(page.includes('<span class="tx-btn-label">Refresh</span>'), 'Refresh needs a visible desktop label');
assert(/tx-header-actions-secondary[\s\S]*?refresh-transactions-btn[\s\S]*?export-btn[\s\S]*?import-btn[\s\S]*?transaction-report-btn[\s\S]*?Open Full Ledger/.test(page), 'secondary transaction actions are not grouped together');

assert(transactions.includes("label: 'Credit Expense'") && transactions.includes("label: 'Debt Payment'") && transactions.includes("label: 'Money Lent'") && transactions.includes("label: 'Money Received'"), 'semantic transaction labels are incomplete');
assert(transactions.includes("t.category_name && t.category_name !== 'Karobar'"), 'synthetic Karobar category is not excluded');
assert(transactions.includes("const subcategoryName = t.subcategory_name || derivedSubcategory"), 'subcategory precedence is incorrect');
assert(transactions.includes('_accountPartyPresentation(t)'), 'Account / Party hierarchy is missing');
assert(transactions.includes("t.description || '—'") && transactions.includes('data-bs-toggle="tooltip"'), 'description fallback or full-text access is missing');
assert(transactions.includes("net < 0 ? '− ' : net > 0 ? '+ ' : ''"), 'net cash flow lacks an explicit direction sign');
assert(transactions.includes("presentation.direction === 'out' ? '− '"), 'row amounts lack an explicit outflow sign');

for (const label of ['View transaction', 'Edit transaction', 'Delete transaction']) {
    assert(transactions.includes(`aria-label="${label}"`), `${label} action lacks an accessible name`);
}
assert(styles.includes('height: 44px') && styles.includes('table-layout: fixed'), 'desktop density or column sizing is uncontrolled');
assert(styles.includes('.tx-party-primary') && styles.includes('.tx-party-secondary'), 'Account / Party hierarchy is not styled');
assert(styles.includes('.tx-mob-description') && styles.includes('.tx-mob-type-wrap'), 'mobile card hierarchy is incomplete');
assert(styles.includes(':focus-visible'), 'keyboard focus treatment is missing');
assert(/#transactions-page \.tx-table-toolbar \{[\s\S]*?border-radius:\s*10px 10px 0 0/.test(styles), 'scope toolbar is not visually connected to the table');
assert(/#transactions-page #tx-server-pagination \{[\s\S]*?border-top:\s*0[\s\S]*?border-radius:\s*0 0 10px 10px/.test(styles), 'server pagination is not visually connected to the table');
assert(/#transactions-page \.tx-table-wrapper \.dt-table-wrap \{[\s\S]*?overflow-x:\s*auto/.test(styles) && /min-width:\s*960px/.test(styles), 'narrow transaction tables must scroll within their own boundary');
assert(/th:nth-child\(7\) \{ width:\s*auto !important; \}/.test(styles), 'Description column must retain the flexible table width');
assert(/#transactions-page \.tx-money \{[\s\S]*?text-align:\s*right/.test(styles), 'Amount must remain prominent and right-aligned');
assert(!styles.includes('.tx-table-wrapper table.dataTable tbody tr:nth-child(even)'), 'zebra selection-like row background remains');

console.log('PASS: transaction table hierarchy, semantic labels, compact density, signed values, responsive cards, and accessibility are wired');
