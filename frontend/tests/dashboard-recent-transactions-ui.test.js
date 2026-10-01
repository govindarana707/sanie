const fs = require('fs');
const assert = require('assert');

const css = fs.readFileSync('frontend/assets/css/styles.css', 'utf8');
const js = fs.readFileSync('frontend/assets/js/dashboard.js', 'utf8');

assert(js.includes("item.className = 'transaction-item'"), 'Dashboard must render one consistent transaction item structure');
assert(/#dashboard-page \.recent-transactions \.transaction-item \{[\s\S]*?min-height:\s*62px[\s\S]*?margin:\s*0[\s\S]*?border-bottom:\s*1px solid var\(--border\)/.test(css), 'Dashboard rows must be compact with separators');
assert(/\.transaction-item:last-child \{ border-bottom: 0; \}/.test(css), 'Last row must not add a separator');
assert(/\.transaction-item:hover \{[\s\S]*?background: color-mix[\s\S]*?transform:\s*none/.test(css), 'Hover treatment must be consistent and non-shifting');
assert(/\.transaction-amount\.income \{ color: var\(--sanie-income\); \}/.test(css), 'Income amount color must remain green');
assert(/\.transaction-amount\.expense \{ color: var\(--sanie-expense\); \}/.test(css), 'Expense amount color must remain red');
assert(/@media \(max-width: 479\.98px\)[\s\S]*?recent-transactions \.transaction-item/.test(css), 'Recent transaction rows need a compact mobile treatment');

console.log('Dashboard recent transaction UI checks passed');
