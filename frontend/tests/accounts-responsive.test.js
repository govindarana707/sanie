const fs = require('fs');
const assert = require('assert');

const css = fs.readFileSync('frontend/assets/css/styles.css', 'utf8');
const html = fs.readFileSync('frontend/index.html', 'utf8');
const accounts = fs.readFileSync('frontend/assets/js/accounts.js', 'utf8');

assert(html.includes('id="accounts-page"'), 'Accounts page is missing');
assert(/#accounts-page,[\s\S]*?#accounts-list,[\s\S]*?min-width:\s*0/.test(css), 'Accounts layout children must be shrinkable');
assert(/body:not\(\.modal-open\) #accounts-page\.page\.active/.test(css), 'Accounts must use document scrolling on mobile');
assert(/#accounts-page\.page\.active[\s\S]*?padding-bottom:\s*calc\(var\(--mobile-bottom-nav-height\)/.test(css), 'Accounts needs bottom-nav clearance');
assert(/\.accounts-overview-card strong,[\s\S]*?overflow-wrap:\s*anywhere/.test(css), 'Account summary amounts must not be truncated');
assert(/#accounts-page \.accounts-overview-grid[\s\S]*?repeat\(auto-fit, minmax\(min\(100%, 155px\), 1fr\)\)/.test(css), 'Account summary cards must adapt to mobile width');
assert(/\.accounts-page-hero[\s\S]*?padding:1rem 1\.25rem/.test(css), 'Accounts hero should use compact desktop padding');
assert(/\.accounts-search input[\s\S]*?min-height:44px/.test(css), 'Accounts search needs a compact, touch-safe height');
assert(/\.accounts-toolbar[\s\S]*?margin-bottom:\.75rem/.test(css), 'Accounts toolbar should keep a compact gap before cards');
assert(/#accounts-page #accounts-list \.accounts-grid[\s\S]*?display:\s*grid !important;[\s\S]*?overflow:\s*visible !important;/.test(css), 'Account cards must not use a horizontal scroller');
assert(/#accounts-page #accounts-list \.accounts-grid[\s\S]*?grid-template-columns:\s*repeat\(2, minmax\(0, 1fr\)\)/.test(css), 'Mobile Accounts must show two cards per row');
assert(accounts.includes('Include in Net Balance') && accounts.includes('acct-net-balance'), 'Create and edit account forms need the Net Balance inclusion switch');
assert(accounts.includes('include_in_net_balance: includeInNetBalance'), 'Account form must persist the explicit Net Balance preference');
assert(accounts.includes('Excluded from Net Balance'), 'Excluded accounts need a list indicator');
assert(/account-card-balance[\s\S]*?account-card-net-excluded/.test(accounts), 'Excluded status must appear beneath the current balance, not in the account header');
assert(/account-card-badge[\s\S]*?\+ '<\/div>'[\s\S]*?isExcludedFromNetBalance/.test(accounts), 'Excluded status must render after the account header closes');
assert(accounts.includes('aria-label="Edit '), 'Account edit icon controls need accessible labels');
assert(accounts.includes('aria-label="Delete '), 'Account delete icon controls need accessible labels');
assert(accounts.includes("const settingRow = (id, title, description, checked)"), 'All account settings must use one reusable form-row builder');
assert(accounts.includes('Account Settings'), 'Account settings need a clear section heading');
assert(!accounts.includes("ON: This account\\'s balance is included"), 'Net Balance setting must not repeat ON/OFF explanatory copy');
assert(/\.account-setting-row[\s\S]*?justify-content:\s*space-between/.test(css), 'Settings rows must align their controls to the far right');
assert(/\.account-setting-control[\s\S]*?flex:\s*0 0 auto/.test(css), 'Settings toggles must not shrink on small screens');
assert(/\.account-setting-toggle\.form-check-input[\s\S]*?width:\s*2\.75rem[\s\S]*?height:\s*1\.5rem/.test(css), 'Settings must share a compact toggle size');
assert(/\.account-setting-toggle\.form-check-input:focus-visible/.test(css), 'Settings toggles need a visible keyboard focus state');
assert(/\.account-card-net-excluded[\s\S]*?color:\s*var\(--text-secondary/.test(css), 'Excluded account indicator must remain subtle in both themes');
assert(/\.account-card-header[\s\S]*?grid-template-columns:\s*auto minmax\(0, 1fr\) auto/.test(css), 'Account headers must reserve space for identity and the Default badge without overlap');
assert(/\.account-card-actions[\s\S]*?flex-wrap:\s*wrap/.test(css), 'Account card footer actions must wrap safely on narrow cards');

console.log('Accounts responsive checks passed');
