const fs=require('fs');
function assert(ok,message){if(!ok)throw new Error(message);}
const karobar=fs.readFileSync('frontend/assets/js/karobar.js','utf8');
const api=fs.readFileSync('frontend/assets/js/karobar-api.js','utf8');
const transactions=fs.readFileSync('frontend/assets/js/transactions.js','utf8');
const offline=fs.readFileSync('frontend/assets/js/offline-storage.js','utf8');

assert(karobar.includes('this.peoplePagination')&&karobar.includes('goToPeoplePage(page)'),'People view lacks server pagination state and controls');
assert(karobar.includes('this.profilePagination')&&karobar.includes('goToProfilePage(page)'),'Person ledger lacks Previous/Next page handling');
assert(karobar.includes('this.transactionsPagination')&&karobar.includes('goToTransactionsPage(page)'),'Karobar history lacks server pagination state and controls');
assert(karobar.includes('this.transactionsSummary=result.data?.summary')&&!karobar.includes("$('#karobar-transactions-table').DataTable"),'Karobar totals or navigation still depend on the capped client table');
assert(karobar.includes('for(let page=2;page<=pages;page++)')&&karobar.includes('exportProfileLedgerCSV'),'Person CSV export does not retrieve every matching history page');
assert(karobar.includes('history_context')&&karobar.includes('_monthlyContextData'),'Profile chart/timeline context is restricted to the visible page');
assert(api.includes('new URLSearchParams(params)'),'Person ledger filters and pagination are not sent to the backend');
assert(transactions.includes('Amount must be positive and use at most two decimal places')&&transactions.includes('\\d{1,2}'),'Transaction form lacks explicit precision validation');
assert(offline.includes("const amountText = String(payload.amount ?? '').trim()")&&offline.includes('\\d{1,2}'),'Offline queue can silently accept excessive precision');
process.stdout.write('PASS: People, person-ledger, Karobar pagination and ordinary/offline money precision UI contracts\n');
