// Dashboard Module - Refactored with Component Lifecycle & ChartService
class DashboardManager {
    constructor() {
        this.currentPeriod = 'month';
        this.customStartDate = null;
        this.customEndDate = null;
        this.hasData = false;
        this._periodHandler = null;
        this._isLoading = false;
        this._reloadPending = false;
        this._customApplyHandler = null;
        this._healthBreakdownHandler = null;
        this._viewportHandler = null;
        this._viewportFrame = null;
        this._hasRendered = false;
        this._renderedUserId = null;
    }

    adjustMobileNavPadding() {
        const update = () => {
            const nav = document.querySelector('.top-nav');
            const main = document.querySelector('.main-content');
            if (nav && window.innerWidth < 992) {
                main.style.setProperty('--mobile-nav-height', nav.offsetHeight + 'px');
            } else if (main) {
                main.style.removeProperty('--mobile-nav-height');
            }
        };
        update();
        this._viewportHandler = () => {
            if (this._viewportFrame !== null) return;
            const scheduleFrame = window.requestAnimationFrame || (callback => setTimeout(callback, 0));
            this._viewportFrame = scheduleFrame(() => {
                this._viewportFrame = null;
                update();
            });
        };
        window.addEventListener('resize', this._viewportHandler, { passive: true });
        window.addEventListener('orientationchange', this._viewportHandler, { passive: true });
    }

    onMount() {
        const userId = window.authManager?.getCurrentUser?.()?.id;
        if (String(this._renderedUserId ?? '') !== String(userId ?? '')) this._hasRendered = false;
        this.adjustMobileNavPadding();
        this.setupEventListeners();
        this.setDashboardGreeting();
        this.initCharts();
        this.updatePeriodLabel();
        this.loadDashboardData();
        this._dataChangeHandler = () => {
            this.loadDashboardData();
        };
        document.addEventListener('app:data-changed', this._dataChangeHandler);
    }

    onUnmount() {
        if (this._viewportHandler) {
            window.removeEventListener('resize', this._viewportHandler);
            window.removeEventListener('orientationchange', this._viewportHandler);
            this._viewportHandler = null;
        }
        if (this._viewportFrame !== null) {
            const cancelFrame = window.cancelAnimationFrame || clearTimeout;
            cancelFrame(this._viewportFrame);
            this._viewportFrame = null;
        }
        if (this._periodHandler) {
            const menu = document.getElementById('dashboard-period-menu');
            if (menu) {
                menu.removeEventListener('click', this._periodHandler);
            }
            this._periodHandler = null;
        }
        if (this._dataChangeHandler) {
            document.removeEventListener('app:data-changed', this._dataChangeHandler);
            this._dataChangeHandler = null;
        }
        const applyBtn = document.getElementById('apply-custom-date');
        if (applyBtn && this._customApplyHandler) {
            applyBtn.removeEventListener('click', this._customApplyHandler);
            this._customApplyHandler = null;
        }
        const breakdownToggle = document.getElementById('health-breakdown-toggle');
        if (breakdownToggle && this._healthBreakdownHandler) {
            breakdownToggle.removeEventListener('click', this._healthBreakdownHandler);
            this._healthBreakdownHandler = null;
        }
        if (window.ChartService) {
            ChartService.destroy('#incomeExpenseChart');
            ChartService.destroy('#categoryChart');
        }
    }

