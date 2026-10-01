const fs = require('fs');
const assert = require('assert');

const css = fs.readFileSync('frontend/assets/css/styles.css', 'utf8');
const js = fs.readFileSync('frontend/assets/js/notifications.js', 'utf8');
const mobileCss = css.slice(css.lastIndexOf('@media (max-width: 991px)'));

assert(/\.notif-dropdown \{[\s\S]*?max-width:\s*min\(380px, calc\(100vw - 24px\)\)/.test(css), 'Desktop panel must remain viewport-safe');
assert(/\.notif-dropdown \{[\s\S]*?position:\s*fixed[\s\S]*?left:\s*12px[\s\S]*?width:\s*calc\(100vw - 24px\)/.test(mobileCss), 'Mobile panel must be viewport constrained');
assert(/\.notif-dropdown-body \{[\s\S]*?overflow-y:\s*auto/.test(css), 'Notification list must own scrolling');
assert(/\.notif-dropdown-footer \{[\s\S]*?position:\s*sticky/.test(css), 'Mobile footer must remain visible');
assert(/\.notif-item-title \{[\s\S]*?white-space:\s*normal[\s\S]*?overflow-wrap:\s*anywhere/.test(css), 'Mobile notification titles must wrap');
assert(js.includes('_positionDropdown()'), 'Panel must align to the actual mobile header');
assert(js.includes("window.addEventListener('resize', this._dropdownViewportHandler)"), 'Panel must reposition on viewport changes');

console.log('Notification panel responsive checks passed');
