const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const assets = path.resolve(__dirname, '..', 'assets', 'js');
const utilsSource = fs.readFileSync(path.join(assets, 'utils.js'), 'utf8');
const accountSource = fs.readFileSync(path.join(assets, 'account-details.js'), 'utf8');
const ledgerSource = fs.readFileSync(path.join(assets, 'transaction.js'), 'utf8');
const reportsSource = fs.readFileSync(path.join(assets, 'reports.js'), 'utf8');

const context = vm.createContext({
    window: {}, console, Intl, Date, URL, URLSearchParams, Blob,
    document: {
        createElement: () => ({ textContent: '', innerHTML: '' }),
        addEventListener: () => {},
        getElementById: () => null,
        querySelector: () => null
    },
    setTimeout, clearTimeout, AbortController
});
vm.runInContext(utilsSource, context);
context.DateUtils = context.window.DateUtils;
context.Formatters = context.window.Formatters;
context.CSVUtils = context.window.CSVUtils;
vm.runInContext(accountSource, context);
vm.runInContext(reportsSource, context);

async function statementScenario(total, filters = {}) {
    const calls = [];
    context.accountsAPI = {
        async getStatement(accountId, query) {
            calls.push({ accountId, query: { ...query } });
            const start = (query.page - 1) * query.limit;
            const count = Math.max(0, Math.min(query.limit, total - start));
            const statement = [{ type: 'opening', running_balance: 1000 + start }];
            for (let index = 0; index < count; index++) {
                const ordinal = start + index + 1;
                statement.push({
                    id: ordinal, date: '2026-08-25', type: 'income', description: `Row ${ordinal}`,
                    money_in: 1, money_out: null, running_balance: 1000 + ordinal
                });
            }
            return {
                success: true,
                data: {
                    account: { id: Number(accountId) }, statement,
                    pagination: { total_rows: total, total_pages: Math.ceil(total / query.limit) }
                }
            };
        }
    };
    const manager = new context.window.AccountDetailsManager();
    manager.accountId = 77;
    const result = await manager._allStatementRows(filters);
    assert.strictEqual(result.rows.length, total, `statement export lost rows for ${total}`);
    assert.strictEqual(result.rows.filter(row => row.type === 'opening').length, 0, 'synthetic page opening leaked into export');
    assert.deepStrictEqual(result.rows.map(row => row.id), Array.from({ length: total }, (_, index) => index + 1), 'statement export order changed');
    result.rows.forEach((row, index) => assert.strictEqual(row.running_balance, 1001 + index, 'server running balance changed across page boundary'));
    calls.forEach(call => {
        assert.strictEqual(call.accountId, '77', 'statement export changed account scope');
        Object.entries(filters).forEach(([key, value]) => assert.strictEqual(call.query[key], value, `statement filter ${key} was dropped`));
    });
    return calls;
}

