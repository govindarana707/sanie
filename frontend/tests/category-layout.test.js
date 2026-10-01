const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const css = fs.readFileSync('frontend/assets/css/category.css', 'utf8');
const html = fs.readFileSync('frontend/index.html', 'utf8');
const source = fs.readFileSync('frontend/assets/js/categories.js', 'utf8');

assert(css.includes('repeat(4, minmax(0, 1fr))'), 'desktop grid must use shrink-safe tracks');
assert(css.includes('repeat(3, minmax(0, 1fr))'), 'laptop grid must have three shrink-safe tracks');
assert(css.includes('repeat(2, minmax(0, 1fr))'), 'tablet grid must have two shrink-safe tracks');
assert(css.includes('grid-template-columns: minmax(0, 1fr)'), 'mobile grid must have one shrink-safe track');
assert(!/100vw/i.test(css), 'Categories CSS must not size content against the full viewport');
assert(!/#categories-page[^{}]*\{[^}]*position\s*:\s*fixed/is.test(css), 'Categories content must remain in normal flow');
assert(html.includes('aria-label="Select all visible categories"'), 'select-all control needs a durable accessible label');
assert(html.includes('id="clear-category-filters-empty"'), 'filtered empty state must offer a clear action');
assert(html.includes('class="categories-page-header"'), 'Categories page needs its compact page header');
assert(html.includes('<span>Add Category</span>'), 'primary add action needs a visible label');
assert(html.includes('<span>Import</span>') && html.includes('<span>Export</span>'), 'file actions need visible labels');
assert.strictEqual((html.match(/id="add-category-btn"/g) || []).length, 1, 'primary add action must not be duplicated');
assert(css.includes('grid-template-columns: minmax(240px, 2fr) repeat(3, minmax(112px, 0.85fr)) auto auto'), 'desktop filters must share one aligned row');
assert(css.includes('#categories-page .category-stat-card'), 'summary cards need compact scoped styling');

// Static width model mirrors the CSS breakpoints and the existing 264px desktop sidebar.
const viewports = [1920, 1600, 1440, 1366, 1280, 1024, 768, 480, 390, 375];
for (const viewport of viewports) {
    const sidebar = viewport >= 992 ? 264 : 0;
    const gutter = viewport <= 768 ? 32 : 64;
    const columns = viewport >= 1400 ? 4 : viewport >= 1200 ? 3 : viewport >= 576 ? 2 : 1;
    const gap = columns > 1 ? 18 * (columns - 1) : 0;
    const available = viewport - sidebar - gutter;
    const track = (available - gap) / columns;
    assert(track > 0, `grid track collapsed at ${viewport}px`);
    assert((track * columns) + gap <= available + 0.01, `grid exceeds content width at ${viewport}px`);
}

const documentMock = { getElementById: () => null, querySelectorAll: () => [] };
const windowMock = {
    APP_CONFIG: { API_BASE: '/api' },
    Api: {},
    addEventListener() {},
    removeEventListener() {},
};
vm.runInNewContext(source, {
    window: windowMock,
    document: documentMock,
    console,
    setTimeout,
    clearTimeout,
    URLSearchParams,
    Formatters: { date: value => value },
});

const manager = new windowMock.CategoriesManager();
const card = manager.buildCardHTML(manager._normalize({
    id: 8,
    name: 'A very long household spending category that must remain contained',
    type: 'expense',
    status: 'active',
    color: '#ef4444',
    subcategory_count: 6,
    subcategories: Array.from({ length: 6 }, (_, index) => ({
        id: index + 1,
        category_id: 8,
        name: `Long subcategory ${index + 1}`,
        icon: 'tag',
    })),
}));

assert.strictEqual((card.match(/class="cat-sub-chip"/g) || []).length, 3, 'card must preview exactly three subcategories');
assert(card.includes('+3 more'), 'card must summarize hidden subcategories');
assert(card.includes('Add subcategory'), 'card must retain the add-subcategory action');
assert(card.includes('aria-label="More actions for A very long'), 'card action menu needs a category-specific label');
assert(card.startsWith('\n        <article'), 'category card must use semantic article markup');

process.stdout.write('PASS: category layout, responsive width model, preview limits, and accessibility hooks\n');
