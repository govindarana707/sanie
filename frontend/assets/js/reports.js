class ReportsManager {
    constructor() {
        this._mounted = false;
        this._data = null;
        this._loading = false;
        this._incomeExpensePage = 1;
        this._incomeExpenseLimit = 50;
        this._reportRange = { start_date: '2026-01-01', end_date: '2026-12-31' };
    }

    onMount() {
        if (this._mounted) return;
        this._mounted = true;
        this.renderReportsUI();
        this.loadReportData();
        this._dataChangeHandler = () => this.loadReportData();
        document.addEventListener('app:data-changed', this._dataChangeHandler);
    }

    onUnmount() {
        this._mounted = false;
        if (this._dataChangeHandler) {
            document.removeEventListener('app:data-changed', this._dataChangeHandler);
            this._dataChangeHandler = null;
        }
    }

    async loadReportData() {
        if (this._loading) return;
        this._loading = true;

        const container = document.querySelector('#reports-page .reports-content');
        if (!container) return;

        const showLoading = () => {
            container.querySelectorAll('.report-section .card-body').forEach(el => {
                el.innerHTML = `<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="text-muted mt-2 small">Loading report data...</p></div>`;
            });
        };
        showLoading();

        try {
            const params = new URLSearchParams({ ...this._reportRange, page: this._incomeExpensePage, limit: this._incomeExpenseLimit });
            const aggregateParams = new URLSearchParams(this._reportRange);

            const [ieRes, cbRes, bhRes] = await Promise.all([
                api.get(`/reports/income-expense?${params}`),
                api.get(`/reports/category-breakdown?${aggregateParams}`),
                api.get(`/reports/budget-health`)
            ]);

            this._data = {
                incomeExpense: ieRes.success ? ieRes.data : null,
                categoryBreakdown: cbRes.success ? cbRes.data : null,
                budgetHealth: bhRes.success ? bhRes.data : null
            };

            this.renderIncomeExpense();
            this.renderCategoryBreakdown();
            this.renderBudgetHealth();
        } catch (error) {
            console.error('Failed to load report data:', error);
            container.querySelectorAll('.report-section .card-body').forEach(el => {
                el.innerHTML = `<div class="text-center py-4"><i class="fas fa-exclamation-triangle text-danger mb-2" style="font-size:2rem;"></i><p class="text-muted small">Failed to load report data</p></div>`;
            });
        } finally {
            this._loading = false;
        }
    }

    renderReportsUI() {
        const container = document.querySelector('#reports-page .reports-content');
        if (!container) return;

        container.innerHTML = `
            <div class="row g-4 mb-4">
                <div class="col-12">
                    <div class="d-flex align-items-center justify-content-between">
                        <p class="text-muted mb-0"><i class="fas fa-sync-alt me-1"></i> Reports are generated from your live transaction data</p>
                        <button class="btn btn-sm btn-outline-secondary" onclick="reportsManager.loadReportData()">
                            <i class="fas fa-redo me-1"></i> Refresh
                        </button>
                    </div>
                </div>
            </div>
            <div class="row g-4">
                <div class="col-12">
                    <div class="card-premium report-section" id="report-income-expense">
                        <div class="card-header bg-transparent border-bottom d-flex align-items-center justify-content-between px-4 py-3">
                            <div>
                                <h5 class="mb-0"><i class="fas fa-file-invoice-dollar text-primary me-2"></i>Income & Expense Report</h5>
                                <small class="text-muted">Comprehensive ledger of all transactions</small>
                            </div>
                            <div class="d-flex gap-2">
                                <button class="btn btn-outline-primary btn-sm" onclick="reportsManager.printReport('income-expense')">
                                    <i class="fas fa-print me-1"></i> Print
                                </button>
                                <button class="btn btn-outline-primary btn-sm" onclick="reportsManager.exportCSV('income-expense')">
                                    <i class="fas fa-file-csv me-1"></i> CSV
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-4">
                            <div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="text-muted mt-2 small">Loading...</p></div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card-premium report-section" id="report-category-breakdown">
                        <div class="card-header bg-transparent border-bottom d-flex align-items-center justify-content-between px-4 py-3">
                            <div>
                                <h5 class="mb-0"><i class="fas fa-chart-pie text-success me-2"></i>Category Breakdown</h5>
                                <small class="text-muted">Spending distribution by category</small>
                            </div>
                            <div class="d-flex gap-2">
                                <button class="btn btn-outline-primary btn-sm" onclick="reportsManager.printReport('category-breakdown')">
                                    <i class="fas fa-print me-1"></i> Print
                                </button>
                                <button class="btn btn-outline-primary btn-sm" onclick="reportsManager.exportCSV('category-breakdown')">
                                    <i class="fas fa-file-csv me-1"></i> CSV
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-4">
                            <div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="text-muted mt-2 small">Loading...</p></div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card-premium report-section" id="report-budget-health">
                        <div class="card-header bg-transparent border-bottom d-flex align-items-center justify-content-between px-4 py-3">
                            <div>
                                <h5 class="mb-0"><i class="fas fa-wallet text-warning me-2"></i>Budget Health Report</h5>
                                <small class="text-muted">Budget utilization and status</small>
                            </div>
                            <div class="d-flex gap-2">
                                <button class="btn btn-outline-primary btn-sm" onclick="reportsManager.printReport('budget-health')">
                                    <i class="fas fa-print me-1"></i> Print
                                </button>
                                <button class="btn btn-outline-primary btn-sm" onclick="reportsManager.exportCSV('budget-health')">
                                    <i class="fas fa-file-csv me-1"></i> CSV
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-4">
                            <div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="text-muted mt-2 small">Loading...</p></div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    renderIncomeExpense() {
        const el = document.querySelector('#report-income-expense .card-body');
        if (!el) return;

        const data = this._data?.incomeExpense;
        if (!data || !data.rows || data.rows.length === 0) {
            el.innerHTML = `<div class="text-center py-5"><i class="fas fa-inbox text-muted mb-3" style="font-size:3rem;"></i><p class="text-muted">No transaction data available</p></div>`;
            return;
        }

        const stats = data.statistics || {};

        let html = `
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="p-3 rounded-3" style="background:rgba(16,185,129,0.1);">
                        <small class="text-muted">Total Income</small>
                        <h4 class="text-success mb-0">Rs ${this._fmt(stats.total_income)}</h4>
                        <small class="text-muted">${stats.income_count || 0} transactions</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 rounded-3" style="background:rgba(239,68,68,0.1);">
                        <small class="text-muted">Total Expense</small>
                        <h4 class="text-danger mb-0">Rs ${this._fmt(stats.total_expense)}</h4>
                        <small class="text-muted">${stats.expense_count || 0} transactions</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 rounded-3" style="background:rgba(59,130,246,0.1);">
                        <small class="text-muted">Net Balance</small>
                        <h4 class="${stats.balance >= 0 ? 'text-success' : 'text-danger'} mb-0">Rs ${this._fmt(Math.abs(stats.balance))}</h4>
                    </div>
                </div>
            </div>
            <div class="table-responsive" style="max-height:400px;overflow-y:auto;">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light" style="position:sticky;top:0;z-index:1;">
                        <tr>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Category</th>
                            <th>Account</th>
                            <th class="text-end">Income (Rs)</th>
                            <th class="text-end">Expense (Rs)</th>
                            <th class="text-end">Balance (Rs)</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

        data.rows.forEach(r => {
            html += `<tr>
                <td class="text-nowrap">${r.date}</td>
                <td>${this._esc(r.description) || '-'}</td>
                <td>${this._esc(r.category)}</td>
                <td>${this._esc(r.account)}</td>
                <td class="text-end text-success fw-semibold">${r.income ? 'Rs ' + this._fmt(r.income) : '-'}</td>
                <td class="text-end text-danger fw-semibold">${r.expense ? 'Rs ' + this._fmt(r.expense) : '-'}</td>
                <td class="text-end fw-semibold ${r.balance >= 0 ? 'text-success' : 'text-danger'}">Rs ${this._fmt(Math.abs(r.balance))}</td>
            </tr>`;
        });

        const pagination = data.pagination || {};
        html += `</tbody></table></div>
            <div class="d-flex align-items-center justify-content-between mt-3" id="income-expense-pagination">
                <small class="text-muted">Page ${pagination.page || 1} of ${pagination.total_pages || 0} · ${(pagination.total_rows || 0).toLocaleString('en-IN')} matching rows</small>
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-secondary" ${pagination.has_previous ? '' : 'disabled'} onclick="reportsManager.goToIncomeExpensePage(${(pagination.page || 1) - 1})">Previous</button>
                    <button class="btn btn-outline-secondary" ${pagination.has_next ? '' : 'disabled'} onclick="reportsManager.goToIncomeExpensePage(${(pagination.page || 1) + 1})">Next</button>
                </div>
            </div>`;
        el.innerHTML = html;
    }

    async goToIncomeExpensePage(page) {
        if (page < 1 || this._loading) return;
        this._incomeExpensePage = page;
        await this.loadReportData();
    }

    async _allIncomeExpenseData() {
        const current = this._data?.incomeExpense;
        if (!current) return null;
        const totalPages = Math.ceil((current.pagination?.total_rows || 0) / 200);
        const rows = [];
        for (let page = 1; page <= totalPages; page++) {
            const params = new URLSearchParams({ ...this._reportRange, page, limit: 200 });
            const response = await api.get(`/reports/income-expense?${params}`);
            if (!response.success) throw new Error(response.message || 'Could not load complete report export');
            rows.push(...(response.data.rows || []));
        }
        return { ...current, rows };
    }

    renderCategoryBreakdown() {
        const el = document.querySelector('#report-category-breakdown .card-body');
        if (!el) return;

        const data = this._data?.categoryBreakdown;
        if (!data) {
            el.innerHTML = `<div class="text-center py-5"><i class="fas fa-inbox text-muted mb-3" style="font-size:3rem;"></i><p class="text-muted">No transaction data available</p></div>`;
            return;
        }

        const expenseCats = data.expense_categories || [];
        const incomeCats = data.income_categories || [];

        if (expenseCats.length === 0 && incomeCats.length === 0) {
            el.innerHTML = `<div class="text-center py-5"><i class="fas fa-inbox text-muted mb-3" style="font-size:3rem;"></i><p class="text-muted">No transaction data available</p></div>`;
            return;
        }

        let html = `
            <div class="row g-3 mb-3">
                <div class="col-6">
                    <small class="text-muted">Total Expense</small>
                    <h5 class="text-danger mb-0">Rs ${this._fmt(data.total_expense)}</h5>
                </div>
                <div class="col-6">
                    <small class="text-muted">Total Income</small>
                    <h5 class="text-success mb-0">Rs ${this._fmt(data.total_income)}</h5>
                </div>
            </div>
        `;

        if (expenseCats.length > 0) {
            html += `<h6 class="text-danger mb-2"><i class="fas fa-arrow-down me-1"></i>Expense Categories</h6>`;
            html += `<div class="mb-3">`;
            expenseCats.forEach(c => {
                html += `
                    <div class="d-flex align-items-center justify-content-between py-2 border-bottom border-light">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge" style="background:${c.category_color}20;color:${c.category_color};width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:8px;">
                                <i class="${c.category_icon || 'fas fa-tag'}"></i>
                            </span>
                            <span class="fw-medium">${this._esc(c.category_name)}</span>
                        </div>
                        <div class="text-end">
                            <div class="fw-semibold">Rs ${this._fmt(c.total_amount)}</div>
                            <small class="text-muted">${c.percentage}% (${c.transaction_count} txns)</small>
                        </div>
                    </div>
                `;
            });
            html += `</div>`;
        }

        if (incomeCats.length > 0) {
            html += `<h6 class="text-success mb-2 mt-3"><i class="fas fa-arrow-up me-1"></i>Income Categories</h6>`;
            html += `<div>`;
            incomeCats.forEach(c => {
                html += `
                    <div class="d-flex align-items-center justify-content-between py-2 border-bottom border-light">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge" style="background:${c.category_color}20;color:${c.category_color};width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:8px;">
                                <i class="${c.category_icon || 'fas fa-tag'}"></i>
                            </span>
                            <span class="fw-medium">${this._esc(c.category_name)}</span>
                        </div>
                        <div class="text-end">
                            <div class="fw-semibold">Rs ${this._fmt(c.total_amount)}</div>
                            <small class="text-muted">${c.percentage}% (${c.transaction_count} txns)</small>
                        </div>
                    </div>
                `;
            });
            html += `</div>`;
        }

        el.innerHTML = html;
    }

    renderBudgetHealth() {
        const el = document.querySelector('#report-budget-health .card-body');
        if (!el) return;

        const data = this._data?.budgetHealth;
        if (!data || !data.budgets || data.budgets.length === 0) {
            el.innerHTML = `<div class="text-center py-5"><i class="fas fa-inbox text-muted mb-3" style="font-size:3rem;"></i><p class="text-muted">No budget data available. Create budgets to see health reports.</p></div>`;
            return;
        }

        let html = `
            <div class="row g-3 mb-3">
                <div class="col-6">
                    <small class="text-muted">Total Budget</small>
                    <h5 class="mb-0">Rs ${this._fmt(data.total_budget)}</h5>
                </div>
                <div class="col-6">
                    <small class="text-muted">Total Spent</small>
                    <h5 class="text-danger mb-0">Rs ${this._fmt(data.total_spent)}</h5>
                </div>
            </div>
        `;

        data.budgets.forEach(b => {
            const statusBadge = {
                'Exceeded': 'bg-danger',
                'Warning': 'bg-warning text-dark',
                'On Track': 'bg-success',
                'Inactive': 'bg-secondary'
            }[b.status] || 'bg-secondary';

            const barColor = b.percentage >= 100 ? '#EF4444' : b.percentage >= (80) ? '#F59E0B' : '#10B981';
            const barWidth = Math.min(b.percentage, 100);

            html += `
                <div class="border rounded-3 p-3 mb-3">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div>
                            <span class="fw-semibold">${this._esc(b.name)}</span>
                            <small class="text-muted d-block">${this._esc(b.scope_label || b.category_name || 'All expenses')} - ${b.period}</small>
                        </div>
                        <span class="badge ${statusBadge}">${b.status}</span>
                    </div>
                    <div class="row g-2 small text-muted mb-2">
                        <div class="col-4">Limit: <span class="fw-semibold text-dark">Rs ${this._fmt(b.amount)}</span></div>
                        <div class="col-4">Spent: <span class="fw-semibold text-danger">Rs ${this._fmt(b.spent)}</span></div>
                        <div class="col-4">Remaining: <span class="fw-semibold ${b.remaining >= 0 ? 'text-success' : 'text-danger'}">Rs ${this._fmt(Math.abs(b.remaining))}</span></div>
                    </div>
                    <div class="progress" style="height:8px;">
                        <div class="progress-bar" role="progressbar" style="width:${barWidth}%;background:${barColor};" aria-valuenow="${barWidth}" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                    <small class="text-muted">${b.percentage}% used</small>
                </div>
            `;
        });

        el.innerHTML = html;
    }

    async printReport(type) {
        const data = this._data;
        if (!data) {
            NotificationService.warning('Report data not loaded yet');
            return;
        }

        let title = '';
        let content = '';

        const logo = `<div style="text-align:center;margin-bottom:20px;"><h1 style="color:#10B981;font-size:24px;margin:0;">SanIE</h1><p style="color:#64748b;font-size:12px;margin:0;">Personal Finance Manager</p></div>`;
        const footer = `<div style="text-align:center;margin-top:30px;padding-top:15px;border-top:1px solid #e2e8f0;font-size:11px;color:#94a3b8;">Generated on ${new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })} &middot; SanIE Reports</div>`;
        const dateRange = `<p style="color:#64748b;font-size:13px;text-align:center;margin:0 0 20px 0;">Period: January 1, 2026 - December 31, 2026</p>`;

        if (type === 'income-expense') {
            const ie = await this._allIncomeExpenseData();
            title = 'Income & Expense Report';

            if (!ie || !ie.rows || ie.rows.length === 0) {
                NotificationService.warning('No transaction data to print');
                return;
            }

            const stats = ie.statistics || {};
            let statsHtml = `
                <table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
                    <tr>
                        <td style="padding:10px;background:#f0fdf4;text-align:center;border:1px solid #e2e8f0;">
                            <div style="color:#64748b;font-size:12px;">Total Income</div>
                            <div style="color:#10B981;font-size:18px;font-weight:700;">Rs ${this._fmt(stats.total_income)}</div>
                        </td>
                        <td style="padding:10px;background:#fef2f2;text-align:center;border:1px solid #e2e8f0;">
                            <div style="color:#64748b;font-size:12px;">Total Expense</div>
                            <div style="color:#EF4444;font-size:18px;font-weight:700;">Rs ${this._fmt(stats.total_expense)}</div>
                        </td>
                        <td style="padding:10px;background:#eff6ff;text-align:center;border:1px solid #e2e8f0;">
                            <div style="color:#64748b;font-size:12px;">Net Balance</div>
                            <div style="color:#3B82F6;font-size:18px;font-weight:700;">Rs ${this._fmt(Math.abs(stats.balance))}</div>
                        </td>
                    </tr>
                </table>
            `;

            let rowsHtml = '';
            ie.rows.forEach(r => {
                rowsHtml += `<tr>
                    <td style="padding:8px;border:1px solid #e2e8f0;font-size:12px;">${r.date}</td>
                    <td style="padding:8px;border:1px solid #e2e8f0;font-size:12px;">${this._esc(r.description) || '-'}</td>
                    <td style="padding:8px;border:1px solid #e2e8f0;font-size:12px;">${this._esc(r.category)}</td>
                    <td style="padding:8px;border:1px solid #e2e8f0;font-size:12px;">${this._esc(r.account)}</td>
                    <td style="padding:8px;border:1px solid #e2e8f0;font-size:12px;text-align:right;color:#10B981;">${r.income ? 'Rs ' + this._fmt(r.income) : '-'}</td>
                    <td style="padding:8px;border:1px solid #e2e8f0;font-size:12px;text-align:right;color:#EF4444;">${r.expense ? 'Rs ' + this._fmt(r.expense) : '-'}</td>
                    <td style="padding:8px;border:1px solid #e2e8f0;font-size:12px;text-align:right;${r.balance >= 0 ? 'color:#10B981;' : 'color:#EF4444;'}">Rs ${this._fmt(Math.abs(r.balance))}</td>
                </tr>`;
            });

            content = statsHtml + `
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="background:#f8fafc;">
                            <th style="padding:10px;border:1px solid #e2e8f0;font-size:12px;text-align:left;">Date</th>
                            <th style="padding:10px;border:1px solid #e2e8f0;font-size:12px;text-align:left;">Description</th>
                            <th style="padding:10px;border:1px solid #e2e8f0;font-size:12px;text-align:left;">Category</th>
                            <th style="padding:10px;border:1px solid #e2e8f0;font-size:12px;text-align:left;">Account</th>
                            <th style="padding:10px;border:1px solid #e2e8f0;font-size:12px;text-align:right;">Income</th>
                            <th style="padding:10px;border:1px solid #e2e8f0;font-size:12px;text-align:right;">Expense</th>
                            <th style="padding:10px;border:1px solid #e2e8f0;font-size:12px;text-align:right;">Balance</th>
                        </tr>
                    </thead>
                    <tbody>${rowsHtml}</tbody>
                </table>
            `;
        } else if (type === 'category-breakdown') {
            const cb = data.categoryBreakdown;
            title = 'Category Breakdown Report';

            if (!cb || (!cb.expense_categories?.length && !cb.income_categories?.length)) {
                NotificationService.warning('No category data to print');
                return;
            }

            let catHtml = `
                <table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
                    <tr>
                        <td style="padding:10px;background:#fef2f2;text-align:center;border:1px solid #e2e8f0;">
                            <div style="color:#64748b;font-size:12px;">Total Expense</div>
                            <div style="color:#EF4444;font-size:18px;font-weight:700;">Rs ${this._fmt(cb.total_expense)}</div>
                        </td>
                        <td style="padding:10px;background:#f0fdf4;text-align:center;border:1px solid #e2e8f0;">
                            <div style="color:#64748b;font-size:12px;">Total Income</div>
                            <div style="color:#10B981;font-size:18px;font-weight:700;">Rs ${this._fmt(cb.total_income)}</div>
                        </td>
                    </tr>
                </table>
            `;

            if (cb.expense_categories?.length) {
                catHtml += `<h3 style="color:#EF4444;font-size:14px;margin:15px 0 8px;">Expense Categories</h3>`;
                catHtml += `<table style="width:100%;border-collapse:collapse;margin-bottom:15px;"><thead><tr style="background:#f8fafc;"><th style="padding:8px;border:1px solid #e2e8f0;font-size:12px;text-align:left;">Category</th><th style="padding:8px;border:1px solid #e2e8f0;font-size:12px;text-align:right;">Total Spending</th><th style="padding:8px;border:1px solid #e2e8f0;font-size:12px;text-align:right;">Percentage</th><th style="padding:8px;border:1px solid #e2e8f0;font-size:12px;text-align:right;">Transactions</th></tr></thead><tbody>`;
                cb.expense_categories.forEach(c => {
                    catHtml += `<tr><td style="padding:8px;border:1px solid #e2e8f0;font-size:12px;">${this._esc(c.category_name)}</td><td style="padding:8px;border:1px solid #e2e8f0;font-size:12px;text-align:right;color:#EF4444;">Rs ${this._fmt(c.total_amount)}</td><td style="padding:8px;border:1px solid #e2e8f0;font-size:12px;text-align:right;">${c.percentage}%</td><td style="padding:8px;border:1px solid #e2e8f0;font-size:12px;text-align:right;">${c.transaction_count}</td></tr>`;
                });
                catHtml += `</tbody></table>`;
            }

            if (cb.income_categories?.length) {
                catHtml += `<h3 style="color:#10B981;font-size:14px;margin:15px 0 8px;">Income Categories</h3>`;
                catHtml += `<table style="width:100%;border-collapse:collapse;"><thead><tr style="background:#f8fafc;"><th style="padding:8px;border:1px solid #e2e8f0;font-size:12px;text-align:left;">Category</th><th style="padding:8px;border:1px solid #e2e8f0;font-size:12px;text-align:right;">Total Income</th><th style="padding:8px;border:1px solid #e2e8f0;font-size:12px;text-align:right;">Percentage</th><th style="padding:8px;border:1px solid #e2e8f0;font-size:12px;text-align:right;">Transactions</th></tr></thead><tbody>`;
                cb.income_categories.forEach(c => {
                    catHtml += `<tr><td style="padding:8px;border:1px solid #e2e8f0;font-size:12px;">${this._esc(c.category_name)}</td><td style="padding:8px;border:1px solid #e2e8f0;font-size:12px;text-align:right;color:#10B981;">Rs ${this._fmt(c.total_amount)}</td><td style="padding:8px;border:1px solid #e2e8f0;font-size:12px;text-align:right;">${c.percentage}%</td><td style="padding:8px;border:1px solid #e2e8f0;font-size:12px;text-align:right;">${c.transaction_count}</td></tr>`;
                });
                catHtml += `</tbody></table>`;
            }

            content = catHtml;
        } else if (type === 'budget-health') {
            const bh = data.budgetHealth;
            title = 'Budget Health Report';

            if (!bh || !bh.budgets || bh.budgets.length === 0) {
                NotificationService.warning('No budget data to print');
                return;
            }

            let budgetHtml = `
                <table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
                    <tr>
                        <td style="padding:10px;background:#f8fafc;text-align:center;border:1px solid #e2e8f0;">
                            <div style="color:#64748b;font-size:12px;">Total Budget</div>
                            <div style="font-size:18px;font-weight:700;">Rs ${this._fmt(bh.total_budget)}</div>
                        </td>
                        <td style="padding:10px;background:#fef2f2;text-align:center;border:1px solid #e2e8f0;">
                            <div style="color:#64748b;font-size:12px;">Total Spent</div>
                            <div style="color:#EF4444;font-size:18px;font-weight:700;">Rs ${this._fmt(bh.total_spent)}</div>
                        </td>
                    </tr>
                </table>
            `;

            budgetHtml += `<table style="width:100%;border-collapse:collapse;"><thead><tr style="background:#f8fafc;"><th style="padding:8px;border:1px solid #e2e8f0;font-size:12px;text-align:left;">Budget Name</th><th style="padding:8px;border:1px solid #e2e8f0;font-size:12px;text-align:right;">Limit</th><th style="padding:8px;border:1px solid #e2e8f0;font-size:12px;text-align:right;">Spent</th><th style="padding:8px;border:1px solid #e2e8f0;font-size:12px;text-align:right;">Remaining</th><th style="padding:8px;border:1px solid #e2e8f0;font-size:12px;text-align:center;">Status</th></tr></thead><tbody>`;
            bh.budgets.forEach(b => {
                budgetHtml += `<tr><td style="padding:8px;border:1px solid #e2e8f0;font-size:12px;">${this._esc(b.name)} <small style="color:#64748b;">(${this._esc(b.scope_label || b.category_name)})</small></td><td style="padding:8px;border:1px solid #e2e8f0;font-size:12px;text-align:right;">Rs ${this._fmt(b.amount)}</td><td style="padding:8px;border:1px solid #e2e8f0;font-size:12px;text-align:right;color:#EF4444;">Rs ${this._fmt(b.spent)}</td><td style="padding:8px;border:1px solid #e2e8f0;font-size:12px;text-align:right;${b.remaining >= 0 ? 'color:#10B981;' : 'color:#EF4444;'}">Rs ${this._fmt(Math.abs(b.remaining))}</td><td style="padding:8px;border:1px solid #e2e8f0;font-size:12px;text-align:center;"><span style="font-weight:600;">${b.status}</span> (${b.percentage}%)</td></tr>`;
            });
            budgetHtml += `</tbody></table>`;

            content = budgetHtml;
        } else {
            return;
        }

        const printWindow = window.open('', '_blank', 'width=900,height=700');
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>${title} - SanIE</title>
                <style>
                    @page { size: A4; margin: 15mm; }
                    * { box-sizing: border-box; }
                    body {
                        font-family: 'Segoe UI', Arial, sans-serif;
                        margin: 0;
                        padding: 20px;
                        color: #0f172a;
                        background: #fff;
                    }
                    @media print {
                        body { padding: 0; }
                    }
                    table { page-break-inside: auto; }
                    tr { page-break-inside: avoid; page-break-after: auto; }
                    thead { display: table-header-group; }
                    tfoot { display: table-footer-group; }
                </style>
            </head>
            <body>
                ${logo}
                <h2 style="text-align:center;font-size:20px;margin:0 0 5px;">${title}</h2>
                ${dateRange}
                ${content}
                ${footer}
                <script>window.onload = function() { window.print(); }<${'/script'}
            </body>
            </html>
        `);
        printWindow.document.close();
    }

    async exportCSV(type) {
        const data = this._data;
        if (!data) {
            NotificationService.warning('Report data not loaded yet');
            return;
        }

        let headers = [];
        let rows = [];
        let filename = '';

        if (type === 'income-expense') {
            const ie = await this._allIncomeExpenseData();
            if (!ie || !ie.rows || ie.rows.length === 0) {
                NotificationService.warning('No transaction data to export');
                return;
            }
            filename = `SanIE_Income_Expense_Report_${new Date().toISOString().split('T')[0]}.csv`;
            headers = ['Date', 'Type', 'Description', 'Category', 'Account', 'Amount'];
            rows = ie.rows.map(r => [
                r.date,
                r.type,
                `"${(r.description || '').replace(/"/g, '""')}"`,
                `"${r.category.replace(/"/g, '""')}"`,
                `"${r.account.replace(/"/g, '""')}"`,
                r.type === 'income' ? r.income : r.expense
            ]);
        } else if (type === 'category-breakdown') {
            const cb = data.categoryBreakdown;
            if (!cb) {
                NotificationService.warning('No category data to export');
                return;
            }
            filename = `SanIE_Category_Breakdown_${new Date().toISOString().split('T')[0]}.csv`;
            headers = ['Category', 'Type', 'Total Amount', 'Percentage', 'Transaction Count'];
            rows = [];
            (cb.expense_categories || []).forEach(c => {
                rows.push([`"${c.category_name}"`, 'Expense', c.total_amount, c.percentage, c.transaction_count]);
            });
            (cb.income_categories || []).forEach(c => {
                rows.push([`"${c.category_name}"`, 'Income', c.total_amount, c.percentage, c.transaction_count]);
            });
            if (rows.length === 0) {
                NotificationService.warning('No category data to export');
                return;
            }
        } else if (type === 'budget-health') {
            const bh = data.budgetHealth;
            if (!bh || !bh.budgets || bh.budgets.length === 0) {
                NotificationService.warning('No budget data to export');
                return;
            }
            filename = `SanIE_Budget_Health_${new Date().toISOString().split('T')[0]}.csv`;
            headers = ['Budget Name', 'Category', 'Limit', 'Spent', 'Remaining', 'Percentage', 'Status'];
            rows = bh.budgets.map(b => [
                `"${b.name}"`,
                `"${b.scope_label || b.category_name}"`,
                b.amount,
                b.spent,
                b.remaining,
                b.percentage,
                b.status
            ]);
        } else {
            return;
        }

        const csvContent = [headers.join(','), ...rows.map(r => r.join(','))].join('\n');
        const blob = new Blob(['\uFEFF' + csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
        NotificationService.success(`${filename} downloaded`);
    }

    _fmt(n) {
        const num = parseFloat(n) || 0;
        return num.toLocaleString('en-IN', { maximumFractionDigits: 0 });
    }

    _esc(s) {
        if (!s) return '';
        const div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }
}

window.ReportsManager = ReportsManager;
