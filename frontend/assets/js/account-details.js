class AccountDetailsManager {
    constructor() {
        this.accountId = null;
        this.accountData = null;
        this.statementData = null;
        this.filters = {};
        this.currentPage = 1;
        this.pageSize = 20;
        this._eventHandler = null;
        this.routeQueryKeys = ['id', 'page', 'limit', 'type', 'search', 'start_date', 'end_date'];
        this._requestController = null;
        this._requestSequence = 0;
        this._routeSignal = null;
        this._routeAbortHandler = null;
        this._isRouteCurrent = () => true;
    }

    async onMount(routeContext = null) {
        const hashParts = window.location.hash.split('?');
        const params = routeContext?.query || new URLSearchParams(hashParts[1] || '');
        const nextAccountId = params.get('id');

        this._cancelActiveRequest();
        this.accountId = nextAccountId;
        this.accountData = null;
        this.statementData = null;
        this.currentPage = Math.max(1, Number(params.get('page')) || 1);
        this.pageSize = Math.max(1, Math.min(200, Number(params.get('limit')) || 20));
        this.filters = {};
        ['type', 'search', 'start_date', 'end_date'].forEach(key => {
            if (params.get(key)) this.filters[key] = params.get(key);
        });
        this._routeSignal = routeContext?.signal || null;
        this._isRouteCurrent = routeContext?.isCurrent || (() => true);

        if (!this.accountId) {
            this.renderError('Account ID not found');
            return;
        }

        this.showSkeleton();
        if (this._eventHandler) document.removeEventListener('app:data-changed', this._eventHandler);
        this._eventHandler = () => {
            this.loadAccountStatement();
        };
        document.addEventListener('app:data-changed', this._eventHandler);
        await this.loadAccountStatement();
    }

    onUnmount() {
        this._requestSequence++;
        this._cancelActiveRequest();
        this.accountData = null;
        this.statementData = null;
        if (window.ChartService) {
            ChartService.destroy('#accountCashFlowChart');
            ChartService.destroy('#accountIncomeExpenseChart');
            ChartService.destroy('#accountRunningBalanceChart');
        }
        if (this._eventHandler) {
            document.removeEventListener('app:data-changed', this._eventHandler);
            this._eventHandler = null;
        }
    }

    showSkeleton() {
        const container = document.getElementById('account-details-content');
        if (!container) return;
        container.innerHTML = `
            <div class="skeleton-header"></div>
            <div class="skeleton-summary-grid">
                <div class="skeleton-card"></div>
                <div class="skeleton-card"></div>
                <div class="skeleton-card"></div>
                <div class="skeleton-card"></div>
                <div class="skeleton-card"></div>
                <div class="skeleton-card"></div>
                <div class="skeleton-card"></div>
            </div>
            <div class="skeleton-chart"></div>
            <div class="skeleton-table"></div>
        `;
    }

    _cancelActiveRequest() {
        if (this._routeSignal && this._routeAbortHandler) {
            this._routeSignal.removeEventListener?.('abort', this._routeAbortHandler);
        }
        this._requestController?.abort();
        this._requestController = null;
        this._routeAbortHandler = null;
    }

    async loadAccountStatement() {
        const container = document.getElementById('account-details-content');
        if (!container) return;

        this._cancelActiveRequest();
        const controller = new AbortController();
        this._requestController = controller;
        const requestedAccountId = String(this.accountId);
        const requestSequence = ++this._requestSequence;
        if (this._routeSignal?.aborted) controller.abort();
        else if (this._routeSignal) {
            this._routeAbortHandler = () => controller.abort();
            this._routeSignal.addEventListener('abort', this._routeAbortHandler, { once: true });
        }
        const isCurrent = () => requestSequence === this._requestSequence
            && requestedAccountId === String(this.accountId)
            && !controller.signal.aborted
            && this._isRouteCurrent();

        try {
            const response = await accountsAPI.getStatement(this.accountId, {
                ...this.filters, page: this.currentPage, limit: this.pageSize
            }, { signal: controller.signal });
            if (!isCurrent()) return;
            if (response.success) {
                if (String(response.data?.account?.id) !== requestedAccountId) {
                    this.renderError('Account not found');
                    return;
                }
                this.accountData = response.data.account;
                this.statementData = response.data;
                this.renderAccountDetails(response.data);
            } else {
                this.renderError(response.message || 'Failed to load account');
            }
        } catch (error) {
            if (!isCurrent() || error?.category === 'aborted_error' || error?.code === 'ABORTED_ERROR') return;
            this.accountData = null;
            this.statementData = null;
            this.renderError(error?.status === 404 || error?.status === 403
                ? 'Account not found'
                : 'Failed to load account details. Please try again.');
        } finally {
            if (this._requestController === controller) {
                if (this._routeSignal && this._routeAbortHandler) {
                    this._routeSignal.removeEventListener?.('abort', this._routeAbortHandler);
                }
                this._requestController = null;
                this._routeAbortHandler = null;
            }
        }
    }

    renderError(message) {
        const container = document.getElementById('account-details-content');
        if (!container) return;
        container.innerHTML = `
            <div class="acct-page-container">
                <div class="acct-header">
                    <div>
                        <button class="btn btn-light mb-2" onclick="window.appRouter?.navigate('dashboard')">
                            <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
                        </button>
                    </div>
                </div>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="bi bi-exclamation-triangle"></i></div>
                    <h4>Error</h4>
                    <p>${Formatters.escapeHTML(message)}</p>
                    <button class="btn btn-primary" onclick="window.appRouter?.navigate('dashboard')">Go to Dashboard</button>
                </div>
            </div>
        `;
    }

    renderAccountDetails(data) {
        const container = document.getElementById('account-details-content');
        if (!container) return;

        const { account, statement, summary, analytics, cash_flow, calculated_balance, pagination = {} } = data;
        const balance = calculated_balance !== undefined ? parseFloat(calculated_balance) : (parseFloat(account.calculated_balance) || parseFloat(account.balance) || 0);
        const storedBalance = parseFloat(account.balance) || 0;
        const openingBalance = parseFloat(data.opening_balance) || 0;
        const totalIncome = parseFloat(summary.total_income) || 0;
        const totalExpense = parseFloat(summary.total_expense) || 0;
        const transferIn = parseFloat(summary.transfer_in) || 0;
        const transferOut = parseFloat(summary.transfer_out) || 0;
        const karobarReceived = parseFloat(summary.karobar_received) || 0;
        const karobarPaid = parseFloat(summary.karobar_paid) || 0;
        const netCashFlow = parseFloat(data.net_change) || 0;
        const txCount = parseInt(summary.transaction_count) || 0;

        const accountTypeLabels = {
            bank: 'Bank Account', cash: 'Cash', esewa: 'eSewa', khalti: 'Khalti',
            ime_pay: 'IME Pay', wallet: 'Wallet', credit_card: 'Credit Card',
            savings: 'Savings Account', current: 'Current Account'
        };
        const accountTypeIcons = {
            bank: 'bi-bank', cash: 'bi-cash-stack', esewa: 'bi-phone',
            khalti: 'bi-phone', ime_pay: 'bi-phone', wallet: 'bi-wallet2',
            credit_card: 'bi-credit-card', savings: 'bi-piggy-bank', current: 'bi-bank'
        };
        const typeLabel = accountTypeLabels[account.type] || account.type;
        const icon = accountTypeIcons[account.type] || 'bi-wallet2';
        const color = account.color || '#10B981';
        const createdDate = account.created_at ? Formatters.date(account.created_at) : '--';
        const lastTx = analytics.last_transaction_date ? Formatters.date(analytics.last_transaction_date) : 'No transactions';
        const currency = account.currency || 'NPR';

        container.innerHTML = `
            <div class="acct-page-container">

                <!-- Premium Header -->
                <div class="acct-header-premium" style="border-left: 4px solid ${color};">
                    <div class="acct-header-top">
                        <div class="acct-header-top-left">
                            <button class="btn btn-light btn-sm rounded-pill" onclick="window.appRouter?.navigate('dashboard')" id="acct-back-btn">
                                <i class="bi bi-arrow-left me-1"></i> Dashboard
                            </button>
                        </div>
                        <div class="acct-header-top-right">
                            <a href="transaction.php?account_id=${account.id}" class="btn btn-outline-primary btn-sm rounded-pill" target="_blank">
                                <i class="bi bi-journal-text me-1"></i> Show Ledger
                            </a>
                        </div>
                    </div>
                    <div class="acct-header-body">
                        <div class="acct-header-icon" style="background:${color}15;color:${color}">
                            <i class="bi ${icon}"></i>
                        </div>
                        <div class="acct-header-info">
                            <div class="acct-header-name-row">
                                <h2 class="acct-header-name">${Formatters.escapeHTML(account.name)}</h2>
                                <span class="acct-badge acct-badge-type">${typeLabel}</span>
                                ${account.is_default ? '<span class="acct-badge acct-badge-default">Default Account</span>' : ''}
                            </div>
                            <div class="acct-header-meta">
                                <span><i class="bi bi-calendar3"></i> Created: ${createdDate}</span>
                                <span><i class="bi bi-clock"></i> Last: ${lastTx}</span>
                                <span><i class="bi bi-currency-exchange"></i> ${currency}</span>
                                <span class="acct-status-dot ${account.is_active ? 'active' : 'inactive'}">${account.is_active ? 'Active' : 'Inactive'}</span>
                            </div>
                        </div>
                        <div class="acct-header-balance">
                            <span class="acct-balance-label">Current Balance</span>
                            <span class="acct-balance-value" style="color:${color}">${Formatters.currency(balance)}</span>
                            ${storedBalance !== balance ? `<span class="acct-balance-warning"><i class="bi bi-exclamation-triangle"></i> Stored: ${Formatters.currency(storedBalance)}</span>` : ''}
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="acct-summary-grid">
                    <div class="acct-summary-card">
                        <div class="acct-summary-icon" style="background:${color}15;color:${color};"><i class="bi bi-wallet2"></i></div>
                        <div class="acct-summary-body">
                            <span class="acct-summary-label">Current Balance</span>
                            <span class="acct-summary-value" style="font-size:1.2rem;">${Formatters.currency(balance)}</span>
                        </div>
                    </div>
                    <div class="acct-summary-card">
                        <div class="acct-summary-icon" style="background:rgba(99,102,241,0.1);color:#6366F1;"><i class="bi bi-hourglass-split"></i></div>
                        <div class="acct-summary-body">
                            <span class="acct-summary-label">Statement Opening</span>
                            <span class="acct-summary-value">${Formatters.currency(openingBalance)}</span>
                        </div>
                    </div>
                    <div class="acct-summary-card">
                        <div class="acct-summary-icon" style="background:rgba(16,185,129,0.1);color:#10B981;"><i class="bi bi-arrow-down-left"></i></div>
                        <div class="acct-summary-body">
                            <span class="acct-summary-label">Total Income</span>
                            <span class="acct-summary-value income-text">${Formatters.currency(totalIncome)}</span>
                        </div>
                    </div>
                    <div class="acct-summary-card">
                        <div class="acct-summary-icon" style="background:rgba(239,68,68,0.1);color:#EF4444;"><i class="bi bi-arrow-up-right"></i></div>
                        <div class="acct-summary-body">
                            <span class="acct-summary-label">Total Expense</span>
                            <span class="acct-summary-value expense-text">${Formatters.currency(totalExpense)}</span>
                        </div>
                    </div>
                    <div class="acct-summary-card">
                        <div class="acct-summary-icon" style="background:rgba(59,130,246,0.1);color:#3B82F6;"><i class="bi bi-box-arrow-in-down"></i></div>
                        <div class="acct-summary-body">
                            <span class="acct-summary-label">Transfer In</span>
                            <span class="acct-summary-value transfer-text">${Formatters.currency(transferIn)}</span>
                        </div>
                    </div>
                    <div class="acct-summary-card">
                        <div class="acct-summary-icon" style="background:rgba(139,92,246,0.1);color:#8B5CF6;"><i class="bi bi-box-arrow-up"></i></div>
                        <div class="acct-summary-body">
                            <span class="acct-summary-label">Transfer Out</span>
                            <span class="acct-summary-value">${Formatters.currency(transferOut)}</span>
                        </div>
                    </div>
                    <div class="acct-summary-card">
                        <div class="acct-summary-icon" style="background:rgba(236,72,153,0.1);color:#EC4899;"><i class="bi bi-arrow-down-right"></i></div>
                        <div class="acct-summary-body">
                            <span class="acct-summary-label">Karobar Received</span>
                            <span class="acct-summary-value karobar-text">${Formatters.currency(karobarReceived)}</span>
                        </div>
                    </div>
                    <div class="acct-summary-card">
                        <div class="acct-summary-icon" style="background:rgba(168,85,247,0.1);color:#A855F7;"><i class="bi bi-arrow-up-left"></i></div>
                        <div class="acct-summary-body">
                            <span class="acct-summary-label">Karobar Paid</span>
                            <span class="acct-summary-value">${Formatters.currency(karobarPaid)}</span>
                        </div>
                    </div>
                    <div class="acct-summary-card">
                        <div class="acct-summary-icon" style="background:${netCashFlow >= 0 ? 'rgba(16,185,129,0.1)' : 'rgba(239,68,68,0.1)'};color:${netCashFlow >= 0 ? '#10B981' : '#EF4444'};"><i class="bi bi-graph-up-arrow"></i></div>
                        <div class="acct-summary-body">
                            <span class="acct-summary-label">Net Cash Flow</span>
                            <span class="acct-summary-value ${netCashFlow >= 0 ? 'income-text' : 'expense-text'}">${Formatters.currency(netCashFlow)}</span>
                        </div>
                    </div>
                    <div class="acct-summary-card">
                        <div class="acct-summary-icon" style="background:rgba(245,158,11,0.1);color:#F59E0B;"><i class="bi bi-list-check"></i></div>
                        <div class="acct-summary-body">
                            <span class="acct-summary-label">Transactions</span>
                            <span class="acct-summary-value">${txCount}</span>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="acct-quick-actions">
                    <button class="btn btn-success rounded-pill" onclick="window.appRouter?.navigate('transactions')"><i class="bi bi-plus-lg me-1"></i> Add Income</button>
                    <button class="btn btn-danger rounded-pill" onclick="window.appRouter?.navigate('transactions')"><i class="bi bi-dash-lg me-1"></i> Add Expense</button>
                    <button class="btn btn-outline-primary rounded-pill" onclick="window.appRouter?.navigate('transactions')"><i class="bi bi-arrow-left-right me-1"></i> Transfer</button>
                </div>

                <!-- Charts Row -->
                <div class="acct-charts-row">
                    <div class="card-premium acct-chart-card">
                        <h4 class="chart-title">Monthly Cash Flow</h4>
                        <div id="accountCashFlowChart" style="height: 280px;"></div>
                        <div id="accountCashFlowChart-empty" class="chart-empty-state" style="display:none;">
                            <div class="empty-state-icon"><i class="bi bi-bar-chart"></i></div>
                            <h4>No Cash Flow Data</h4>
                            <p>Add transactions to see your monthly cash flow</p>
                        </div>
                    </div>
                    <div class="card-premium acct-chart-card">
                        <h4 class="chart-title">Income vs Expense</h4>
                        <div id="accountIncomeExpenseChart" style="height: 280px;"></div>
                        <div id="accountIncomeExpenseChart-empty" class="chart-empty-state" style="display:none;">
                            <div class="empty-state-icon"><i class="bi bi-pie-chart"></i></div>
                            <h4>No Data</h4>
                            <p>Add income and expense transactions</p>
                        </div>
                    </div>
                    <div class="card-premium acct-chart-card">
                        <h4 class="chart-title">Running Balance Trend</h4>
                        <div id="accountRunningBalanceChart" style="height: 280px;"></div>
                        <div id="accountRunningBalanceChart-empty" class="chart-empty-state" style="display:none;">
                            <div class="empty-state-icon"><i class="bi bi-graph-up"></i></div>
                            <h4>No Data</h4>
                            <p>Transactions will appear here</p>
                        </div>
                    </div>
                </div>

                <!-- Analytics Panel -->
                <div class="card-premium acct-analytics-panel">
                    <h4 class="chart-title">Account Analytics</h4>
                    <div class="acct-analytics-grid">
                        <div class="acct-analytics-item">
                            <span class="acct-analytics-label">Highest Expense</span>
                            <span class="acct-analytics-value expense-text">${Formatters.currency(analytics.highest_expense || 0)}</span>
                        </div>
                        <div class="acct-analytics-item">
                            <span class="acct-analytics-label">Highest Income</span>
                            <span class="acct-analytics-value income-text">${Formatters.currency(analytics.highest_income || 0)}</span>
                        </div>
                        <div class="acct-analytics-item">
                            <span class="acct-analytics-label">Avg Daily Spending</span>
                            <span class="acct-analytics-value">${Formatters.currency(analytics.avg_daily_spending || 0)}</span>
                        </div>
                        <div class="acct-analytics-item">
                            <span class="acct-analytics-label">Avg Monthly Spending</span>
                            <span class="acct-analytics-value">${Formatters.currency(analytics.avg_monthly_spending || 0)}</span>
                        </div>
                        <div class="acct-analytics-item">
                            <span class="acct-analytics-label">Most Used Category</span>
                            <span class="acct-analytics-value">${analytics.most_used_category ? Formatters.escapeHTML(analytics.most_used_category.category_name) : 'N/A'}</span>
                        </div>
                        <div class="acct-analytics-item">
                            <span class="acct-analytics-label">Largest Transaction</span>
                            <span class="acct-analytics-value">${Formatters.currency(analytics.largest_transaction || 0)}</span>
                        </div>
                        <div class="acct-analytics-item">
                            <span class="acct-analytics-label">Transaction Count</span>
                            <span class="acct-analytics-value">${analytics.total_transactions || 0}</span>
                        </div>
                        <div class="acct-analytics-item">
                            <span class="acct-analytics-label">Last Activity</span>
                            <span class="acct-analytics-value">${analytics.last_transaction_date ? Formatters.date(analytics.last_transaction_date) : 'N/A'}</span>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="acct-toolbar">
                    <div class="acct-filters">
                        <div class="acct-search-wrapper">
                            <i class="bi bi-search"></i>
                            <input type="search" id="acct-search-input" placeholder="Search description, category..." class="acct-search" value="${Formatters.escapeHTML(this.filters.search || '')}">
                        </div>
                        <select id="acct-filter-type" class="form-select form-select-sm">
                            <option value="">All Types</option>
                            <option value="income" ${this.filters.type === 'income' ? 'selected' : ''}>Income</option>
                            <option value="expense" ${this.filters.type === 'expense' ? 'selected' : ''}>Expense</option>
                            <option value="transfer" ${this.filters.type === 'transfer' ? 'selected' : ''}>Transfer</option>
                            <option value="goal_contribution" ${this.filters.type === 'goal_contribution' ? 'selected' : ''}>Goal Contribution</option>
                            <option value="karobar" ${this.filters.type === 'karobar' ? 'selected' : ''}>Karobar Cash Movement</option>
                        </select>
                        <select id="acct-filter-period" class="form-select form-select-sm">
                            <option value="" ${!this.filters.start_date && !this.filters.end_date ? 'selected' : ''}>All Time</option>
                            <option value="today">Today</option>
                            <option value="week">This Week</option>
                            <option value="month">This Month</option>
                            <option value="year">This Year</option>
                            <option value="custom" ${this.filters.start_date || this.filters.end_date ? 'selected' : ''}>Custom Range</option>
                        </select>
                        <div class="acct-date-range" id="acct-date-range" style="display:${this.filters.start_date || this.filters.end_date ? 'flex' : 'none'};">
                            <input type="date" id="acct-filter-start" class="form-control form-control-sm" value="${this.filters.start_date || ''}">
                            <span class="mx-1">to</span>
                            <input type="date" id="acct-filter-end" class="form-control form-control-sm" value="${this.filters.end_date || ''}">
                        </div>
                        <button class="btn btn-sm btn-primary" id="acct-apply-filters"><i class="bi bi-funnel"></i> Apply</button>
                        <button class="btn btn-sm btn-outline-secondary" id="acct-clear-filters"><i class="bi bi-x-lg"></i> Clear</button>
                    </div>
                    <div class="acct-export-btns">
                        <button class="btn btn-sm btn-outline-secondary" id="acct-export-csv" title="Export CSV"><i class="bi bi-filetype-csv"></i> CSV</button>
                        <button class="btn btn-sm btn-outline-secondary" id="acct-export-print" title="Print"><i class="bi bi-printer"></i> Print</button>
                    </div>
                </div>

                <!-- Statement Table -->
                <div class="acct-statement-wrapper">
                    <div class="acct-statement-scroll">
                        <table class="table acct-statement-table" id="acct-statement-table">
                            <thead>
                                <tr>
                                    <th class="sticky-col">#</th>
                                    <th class="sticky-col">Date</th>
                                    <th>Type</th>
                                    <th>Category</th>
                                    <th>Subcategory</th>
                                    <th>Description</th>
                                    <th>Reference</th>
                                    <th class="text-end">Money In</th>
                                    <th class="text-end">Money Out</th>
                                    <th class="text-end">Running Balance</th>
                                </tr>
                            </thead>
                            <tbody id="acct-statement-body">
                            </tbody>
                        </table>
                    </div>
                    ${statement.length === 0 || (statement.length === 1 && statement[0].type === 'opening') ? `
                        <div class="empty-state py-5">
                            <div class="empty-state-icon"><i class="bi bi-receipt"></i></div>
                            <h4>No Transactions Yet</h4>
                            <p>Start by adding your first transaction to this account</p>
                            <button class="btn btn-primary" onclick="window.appRouter?.navigate('transactions')"><i class="bi bi-plus-lg me-1"></i> Add Transaction</button>
                        </div>
                    ` : ''}
                    ${pagination.total_pages > 1 ? `
                        <div class="d-flex justify-content-between align-items-center p-3 border-top">
                            <span class="text-muted small">Page ${pagination.page} of ${pagination.total_pages} · ${pagination.total_rows} rows</span>
                            <div class="btn-group btn-group-sm">
                                <button id="acct-page-previous" data-page="${pagination.page - 1}" class="btn btn-outline-secondary" ${pagination.page <= 1 ? 'disabled' : ''}>Previous</button>
                                <button id="acct-page-next" data-page="${pagination.page + 1}" class="btn btn-outline-secondary" ${pagination.page >= pagination.total_pages ? 'disabled' : ''}>Next</button>
                            </div>
                        </div>` : ''}
                </div>
            </div>
        `;

        this.renderStatementRows(statement);
        this.renderCashFlowChart(cash_flow);
        this.renderIncomeExpenseChart(statement);
        this.renderRunningBalanceChart(statement);
        this.setupFilterListeners();
        this.setupExportListeners();
        this.setupPaginationListeners();
    }

    renderStatementRows(rows) {
        const tbody = document.getElementById('acct-statement-body');
        if (!tbody) return;

        const typeBadges = {
            income: '<span class="acct-type-badge acct-type-income">Income</span>',
            expense: '<span class="acct-type-badge acct-type-expense">Expense</span>',
            transfer: '<span class="acct-type-badge acct-type-transfer">Transfer</span>',
            goal_contribution: '<span class="acct-type-badge acct-type-transfer">Goal Contribution</span>',
            karobar_borrowed: '<span class="acct-type-badge acct-type-income">Borrowed</span>',
            karobar_returned: '<span class="acct-type-badge acct-type-income">Received Back</span>',
            karobar_lent: '<span class="acct-type-badge acct-type-expense">Lent</span>',
            karobar_repaid: '<span class="acct-type-badge acct-type-expense">Repaid</span>',
            opening: '<span class="acct-type-badge acct-type-opening">Opening</span>',
        };

        tbody.innerHTML = rows.map((row, idx) => {
            if (row.type === 'opening') {
                return `
                    <tr class="acct-row-opening">
                        <td>--</td>
                        <td><span class="acct-date-text">--</span></td>
                        <td>${typeBadges.opening}</td>
                        <td colspan="3" class="text-muted fst-italic">Opening Balance</td>
                        <td>--</td>
                        <td class="text-end">--</td>
                        <td class="text-end">--</td>
                        <td class="text-end acct-balance-cell">${Formatters.currency(row.running_balance)}</td>
                    </tr>
                `;
            }

            const karobarLabel = row.karobar_type ? `<span class="acct-karobar-tag">Karobar</span>` : '';
            const dateStr = row.date ? Formatters.date(row.date) : '--';
            const rowClass = idx % 2 === 0 ? 'acct-row-even' : 'acct-row-odd';

            return `
                <tr class="${rowClass}">
                    <td class="text-muted">${(this.statementData?.pagination?.offset || 0) + idx}</td>
                    <td><span class="acct-date-text">${dateStr}</span></td>
                    <td>${typeBadges[row.type] || typeBadges.opening}</td>
                    <td>${Formatters.escapeHTML(row.category_name || '--')}</td>
                    <td>${Formatters.escapeHTML(row.subcategory_name || '--')}</td>
                    <td>
                        <div class="acct-desc-cell">
                            <span>${Formatters.escapeHTML(row.description || '--')}</span>
                            ${karobarLabel}
                        </div>
                    </td>
                    <td><span class="acct-ref-text">${Formatters.escapeHTML(row.reference || '--')}</span></td>
                    <td class="text-end acct-money-in">${row.money_in ? Formatters.currency(row.money_in) : '--'}</td>
                    <td class="text-end acct-money-out">${row.money_out ? Formatters.currency(row.money_out) : '--'}</td>
                    <td class="text-end acct-balance-cell">${Formatters.currency(row.running_balance)}</td>
                </tr>
            `;
        }).join('');
    }

    renderCashFlowChart(cashFlow) {
        if (!cashFlow || cashFlow.length === 0) {
            const chartEl = document.getElementById('accountCashFlowChart');
            const emptyEl = document.getElementById('accountCashFlowChart-empty');
            if (chartEl) chartEl.style.display = 'none';
            if (emptyEl) emptyEl.style.display = 'flex';
            return;
        }

        const months = cashFlow.map(d => d.month);
        const income = cashFlow.map(d => parseFloat(d.income) || 0);
        const expense = cashFlow.map(d => parseFloat(d.expense) || 0);
        const transferIn = cashFlow.map(d => parseFloat(d.transfer_in) || 0);
        const transferOut = cashFlow.map(d => parseFloat(d.transfer_out) || 0);

        const options = {
            series: [
                { name: 'Income', data: income },
                { name: 'Expense', data: expense },
                { name: 'Transfer In', data: transferIn },
                { name: 'Transfer Out', data: transferOut }
            ],
            chart: {
                type: 'bar', height: 280, stacked: false, toolbar: { show: true, tools: { download: true } },
                fontFamily: 'Inter, sans-serif', animations: { enabled: true, easing: 'easeinout' }
            },
            plotOptions: { bar: { borderRadius: 4, columnWidth: '55%' } },
            colors: ['#10B981', '#EF4444', '#3B82F6', '#8B5CF6'],
            dataLabels: { enabled: false },
            stroke: { show: true, width: 2, colors: ['transparent'] },
            xaxis: { categories: months, labels: { style: { colors: '#6B7280', fontSize: '12px' } } },
            yaxis: { labels: { style: { colors: '#6B7280', fontSize: '12px' }, formatter: (v) => Formatters.compactCurrency(v) } },
            fill: { opacity: 1 },
            tooltip: { y: { formatter: (v) => Formatters.currency(v) } },
            legend: { position: 'top', horizontalAlign: 'right', fontSize: '13px' },
            grid: { borderColor: 'var(--border-color, #e2e8f0)', strokeDashArray: 4 }
        };

        if (window.ChartService) {
            ChartService.create('#accountCashFlowChart', options);
        }
    }

    renderIncomeExpenseChart(statement) {
        const data = statement.filter(r => r.type === 'income' || r.type === 'expense');
        const totalIncome = data.filter(r => r.type === 'income').reduce((s, r) => s + (r.money_in || 0), 0);
        const totalExpense = data.filter(r => r.type === 'expense').reduce((s, r) => s + (r.money_out || 0), 0);

        if (totalIncome === 0 && totalExpense === 0) {
            const chartEl = document.getElementById('accountIncomeExpenseChart');
            const emptyEl = document.getElementById('accountIncomeExpenseChart-empty');
            if (chartEl) chartEl.style.display = 'none';
            if (emptyEl) emptyEl.style.display = 'flex';
            return;
        }

        const options = {
            series: [totalIncome, totalExpense],
            chart: { type: 'donut', height: 280, fontFamily: 'Inter, sans-serif', animations: { enabled: true } },
            labels: ['Income', 'Expense'],
            colors: ['#10B981', '#EF4444'],
            dataLabels: { enabled: true, formatter: (v) => Math.round(v) + '%' },
            tooltip: { y: { formatter: (v) => Formatters.currency(v) } },
            legend: { position: 'bottom', fontSize: '13px' },
            plotOptions: { pie: { donut: { size: '60%', labels: { show: true, total: { show: true, label: 'Total', formatter: () => Formatters.currency(totalIncome + totalExpense) } } } } },
            responsive: [{ breakpoint: 480, options: { chart: { height: 220 }, legend: { position: 'bottom' } } }]
        };

        if (window.ChartService) {
            ChartService.create('#accountIncomeExpenseChart', options);
        }
    }

    renderRunningBalanceChart(statement) {
        const data = statement.filter(r => r.type !== 'opening');
        if (data.length === 0) {
            const chartEl = document.getElementById('accountRunningBalanceChart');
            const emptyEl = document.getElementById('accountRunningBalanceChart-empty');
            if (chartEl) chartEl.style.display = 'none';
            if (emptyEl) emptyEl.style.display = 'flex';
            return;
        }

        const dates = data.map(r => r.date ? Formatters.date(r.date) : '');
        const balances = data.map(r => parseFloat(r.running_balance) || 0);

        const options = {
            series: [{ name: 'Running Balance', data: balances }],
            chart: { type: 'area', height: 280, toolbar: { show: true, tools: { download: true } }, fontFamily: 'Inter, sans-serif', animations: { enabled: true, easing: 'easeinout' } },
            colors: ['#6366F1'],
            fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.1, stops: [0, 90, 100] } },
            dataLabels: { enabled: false },
            stroke: { curve: 'smooth', width: 2 },
            xaxis: { categories: dates, labels: { style: { colors: '#6B7280', fontSize: '11px' } } },
            yaxis: { labels: { style: { colors: '#6B7280', fontSize: '12px' }, formatter: (v) => Formatters.compactCurrency(v) } },
            tooltip: { y: { formatter: (v) => Formatters.currency(v) } },
            grid: { borderColor: 'var(--border-color, #e2e8f0)', strokeDashArray: 4 }
        };

        if (window.ChartService) {
            ChartService.create('#accountRunningBalanceChart', options);
        }
    }

    setupFilterListeners() {
        const applyBtn = document.getElementById('acct-apply-filters');
        const clearBtn = document.getElementById('acct-clear-filters');
        const searchInput = document.getElementById('acct-search-input');
        const periodSelect = document.getElementById('acct-filter-period');
        const dateRange = document.getElementById('acct-date-range');

        if (applyBtn) {
            applyBtn.addEventListener('click', () => this.applyFilters());
        }
        if (clearBtn) {
            clearBtn.addEventListener('click', () => this.clearFilters());
        }
        if (searchInput) {
            let timeout;
            searchInput.addEventListener('input', () => {
                clearTimeout(timeout);
                timeout = setTimeout(() => this.applyFilters(), 400);
            });
        }
        if (periodSelect && dateRange) {
            periodSelect.addEventListener('change', () => {
                dateRange.style.display = periodSelect.value === 'custom' ? 'flex' : 'none';
            });
        }
    }

    applyFilters() {
        const type = document.getElementById('acct-filter-type')?.value || '';
        const period = document.getElementById('acct-filter-period')?.value || '';
        const search = document.getElementById('acct-search-input')?.value || '';

        this.filters = {};
        if (type) this.filters.type = type;
        if (search) this.filters.search = search;

        if (period === 'custom') {
            const s = document.getElementById('acct-filter-start')?.value;
            const e = document.getElementById('acct-filter-end')?.value;
            if (s) this.filters.start_date = s;
            if (e) this.filters.end_date = e;
        } else if (period) {
            const now = new Date();
            switch (period) {
                case 'today':
                    this.filters.start_date = this.filters.end_date = now.toISOString().split('T')[0];
                    break;
                case 'week': {
                    const ws = new Date(now);
                    ws.setDate(now.getDate() - now.getDay() + 1);
                    this.filters.start_date = ws.toISOString().split('T')[0];
                    const we = new Date(ws);
                    we.setDate(ws.getDate() + 6);
                    this.filters.end_date = we.toISOString().split('T')[0];
                    break;
                }
                case 'month':
                    this.filters.start_date = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split('T')[0];
                    this.filters.end_date = new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString().split('T')[0];
                    break;
                case 'year':
                    this.filters.start_date = new Date(now.getFullYear(), 0, 1).toISOString().split('T')[0];
                    this.filters.end_date = new Date(now.getFullYear(), 11, 31).toISOString().split('T')[0];
                    break;
            }
        }

        this.currentPage = 1;
        this.showSkeleton();
        this.loadAccountStatement();
    }

    clearFilters() {
        this.filters = {};
        this.currentPage = 1;
        const typeEl = document.getElementById('acct-filter-type');
        const periodEl = document.getElementById('acct-filter-period');
        const searchEl = document.getElementById('acct-search-input');
        if (typeEl) typeEl.value = '';
        if (periodEl) periodEl.value = '';
        if (searchEl) searchEl.value = '';
        this.showSkeleton();
        this.loadAccountStatement();
    }

    goToPage(page) {
        const total = Number(this.statementData?.pagination?.total_pages || 1);
        this.currentPage = Math.max(1, Math.min(total, Number(page) || 1));
        this.showSkeleton();
        this.loadAccountStatement();
    }

    setupPaginationListeners() {
        ['acct-page-previous', 'acct-page-next'].forEach(id => {
            const button = document.getElementById(id);
            if (button) button.addEventListener('click', () => this.goToPage(button.dataset.page));
        });
    }

    setupExportListeners() {
        const csvBtn = document.getElementById('acct-export-csv');
        const printBtn = document.getElementById('acct-export-print');

        if (csvBtn) {
            csvBtn.addEventListener('click', () => this.exportCSV());
        }
        if (printBtn) {
            printBtn.addEventListener('click', () => window.print());
        }
    }

    exportCSV() {
        if (!this.statementData || !this.statementData.statement) return;

        const headers = ['Date', 'Type', 'Category', 'Subcategory', 'Description', 'Reference', 'Money In', 'Money Out', 'Running Balance'];
        const rows = this.statementData.statement.map(row => [
            row.date || '',
            row.type || '',
            row.category_name || '',
            row.subcategory_name || '',
            row.description || '',
            row.reference || '',
            row.money_in || '',
            row.money_out || '',
            row.running_balance || ''
        ]);

        let csv = headers.join(',') + '\n';
        rows.forEach(row => {
            csv += row.map(cell => `"${String(cell).replace(/"/g, '""')}"`).join(',') + '\n';
        });

        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `${this.accountData?.name || 'account'}_statement.csv`;
        link.click();
        URL.revokeObjectURL(url);
    }
}

window.AccountDetailsManager = AccountDetailsManager;