    setupEventListeners() {
        const breakdownToggle = document.getElementById('health-breakdown-toggle');
        if (breakdownToggle) {
            if (this._healthBreakdownHandler) breakdownToggle.removeEventListener('click', this._healthBreakdownHandler);
            this._healthBreakdownHandler = () => {
                const breakdown = document.getElementById('health-breakdown');
                if (!breakdown) return;
                const isOpening = breakdown.hidden;
                breakdown.hidden = !isOpening;
                breakdownToggle.setAttribute('aria-expanded', String(isOpening));
                breakdownToggle.innerHTML = `${isOpening ? 'Hide' : 'View'} breakdown <i class="bi bi-chevron-${isOpening ? 'up' : 'down'}" aria-hidden="true"></i>`;
            };
            breakdownToggle.addEventListener('click', this._healthBreakdownHandler);
        }

        const menu = document.getElementById('dashboard-period-menu');
        if (menu) {
            menu.removeEventListener('click', this._periodHandler);
            this._periodHandler = (e) => {
                const item = e.target.closest('.dropdown-item');
                if (!item) return;
                const value = item.dataset.value;
                if (!value) return;

                if (value === 'custom') {
                    const modal = new bootstrap.Modal(document.getElementById('customDateModal'));
                    // Pre-fill with current period
                    const { startDate, endDate } = this.getPeriodDates();
                    const cs = document.getElementById('custom-start-date');
                    const ce = document.getElementById('custom-end-date');
                    if (cs) cs.value = startDate;
                    if (ce) ce.value = endDate;
                    document.getElementById('custom-date-error').style.display = 'none';
                    modal.show();
                    return;
                }

                this.currentPeriod = value;
                this.customStartDate = null;
                this.customEndDate = null;
                document.querySelectorAll('#dashboard-period-menu .dropdown-item').forEach(el => el.classList.remove('active'));
                item.classList.add('active');
                this.updatePeriodLabel();
                this.loadDashboardData();
            };
            menu.addEventListener('click', this._periodHandler);
        }

        // Custom date apply
        const applyBtn = document.getElementById('apply-custom-date');
        if (applyBtn) {
            if (this._customApplyHandler) applyBtn.removeEventListener('click', this._customApplyHandler);
            this._customApplyHandler = () => {
                const startInput = document.getElementById('custom-start-date');
                const endInput = document.getElementById('custom-end-date');
                const errorEl = document.getElementById('custom-date-error');
                if (!startInput || !endInput) return;

                const sd = startInput.value;
                const ed = endInput.value;
                if (!sd || !ed) return;

                if (sd > ed) {
                    errorEl.style.display = 'block';
                    return;
                }
                errorEl.style.display = 'none';

                this.currentPeriod = 'custom';
                this.customStartDate = sd;
                this.customEndDate = ed;

                // Set active on custom item
                document.querySelectorAll('#dashboard-period-menu .dropdown-item').forEach(el => el.classList.remove('active'));
                const customItem = document.querySelector('#dashboard-period-menu .dropdown-item[data-value="custom"]');
                if (customItem) customItem.classList.add('active');

                this.updatePeriodLabel();
                bootstrap.Modal.getInstance(document.getElementById('customDateModal')).hide();
                this.loadDashboardData();
            };
            applyBtn.addEventListener('click', this._customApplyHandler);
        }
    }

    setDashboardGreeting() {
        const greetingEl = document.getElementById('dashboard-greeting');
        const userNameEl = document.getElementById('user-name');

        if (greetingEl) {
            const hour = new Date().getHours();
            let greeting, emoji;
            if (hour < 12) { greeting = 'Good Morning'; emoji = '☀️'; }
            else if (hour < 17) { greeting = 'Good Afternoon'; emoji = '👋'; }
            else { greeting = 'Good Evening'; emoji = '🌙'; }

            const firstName = userNameEl ? userNameEl.textContent.split(' ')[0] : '';
            if (firstName && firstName !== 'User' && firstName !== 'user@email.com') {
                greetingEl.textContent = `${greeting}, ${firstName} ${emoji}`;
            } else {
                greetingEl.textContent = `${greeting} ${emoji}`;
            }
        }
    }

    initCharts() {
        const incomeExpenseOptions = {
            series: [{ name: 'Income', data: [] }, { name: 'Expense', data: [] }],
            chart: {
                type: 'bar',
                height: 300,
                toolbar: { show: false },
                fontFamily: 'Inter, sans-serif'
            },
            plotOptions: { bar: { borderRadius: 8, columnWidth: '60%' } },
            colors: ['#10B981', '#EF4444'],
            dataLabels: { enabled: false },
            stroke: { show: true, width: 2, colors: ['transparent'] },
            xaxis: {
                categories: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                labels: { style: { colors: '#6B7280', fontSize: '12px' } }
            },
            yaxis: {
                labels: {
                    style: { colors: '#6B7280', fontSize: '12px' },
                    formatter: (val) => Formatters.compactCurrency(val)
                }
            },
            fill: { opacity: 1 },
            tooltip: {
                y: { formatter: (val) => Formatters.currency(val) }
            },
            legend: { position: 'top', horizontalAlign: 'right' }
        };

        const categoryOptions = {
            series: [],
            chart: { type: 'donut', height: 300, fontFamily: 'Inter, sans-serif' },
            labels: [],
            colors: ['#10B981', '#EF4444', '#F59E0B', '#3B82F6', '#8B5CF6', '#EC4899', '#14B8A6', '#F97316'],
            plotOptions: { pie: { donut: { size: '70%' } } },
            dataLabels: { enabled: false },
            legend: { position: 'bottom', horizontalAlign: 'center' },
            tooltip: {
                y: { formatter: (val) => Formatters.currency(val) }
            }
        };

        if (window.ChartService) {
            ChartService.create('#incomeExpenseChart', incomeExpenseOptions);
            ChartService.create('#categoryChart', categoryOptions);
        }
    }

