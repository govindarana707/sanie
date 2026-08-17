const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '..', 'assets', 'js');
const budgets = fs.readFileSync(path.join(root, 'budgets.js'), 'utf8');
const api = fs.readFileSync(path.join(root, 'api.js'), 'utf8');
const reports = fs.readFileSync(path.join(root, 'reports.js'), 'utf8');
const dashboard = fs.readFileSync(path.join(root, 'dashboard.js'), 'utf8');

if (!budgets.includes('id="budget-subcategory"') || !budgets.includes('loadSubcategoriesForForm'))
    throw new Error('Budget form does not provide a parent-driven subcategory selector');
if (!budgets.includes("select.innerHTML='<option value=\"\">All subcategories</option>'") || !budgets.includes('select.disabled=!categoryId'))
    throw new Error('Budget form does not clear and disable stale child selection when parent changes');
if (!budgets.includes('subcategory_id: document.getElementById(\'budget-subcategory\')'))
    throw new Error('Budget form does not submit the selected subcategory ID');
if (!budgets.includes("budget.scope_label || budget.category_name || 'All expenses'"))
    throw new Error('Budget cards do not use the authoritative backend scope label');
if (!api.includes('`/subcategories?category_id=${parentId}&status=active`'))
    throw new Error('Subcategory selector does not use the owned, active child endpoint');
for (const pattern of ['/budgets', '/categories', '/subcategories', '/dashboard', '/reports'])
    if (!api.includes(`'${pattern}'`)) throw new Error(`Budget cache invalidation is missing ${pattern}`);
if (!api.includes("['/accounts', '/dashboard', '/ledger', '/reports', '/budgets']"))
    throw new Error('Expense mutations do not invalidate cached budget usage');
if (!reports.includes("b.scope_label || b.category_name"))
    throw new Error('Budget reports do not render the authoritative scope label');
if (!dashboard.includes("b.budget.scope_label || b.budget.category_name || 'All expenses'"))
    throw new Error('Dashboard budget summary does not render the authoritative scope label');

console.log('PASS: budget UI uses parent-driven selectors, authoritative labels, and targeted cache invalidation');
