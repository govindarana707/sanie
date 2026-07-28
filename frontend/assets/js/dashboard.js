// Dashboard Module - Refactored with Component Lifecycle & ChartService
class DashboardManager {
    constructor() {
        this.currentPeriod = 'month';
        this.hasData = false;
        this._periodHandler = null;
    }

    onMount() {
        this.setupEventListeners();
        this.setDashboardGreeting();
        this.initCharts();
        this.loadDashboardData();
        this._dataChangeHandler = () => {
            this.loadDashboardData();
        };
        document.addEventListener('app:data-changed', this._dataChangeHandler);
    }

    onUnmount() {
        if (this._periodHandler) {
            const periodSelect = document.getElementById('dashboard-period');
            if (periodSelect) {
                periodSelect.removeEventListener('change', this._periodHandler);
            }
            this._periodHandler = null;
        }
        if (this._dataChangeHandler) {
            document.removeEventListener('app:data-changed', this._dataChangeHandler);
            this._dataChangeHandler = null;
        }
        if (window.ChartService) {
            ChartService.destroy('#incomeExpenseChart');
            ChartService.destroy('#categoryChart');
        }
    }

    setupEventListeners() {
        const periodSelect = document.getElementById('dashboard-period');
        if (periodSelect) {
            periodSelect.removeEventListener('change', this._periodHandler);
            this._periodHandler = (e) => {
                this.currentPeriod = e.target.value;
                this.loadDashboardData();
            };
            periodSelect.addEventListener('change', this._periodHandler);
        }
    }