    updateCharts(data) {
        this.hasData = data.monthly_data && data.monthly_data.length > 0;

        const incomeExpenseChartEl = document.getElementById('incomeExpenseChart');
        const incomeExpenseEmptyEl = document.getElementById('incomeExpenseChart-empty');

        if (this.hasData && data.monthly_data) {
            const months = data.monthly_data.map(d => d.month);
            const income = data.monthly_data.map(d => parseFloat(d.income) || 0);
            const expense = data.monthly_data.map(d => parseFloat(d.expense) || 0);

            if (incomeExpenseChartEl) incomeExpenseChartEl.style.display = 'block';
            if (incomeExpenseEmptyEl) incomeExpenseEmptyEl.style.display = 'none';

            if (window.ChartService) {
                ChartService.updateOptions('#incomeExpenseChart', { xaxis: { categories: months } });
                ChartService.updateSeries('#incomeExpenseChart', [
                    { name: 'Income', data: income },
                    { name: 'Expense', data: expense }
                ]);
            }
        } else {
            if (incomeExpenseChartEl) incomeExpenseChartEl.style.display = 'none';
            if (incomeExpenseEmptyEl) incomeExpenseEmptyEl.style.display = 'flex';
        }

        const categoryChartEl = document.getElementById('categoryChart');
        const categoryEmptyEl = document.getElementById('categoryChart-empty');
        const breakdown = data.expense_breakdown || [];
        const hasCategoryData = breakdown.length > 0;

        if (hasCategoryData) {
            const categories = breakdown.map(d => d.category_name);
            const amounts = breakdown.map(d => parseFloat(d.total_amount) || 0);
            const colors = breakdown.map(d => d.category_color || null).filter(Boolean);

            if (categoryChartEl) categoryChartEl.style.display = 'block';
            if (categoryEmptyEl) categoryEmptyEl.style.display = 'none';

            if (window.ChartService) {
                const updateOpts = { labels: categories };
                if (colors.length === categories.length) updateOpts.colors = colors;
                ChartService.updateOptions('#categoryChart', updateOpts);
                ChartService.updateSeries('#categoryChart', amounts);
            }
        } else {
            if (categoryChartEl) categoryChartEl.style.display = 'none';
            if (categoryEmptyEl) categoryEmptyEl.style.display = 'flex';
        }
    }

    getPeriodDates() {
        if (this.currentPeriod === 'custom' && this.customStartDate && this.customEndDate) {
            return { startDate: this.customStartDate, endDate: this.customEndDate };
        }

        const period = ['today', 'week', 'month', 'year'].includes(this.currentPeriod) ? this.currentPeriod : 'month';
        const range = DateUtils.getKathmanduRange(period);
        return { startDate: range.start, endDate: range.end };
    }

    updatePeriodLabel() {
        const label = document.getElementById('dashboard-period-label');
        if (!label) return;
        if (this.currentPeriod === 'custom' && this.customStartDate && this.customEndDate) {
            const fmt = (d) => {
                const dt = new Date(d + 'T00:00:00');
                return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            };
            if (this.customStartDate === this.customEndDate) {
                label.textContent = fmt(this.customStartDate);
            } else {
                label.textContent = `${fmt(this.customStartDate)} – ${fmt(this.customEndDate)}`;
            }
            return;
        }
        const activeItem = document.querySelector('#dashboard-period-menu .dropdown-item.active');
        label.textContent = activeItem ? activeItem.textContent.trim() : 'This Month';
    }

    getPeriodLabel() {
        if (this.currentPeriod === 'custom' && this.customStartDate && this.customEndDate) {
            const fmt = (d) => {
                const dt = new Date(d + 'T00:00:00');
                return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            };
            if (this.customStartDate === this.customEndDate) {
                return fmt(this.customStartDate);
            }
            return `${fmt(this.customStartDate)} – ${fmt(this.customEndDate)}`;
        }
        const map = { today: 'Today', week: 'This Week', month: 'This Month', year: 'This Year' };
        return map[this.currentPeriod] || 'This Month';
    }

