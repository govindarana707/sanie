const fs = require('fs');
const assert = require('assert');

const css = fs.readFileSync('frontend/assets/css/karobar.css', 'utf8');
const sharedCss = fs.readFileSync('frontend/assets/css/styles.css', 'utf8');
const source = fs.readFileSync('frontend/assets/js/karobar.js', 'utf8');

assert(source.includes('karobar-overview-charts'), 'Overview charts need a responsive row hook');
assert(source.includes('karobar-overview-lists'), 'Overview lists need a responsive row hook');
assert(source.includes('karobar-overview-list-primary'), 'Overview list content must be shrinkable');
assert(source.includes('responsive: [{ breakpoint: 600'), 'Overview charts need mobile-specific chart options');
assert(!/:is\([\s\S]*?#karobar-overview-page,[\s\S]*?\)\s*\{\s*overflow-x:\s*hidden/.test(css), 'Overview must not mask horizontal overflow');
assert(/#karobar-overview-content > \.row > \[class\*="col-"\][\s\S]*?min-width:\s*0/.test(css), 'Overview grid children must be able to shrink');
assert(/@media \(max-width: 1199\.98px\)[\s\S]*?\.karobar-overview-charts > \.col-lg-8/.test(css), 'Charts must stack before small-laptop widths become cramped');
assert(/repeat\(auto-fit, minmax\(min\(100%, 170px\), 1fr\)\)/.test(css), 'Stat cards must adapt to available width');
assert(/\.karobar-overview-list-amounts,[\s\S]*?overflow-wrap:\s*anywhere/.test(css), 'Financial list amounts must not be clipped');
assert(/@media \(min-width: 992px\)[\s\S]*?\.main-app > \.main-content[\s\S]*?min-height:\s*0[\s\S]*?\.page\.active[\s\S]*?overflow-y:\s*auto/.test(sharedCss), 'Desktop active pages must retain an internal scroll container');
assert(/body:not\(\.modal-open\) #karobar-overview-page\.page\.active[\s\S]*?height:\s*auto !important;[\s\S]*?overflow:\s*visible !important;[\s\S]*?touch-action:\s*pan-y;/.test(sharedCss), 'Karobar must use document scrolling on mobile');
assert(/--mobile-bottom-nav-height/.test(sharedCss) && /padding-bottom:\s*calc\(var\(--mobile-bottom-nav-height\)/.test(sharedCss), 'Karobar content needs bottom-nav clearance derived from the nav height');
assert(/#karobar-people-page,[\s\S]*?#karobar-people-content,[\s\S]*?min-width:\s*0/.test(css), 'People page and its content must be shrinkable');
assert(/\.karobar-person-card \.person-name,[\s\S]*?overflow-wrap:\s*anywhere/.test(css), 'People names and financial values must not force horizontal overflow');
for (const page of ['transactions', 'reports', 'credit-reports', 'ai-analysis']) {
    assert(sharedCss.includes(`#karobar-${page}-page.page.active`), `${page} must use the mobile document-scroll rule`);
}
assert(source.includes('karobar-transaction-mobile-list'), 'Transactions need a compact mobile row presentation');
assert(/\.karobar-transaction-mobile-list[\s\S]*?display:\s*grid/.test(css), 'Mobile transaction rows must be enabled by CSS');
assert(source.includes('karobar-responsive-chart') && /\.karobar-responsive-chart[\s\S]*?width:\s*100%/.test(css), 'Reports and AI charts must have responsive containers');
assert(/#karobar-ai-analysis-content :is\(p, li, td, th, a\)[\s\S]*?overflow-wrap:\s*anywhere/.test(css), 'AI text must safely wrap dynamic content');
assert(source.includes('Net Position') && source.includes('Active People'), 'AI analysis uses unambiguous summary labels');
assert(source.includes("'N/A' : `${Number(data.credit_dependency)") && source.includes("'N/A' : `${Number(data.avg_repayment_days)} days`"), 'Unavailable analytics render as N/A instead of misleading zeros');
assert(source.includes("{ name: 'Outstanding Balance'"), 'AI debt chart includes the running outstanding balance series');
assert(source.includes('No Karobar activity available for this period.'), 'AI chart has a truthful empty state');
assert(/\.karobar-ai-metric-value[\s\S]*?overflow-wrap:\s*anywhere/.test(css), 'Long AI currency values wrap without breaking cards');
assert(/\.karobar-ai-summary-grid > \[class\*="col-"\][\s\S]*?min-width:\s*0/.test(css), 'AI summary columns can shrink at narrow widths');

console.log('Karobar overview responsive checks passed');
