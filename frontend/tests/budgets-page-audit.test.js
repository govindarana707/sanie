const fs = require('fs');
const assert = require('assert');

const html = fs.readFileSync('frontend/index.html', 'utf8');
const css = fs.readFileSync('frontend/assets/css/styles.css', 'utf8');
const budgets = fs.readFileSync('frontend/assets/js/budgets.js', 'utf8');

assert(/budget-primary-controls[\s\S]*?budget-month[\s\S]*?add-budget-btn/.test(html),
    'Month selection and Add Budget must form the primary control group');
assert(/budget-secondary-actions[\s\S]*?budget-report-btn[\s\S]*?copy-budget-btn[\s\S]*?bulk-budget-btn/.test(html),
    'Report, Copy, and Bulk must remain grouped as secondary actions');
assert(html.includes('Total budgets') && html.includes('Budget records across all months'),
    'The unfiltered count must make its all-month scope explicit');
assert(budgets.includes('this.visibleBudgets = this.budgets.filter') && budgets.includes("set('budget-active-count', String(this.budgets.length))"),
    'Selected-month monetary totals and the all-month count must remain deliberately distinct');
assert(/#budgets-page \.budget-empty-state[\s\S]*?min-height:\s*180px/.test(css),
    'The budget empty state should be compact rather than viewport-height sized');
assert(/#budgets-page \.budget-empty-state \{[\s\S]*?min-height:\s*160px/.test(css),
    'The mobile empty state should remain compact');
assert(/#budgets-page \.budget-primary-controls \.btn-primary[\s\S]*?box-shadow:\s*none/.test(css) &&
       /#budgets-page \.budget-secondary-actions \.btn \{[\s\S]*?background:\s*var\(--surface-soft/.test(css),
    'Primary and secondary budget actions need their intended visual hierarchy');
assert(/\.main-content:has\(> #budgets-page\.active\)[\s\S]*?flex-direction:\s*column/.test(css) &&
       /\.main-content:has\(> #budgets-page\.active\) > \.app-footer[\s\S]*?position:\s*static/.test(css),
    'Budget content and footer must use a natural flex-column document flow');
assert(/\[data-bs-theme="dark"\] #budgets-page \.budget-month-picker[\s\S]*?\.budget-card/.test(css),
    'Budget surfaces need an explicit dark-theme treatment');
assert(/\[data-bs-theme="dark"\] #budgets-page \.budget-secondary-actions \.btn:hover/.test(css),
    'Budget secondary actions need a restrained dark-theme hover state');
assert(/@media \(min-width: 1101px\)[\s\S]*?#budgets-page \.budget-page-header \{[\s\S]*?align-items:\s*center[\s\S]*?padding:\s*\.6rem 1\.5rem/.test(css),
    'Desktop budget hero must stay vertically centered and compact');
assert(/@media \(min-width: 1280px\)[\s\S]*?#budgets-page \.budget-page-actions \{[\s\S]*?display:\s*flex[\s\S]*?#budgets-page #budget-report-btn \{ order: 2; \}[\s\S]*?#budgets-page #add-budget-btn \{ order: 5; \}/.test(css),
    'Wide desktop budget controls must be one ordered horizontal row');
assert(/@media \(max-width: 600px\)[\s\S]*?#budgets-page \.budget-secondary-actions[\s\S]*?repeat\(2, minmax\(0, 1fr\)\)/.test(css),
    'Secondary actions must wrap on mobile instead of squeezing into a single row');

console.log('Budgets page audit checks passed');