    async loadDashboardData() {
        if (!window.authManager?.isAuthenticated()) {
            return;
        }

        if (this._isLoading) {
            this._reloadPending = true;
            return;
        }
        this._isLoading = true;
        this.showLoading(true);

        try {
            const { startDate, endDate } = this.getPeriodDates();
            const response = await dashboardAPI.getData(startDate, endDate);

            if (response.success && response.data && typeof response.data === 'object') {
                const userId = window.authManager?.getCurrentUser()?.id;
                const adjusted = userId !== undefined && userId !== null
                    ? await window.OfflineStorage?.applyPendingDashboardAdjustments(userId, response.data)
                    : { data: response.data, pendingCount: 0 };
                this.renderDashboardData(adjusted?.data || response.data);
                this.setOfflineSnapshotStatus(adjusted?.pendingCount > 0
                    ? { pendingOnly: true, pendingCount: adjusted.pendingCount }
                    : null);
                if (userId !== undefined && userId !== null) {
                    window.OfflineStorage?.saveDashboardSnapshot(userId, response.data);
                }
            } else {
                console.error('Dashboard API error:', response.message);
                NotificationService.error('Dashboard API error: ' + (response.message || 'Unknown error'));
                this.handleEmptyStates(null);
            }
        } catch (error) {
            console.error('Failed to load dashboard data:', error);
            const authFailure = [401, 403, 419].includes(Number(error?.status));
            const usedSnapshot = authFailure ? false : await this.loadOfflineSnapshot();
            if (!usedSnapshot) {
                this.handleEmptyStates(null);
                this.setOfflineSnapshotStatus(authFailure ? null : { empty: true });
            }
        } finally {
            this._isLoading = false;
            this.showLoading(false);
            if (this._reloadPending) {
                this._reloadPending = false;
                this.loadDashboardData();
            }
        }
    }

    renderDashboardData(data) {
        this.updateStatistics(data.statistics, data.today_statistics, data.total_balance, data.savings_balance);
        this.updateCreditCards(data.total_receivable, data.total_payable, data.net_worth);
        this.updateHealthScore(data.financial_health_score);
        this.updateRecentTransactions(data.recent_transactions);
        this.updateBudgetProgress(data.budget_progress);
        this.updateGoalProgress(data.goal_progress);
        this.updateIncomeSources(data.income_breakdown);
        this.updateAccountsOverview(data.accounts_overview);
        this.updateCharts(data);
        this.handleEmptyStates(data);
        this._hasRendered = true;
        this._renderedUserId = window.authManager?.getCurrentUser?.()?.id ?? null;
    }

    async loadOfflineSnapshot() {
        const userId = window.authManager?.getCurrentUser()?.id;
        if (userId === undefined || userId === null || !window.OfflineStorage) return false;
        const snapshot = await window.OfflineStorage.getDashboardSnapshot(userId);
        if (!snapshot?.data || !snapshot.cachedAt) return false;
        const adjusted = await window.OfflineStorage.applyPendingDashboardAdjustments(userId, snapshot.data);
        const pendingCount = adjusted.pendingCount;
        this.renderDashboardData(adjusted.data);
        this.setOfflineSnapshotStatus({ cachedAt: snapshot.cachedAt, pendingCount });
        return true;
    }

    setOfflineSnapshotStatus(state) {
        const page = document.getElementById('dashboard-page');
        if (!page) return;
        let status = document.getElementById('dashboard-offline-data-status');

        if (!state) {
            status?.remove();
            return;
        }

        if (!status) {
            status = document.createElement('div');
            status.id = 'dashboard-offline-data-status';
            status.className = 'alert alert-warning py-2 px-3 mb-3';
            status.setAttribute('role', 'status');
            page.prepend(status);
        }

        if (state.empty) {
            status.textContent = 'Offline — no saved dashboard data is available yet. Connect to the internet and open the dashboard once.';
            return;
        }

        if (state.pendingOnly) {
            status.textContent = `Includes ${state.pendingCount} pending change${state.pendingCount === 1 ? '' : 's'}. Server totals will replace these estimates after synchronization.`;
            return;
        }

        const cachedAt = new Date(state.cachedAt);
        const timestamp = Number.isNaN(cachedAt.getTime())
            ? 'an earlier time'
            : cachedAt.toLocaleString('en-US', { dateStyle: 'medium', timeStyle: 'short' });
        const mode = navigator.onLine ? 'Saved data — server unavailable' : 'Offline';
        const pendingText = state.pendingCount > 0
            ? ` Includes ${state.pendingCount} pending change${state.pendingCount === 1 ? '' : 's'}.`
            : '';
        status.textContent = `${mode} — showing saved dashboard data. Last updated: ${timestamp}.${pendingText}`;
    }

    showLoading(show) {
        const overlay = document.getElementById('dashboard-loading-overlay');
        const page = document.getElementById('dashboard-page');
        if (overlay) {
            overlay.style.display = show && !this._hasRendered ? 'flex' : 'none';
            overlay.setAttribute('aria-busy', show ? 'true' : 'false');
        }
        page?.classList.toggle('is-updating', Boolean(show && this._hasRendered));
        page?.setAttribute('aria-busy', show ? 'true' : 'false');
    }

