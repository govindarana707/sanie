const fs = require('fs');
const assert = require('assert');

const css = fs.readFileSync('frontend/assets/css/styles.css', 'utf8');
const html = fs.readFileSync('frontend/index.html', 'utf8');

assert(html.includes('id="recurring-transactions-page"'), 'Recurring page is missing');
assert(/#recurring-transactions-page \{[\s\S]*?flex:\s*1 1 auto/.test(css), 'Recurring page must participate in the flex layout');
assert(/\.main-content:has\(> #recurring-transactions-page\.active\) > \.app-footer[\s\S]*?position:\s*static/.test(css), 'Recurring footer must remain in normal flow');
assert(/\[data-bs-theme="dark"\] #recurring-transactions-page \.recurring-page-header[\s\S]*?background:\s*var\(--bg-secondary\)/.test(css), 'Recurring hero needs a dark theme surface');
assert(/\[data-bs-theme="dark"\] #recurring-transactions-page \.recurring-summary > div,[\s\S]*?background:\s*var\(--surface\)/.test(css), 'Recurring cards need dark theme surfaces');
assert(/\[data-bs-theme="dark"\] #appModal \.recurring-form :is\(\.form-control, \.form-select\)[\s\S]*?background-color:\s*var\(--bg-secondary\)/.test(css), 'Recurring modal inputs need dark theme surfaces');
assert(/#recurring-transactions-page \.recurring-card-title :is\(h3, p\)[\s\S]*?text-overflow:\s*clip/.test(css), 'Mobile recurring content must not truncate');

console.log('Recurring responsive theme checks passed');
