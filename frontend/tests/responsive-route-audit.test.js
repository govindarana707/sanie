const fs = require('fs');
const assert = require('assert');

const html = fs.readFileSync('frontend/index.html', 'utf8');
const css = fs.readFileSync('frontend/assets/css/styles.css', 'utf8');

const routes = [
    'dashboard', 'transactions', 'recurring-transactions', 'budgets', 'goals',
    'tasks', 'savings', 'analysis', 'reports', 'categories', 'subcategories',
    'karobar-overview', 'karobar-people', 'karobar-person-profile',
    'karobar-transactions', 'karobar-reports', 'karobar-credit-reports',
    'karobar-ai-analysis', 'notifications', 'settings', 'accounts', 'account-details'
];

for (const route of routes) {
    assert(html.includes(`id="${route}-page"`), `Missing discovered route: ${route}`);
}

assert(/\.main-content > \.page\.active[\s\S]*?overflow:\s*visible !important/.test(css), 'Every mobile route needs shared document scrolling');
assert(/\.main-content > \.page\.active[\s\S]*?mobile-bottom-nav-height/.test(css), 'Every mobile route needs bottom-nav clearance');
assert(/\.page :is\(\.table-responsive[\s\S]*?overflow-x:\s*auto/.test(css), 'Tables need internal scrolling');
assert(/#appModal \.modal-dialog[\s\S]*?max-width:\s*calc\(100vw - 1rem\)/.test(css), 'Mobile dialogs must fit the viewport');
assert(/\.page :is\(\.stat-value[\s\S]*?text-overflow:\s*clip/.test(css), 'Financial values must not use mobile ellipsis');

console.log(`Responsive route audit checks passed for ${routes.length} application routes`);