    updateCreditCards(receivable, payable, netWorth) {
        const r = parseFloat(receivable) || 0;
        const p = parseFloat(payable) || 0;
        const nw = parseFloat(netWorth) || 0;

        const animateCounter = (elementId, endValue, prefix = 'Rs ') => {
            const element = document.getElementById(elementId);
            if (!element) return;
            const numericValue = parseFloat(endValue) || 0;
            element.textContent = Formatters.currency(numericValue);
        };

        animateCounter('stat-receivable', r);
        animateCounter('stat-payable', p);
        animateCounter('stat-net-worth', nw);

        const receivableChange = document.getElementById('stat-receivable-change');
        const payableChange = document.getElementById('stat-payable-change');
        const netWorthChange = document.getElementById('stat-net-worth-change');

        if (receivableChange) {
            receivableChange.textContent = r > 0 ? `${Formatters.currency(r)} pending` : 'Nothing pending';
        }
        if (payableChange) {
            payableChange.textContent = p > 0 ? `${Formatters.currency(p)} outstanding` : 'Nothing pending';
        }
        if (netWorthChange) {
            netWorthChange.textContent = 'Net Balance + Goals + Receivable - Payable';
        }
    }

    updateIncomeSources(breakdown) {
        const container = document.getElementById('income-sources-list');
        if (!container) return;

        if (!breakdown || breakdown.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="bi bi-arrow-down-left"></i>
                    </div>
                    <h4>No Income Data</h4>
                    <p>Add income transactions to see your sources</p>
                    <button class="btn btn-primary" onclick="window.appRouter?.navigate('transactions')">
                        <i class="bi bi-plus-lg"></i> Add Transaction
                    </button>
                </div>
            `;
            return;
        }

        const totalIncome = breakdown.reduce((sum, d) => sum + parseFloat(d.total_amount || 0), 0);

        container.innerHTML = breakdown.map(d => {
            const amount = parseFloat(d.total_amount) || 0;
            const pct = totalIncome > 0 ? ((amount / totalIncome) * 100).toFixed(1) : 0;
            const color = Formatters.safeColor(d.category_color, '#10B981');
            return `
                <div class="income-source-item">
                    <div class="income-source-info">
                        <div class="income-source-icon" style="background:${color}18;color:${color}">
                            <i class="bi bi-arrow-down-left"></i>
                        </div>
                        <div>
                            <p class="income-source-name">${Formatters.escapeHTML(d.category_name)}</p>
                            <small style="color:var(--text-secondary);font-weight:400">${d.transaction_count} transaction${d.transaction_count !== 1 ? 's' : ''}</small>
                        </div>
                    </div>
                    <div class="income-source-right">
                        <span class="income-source-amount">${Formatters.currency(amount)}</span>
                        <span class="income-source-pct" style="color:${color}">${pct}%</span>
                    </div>
                </div>
            `;
        }).join('');
    }

    updateAccountsOverview(accounts) {
        const container = document.getElementById('accounts-grid');
        if (!container) return;

        if (!accounts || accounts.length === 0) {
            container.innerHTML = `
                <div class="empty-state" style="grid-column: 1 / -1;">
                    <div class="empty-state-icon">
                        <i class="bi bi-wallet2"></i>
                    </div>
                    <h4>No Accounts Yet</h4>
                    <p>Create your first account to start tracking balances</p>
                    <button class="btn btn-primary" onclick="window.appRouter?.navigate('transactions')">
                        <i class="bi bi-plus-lg"></i> Add Account
                    </button>
                </div>
            `;
            return;
        }

        const accountTypeIcons = {
            bank: 'bi-bank', cash: 'bi-cash-stack', esewa: 'bi-phone',
            khalti: 'bi-phone', ime_pay: 'bi-phone', wallet: 'bi-wallet2',
            credit_card: 'bi-credit-card', savings: 'bi-piggy-bank', current: 'bi-bank'
        };
        const accountTypeLabels = {
            bank: 'Bank Account', cash: 'Cash', esewa: 'eSewa', khalti: 'Khalti',
            ime_pay: 'IME Pay', wallet: 'Wallet', credit_card: 'Credit Card',
            savings: 'Savings Account', current: 'Current Account'
        };

        container.innerHTML = accounts.map(account => {
            const balance = parseFloat(account.calculated_balance ?? account.balance) || 0;
            const icon = accountTypeIcons[account.type] || 'bi-wallet2';
            const typeLabel = accountTypeLabels[account.type] || 'Account';
            const color = Formatters.safeColor(account.color, '#10B981');
            const accountId = Number.isInteger(Number(account.id)) && Number(account.id) > 0 ? Number(account.id) : 0;

            return `
                <div class="account-card dashboard-account-card" onclick="window.appRouter?.navigate('account-details?id=${accountId}')" style="cursor:pointer;">
                    <div class="account-card-header">
                        <div class="account-card-icon" style="background:${color}15;color:${color}">
                            <i class="bi ${icon}"></i>
                        </div>
                        <div class="account-card-info">
                            <h4 class="account-card-name">${Formatters.escapeHTML(account.name)}</h4>
                        <span class="account-card-type">${typeLabel}</span>
                        ${String(account.include_in_net_balance) === '0' ? '<span class="account-card-net-excluded">Excluded from Net Balance</span>' : ''}
                    </div>
                        ${account.is_default ? '<span class="account-card-badge">Default</span>' : ''}
                    </div>
                    <div class="account-card-balance">
                        <span class="account-card-balance-label">Current Balance</span>
                        <span class="account-card-balance-value">${Formatters.currency(balance)}</span>
                    </div>
                    <div class="account-card-footer">
                        <span class="account-card-view">View Statement <i class="bi bi-arrow-right"></i></span>
                    </div>
                </div>
            `;
        }).join('');
    }

    handleEmptyStates(data) {
        if (!data) {
            const healthScoreEl = document.getElementById('health-score');
            const healthStatusEl = document.getElementById('health-status');
            if (healthScoreEl) healthScoreEl.textContent = '--';
            if (healthStatusEl) healthStatusEl.textContent = 'Add transactions to calculate your score';
            return;
        }

        const healthScoreEl = document.getElementById('health-score');
        const healthStatusEl = document.getElementById('health-status');
        const hs = data.financial_health_score;
        if (!hs || hs.has_sufficient_data === undefined) {
            if (healthScoreEl) healthScoreEl.textContent = '--';
            if (healthStatusEl) healthStatusEl.textContent = 'Add transactions to calculate your score';
        }

        const transactionsListEl = document.getElementById('recent-transactions-list');
        if (!data.recent_transactions || data.recent_transactions.length === 0) {
            if (transactionsListEl) {
                transactionsListEl.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="bi bi-receipt"></i>
                        </div>
                        <h4>No Transactions Yet</h4>
                        <p>Start tracking your finances by adding your first transaction</p>
                        <button class="btn btn-primary" onclick="window.appRouter?.navigate('transactions')">
                            <i class="bi bi-plus-lg"></i> Add Transaction
                        </button>
                    </div>
                `;
            }
        }

