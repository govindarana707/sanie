const fs = require('fs');
const assert = require('assert');

const css = fs.readFileSync('frontend/assets/css/styles.css', 'utf8');
const html = fs.readFileSync('frontend/index.html', 'utf8');

assert(html.includes('id="budgets-page"'), 'Budgets page is missing');
assert(/body:not\(\.modal-open\) #budgets-page\.page\.active/.test(css), 'Budgets must use mobile document scrolling');
assert(/#budgets-page\.page\.active[\s\S]*?padding-bottom:\s*calc\(var\(--mobile-bottom-nav-height\)/.test(css), 'Budgets needs bottom-nav clearance');
assert(/#budgets-page,[\s\S]*?#budgets-page \.budget-page-header,[\s\S]*?min-width:\s*0/.test(css), 'Budget layout children must be shrinkable');
assert(/#budgets-page \.budget-overview-item strong[\s\S]*?white-space:\s*normal/.test(css), 'Budget overview values must not be truncated');
assert(/#budgets-page \.budget-overview[\s\S]*?repeat\(2, minmax\(0, 1fr\)\)/.test(css), 'Budget overview should use two compact mobile columns');

console.log('Budgets responsive checks passed');