    setDashboardGreeting() {
        const greetingEl = document.getElementById('dashboard-greeting');
        const dateEl = document.getElementById('dashboard-date');
        const userNameEl = document.getElementById('user-name');

        if (greetingEl) {
            const hour = new Date().getHours();
            let greeting = 'Good Evening';
            if (hour < 12) greeting = 'Good Morning';
            else if (hour < 17) greeting = 'Good Afternoon';

            const firstName = userNameEl ? userNameEl.textContent.split(' ')[0] : '';
            if (firstName && firstName !== 'User' && firstName !== 'user@email.com') {
                greetingEl.textContent = `${greeting}, ${firstName} 👋`;
            } else {
                greetingEl.textContent = `${greeting} 👋`;
            }
        }

        if (dateEl) {
            const now = new Date();
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            dateEl.textContent = now.toLocaleDateString('en-US', options);
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
        const now = new Date();
        let startDate, endDate;

        switch (this.currentPeriod) {
            case 'today':
                startDate = endDate = now.toISOString().split('T')[0];
                break;
            case 'week': {
                const weekStart = new Date(now);
                weekStart.setDate(now.getDate() - now.getDay() + 1);
                startDate = weekStart.toISOString().split('T')[0];
                const weekEnd = new Date(weekStart);
                weekEnd.setDate(weekStart.getDate() + 6);
                endDate = weekEnd.toISOString().split('T')[0];
                break;
            }
            case 'month':
                startDate = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split('T')[0];
                endDate = new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString().split('T')[0];
                break;
            case 'year':
                startDate = new Date(now.getFullYear(), 0, 1).toISOString().split('T')[0];
                endDate = new Date(now.getFullYear(), 11, 31).toISOString().split('T')[0];
                break;
            default:
                startDate = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split('T')[0];
                endDate = new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString().split('T')[0];
        }

        return { startDate, endDate };
    }

    async loadDashboardData() {
        if (!window.authManager?.isAuthenticated()) {
            return;
        }

        try {
            const { startDate, endDate } = this.getPeriodDates();
            const response = await dashboardAPI.getData(startDate, endDate);

            if (response.success) {
                this.updateStatistics(response.data.statistics, response.data.total_balance, response.data.savings_balance);
                this.updateCreditCards(response.data.total_receivable, response.data.total_payable, response.data.net_worth);
                this.updateHealthScore(response.data.financial_health_score);
                this.updateRecentTransactions(response.data.recent_transactions);
                this.updateBudgetProgress(response.data.budget_progress);
                this.updateGoalProgress(response.data.goal_progress);
                this.updateIncomeSources(response.data.income_breakdown);
                this.updateAccountsOverview(response.data.accounts_overview);
                this.updateCharts(response.data);
                this.handleEmptyStates(response.data);
            } else {
                console.error('Dashboard API error:', response.message);
                NotificationService.error('Dashboard API error: ' + (response.message || 'Unknown error'));
                this.handleEmptyStates(null);
            }
        } catch (error) {
            console.error('Failed to load dashboard data:', error);
            NotificationService.error('Failed to load dashboard data: ' + error.message);
            this.handleEmptyStates(null);
        }
    }

    updateCreditCards(receivable, payable, netWorth) {
        const r = parseFloat(receivable) || 0;
        const p = parseFloat(payable) || 0;
        const nw = parseFloat(netWorth) || 0;

        const animateCounter = (elementId, endValue, prefix = 'Rs ') => {
            const element = document.getElementById(elementId);
            if (!element) return;
            const numericValue = parseFloat(endValue) || 0;
            const CountUpCtor = window.CountUp || window.countUp?.CountUp || window.countUp;
            if (CountUpCtor) {
                const countUp = new CountUpCtor(element, numericValue, {
                    duration: 1.5,
                    decimalPlaces: 2,
                    prefix: prefix
                });
                if (!countUp.error) {
                    countUp.start();
                    return;
                }
            }
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
            netWorthChange.textContent = 'Cash + Receivable - Payable';
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
            const color = d.category_color || '#10B981';
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
            const totalIncome = parseFloat(account.total_income) || 0;
            const totalExpense = parseFloat(account.total_expense) || 0;
            const lastTxDate = account.last_transaction_date;
            const icon = accountTypeIcons[account.type] || 'bi-wallet2';
            const typeLabel = accountTypeLabels[account.type] || account.type;
            const color = account.color || '#10B981';

            let lastTransactionText = 'No transactions';
            if (lastTxDate) {
                const d = new Date(lastTxDate);
                const now = new Date();
                const diffMs = now - d;
                const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));
                if (diffDays === 0) lastTransactionText = 'Today';
                else if (diffDays === 1) lastTransactionText = 'Yesterday';
                else if (diffDays < 7) lastTransactionText = `${diffDays} days ago`;
                else lastTransactionText = Formatters.date(lastTxDate);
            }

            return `
                <div class="account-card" onclick="window.appRouter?.navigate('account-details?id=${account.id}')" style="cursor:pointer;">
                    <div class="account-card-header">
                        <div class="account-card-icon" style="background:${color}15;color:${color}">
                            <i class="bi ${icon}"></i>
                        </div>
                        <div class="account-card-info">
                            <h4 class="account-card-name">${Formatters.escapeHTML(account.name)}</h4>
                            <span class="account-card-type">${typeLabel}</span>
                        </div>
                        ${account.is_default ? '<span class="account-card-badge">Default</span>' : ''}
                    </div>
                    <div class="account-card-balance">
                        <span class="account-card-balance-label">Current Balance</span>
                        <span class="account-card-balance-value">${Formatters.currency(balance)}</span>
                    </div>
                    <div class="account-card-stats">
                        <div class="account-card-stat">
                            <span class="stat-mini-label">Income</span>
                            <span class="stat-mini-value income">${Formatters.currency(totalIncome)}</span>
                        </div>
                        <div class="account-card-stat">
                            <span class="stat-mini-label">Expense</span>
                            <span class="stat-mini-value expense">${Formatters.currency(totalExpense)}</span>
                        </div>
                    </div>
                    <div class="account-card-footer">
                        <span class="account-card-last-tx"><i class="bi bi-clock"></i> ${lastTransactionText}</span>
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
        if (!hs || hs.score === undefined || hs.score === null) {
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

    updateStatistics(stats, totalBalance, savingsBalance) {
        if (!stats) return;
        const animateCounter = (elementId, endValue, prefix = 'Rs ') => {
            const element = document.getElementById(elementId);
            if (!element) return;

            const numericValue = parseFloat(endValue) || 0;
            const CountUpCtor = window.CountUp || window.countUp?.CountUp || window.countUp;

            if (CountUpCtor) {
                const countUp = new CountUpCtor(element, numericValue, {
                    duration: 1.5,
                    decimalPlaces: 2,
                    prefix: prefix
                });
                if (!countUp.error) {
                    countUp.start();
                    return;
                }
            }
            element.textContent = Formatters.currency(numericValue);
        };

        animateCounter('stat-income', stats.total_income || 0);
        animateCounter('stat-expense', stats.total_expense || 0);
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

        setChange('stat-income-change',
            incomeCount > 0 ? `${incomeCount} transaction${incomeCount !== 1 ? 's' : ''} this month` : 'No data yet',
            incomeCount > 0 ? 'positive' : ''
        );

        setChange('stat-expense-change',
            expenseCount > 0 ? `${expenseCount} transaction${expenseCount !== 1 ? 's' : ''} this month` : 'No data yet',
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
            setChange('stat-savings-change', 'No data yet', '');
        }

        // Balance supporting text
        const balanceChange = document.getElementById('stat-balance');
        if (balanceChange) {
            // Just set the value, supporting text stays as "Across all accounts"
        }
    }

    updateHealthScore(healthScore) {
        if (!healthScore) return;
        const scoreEl = document.getElementById('health-score');
        const statusEl = document.getElementById('health-status');
        const progress = document.getElementById('health-progress');

        if (scoreEl) scoreEl.textContent = healthScore.score || '--';
        if (statusEl) statusEl.textContent = healthScore.status || 'No score data';

        if (progress && healthScore.score) {
            progress.setAttribute('stroke-dasharray', `${healthScore.score}, 100`);
            if (healthScore.score >= 80) progress.style.stroke = '#10B981';
            else if (healthScore.score >= 60) progress.style.stroke = '#34D399';
            else if (healthScore.score >= 40) progress.style.stroke = '#F59E0B';
            else progress.style.stroke = '#EF4444';
        }
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
                    <span class="budget-name">${Formatters.escapeHTML(b.budget.name)}</span>
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

            item.innerHTML = `
                <div class="goal-header">
                    <span class="goal-name"><i class="bi ${g.goal.icon || 'bi-bullseye'} me-2 text-primary"></i>${Formatters.escapeHTML(g.goal.name)}</span>
                    <span class="goal-icon"><i class="bi ${g.goal.icon || 'bi-bullseye'}"></i></span>
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
