const fs = require('fs');
const vm = require('vm');

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
}

async function testAccountDetails() {
    const source = fs.readFileSync('frontend/assets/js/account-details.js', 'utf8');
    const container = { innerHTML: '' };
    const requests = new Map();
    const listeners = new Set();
    const calls = [];
    let activeIdentity = '';
    const context = {
        window: { location: { hash: '' }, ChartService: null },
        document: {
            getElementById: id => id === 'account-details-content' ? container : null,
            addEventListener: (name, handler) => listeners.add(handler),
            removeEventListener: (name, handler) => listeners.delete(handler),
            createElement: () => ({ click() {} })
        },
        accountsAPI: {
            getStatement: (id, filters, options) => {
                calls.push({ id: String(id), filters, signal: options.signal });
                const request = deferred();
                requests.set(String(id), request);
                return request.promise;
            }
        },
        Formatters: { escapeHTML: value => String(value), currency: String, compactCurrency: String, date: String },
        URLSearchParams, AbortController, Blob, URL, console: { error() {} }, setTimeout, clearTimeout
    };
    vm.runInNewContext(source, context);
    const manager = new context.window.AccountDetailsManager();
    manager.renderAccountDetails = data => { container.innerHTML = `account:${data.account.id}`; };
    const route = (id, extra = '') => {
        activeIdentity = `account-details?id=${id}${extra}`;
        return {
            query: new URLSearchParams(`id=${id}${extra}`), signal: new AbortController().signal,
            isCurrent: () => activeIdentity === `account-details?id=${id}${extra}`
        };
    };

    const first = manager.onMount(route(1));
    if (container.innerHTML.includes('account:')) throw new Error('Account 1 old data was visible before loading');
    const second = manager.onMount(route(2));
    if (!container.innerHTML.includes('skeleton')) throw new Error('Account ID change did not clear the old UI');
    requests.get('2').resolve({ success: true, data: { account: { id: 2 } } });
    await second;
    requests.get('1').resolve({ success: true, data: { account: { id: 1 } } });
    await first;
    if (container.innerHTML !== 'account:2') throw new Error('Late account 1 response overwrote account 2');

    activeIdentity = 'account-details?id=404';
    const missing = manager.onMount({ query: new URLSearchParams('id=404'), signal: new AbortController().signal, isCurrent: () => activeIdentity === 'account-details?id=404' });
    if (container.innerHTML === 'account:2') throw new Error('Missing account route retained the prior entity');
    requests.get('404').reject(Object.assign(new Error('missing'), { status: 404, category: 'server_error' }));
    await missing;
    if (!container.innerHTML.includes('Account not found') || container.innerHTML.includes('account:2')) throw new Error('Missing account did not render a clean not-found state');

    activeIdentity = 'account-details?id=999';
    const foreign = manager.onMount({ query: new URLSearchParams('id=999'), signal: new AbortController().signal, isCurrent: () => activeIdentity === 'account-details?id=999' });
    requests.get('999').reject(Object.assign(new Error('denied'), { status: 403, category: 'auth_error' }));
    await foreign;
    if (!container.innerHTML.includes('Account not found')) throw new Error('Foreign account did not use the tenant-safe not-found state');

    activeIdentity = 'account-details?id=7&page=3&type=expense';
    const queried = manager.onMount({ query: new URLSearchParams('id=7&page=3&type=expense'), signal: new AbortController().signal, isCurrent: () => activeIdentity === 'account-details?id=7&page=3&type=expense' });
    const queryCall = calls.at(-1);
    if (queryCall.filters.page !== 3 || queryCall.filters.type !== 'expense') throw new Error('Relevant account query parameters were not applied');
    manager.onUnmount();
    requests.get('7').resolve({ success: true, data: { account: { id: 7 } } });
    await queried;
    if (listeners.size !== 0 || manager.accountData !== null) throw new Error('Account route abort left listeners or committed stale data');
}

async function testPersonDetails() {
    const source = fs.readFileSync('frontend/assets/js/karobar.js', 'utf8');
    const container = { innerHTML: '' };
    const requests = new Map();
    let activeId = '';
    let notifications = 0;
    const context = {
        window: { crypto: { randomUUID: () => 'id' }, appRouter: { currentPage: 'karobar-person-profile' } },
        document: { getElementById: id => id === 'karobar-person-profile-content' ? container : null },
        karobarAPI: {
            getPersonLedger: (id) => {
                const request = deferred();
                requests.set(String(id), request);
                return request.promise;
            }
        },
        NotificationService: { error() { notifications++; } }, AjaxService: null,
        Formatters: { escapeHTML: value => String(value), date: String },
        AbortController, Blob, URL, console: { error() {}, warn() {} }, setTimeout, clearTimeout
    };
    vm.runInNewContext(source, context);
    const manager = new context.window.KarobarManager();
    manager.renderPersonProfile = () => { container.innerHTML = `person:${manager.currentPerson.id}`; };
    const route = id => {
        activeId = String(id);
        return { signal: new AbortController().signal, isCurrent: () => activeId === String(id) };
    };

    const first = manager.loadPersonProfile(5, route(5));
    const second = manager.loadPersonProfile(9, route(9));
    if (!container.innerHTML.includes('Loading person profile')) throw new Error('Person ID change did not clear the old profile');
    requests.get('9').resolve({ success: true, data: { person: { id: 9 }, ledger: [], balance: 0 } });
    await second;
    requests.get('5').resolve({ success: true, data: { person: { id: 5 }, ledger: [], balance: 0 } });
    await first;
    if (container.innerHTML !== 'person:9' || manager.currentPerson.id !== 9) throw new Error('Late person 5 response overwrote person 9');

    const missing = manager.loadPersonProfile(404, route(404));
    if (container.innerHTML === 'person:9') throw new Error('Missing person route retained the prior profile');
    requests.get('404').reject(Object.assign(new Error('missing'), { status: 404 }));
    await missing;
    if (!container.innerHTML.includes('Person not found') || manager.currentPerson !== null) throw new Error('Missing person did not render not-found safely');

    const foreign = manager.loadPersonProfile(999, route(999));
    requests.get('999').reject(Object.assign(new Error('denied'), { status: 403 }));
    await foreign;
    if (!container.innerHTML.includes('Person not found')) throw new Error('Foreign person did not use tenant-safe not-found');

    const aborted = manager.loadPersonProfile(12, route(12));
    manager.cancelPersonProfileRequest();
    requests.get('12').resolve({ success: true, data: { person: { id: 12 }, ledger: [], balance: 0 } });
    await aborted;
    if (manager.currentPerson !== null || notifications !== 0) throw new Error('Intentional profile abort committed data or showed an error');
}

(async () => {
    await testAccountDetails();
    await testPersonDetails();
    process.stdout.write('PASS: Phase 13 account/person detail state, missing/foreign IDs, query, abort, and late-response guards\n');
})().catch(error => {
    process.stderr.write(`FAIL: ${error.message}\n`);
    process.exitCode = 1;
});
