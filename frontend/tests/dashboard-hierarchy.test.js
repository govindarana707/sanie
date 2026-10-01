const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const html = fs.readFileSync(path.join(root, 'frontend', 'index.html'), 'utf8');
const dashboard = fs.readFileSync(path.join(root, 'frontend', 'assets', 'js', 'dashboard.js'), 'utf8');
const offline = fs.readFileSync(path.join(root, 'frontend', 'assets', 'js', 'offline-storage.js'), 'utf8');
const controller = fs.readFileSync(path.join(root, 'backend', 'controllers', 'DashboardController.php'), 'utf8');
const styles = fs.readFileSync(path.join(root, 'frontend', 'assets', 'css', 'styles.css'), 'utf8');

const assert = (condition, message) => {
    if (!condition) throw new Error(message);
};

assert(/id="stat-balance"[^>]*>Rs 0<\/p>/.test(html) && /Net Balance/.test(html), 'Net Balance hero is missing');
assert((html.match(/class="compact-metric /g) || []).length === 3, 'Dashboard must have exactly three compact primary metrics');
assert((html.match(/class="compact-metric-content"/g) || []).length === 3, 'Compact metrics must use one shared internal structure');
assert((html.match(/class="compact-metric-heading"/g) || []).length === 3, 'Compact metric header rows are missing');
assert(/id="stat-today-expense"/.test(html), "Today's Expense metric is missing");
assert(/class="financial-overview-grid"/.test(html), 'Financial Overview container is missing');
assert(/id="health-breakdown-toggle"/.test(html) && /id="health-breakdown"/.test(html), 'Financial Health Score breakdown interaction is missing');
for (const label of ['Savings Rate', 'Income vs Expense', 'Net Balance / Liquidity', 'Payable Burden', 'Financial Stability']) {
    assert(html.includes(label), `Health breakdown is missing ${label}`);
}
assert(/aria-label="Savings:/.test(html) && /aria-label="Net Worth:/.test(html) && /aria-label="To Receive:/.test(html) && /aria-label="To Pay:/.test(html), 'Financial Overview metric explanations are missing');
assert(/id="recent-transactions-list"/.test(html), 'Recent Transactions section is missing');
assert(!/Cash Balance/.test(html), 'Legacy Cash Balance label remains on the dashboard');
assert(!/You Will Receive|You Need to Pay|Nothing pending/.test(html), 'Legacy noisy summary labels remain');

const positions = [
    html.indexOf('dashboard-summary-grid'),
    html.indexOf('my-accounts-section'),
    html.indexOf('dashboard-analytics'),
    html.indexOf('recent-transactions-list'),
    html.indexOf('dashboard-bottom-grid')
];
assert(positions.every(position => position >= 0) && positions.every((position, index) => index === 0 || position > positions[index - 1]), 'Dashboard sections are not in the required order');
assert(/View All Accounts/.test(html), 'Accounts listing action is missing');
assert(!/account-card-stats/.test(dashboard), 'Dashboard account cards still render income and expense details');
assert(/dashboard-account-card/.test(dashboard), 'Compact dashboard account card is missing');
assert(/Excluded from Net Balance/.test(dashboard) && /include_in_net_balance/.test(dashboard), 'Dashboard account exclusion label or behavior is missing');

assert(/\.main-content:has\(> #dashboard-page\.active\) > \.app-footer \{[\s\S]*?position:\s*static[\s\S]*?margin-top:\s*0/.test(styles), 'Dashboard footer must remain in normal flow after the page');
assert(/@media \(min-width: 992px\)[\s\S]*?\.main-content:has\(> #dashboard-page\.active\) \{[\s\S]*?overflow-y:\s*auto/.test(styles), 'Desktop dashboard must use one natural vertical scroll owner');
assert(/\.main-content:has\(> #dashboard-page\.active\) > #dashboard-page\.active \{[\s\S]*?overflow:\s*visible/.test(styles), 'Dashboard page content must not be clipped inside the shell');
assert(/#dashboard-page \.net-balance-card,[\s\S]*?#dashboard-page \.compact-metric \{[\s\S]*?min-height:\s*138px/.test(styles), 'Dashboard summary cards need the compact aligned height');
assert(/#dashboard-page \.my-accounts-section \.section-header \{[\s\S]*?margin-bottom:\s*\.65rem/.test(styles), 'Accounts heading and cards need compact spacing');

assert(/today_statistics/.test(controller), 'Dashboard API does not return today statistics');
assert(/\$netBalance\s*=\s*\$this->balanceService->getNetBalance\(\$userId\)/.test(controller), 'Net Balance must use the centralized account-inclusion calculation');
assert(/'total_balance'\s*=>\s*\$netBalance/.test(controller), 'Dashboard Net Balance must return the centralized result');
assert(/\$netWorth\s*=\s*\$netBalance\s*\+\s*\$goalAssets\s*\+\s*\$totalReceivable\s*-\s*\$totalPayable/.test(controller), 'Net Worth formula must include goal savings');
assert(/todayStats\?\.total_expense/.test(dashboard), "Today's Expense is not rendered from API data");
assert(/todayExpenseCount/.test(dashboard), "Today's transaction count is not rendered");
assert(/today_statistics/.test(offline) && /record\.date === today/.test(offline), "Offline pending changes do not update today's metric");
assert(/financial_health_score/.test(controller) && /financialHealthService->calculate/.test(controller), 'Health score must come from the central financial service');
assert(/healthScore\?\.breakdown/.test(dashboard), 'Health breakdown is not populated from the score service');
assert(/Math\.max\(0, Math\.min\(100, Math\.round\(rawScore\)\)\)/.test(dashboard), 'Gauge score must be clamped to 0–100');
assert(/stroke-dasharray', `\$\{score\}, 100`/.test(dashboard), 'Gauge progress must use the exact clamped score percentage');
assert(/data-health-state/.test(html) && /--health-accent/.test(styles), 'Score number, status, and gauge do not share one semantic state');
assert(/#dashboard-page \.compact-metric \.stat-value[\s\S]*white-space: nowrap/.test(styles), 'Compact metric amounts must remain on one line');
assert(/#dashboard-page \.compact-metric \.stat-value[\s\S]*text-overflow: clip/.test(styles), 'Compact metric amounts must not use ellipsis');
assert(!/content:\s*'▲'|content:\s*'▼'/.test(styles), 'Summary metric trend triangles must not be rendered');

console.log('Dashboard hierarchy checks passed');