(async () => {
    for (const count of [0, 1, 20, 21, 25, 26, 125, 200, 201, 425]) await statementScenario(count);
    const filteredCalls = await statementScenario(401, { type: 'expense', search: 'needle', start_date: '2025-01-01', end_date: '2025-12-31' });
    assert.strictEqual(filteredCalls.length, 3, 'multi-page filtered statement export did not retrieve every page');

    const textColumns = new Set([0]);
    const csv = context.CSVUtils.document(['Text', 'Number'], [
        ['=SUM(A1:A2)', '-125.50'], ['+123', '2'], ['-CMD', '3'], ['@test', '4'],
        ['comma,value', '5'], ['quote "value"', '6'], ['multi\nline', '7'], ['नेपाली विवरण', '8']
    ], textColumns);
    for (const safe of ["'=SUM(A1:A2)", "'+123", "'-CMD", "'@test"]) assert(csv.includes(safe), `${safe} was not formula-neutralized`);
    assert(csv.includes('"comma,value"') && csv.includes('"quote ""value"""') && csv.includes('"multi\nline"') && csv.includes('नेपाली विवरण'), 'CSV quoting or UTF-8 content changed');
    assert(csv.includes('"-125.50"') && !csv.includes('"\'-125.50"'), 'numeric negative value was altered as text');
    assert(accountSource.includes('CSVUtils.document(headers, rows, new Set([1, 2, 3, 4, 5]))'), 'account statement does not use formula-safe textual columns');
    assert(ledgerSource.includes('CSVUtils.document(headers, rows, new Set([1, 2, 3, 4]))'), 'Ledger does not use formula-safe textual columns');
    assert(ledgerSource.includes("if (all.length !== expected) throw new Error('Ledger export was incomplete')"), 'Ledger can silently export an incomplete page set');

    const reports = new context.window.ReportsManager();
    assert.deepStrictEqual(JSON.parse(JSON.stringify(reports._defaultRange(new Date('2026-12-31T18:14:00.000Z')))), { start_date: '2026-01-01', end_date: '2026-12-31' }, 'December 2026 default range is wrong');
    assert.deepStrictEqual(JSON.parse(JSON.stringify(reports._defaultRange(new Date('2026-12-31T18:16:00.000Z')))), { start_date: '2027-01-01', end_date: '2027-12-31' }, 'Kathmandu 2027 boundary retained 2026');
    assert.deepStrictEqual(JSON.parse(JSON.stringify(reports._defaultRange(new Date('2028-06-01T00:00:00.000Z')))), { start_date: '2028-01-01', end_date: '2028-12-31' }, '2028 default range is wrong');
    assert(context.DateUtils.isValidCalendarDate('2028-02-29'), 'valid leap day was rejected');
    assert(!context.DateUtils.isValidCalendarDate('2027-02-29'), 'invalid non-leap day was accepted');

    const inputs = { 'reports-start-date': { value: '2025-06-01' }, 'reports-end-date': { value: '2026-02-28' }, 'reports-active-period': { textContent: '' } };
    const errors = [];
    context.document.getElementById = id => inputs[id] || null;
    context.NotificationService = { error: message => errors.push(message) };
    reports.loadReportData = async () => { reports._loadedRange = { ...reports._reportRange }; };
    await reports.applyReportRange();
    assert.deepStrictEqual(JSON.parse(JSON.stringify(reports._loadedRange)), { start_date: '2025-06-01', end_date: '2026-02-28' }, 'custom cross-year range did not drive report loading');
    inputs['reports-start-date'].value = '2028-02-01'; inputs['reports-end-date'].value = '2028-02-29';
    await reports.applyReportRange();
    assert.strictEqual(reports._reportRange.end_date, '2028-02-29', 'custom leap-day range was rejected');
    inputs['reports-start-date'].value = '2028-03-01'; inputs['reports-end-date'].value = '2028-02-29';
    await reports.applyReportRange();
    assert.strictEqual(reports._reportRange.start_date, '2028-02-01', 'reversed range changed active filters');
    assert(errors.some(message => message.includes('cannot be after')), 'reversed range did not report validation error');

    assert(!reportsSource.includes("start_date: '2026-01-01'") && !reportsSource.includes('January 1, 2026 - December 31, 2026'), 'Reports still contains a hard-coded 2026 period');
    assert(reportsSource.includes('const activeRange = { ...this._reportRange }') && reportsSource.includes('requestSequence !== this._loadSequence') && reportsSource.includes('const periodSlug = `${this._reportRange.start_date}_to_${this._reportRange.end_date}`') && reportsSource.includes('Period: ${this._esc(this._rangeLabel())}'), 'screen, pagination, export, and print do not share latest active report range');

    console.log('PASS: Phase 16 complete statement pagination, running balances, CSV safety, dynamic Kathmandu year, and custom ranges are correct');
})().catch(error => {
    console.error(`FAIL: ${error.stack || error.message}`);
    process.exitCode = 1;
});
