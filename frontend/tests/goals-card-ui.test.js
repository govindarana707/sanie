const fs = require('fs');
const assert = require('assert');

const css = fs.readFileSync('frontend/assets/css/styles.css', 'utf8');
const js = fs.readFileSync('frontend/assets/js/goals.js', 'utf8');

assert(js.includes('class="goal-balance"'), 'Goal amount hierarchy needs a balance block');
assert(js.includes('class="goal-meta"'), 'Goal percentage and deadline need a shared metadata row');
assert(js.includes('goal-delete-action'), 'Delete must participate in the action row');
assert(/#goals-page \.goal-card \{[\s\S]*?padding:\s*1\.2rem[\s\S]*?background:\s*var\(--surface\)/.test(css), 'Goal cards need compact theme-aware surfaces');
assert(/#goals-page \.goal-actions \{[\s\S]*?border-top:\s*1px solid var\(--border\)/.test(css), 'Goal actions need one consistent row');
assert(/@media \(min-width: 900px\)[\s\S]*?goals-grid \{ grid-template-columns: repeat\(2, minmax\(0, 1fr\)\)/.test(css), 'Goals need a two-column desktop grid');
assert(/\[data-bs-theme="dark"\] #goals-page \.goal-card[\s\S]*?background:\s*var\(--surface\)/.test(css), 'Goal card dark theme surface is missing');

console.log('Goals card UI checks passed');