        const budgetListEl = document.getElementById('budget-list');
        if (!data.budget_progress || data.budget_progress.length === 0) {
            if (budgetListEl) {
                budgetListEl.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="bi bi-wallet2"></i>
                        </div>
                        <h4>No Budgets Set</h4>
                        <p>Create budgets to track your spending limits</p>
                        <button class="btn btn-primary" onclick="window.appRouter?.navigate('budgets')">
                            <i class="bi bi-plus-lg"></i> Create Budget
                        </button>
                    </div>
                `;
            }
        }

        const goalListEl = document.getElementById('goal-list');
        if (!data.goal_progress || data.goal_progress.length === 0) {
            if (goalListEl) {
                goalListEl.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="bi bi-bullseye"></i>
                        </div>
                        <h4>No Savings Goals</h4>
                        <p>Set goals to track your savings progress</p>
                        <button class="btn btn-primary" onclick="window.appRouter?.navigate('goals')">
                            <i class="bi bi-plus-lg"></i> Create Goal
                        </button>
                    </div>
                `;
            }
        }
    }

    updateStatistics(stats, todayStats, totalBalance, savingsBalance) {
        if (!stats) return;
        const animateCounter = (elementId, endValue, prefix = 'Rs ') => {
            const element = document.getElementById(elementId);
            if (!element) return;

            const numericValue = parseFloat(endValue) || 0;
            element.textContent = Formatters.currency(numericValue);
        };

        animateCounter('stat-income', stats.total_income || 0);
        animateCounter('stat-expense', stats.total_expense || 0);
        animateCounter('stat-today-expense', todayStats?.total_expense || 0);
        animateCounter('stat-savings', savingsBalance || 0);
        animateCounter('stat-balance', totalBalance || 0);

        const setChange = (id, text, className) => {
            const el = document.getElementById(id);
            if (el) {
                el.textContent = text;
                if (className) {
                    el.classList.remove('positive', 'negative');
                    el.classList.add(className);
                }
            }
        };

        const incomeCount = stats.income_count || 0;
        const expenseCount = stats.expense_count || 0;
        const todayExpenseCount = todayStats?.expense_count || 0;
        const periodLabel = this.getPeriodLabel();

        setChange('stat-today-expense-change',
            todayExpenseCount > 0
                ? `${todayExpenseCount} transaction${todayExpenseCount !== 1 ? 's' : ''} today`
                : 'Today',
            ''
        );

        setChange('stat-income-change',
            incomeCount > 0 ? `${incomeCount} transaction${incomeCount !== 1 ? 's' : ''} ${periodLabel.toLowerCase() === 'today' ? 'today' : 'this period'}` : 'This period',
            incomeCount > 0 ? 'positive' : ''
        );

        setChange('stat-expense-change',
            expenseCount > 0 ? `${expenseCount} transaction${expenseCount !== 1 ? 's' : ''} ${periodLabel.toLowerCase() === 'today' ? 'today' : 'this period'}` : 'This period',
            expenseCount > 0 ? 'negative' : ''
        );

        const savings = savingsBalance || 0;
        if (stats.total_income > 0) {
            const rate = ((savings / stats.total_income) * 100).toFixed(1);
            const isPositive = savings >= 0;
            setChange('stat-savings-change',
                `${rate}% savings rate`,
                isPositive ? 'positive' : 'negative'
            );
        } else {
            setChange('stat-savings-change', 'Current savings', '');
        }

        // Balance supporting text
        const balanceChange = document.getElementById('stat-balance');
        if (balanceChange) {
            // Just set the value, supporting text stays as "Across all accounts"
        }
    }

    updateHealthScore(healthScore) {
        const scoreEl = document.getElementById('health-score');
        const statusEl = document.getElementById('health-status');
        const progress = document.getElementById('health-progress');
        const card = document.querySelector('#dashboard-page .health-score-card');
        const toggle = document.getElementById('health-breakdown-toggle');
        const breakdown = document.getElementById('health-breakdown');
        const gaugeLabel = document.getElementById('health-gauge-label');
        const rawScore = Number(healthScore?.score);
        const hasScore = Boolean(healthScore?.has_sufficient_data) && Number.isFinite(rawScore);
        const score = hasScore ? Math.max(0, Math.min(100, Math.round(rawScore))) : 0;
        const state = hasScore ? this.healthState(healthScore?.state, healthScore?.status) : 'neutral';

        if (scoreEl) scoreEl.textContent = hasScore ? score : '—';
        if (statusEl) statusEl.textContent = hasScore ? healthScore.status : 'Not enough data';
        if (card) card.dataset.healthState = state;
        if (gaugeLabel) gaugeLabel.textContent = hasScore
            ? `Financial health score: ${score} out of 100, ${healthScore.status}`
            : 'Financial health score: not enough data';

        if (progress) {
            progress.setAttribute('stroke-dasharray', `${score}, 100`);
            progress.style.stroke = 'var(--health-accent)';
        }

        this.updateHealthBreakdown(healthScore?.breakdown, hasScore);
        if (toggle) toggle.hidden = !hasScore;
        if (!hasScore && breakdown) {
            breakdown.hidden = true;
            toggle?.setAttribute('aria-expanded', 'false');
            if (toggle) toggle.innerHTML = 'View breakdown <i class="bi bi-chevron-down" aria-hidden="true"></i>';
        }
    }

    healthState(state, status) {
        const validStates = ['critical', 'needs-attention', 'fair', 'good', 'very-good', 'excellent'];
        if (validStates.includes(state)) return state;
        return {
            'Critical': 'critical',
            'Needs Attention': 'needs-attention',
            'Fair': 'fair',
            'Good': 'good',
            'Very Good': 'very-good',
            'Excellent': 'excellent'
        }[status] || 'critical';
    }

    updateHealthBreakdown(breakdown, hasScore) {
        const components = [
            ['health-breakdown-savings-rate', 'savings_rate', 30],
            ['health-breakdown-income-expense', 'income_vs_expense', 25],
            ['health-breakdown-net-balance', 'net_balance', 20],
            ['health-breakdown-payable', 'debt_payable', 15],
            ['health-breakdown-stability', 'stability_activity', 10]
        ];
        components.forEach(([id, key, maximum]) => {
            const element = document.getElementById(id);
            if (!element) return;
            const points = Number(breakdown?.[key]);
            element.textContent = hasScore && Number.isFinite(points)
                ? `${Math.max(0, Math.min(maximum, Math.round(points)))}/${maximum}`
                : `—/${maximum}`;
        });
    }

    updateRecentTransactions(transactions) {
        const container = document.getElementById('recent-transactions-list');
        if (!container) return;
        container.innerHTML = '';

        if (!transactions || transactions.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="bi bi-receipt"></i>
                    </div>
                    <h4>No Transactions Yet</h4>
                    <p>Start tracking your finances by adding your first transaction</p>
                    <button class="btn btn-primary" onclick="window.appRouter?.navigate('transactions')">
                        <i class="bi bi-plus-lg"></i> Add Transaction
                    </button>
                </div>
            `;
            return;
        }

        transactions.slice(0, 5).forEach(transaction => {
            const item = document.createElement('div');
            item.className = 'transaction-item';

            const isIncome = transaction.type === 'income';
            const iconClass = isIncome ? 'income' : 'expense';
            const icon = isIncome ? 'bi-arrow-down-left' : 'bi-arrow-up-right';
            const amountClass = isIncome ? 'income' : 'expense';
            const sign = isIncome ? '+' : '-';
            const badgeClass = isIncome ? 'badge-income' : 'badge-expense';

            item.innerHTML = `
                <div class="transaction-icon ${iconClass}">
                    <i class="bi ${icon}"></i>
                </div>
                <div class="transaction-details">
                    <p class="transaction-description">${Formatters.escapeHTML(transaction.description || transaction.category_name)}</p>
                    <p class="transaction-category">${Formatters.escapeHTML(transaction.category_name)}</p>
                </div>
                <div class="transaction-amount-wrapper" style="text-align:right;">
                    <p class="transaction-amount ${amountClass}">${sign} ${Formatters.currency(transaction.amount)}</p>
                    <p class="transaction-date">${Formatters.date(transaction.date)}</p>
                </div>
            `;

            container.appendChild(item);
        });
    }

    updateBudgetProgress(budgets) {
        const container = document.getElementById('budget-list');
        if (!container) return;
        container.innerHTML = '';

        if (!budgets || budgets.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="bi bi-wallet2"></i>
                    </div>
                    <h4>No Budgets Set</h4>
                    <p>Create budgets to track your spending limits</p>
                    <button class="btn btn-primary" onclick="window.appRouter?.navigate('budgets')">
                        <i class="bi bi-plus-lg"></i> Create Budget
                    </button>
                </div>
            `;
            return;
        }

        budgets.slice(0, 3).forEach(b => {
            const item = document.createElement('div');
            item.className = 'budget-item';

            const percentage = Math.min(100, b.percentage);
            let progressClass = 'budget-progress-fill';
            if (percentage >= 90) progressClass += ' danger';
            else if (percentage >= 70) progressClass += ' warning';

            item.innerHTML = `
                <div class="budget-header">
                    <span class="budget-name">${Formatters.escapeHTML(b.budget.name)}<small class="d-block text-muted">${Formatters.escapeHTML(b.budget.scope_label || b.budget.category_name || 'All expenses')}</small></span>
                    <span class="budget-amount">${Formatters.currency(b.budget.amount)}</span>
                </div>
                <div class="budget-progress-bar">
                    <div class="${progressClass}" role="progressbar" style="width: ${percentage}%"></div>
                </div>
                <div class="budget-details">
                    <span>${Formatters.currency(b.spent)} spent</span>
                    <span>${percentage.toFixed(1)}%</span>
                </div>
            `;

            container.appendChild(item);
        });
    }

    updateGoalProgress(goals) {
        const container = document.getElementById('goal-list');
        if (!container) return;
        container.innerHTML = '';

        if (!goals || goals.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="bi bi-bullseye"></i>
                    </div>
                    <h4>No Savings Goals</h4>
                    <p>Set goals to track your savings progress</p>
                    <button class="btn btn-primary" onclick="window.appRouter?.navigate('goals')">
                        <i class="bi bi-plus-lg"></i> Create Goal
                    </button>
                </div>
            `;
            return;
        }

        goals.slice(0, 3).forEach(g => {
            const item = document.createElement('div');
            item.className = 'goal-item';

            const pct = Math.min(100, g.percentage);

            const goalIcon = Formatters.safeIconClass(g.goal.icon, 'bi bi-bullseye');
            item.innerHTML = `
                <div class="goal-header">
                    <span class="goal-name"><i class="${goalIcon} me-2 text-primary"></i>${Formatters.escapeHTML(g.goal.name)}</span>
                    <span class="goal-icon"><i class="${goalIcon}"></i></span>
                </div>
                <div class="goal-amount">${Formatters.currency(g.goal.current_amount)}</div>
                <div class="goal-target">Target: ${Formatters.currency(g.goal.target_amount)}</div>
                <div class="goal-progress-bar">
                    <div class="goal-progress-fill" role="progressbar" style="width: ${pct}%"></div>
                </div>
                <div class="goal-percentage">${pct.toFixed(1)}% completed</div>
            `;

            container.appendChild(item);
        });
    }
}

window.DashboardManager = DashboardManager;
