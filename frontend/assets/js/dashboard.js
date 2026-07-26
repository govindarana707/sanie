// Dashboard Module - Refactored with Component Lifecycle & ChartService
class DashboardManager {
    constructor() {
        this.currentPeriod = 'month';
        this.hasData = false;
        this._periodHandler = null;
    }

    onMount() {
        this.setupEventListeners();
        this.initCharts();
        this.loadDashboardData();
    }

    onUnmount() {
        if (this._periodHandler) {
            const periodSelect = document.getElementById('dashboard-period');
            if (periodSelect) {
                periodSelect.removeEventListener('change', this._periodHandler);
            }
            this._periodHandler = null;
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
            const income = data.monthly_data.map(d => d.income);
            const expense = data.monthly_data.map(d => d.expense);

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
        const hasCategoryData = data.category_breakdown && data.category_breakdown.length > 0;

        if (hasCategoryData) {
            const categories = data.category_breakdown.map(d => d.category);
            const amounts = data.category_breakdown.map(d => d.amount);

            if (categoryChartEl) categoryChartEl.style.display = 'block';
            if (categoryEmptyEl) categoryEmptyEl.style.display = 'none';

            if (window.ChartService) {
                ChartService.updateOptions('#categoryChart', { labels: categories });
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
                this.updateStatistics(response.data.statistics);
                this.updateHealthScore(response.data.financial_health_score);
                this.updateRecentTransactions(response.data.recent_transactions);
                this.updateBudgetProgress(response.data.budget_progress);
                this.updateGoalProgress(response.data.goal_progress);
                this.updateCharts(response.data);
                this.handleEmptyStates(response.data);
            }
        } catch (error) {
            console.error('Failed to load dashboard data:', error);
            NotificationService.error('Failed to load dashboard data');
            this.handleEmptyStates(null);
        }
    }

    handleEmptyStates(data) {
        const healthScoreEl = document.getElementById('health-score');
        const healthStatusEl = document.getElementById('health-status');

        if (!data || !data.financial_health_score || data.financial_health_score.score === 0) {
            if (healthScoreEl) healthScoreEl.textContent = '--';
            if (healthStatusEl) healthStatusEl.textContent = 'Add transactions to calculate your score';
        }

        const transactionsListEl = document.getElementById('recent-transactions-list');
        if (!data || !data.recent_transactions || data.recent_transactions.length === 0) {
            if (transactionsListEl) {
                transactionsListEl.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-receipt"></i>
                        </div>
                        <h4>No Transactions Yet</h4>
                        <p>Start tracking your finances by adding your first transaction</p>
                        <button class="btn btn-primary" onclick="window.appRouter?.navigate('transactions')">
                            <i class="fas fa-plus"></i> Add Transaction
                        </button>
                    </div>
                `;
            }
        }

        const budgetListEl = document.getElementById('budget-list');
        if (!data || !data.budget_progress || data.budget_progress.length === 0) {
            if (budgetListEl) {
                budgetListEl.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-wallet"></i>
                        </div>
                        <h4>No Budgets Set</h4>
                        <p>Create budgets to track your spending limits</p>
                        <button class="btn btn-primary" onclick="window.appRouter?.navigate('budgets')">
                            <i class="fas fa-plus"></i> Create Budget
                        </button>
                    </div>
                `;
            }
        }

        const goalListEl = document.getElementById('goal-list');
        if (!data || !data.goal_progress || data.goal_progress.length === 0) {
            if (goalListEl) {
                goalListEl.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-bullseye"></i>
                        </div>
                        <h4>No Savings Goals</h4>
                        <p>Set goals to track your savings progress</p>
                        <button class="btn btn-primary" onclick="window.appRouter?.navigate('goals')">
                            <i class="fas fa-plus"></i> Create Goal
                        </button>
                    </div>
                `;
            }
        }
    }

    updateStatistics(stats) {
        if (!stats) return;
        const animateCounter = (elementId, endValue) => {
            const element = document.getElementById(elementId);
            if (!element) return;

            const numericValue = parseFloat(endValue) || 0;
            const CountUpCtor = window.CountUp || window.countUp?.CountUp || window.countUp;

            if (CountUpCtor) {
                const countUp = new CountUpCtor(element, numericValue, {
                    duration: 1.5,
                    decimalPlaces: 2,
                    prefix: 'Rs '
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
        animateCounter('stat-savings', stats.balance || 0);
        animateCounter('stat-balance', stats.total_balance || 0);
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
            container.innerHTML = '<p class="no-data text-muted text-center py-4">No transactions yet</p>';
            return;
        }

        transactions.slice(0, 5).forEach(transaction => {
            const item = document.createElement('div');
            item.className = 'transaction-item d-flex align-items-center justify-content-between p-3 mb-2 rounded-3 bg-secondary bg-opacity-10';

            const iconClass = transaction.type === 'income' ? 'income' : 'expense';
            const icon = transaction.type === 'income' ? 'fa-arrow-up text-success' : 'fa-arrow-down text-danger';
            const amountClass = transaction.type === 'income' ? 'text-success' : 'text-danger';
            const sign = transaction.type === 'income' ? '+' : '-';

            item.innerHTML = `
                <div class="d-flex align-items-center gap-3">
                    <div class="category-icon ${iconClass}">
                        <i class="fas ${icon}"></i>
                    </div>
                    <div>
                        <p class="mb-0 fw-semibold">${Formatters.escapeHTML(transaction.description || transaction.category_name)}</p>
                        <small class="text-muted">${Formatters.escapeHTML(transaction.category_name)}</small>
                    </div>
                </div>
                <div class="text-end">
                    <p class="mb-0 fw-bold ${amountClass}">${sign} ${Formatters.currency(transaction.amount)}</p>
                    <small class="text-muted">${Formatters.date(transaction.date)}</small>
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
            container.innerHTML = '<p class="no-data text-muted text-center py-4">No budgets set</p>';
            return;
        }

        budgets.slice(0, 3).forEach(b => {
            const item = document.createElement('div');
            item.className = 'budget-item mb-3';

            const percentage = Math.min(100, b.percentage);
            let progressClass = 'bg-primary';
            if (percentage >= 90) progressClass = 'bg-danger';
            else if (percentage >= 70) progressClass = 'bg-warning';

            item.innerHTML = `
                <div class="d-flex justify-content-between mb-1">
                    <span class="fw-semibold">${Formatters.escapeHTML(b.budget.name)}</span>
                    <span class="fw-bold">${Formatters.currency(b.budget.amount)}</span>
                </div>
                <div class="progress mb-1" style="height: 8px;">
                    <div class="progress-bar ${progressClass}" role="progressbar" style="width: ${percentage}%"></div>
                </div>
                <div class="d-flex justify-content-between small text-muted">
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
            container.innerHTML = '<p class="no-data text-muted text-center py-4">No goals set</p>';
            return;
        }

        goals.slice(0, 3).forEach(g => {
            const item = document.createElement('div');
            item.className = 'goal-item mb-3';

            item.innerHTML = `
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="fw-semibold"><i class="fas ${g.goal.icon || 'fa-bullseye'} me-2 text-primary"></i>${Formatters.escapeHTML(g.goal.name)}</span>
                    <span class="small text-muted">${g.percentage.toFixed(1)}%</span>
                </div>
                <div class="progress mb-1" style="height: 8px;">
                    <div class="progress-bar bg-success" role="progressbar" style="width: ${Math.min(100, g.percentage)}%"></div>
                </div>
                <div class="d-flex justify-content-between small text-muted">
                    <span>${Formatters.currency(g.goal.current_amount)}</span>
                    <span>Target: ${Formatters.currency(g.goal.target_amount)}</span>
                </div>
            `;

            container.appendChild(item);
        });
    }
}

window.DashboardManager = DashboardManager;
