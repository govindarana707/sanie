const fs = require('fs');
const assert = require('assert');

const css = fs.readFileSync('frontend/assets/css/styles.css', 'utf8');
const html = fs.readFileSync('frontend/index.html', 'utf8');

assert(html.includes('id="goals-page"'), 'Goals page is missing');
assert(/body:not\(\.modal-open\) #goals-page\.page\.active/.test(css), 'Goals must use mobile document scrolling');
assert(/#goals-page\.page\.active[\s\S]*?padding-bottom:\s*calc\(var\(--mobile-bottom-nav-height\)/.test(css), 'Goals needs bottom-nav clearance');
assert(/#goals-page,[\s\S]*?#goals-page \.page-header,[\s\S]*?min-width:\s*0/.test(css), 'Goal layout children must be shrinkable');
assert(/#goals-page \.goals-grid[\s\S]*?grid-template-columns:\s*minmax\(0, 1fr\)/.test(css), 'Goals should use a readable single mobile column');
assert(/#goals-page \.goal-name,[\s\S]*?overflow-wrap:\s*anywhere/.test(css), 'Long goal labels and values must remain readable');
assert(/#goals-page #add-goal-btn[\s\S]*?width:\s*100%/.test(css), 'The add-goal action should be easy to use on mobile');

console.log('Goals responsive checks passed');
