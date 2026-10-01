const fs = require('fs');
const assert = require('assert');

const transactions = fs.readFileSync('frontend/assets/js/transactions.js', 'utf8');
const karobar = fs.readFileSync('frontend/assets/js/karobar.js', 'utf8');
const page = fs.readFileSync('frontend/index.html', 'utf8');
const styles = fs.readFileSync('frontend/assets/css/styles.css', 'utf8');
const reporting = fs.readFileSync('backend/services/ReportingPaginationService.php', 'utf8');
const controller = fs.readFileSync('backend/controllers/TransactionController.php', 'utf8');

assert(page.includes('id="tx-scope-filter"'), 'main transaction page has no scope filter');
for (const value of ['all', 'personal', 'karobar']) {
    assert(page.includes(`value="${value}"`), `scope option ${value} is missing`);
}
assert(transactions.includes("this.filters.scope = scope"), 'scope changes do not reach the transaction query');
assert(transactions.includes("t.scope === 'karobar'"), 'Karobar rows are not recognized by the renderer');
assert(transactions.includes("label: 'Credit Expense'") && transactions.includes("label: 'Debt Payment'"), 'Karobar rows lack meaningful single-badge labels');
const rowRenderer = transactions.slice(transactions.indexOf('_buildRow(t)'), transactions.indexOf('_karobarTypeLabel(type)'));
assert(!rowRenderer.includes('tx-scope-badge'), 'desktop rows still stack a Karobar badge beside the transaction type');
assert(transactions.includes('_accountPartyPresentation(transaction)') && transactions.includes('transaction?.person_name'), 'Karobar people are not mapped to Account / Party');
assert(styles.includes('.tx-scope-filter') && styles.includes('.tx-scope-badge'), 'scope UI styles are missing');

assert(transactions.includes("transaction?.source_table === 'karobar_transactions'"), 'standalone Karobar source routing is missing');
assert(transactions.includes("window.Api.get('/karobar/' + local.source_id)"), 'standalone detail does not use the Karobar API');
assert(transactions.includes('window.karobarManager.editTransaction(local.source_id)'), 'standalone edit does not reuse the Karobar editor');
assert(transactions.includes("window.Api.delete('/karobar/' + transaction.source_id)"), 'standalone delete does not use the Karobar lifecycle');
assert(karobar.includes("window.appRouter?.currentPage === 'transactions'"), 'Karobar mutations do not refresh the combined list');

assert(reporting.includes("CONCAT('karobar:',k.id)"), 'Karobar rows do not have collision-safe identities');
assert(reporting.includes("NOT EXISTS(SELECT 1 FROM transactions linked"), 'linked credit purchases are not collapsed');
assert(reporting.includes("CASE WHEN kt.id IS NULL THEN 'personal' ELSE 'karobar' END scope"), 'linked credit purchases lack Karobar provenance');
assert(controller.includes("['all', 'personal', 'karobar']"), 'API scope validation is missing');

const exportStart = transactions.indexOf('async exportCSV()');
const exportEnd = transactions.indexOf('_csvCell(value)', exportStart);
const exportBlock = transactions.slice(exportStart, exportEnd);
assert(exportBlock.includes("'Scope'") && exportBlock.includes("'Karobar Person'") && exportBlock.includes("'Karobar Type'"), 'CSV cannot distinguish personal and Karobar rows');
assert(transactions.includes('<th>Scope</th>') && transactions.includes('Category / Person'), 'print report cannot distinguish personal and Karobar rows');
assert(transactions.includes("params.set('scope', this.filters.scope || 'all')"), 'print report ignores the selected scope');

console.log('PASS: unified transaction scope, provenance, source-aware actions, duplicate collapse, exports, and reports are wired');
