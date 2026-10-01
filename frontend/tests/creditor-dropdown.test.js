const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync('frontend/assets/js/transactions.js', 'utf8');

class FakeOption {
    constructor() {
        this.value = '';
        this.textContent = '';
        this.disabled = false;
        this.selected = false;
    }
}

class FakeSelect {
    constructor() {
        this._options = [];
        this._value = '';
        this.disabled = false;
        this.required = false;
    }
    get options() { return this._options; }
    set innerHTML(value) {
        this._options = [];
        this._value = '';
        const match = String(value).match(/<option[^>]*>(.*?)<\/option>/);
        if (match) {
            const option = new FakeOption();
            option.textContent = match[1];
            option.selected = /selected/.test(value);
            this.appendChild(option);
        }
    }
    get innerHTML() { return ''; }
    set value(value) {
        const normalized = String(value);
        this._value = this._options.some(option => option.value === normalized) ? normalized : '';
    }
    get value() { return this._value; }
    appendChild(option) {
        this._options.push(option);
        if (option.selected) this._value = option.value;
    }
}

let creditorSelect = new FakeSelect();
const calls = [];
const activePeople = ['person', 'friend', 'family', 'shop', 'vendor', 'business', 'other'].map((type, index) => ({
    id: index + 10,
    name: `${type} name`,
    type,
    status: 'active',
    balance: 0,
}));

const windowMock = {
    Api: {
        async get(endpoint) {
            calls.push(endpoint);
            if (endpoint.includes('page=1')) {
                await new Promise(resolve => setTimeout(resolve, 5));
                return { success: true, data: { people: activePeople.slice(0, 6), pagination: { total_pages: 2 } } };
            }
            return { success: true, data: { people: activePeople.slice(6), pagination: { total_pages: 2 } } };
        },
    },
};
const documentMock = {
    getElementById(id) { return id === 'transaction-creditor' ? creditorSelect : null; },
    createElement(tag) { return tag === 'option' ? new FakeOption() : {}; },
};

vm.runInNewContext(source, {
    window: windowMock,
    document: documentMock,
    console: { ...console, error() {} },
    setTimeout,
    clearTimeout,
    URLSearchParams,
});

(async () => {
    const manager = new windowMock.TransactionsManager();
    manager._formEpoch = 1;
    await Promise.all([manager._loadCreditors(), manager._loadCreditors()]);

    assert.deepStrictEqual(calls, [
        '/people?status=active&page=1&limit=200',
        '/people?status=active&page=2&limit=200',
    ], 'concurrent population created duplicate people requests');
    assert.strictEqual(creditorSelect.disabled, false, 'eligible creditor select remained disabled');
    assert.strictEqual(creditorSelect.options.length, 8, 'all active person types plus the placeholder must be present');
    assert.strictEqual(creditorSelect.options[0].disabled, true, 'placeholder must not be selectable');
    assert(creditorSelect.options.some(option => option.value === '13' && option.textContent === 'shop name — Shop'), 'active settled Shop is missing or mislabeled');
    assert(creditorSelect.options.some(option => option.value === '16' && option.textContent === 'other name — Other'), 'active Other person is missing or mislabeled');

    creditorSelect = new FakeSelect();
    manager._formEpoch++;
    windowMock.Api.get = async () => ({ success: true, data: { people: [], pagination: { total_pages: 0 } } });
    await manager._loadCreditors();
    assert.strictEqual(creditorSelect.disabled, true, 'empty creditor select must remain disabled');
    assert.strictEqual(creditorSelect.options[0].textContent, 'No people available — add a person in Karobar first');

    creditorSelect = new FakeSelect();
    manager._formEpoch++;
    windowMock.Api.get = async () => { throw new Error('network unavailable'); };
    await manager._loadCreditors();
    assert.strictEqual(creditorSelect.disabled, true, 'failed creditor select must remain disabled');
    assert.strictEqual(creditorSelect.options[0].textContent, 'Unable to load creditors. Please try again.');

    process.stdout.write('PASS: creditor dropdown maps paginated people, includes all active types, deduplicates loads, and exposes empty/error states\n');
})().catch(error => {
    console.error(`FAIL: ${error.message}`);
    process.exit(1);
});
