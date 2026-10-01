const fs = require('fs');
const vm = require('vm');

class ElementMock {
    constructor(tagName) {
        this.tagName = String(tagName).toUpperCase();
        this.children = [];
        this.dataset = {};
        this.value = '';
        this.textContent = '';
        this.label = '';
    }
    appendChild(child) { this.children.push(child); return child; }
    set innerHTML(value) {
        this.children = [];
        if (String(value).includes('<option')) {
            const placeholder = new ElementMock('option');
            placeholder.value = '';
            placeholder.textContent = String(value).replace(/<[^>]+>/g, '').trim();
            this.children.push(placeholder);
        }
    }
}

const documentMock = {
    createElement: tag => new ElementMock(tag),
    getElementById: () => null,
    querySelectorAll: () => [],
};
const windowMock = {
    APP_CONFIG: { API_BASE: '/api' },
    Api: {},
    addEventListener() {},
    removeEventListener() {},
};
const context = {
    window: windowMock,
    document: documentMock,
    console,
    setTimeout,
    clearTimeout,
    URLSearchParams,
};

vm.runInNewContext(fs.readFileSync('frontend/assets/js/categories.js', 'utf8'), context);
vm.runInNewContext(fs.readFileSync('frontend/assets/js/transactions.js', 'utf8'), context);

const categories = new windowMock.CategoriesManager();
const ranked = [
    { id: 4, name: 'Alphabetical', is_pinned: 0, sort_order: 999, transaction_count: 2 },
    { id: 3, name: 'Popular', is_pinned: 0, sort_order: 999, transaction_count: 8 },
    { id: 2, name: 'Pinned Two', is_pinned: 1, sort_order: 2, transaction_count: 0 },
    { id: 1, name: 'Pinned One', is_pinned: 1, sort_order: 1, transaction_count: 0 },
].map(item => categories._normalize(item));
ranked.sort((a, b) => categories._priorityCompare(a, b));
if (ranked.map(item => item.id).join(',') !== '1,2,3,4') {
    throw new Error('client priority fallback is not deterministic');
}

const transactions = new windowMock.TransactionsManager();
const select = new ElementMock('select');
transactions._renderCategoryOptions(select, ranked, 'Select category');
if (select.children.length !== 3 || select.children[1].label !== 'Pinned' || select.children[2].label !== 'Other categories') {
    throw new Error('pinned and unpinned category groups were not rendered');
}
const pinnedOptions = select.children[1].children;
if (pinnedOptions.map(option => option.textContent).join('|') !== '★ Pinned One|★ Pinned Two') {
    throw new Error('pinned category labels or order are incorrect');
}
if (pinnedOptions[0].dataset.name !== 'Pinned One' || pinnedOptions[0].dataset.pinned !== '1') {
    throw new Error('clean category metadata was not retained for transaction display');
}

const ungrouped = new ElementMock('select');
transactions._renderCategoryOptions(ungrouped, ranked.filter(item => !item.is_pinned), 'Select category');
if (ungrouped.children.some(child => child.tagName === 'OPTGROUP')) {
    throw new Error('unnecessary groups were rendered without pinned categories');
}

const categorySelect = new ElementMock('select');
const subcategorySelect = new ElementMock('select');
const typeSelect = new ElementMock('select');
typeSelect.value = 'expense';
const elements = {
    'transaction-category': categorySelect,
    'transaction-subcategory': subcategorySelect,
    'transaction-type': typeSelect,
};
documentMock.getElementById = id => elements[id] || null;
windowMock.Api = {
    get: async endpoint => endpoint.startsWith('/subcategories')
        ? { success: true, data: [{ id: 91, category_id: 1, name: 'Groceries' }] }
        : { success: true, data: ranked },
};
windowMock.authManager = { getCurrentUser: () => ({ id: 7 }) };
windowMock.OfflineStorage = { saveReferenceData: async () => true, getReferenceData: async () => null };

(async () => {
    await transactions._loadCategoriesByType('expense');
    if (subcategorySelect.disabled !== true || subcategorySelect.children.length !== 1) {
        throw new Error('type change did not clear stale subcategory state');
    }
    await transactions._loadSubcategories('1');
    if (subcategorySelect.disabled !== false || subcategorySelect.children.length !== 2 || subcategorySelect.children[1].textContent !== 'Groceries') {
        throw new Error('selected category did not load only its subcategories');
    }
    process.stdout.write('PASS: category priority fallback, dropdown grouping, and subcategory reset/load safety\n');
})().catch(error => {
    process.stderr.write(`FAIL: ${error.message}\n`);
    process.exitCode = 1;
});
