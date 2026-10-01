// Budgets Module - Refactored with ModalService (double-submit fix) and lifecycle hooks
class BudgetsManager {
    constructor() {
        this.budgets = [];
        this.visibleBudgets = [];
        this.progressById = new Map();
        this._mounted = false;
        this._listeners = {};
        this._budgetSubmitInProgress = false;
        const now = DateUtils.getKathmanduDateParts();
        this.selectedMonth = `${now.year}-${String(now.month).padStart(2, '0')}`;
    }

    onMount() {
        if (this._mounted) return;
        this._mounted = true;
        this.setupEventListeners();
        document.addEventListener('app:data-changed', this._onDataChanged = () => this.loadBudgets());
        if (window.authManager?.isAuthenticated()) {
            this.loadBudgets();
        }
    }

    onUnmount() {
        this._mounted = false;
        if (this._onDataChanged) {
            document.removeEventListener('app:data-changed', this._onDataChanged);
        }
        const addBtn = document.getElementById('add-budget-btn');
        if (addBtn && this._listeners.addClick) {
            addBtn.removeEventListener('click', this._listeners.addClick);
        }
        const bulkBtn = document.getElementById('bulk-budget-btn');
        if (bulkBtn && this._listeners.bulkClick) {
            bulkBtn.removeEventListener('click', this._listeners.bulkClick);
        }
        const monthInput = document.getElementById('budget-month');
        if (monthInput && this._listeners.monthChange) {
            monthInput.removeEventListener('change', this._listeners.monthChange);
        }
        document.getElementById('budget-report-btn')?.removeEventListener('click', this._listeners.reportClick);
        document.getElementById('copy-budget-btn')?.removeEventListener('click', this._listeners.copyClick);
    }

    setupEventListeners() {
        const addBtn = document.getElementById('add-budget-btn');
        if (addBtn) {
            this._listeners.addClick = () => this.showAddBudgetModal();
            addBtn.addEventListener('click', this._listeners.addClick);
        }
        const bulkBtn = document.getElementById('bulk-budget-btn');
        if (bulkBtn) {
            this._listeners.bulkClick = () => this.showBulkBudgetModal();
            bulkBtn.addEventListener('click', this._listeners.bulkClick);
        }
        const monthInput = document.getElementById('budget-month');
        if (monthInput) {
            monthInput.value = this.selectedMonth;
            this._listeners.monthChange = () => {
                if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(monthInput.value)) return;
                this.selectedMonth = monthInput.value;
                this.loadBudgets();
            };
            monthInput.addEventListener('change', this._listeners.monthChange);
        }
        const reportBtn = document.getElementById('budget-report-btn');
        if (reportBtn) {
            this._listeners.reportClick = () => this.showReportModal();
            reportBtn.addEventListener('click', this._listeners.reportClick);
        }
        const copyBtn = document.getElementById('copy-budget-btn');
        if (copyBtn) {
            this._listeners.copyClick = () => this.showCopyBudgetModal();
            copyBtn.addEventListener('click', this._listeners.copyClick);
        }
    }

    async loadBudgets() {
        if (!window.authManager?.isAuthenticated()) return;

        AjaxService?.showSkeleton('#budgets-grid');
        try {
            const response = await budgetsAPI.getAll();
            if (response.success) {
                this.budgets = response.data;
                await this.renderBudgets();
            } else {
                throw new Error(response?.message || 'Failed to load budgets');
            }
        } catch (error) {
            console.error('Failed to load budgets:', error);
            NotificationService.error('Failed to load budgets');
        } finally {
            AjaxService?.hideSkeleton('#budgets-grid');
        }
    }

    async renderBudgets() {
        const container = document.getElementById('budgets-grid');
        container.innerHTML = '';
        this._setOverview(0, 0);
        const { startDate, endDate } = this._selectedMonthRange();
        this.visibleBudgets = this.budgets.filter(budget => budget.start_date <= endDate && budget.end_date >= startDate);

        if (this.visibleBudgets.length === 0) {
            container.innerHTML = `
                <div class="budget-empty-state">
                    <span><i class="bi bi-pie-chart"></i></span>
                    <h3>No budgets for this month</h3>
                    <p>Create a budget for the selected month or copy a previous period.</p>
                    <button class="btn btn-primary" onclick="budgetsManager.showAddBudgetModal()"><i class="fas fa-plus"></i> Create Budget</button>
                </div>`;
            return;
        }

        this._setOverview(this.visibleBudgets.reduce((sum, b) => sum + Number(b.amount || 0), 0), 0);

        this.visibleBudgets.forEach(budget => {
            const card = document.createElement('div');
            card.className = 'budget-card';
            card.dataset.budgetId = budget.id;

            card.innerHTML = `
                <div class="budget-header">
                    <div class="budget-title-wrap">
                        <span class="budget-card-icon"><i class="bi bi-pie-chart-fill"></i></span>
                        <div>
                            <p class="budget-name">${Formatters.escapeHTML(budget.name)}</p>
                            <span class="budget-category">${Formatters.escapeHTML(budget.scope_label || budget.category_name || 'All expenses')}</span>
                        </div>
                    </div>
                    <button class="budget-delete-btn" title="Delete budget" aria-label="Delete ${Formatters.escapeHTML(budget.name)}" onclick="budgetsManager.deleteBudget(${budget.id})">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
                <div class="budget-limit-row">
                    <div><small>Budget limit</small><p class="budget-amount">${Formatters.currency(budget.amount)}</p></div>
                    <span class="budget-period">${Formatters.escapeHTML(budget.period || 'monthly')}</span>
                </div>
                <div class="budget-progress-meta"><span>Spent</span><strong class="budget-progress-rate">0%</strong></div>
                <div class="budget-progress-bar">
                    <div class="budget-progress-fill" style="width: 0%"></div>
                </div>
                <div class="budget-card-footer">
                    <span class="budget-percentage">Loading progress...</span>
                    <span class="budget-remaining">Calculating...</span>
                </div>
            `;

            container.appendChild(card);
        });

        // Batch fetch all progress in one request
        const ids = this.visibleBudgets.map(b => b.id);
        try {
            const [response,aggregateResponse] = await Promise.all([
                budgetsAPI.getBatchProgress(ids, startDate, endDate),
                budgetsAPI.getAggregateProgress(ids, startDate, endDate)
            ]);
            if (response.success) {
                this.progressById = new Map(response.data.map(progress => [String(progress.budget_id), progress]));
                const totalSpent = Number(aggregateResponse?.data?.unique_spent || 0);
                const totalLimit = this.visibleBudgets.reduce((sum, b) => sum + Number(b.amount || 0), 0);
                this._setOverview(totalLimit, totalSpent);
                response.data.forEach(progress => {
                    const card = container.querySelector(`[data-budget-id="${progress.budget_id}"]`);
                    if (card) this._updateProgressCard(card, progress);
                });
            }
        } catch (error) {
            console.error('Failed to load budget progress:', error);
        }
    }

    _updateProgressCard(card, progress) {
        const percentage = Math.min(100, progress.percentage);
        let progressClass = '';
        if (percentage >= 90) progressClass = 'danger';
        else if (percentage >= 70) progressClass = 'warning';

        const progressBar = card.querySelector('.budget-progress-fill');
        progressBar.style.width = `${percentage}%`;
        progressBar.className = `budget-progress-fill ${progressClass}`;

        const percentageText = card.querySelector('.budget-percentage');
        percentageText.textContent = `${Formatters.currency(progress.spent)} spent`;
        card.querySelector('.budget-progress-rate').textContent = `${percentage.toFixed(0)}%`;
        const remaining = Number(progress.budget_amount || progress.amount || 0) - Number(progress.spent || 0);
        const fallbackLimit = Number(this.visibleBudgets.find(b => String(b.id) === String(progress.budget_id))?.amount || 0);
        const finalRemaining = Number(progress.remaining ?? (fallbackLimit - Number(progress.spent || 0)));
        const remainingEl = card.querySelector('.budget-remaining');
        remainingEl.textContent = finalRemaining >= 0 ? `${Formatters.currency(finalRemaining)} left` : `${Formatters.currency(Math.abs(finalRemaining))} over`;
        remainingEl.classList.toggle('is-over', finalRemaining < 0);
    }

    _setOverview(limit, spent) {
        const remaining = Number(limit) - Number(spent);
        const set = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = value; };
        set('budget-total-limit', Formatters.currency(limit));
        set('budget-total-spent', Formatters.currency(spent));
        set('budget-total-remaining', Formatters.currency(remaining));
        set('budget-active-count', String(this.budgets.length));
        document.getElementById('budget-total-remaining')?.classList.toggle('text-danger', remaining < 0);
    }

    _selectedMonthRange(selectedMonth = this.selectedMonth) {
        const [year, month] = selectedMonth.split('-').map(Number);
        const endDay = new Date(year, month, 0).getDate();
        return {
            startDate: `${selectedMonth}-01`,
            endDate: `${selectedMonth}-${String(endDay).padStart(2, '0')}`
        };
    }

    showReportModal() {
        if (!this.budgets.length) {
            NotificationService.warning('No budgets available to report');
            return;
        }
        window.modalService?.open({
            title: 'Budget Report',
            subtitle: 'Choose the month you want to print.',
            icon: 'fa-print',
            bodyHTML: `<div class="form-group"><label for="budget-report-month">Report month</label><div class="form-control-icon"><i class="fas fa-calendar-alt"></i><input type="month" id="budget-report-month" value="${this.selectedMonth}" required></div><small class="d-block text-muted mt-2">The printable report will include all budgets and their spending for the selected month.</small></div>`,
            saveText: '<i class="fas fa-print me-1"></i> Preview Report',
            onSave: () => this.openReportPreview()
        });
    }

    async openReportPreview() {
        const reportMonth = document.getElementById('budget-report-month')?.value || '';
        if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(reportMonth)) {
            NotificationService.warning('Select a valid report month');
            return;
        }
        const preview = window.open('', '_blank', 'width=1050,height=760');
        if (!preview) {
            NotificationService.error('Please allow pop-ups to open the report preview');
            return;
        }
        preview.document.write('<!doctype html><title>Preparing budget report...</title><p style="font:16px Arial;padding:32px">Preparing budget report...</p>');
        preview.document.close();
        const { startDate, endDate } = this._selectedMonthRange(reportMonth);
        const reportBudgets=this.budgets.filter(budget=>budget.start_date<=endDate&&budget.end_date>=startDate);
        if(!reportBudgets.length){preview.document.open();preview.document.write('<!doctype html><title>No budgets</title><p style="font:16px Arial;padding:32px">No budgets exist for the selected month.</p>');preview.document.close();NotificationService.warning('No budgets exist for the selected month');return;}
        let reportProgress,aggregateResponse;
        try {
            const [response,aggregate] = await Promise.all([
                budgetsAPI.getBatchProgress(reportBudgets.map(budget => budget.id), startDate, endDate),
                budgetsAPI.getAggregateProgress(reportBudgets.map(budget=>budget.id),startDate,endDate)
            ]);
            if (!response?.success) throw new Error(response?.message || 'Unable to load budget report');
            reportProgress = new Map(response.data.map(progress => [String(progress.budget_id), progress]));
            aggregateResponse=aggregate;
        } catch (error) {
            preview.document.open();
            preview.document.write('<!doctype html><title>Report Error</title><p style="font:16px Arial;padding:32px">Unable to load the budget report.</p>');
            preview.document.close();
            NotificationService.error(error.message || 'Unable to load budget report');
            return;
        }
        const money = value => 'Rs ' + Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        let totalLimit = 0;
        let totalSpent = 0;
        const rows = reportBudgets.map(budget => {
            const progress = reportProgress.get(String(budget.id)) || {};
            const limit = Number(budget.amount || 0);
            const spent = Number(progress.spent || 0);
            const remaining = Number(progress.remaining ?? (limit - spent));
            const percentage = limit > 0 ? (spent / limit) * 100 : 0;
            totalLimit += limit;
            totalSpent += spent;
            return `<tr><td><strong>${Formatters.escapeHTML(budget.name)}</strong><small>${Formatters.escapeHTML(budget.scope_label || budget.category_name || 'All expenses')}</small></td><td>${money(limit)}</td><td>${money(spent)}</td><td class="${remaining < 0 ? 'over' : ''}">${money(remaining)}</td><td>${percentage.toFixed(1)}%</td></tr>`;
        }).join('');
        totalSpent=Number(aggregateResponse?.data?.unique_spent||0);
        const totalRemaining = totalLimit - totalSpent;
        const reportMonthLabel = this._monthLabel(reportMonth);
        preview.document.open();
        preview.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>SanIE Budget Report - ${reportMonthLabel}</title><style>*{box-sizing:border-box}body{margin:0;padding:24px;background:linear-gradient(145deg,#dff5ed,#eef3f8);color:#172033;font-family:Inter,Arial,sans-serif}.sheet{position:relative;width:210mm;min-height:297mm;margin:0 auto;background:#fff;border-radius:20px;overflow:hidden;box-shadow:0 24px 70px #0f172a2b}.sheet:after{content:'SanIE';position:absolute;right:-18px;bottom:58px;color:#10b9810a;font-size:92px;font-weight:900;transform:rotate(-12deg);pointer-events:none}.hero{position:relative;display:flex;justify-content:space-between;align-items:center;padding:30px 36px;color:#fff;background:linear-gradient(125deg,#052e2b,#047857 62%,#34d399);overflow:hidden}.hero:after{content:'';position:absolute;width:180px;height:180px;border:34px solid #ffffff12;border-radius:50%;right:-55px;top:-95px}.brand{font-size:32px;font-weight:900;letter-spacing:-1px}.hero small{display:block;opacity:.78;letter-spacing:2px;font-weight:700}.meta{position:relative;z-index:1;text-align:right}.meta strong{font-size:21px}.meta div{margin-top:5px;opacity:.85}.content{padding:30px 36px 36px}.toolbar{text-align:right;margin-bottom:18px}.print{border:0;border-radius:11px;background:linear-gradient(135deg,#10b981,#047857);color:#fff;padding:12px 20px;font-weight:800;cursor:pointer;box-shadow:0 7px 18px #10b98140}.summary{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:27px}.box{position:relative;padding:17px 16px;border:1px solid #dce9e4;border-radius:14px;background:linear-gradient(145deg,#fff,#f3faf7);overflow:hidden}.box:before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;background:#10b981}.box:nth-child(2):before{background:#fb7185}.box:nth-child(3):before{background:#60a5fa}.box span,td small{display:block;color:#64748b;font-size:10px;margin-bottom:7px;text-transform:uppercase;letter-spacing:.45px}.box strong{font-size:19px}table{position:relative;z-index:1;width:100%;border-collapse:separate;border-spacing:0;font-size:11px;border:1px solid #dce6e2;border-radius:13px;overflow:hidden}th,td{padding:12px 10px;border-bottom:1px solid #e5ece9;text-align:left}th{background:#e9f9f2;color:#066449;text-transform:uppercase;font-size:9px;letter-spacing:.45px}tbody tr:nth-child(even){background:#f8fbfa}tbody tr:last-child td{border-bottom:0}.over{color:#dc2626;font-weight:700}.footer{position:absolute;left:0;right:0;bottom:0;padding:15px 36px;background:#f4f8f6;color:#82938c;font-size:9px;display:flex;justify-content:space-between;border-top:1px solid #e3ebe7}@media(max-width:850px){body{padding:0}.sheet{width:100%;min-height:100vh;border-radius:0}.content{padding:24px 20px}.hero{padding:25px 20px}}@media print{body{padding:0;background:#fff}.sheet{width:100%;min-height:277mm;margin:0;border-radius:0;box-shadow:none}.toolbar{display:none}.hero,.box,th,tbody tr:nth-child(even),.footer{-webkit-print-color-adjust:exact;print-color-adjust:exact}.content{padding:24px 26px 32mm}.footer{position:fixed}@page{size:A4 portrait;margin:10mm}}</style></head><body><main class="sheet"><header class="hero"><div><div class="brand">SanIE</div><small>FINANCE MANAGER</small></div><div class="meta"><strong>Budget Report</strong><div>${reportMonthLabel}</div></div></header><div class="content"><div class="toolbar"><button class="print" id="print-budget-report">Print / Save PDF</button></div><section class="summary"><div class="box"><span>Total budget</span><strong>${money(totalLimit)}</strong></div><div class="box"><span>Total spent</span><strong>${money(totalSpent)}</strong></div><div class="box"><span>Remaining</span><strong>${money(totalRemaining)}</strong></div></section><table><thead><tr><th>Budget</th><th>Limit</th><th>Spent</th><th>Remaining</th><th>Used</th></tr></thead><tbody>${rows}</tbody></table></div><footer class="footer"><span>Generated by SanIE Finance Manager</span><span>${new Date().toLocaleString()}</span></footer></main></body></html>`);
        preview.document.close();
        preview.document.getElementById('print-budget-report')?.addEventListener('click', () => preview.print());
        window.modalService?.close();
    }

    _monthLabel(selectedMonth = this.selectedMonth) {
        const [year, month] = selectedMonth.split('-').map(Number);
        return new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric' }).format(new Date(year, month - 1, 1));
    }

    showCopyBudgetModal() {
        const [year, month] = this.selectedMonth.split('-').map(Number);
        const previous = new Date(year, month - 2, 1);
        const sourceMonth = `${previous.getFullYear()}-${String(previous.getMonth() + 1).padStart(2, '0')}`;
        window.modalService?.open({
            title: 'Copy Monthly Budgets',
            subtitle: 'Reuse a previous or custom month without changing the original budgets.',
            icon: 'fa-copy',
            saveText: '<i class="fas fa-copy me-1"></i> Copy Selected Budgets',
            bodyHTML: `
                <div class="copy-budget-flow">
                    <div class="row g-3 align-items-end mb-3">
                        <div class="col-md-5">
                            <label class="form-label fw-semibold" for="copy-budget-source-month">Copy from</label>
                            <input class="form-control" type="month" id="copy-budget-source-month" value="${sourceMonth}" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold" for="copy-budget-target-month">Paste into</label>
                            <input class="form-control" type="month" id="copy-budget-target-month" value="${this.selectedMonth}" required>
                        </div>
                        <div class="col-md-2 d-grid">
                            <button type="button" class="btn btn-outline-secondary" id="copy-budget-previous-btn" title="Use the month before the destination"><i class="fas fa-history me-1"></i> Previous</button>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center rounded-3 p-3 mb-2" style="background:var(--bg-secondary,#f4f8f7)">
                        <div><strong id="copy-budget-source-label"></strong><small class="d-block text-muted">Choose the limits you want to reuse.</small></div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="copy-budget-select-all">Select all</button>
                    </div>
                    <div id="copy-budget-list" class="d-grid gap-2" style="max-height:340px;overflow:auto"></div>
                    <div class="alert alert-light border mt-3 mb-0 d-flex justify-content-between align-items-center">
                        <span id="copy-budget-count">0 selected</span>
                        <strong id="copy-budget-total">Rs 0.00</strong>
                    </div>
                </div>`,
            onSave: () => this.copySelectedBudgets()
        });

        const source = document.getElementById('copy-budget-source-month');
        const target = document.getElementById('copy-budget-target-month');
        const rerender = () => this.renderCopyBudgetChoices(source?.value, target?.value);
        source?.addEventListener('change', rerender);
        target?.addEventListener('change', rerender);
        document.getElementById('copy-budget-previous-btn')?.addEventListener('click', () => {
            if (!target?.value || !source) return;
            const [targetYear, targetMonth] = target.value.split('-').map(Number);
            const prior = new Date(targetYear, targetMonth - 2, 1);
            source.value = `${prior.getFullYear()}-${String(prior.getMonth() + 1).padStart(2, '0')}`;
            rerender();
        });
        document.getElementById('copy-budget-select-all')?.addEventListener('click', () => {
            document.querySelectorAll('.copy-budget-check:not(:disabled)').forEach(box => { box.checked = true; });
            this.updateCopyBudgetSummary();
        });
        rerender();
    }

    _copyBudgetKey(budget) {
        return `${String(budget.name || '').trim().toLowerCase()}|${budget.category_id || ''}|${budget.subcategory_id || ''}`;
    }

    renderCopyBudgetChoices(sourceMonth, targetMonth) {
        const list = document.getElementById('copy-budget-list');
        const label = document.getElementById('copy-budget-source-label');
        if (!list || !/^\d{4}-(0[1-9]|1[0-2])$/.test(sourceMonth || '') || !/^\d{4}-(0[1-9]|1[0-2])$/.test(targetMonth || '')) return;
        const sourceRange = this._selectedMonthRange(sourceMonth);
        const targetRange = this._selectedMonthRange(targetMonth);
        const sourceBudgets = this.budgets.filter(b => b.period === 'monthly' && b.start_date <= sourceRange.endDate && b.end_date >= sourceRange.startDate);
        const targetKeys = new Set(this.budgets
            .filter(b => b.period === 'monthly' && b.start_date <= targetRange.endDate && b.end_date >= targetRange.startDate)
            .map(b => this._copyBudgetKey(b)));
        if (label) label.textContent = `Budgets from ${this._monthLabel(sourceMonth)}`;
        if (!sourceBudgets.length) {
            list.innerHTML = '<div class="text-center text-muted border rounded-3 p-4"><i class="fas fa-calendar-times fa-2x mb-2"></i><div>No monthly budgets found for this source month.</div></div>';
            this.updateCopyBudgetSummary();
            return;
        }
        list.innerHTML = sourceBudgets.map(budget => {
            const duplicate = targetKeys.has(this._copyBudgetKey(budget));
            return `<label class="d-flex align-items-center gap-3 border rounded-3 p-3 ${duplicate ? 'opacity-75' : ''}" style="cursor:${duplicate ? 'not-allowed' : 'pointer'}">
                <input class="form-check-input copy-budget-check" type="checkbox" value="${Number(budget.id)}" data-amount="${Number(budget.amount || 0)}" ${duplicate ? 'disabled' : 'checked'}>
                <span class="budget-card-icon flex-shrink-0"><i class="bi bi-pie-chart-fill"></i></span>
                <span class="flex-grow-1"><strong class="d-block">${Formatters.escapeHTML(budget.name)}</strong><small class="text-muted">${Formatters.escapeHTML(budget.scope_label || budget.category_name || 'All expenses')}</small></span>
                <span class="text-end"><strong>${Formatters.currency(budget.amount)}</strong>${duplicate ? '<small class="d-block text-success">Already exists</small>' : ''}</span>
            </label>`;
        }).join('');
        list.querySelectorAll('.copy-budget-check').forEach(box => box.addEventListener('change', () => this.updateCopyBudgetSummary()));
        this.updateCopyBudgetSummary();
    }

    updateCopyBudgetSummary() {
        const selected = [...document.querySelectorAll('.copy-budget-check:checked')];
        const count = document.getElementById('copy-budget-count');
        const total = document.getElementById('copy-budget-total');
        if (count) count.textContent = `${selected.length} selected`;
        if (total) total.textContent = Formatters.currency(selected.reduce((sum, box) => sum + Number(box.dataset.amount || 0), 0));
    }

    async copySelectedBudgets() {
        const sourceMonth = document.getElementById('copy-budget-source-month')?.value || '';
        const targetMonth = document.getElementById('copy-budget-target-month')?.value || '';
        if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(sourceMonth) || !/^\d{4}-(0[1-9]|1[0-2])$/.test(targetMonth)) {
            NotificationService.warning('Choose valid source and destination months');
            return;
        }
        if (sourceMonth === targetMonth) {
            NotificationService.warning('Source and destination months must be different');
            return;
        }
        const ids = [...document.querySelectorAll('.copy-budget-check:checked')].map(box => Number(box.value));
        if (!ids.length) {
            NotificationService.warning('Select at least one budget to copy');
            return;
        }
        const saveBtn = document.getElementById('modal-save-btn');
        AjaxService?.showButtonLoading(saveBtn, 'Copying...');
        try {
            const response = await budgetsAPI.copyToMonth(ids, sourceMonth, targetMonth);
            if (!response?.success) throw new Error(response?.message || 'Unable to copy budgets');
            const created = Number(response.data?.created || 0);
            const skipped = response.data?.skipped?.length || 0;
            NotificationService.success(`${created} budget(s) copied to ${this._monthLabel(targetMonth)}${skipped ? `; ${skipped} already existed` : ''}`);
            this.selectedMonth = targetMonth;
            const monthInput = document.getElementById('budget-month');
            if (monthInput) monthInput.value = targetMonth;
            window.modalService?.close();
        } catch (error) {
            NotificationService.error(error.message || 'Unable to copy budgets');
        } finally {
            AjaxService?.hideButtonLoading(saveBtn);
        }
    }

    showAddBudgetModal(draft = {}) {
        const escapeAttribute = value => String(value ?? '').replace(/[&<>'"]/g, character => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#39;', '"':'&quot;' }[character]));
        const selectedPeriod = draft.period || 'monthly';
        const startDate = draft.start_date || DateUtils.getKathmanduDateString();
        const endDate = draft.end_date || '';
        const formHTML = `
            <form id="budget-form">
                <div class="row g-4">
                    <div class="col-lg-6">
                        <div class="form-group">
                            <label>Budget Name</label>
                            <div class="form-control-icon">
                                <i class="fas fa-tag"></i>
                                <input type="text" id="budget-name" value="${escapeAttribute(draft.name)}" placeholder="e.g. Monthly Groceries" required>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="form-group">
                            <label>Amount</label>
                            <div class="form-control-icon">
                                <i class="fas fa-rupee-sign"></i>
                                <input type="number" id="budget-amount" value="${escapeAttribute(draft.amount)}" step="0.01" placeholder="Rs 25,000" required>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="form-group">
                            <label>Period</label>
                            <div class="form-control-icon">
                                <i class="fas fa-calendar"></i>
                                <select id="budget-period" required>
                                    <option value="daily" ${selectedPeriod === 'daily' ? 'selected' : ''}>Daily</option>
                                    <option value="weekly" ${selectedPeriod === 'weekly' ? 'selected' : ''}>Weekly</option>
                                    <option value="monthly" ${selectedPeriod === 'monthly' ? 'selected' : ''}>Monthly</option>
                                    <option value="yearly" ${selectedPeriod === 'yearly' ? 'selected' : ''}>Yearly</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="form-group">
                            <label>Category (Optional)</label>
                            <div class="form-control-icon">
                                <i class="fas fa-folder-open"></i>
                                <select id="budget-category">
                                    <option value="">All categories</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="form-group">
                            <label>Subcategory (Optional)</label>
                            <div class="form-control-icon">
                                <i class="fas fa-sitemap"></i>
                                <select id="budget-subcategory" disabled>
                                    <option value="">All subcategories</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="form-group">
                            <label>Start Date</label>
                            <div class="form-control-icon">
                                <i class="fas fa-calendar-check"></i>
                                <input type="date" id="budget-start-date" value="${escapeAttribute(startDate)}" required>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="form-group">
                            <label>End Date</label>
                            <div class="form-control-icon">
                                <i class="fas fa-calendar-times"></i>
                                <input type="date" id="budget-end-date" value="${escapeAttribute(endDate)}" required>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="form-group">
                            <label>Alert Threshold (%)</label>
                            <div class="form-control-icon">
                                <i class="fas fa-bell"></i>
                                <input type="number" id="budget-alert-threshold" value="${escapeAttribute(draft.alert_threshold ?? 80)}" min="0" max="100" required>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        `;

        if (window.ModalService) {
            window.modalService.open({
                title: 'Add Budget',
                subtitle: 'Set spending limits for categories.',
                icon: 'fa-wallet',
                bodyHTML: formHTML,
                onSave: () => this.handleBudgetSubmit()
            });
        } else if (window.premiumModal) {
            document.getElementById('modal-body').innerHTML = formHTML;
            document.getElementById('modal-footer').classList.add('hidden');
            premiumModal.setTitle('Add Budget');
            premiumModal.setSubtitle('Set spending limits for categories.');
            premiumModal.setIcon('fa-wallet');
            premiumModal.open();
        }

        if (window.DatePickerManager) {
            DatePickerManager.bind('#budget-start-date');
            DatePickerManager.bind('#budget-end-date');
        }

        this.loadCategoriesForForm(draft.category_id, draft.subcategory_id);
    }

    async loadCategoriesForForm(selectedCategoryId = null, selectedSubcategoryId = null) {
        try {
            const response = await categoriesAPI.getAll('expense');

            if (response.success) {
                const select = document.getElementById('budget-category');
                if (select) {
                    select.innerHTML = '<option value="">All categories</option>';
                    response.data.forEach(category => {
                        const option = document.createElement('option');
                        option.value = category.id;
                        option.textContent = category.name;
                        select.appendChild(option);
                    });
                    select.addEventListener('change',()=>this.loadSubcategoriesForForm(select.value));
                    if (selectedCategoryId) {
                        select.value = String(selectedCategoryId);
                        await this.loadSubcategoriesForForm(select.value, selectedSubcategoryId);
                    }
                }
            }
        } catch (error) {
            console.error('Failed to load categories:', error);
        }
    }

    async loadSubcategoriesForForm(categoryId, selectedSubcategoryId = null){
        const select=document.getElementById('budget-subcategory');
        if(!select)return;
        select.innerHTML='<option value="">All subcategories</option>';
        select.disabled=!categoryId;
        if(!categoryId)return;
        try{
            const response=await categoriesAPI.getSubcategories(categoryId);
            if(response.success)response.data.forEach(subcategory=>{
                const option=document.createElement('option');option.value=subcategory.id;option.textContent=subcategory.name;select.appendChild(option);
            });
            if (selectedSubcategoryId) select.value = String(selectedSubcategoryId);
        }catch(error){console.error('Failed to load budget subcategories:',error);}
    }

    async handleBudgetSubmit() {
        if (this._budgetSubmitInProgress) return;
        const form = document.getElementById('budget-form');
        if (form && !form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const data = {
            name: document.getElementById('budget-name').value,
            amount: parseFloat(document.getElementById('budget-amount').value),
            period: document.getElementById('budget-period').value,
            category_id: document.getElementById('budget-category')?.value ? parseInt(document.getElementById('budget-category').value) : null,
            subcategory_id: document.getElementById('budget-subcategory')?.value ? parseInt(document.getElementById('budget-subcategory').value) : null,
            start_date: document.getElementById('budget-start-date').value,
            end_date: document.getElementById('budget-end-date').value,
            alert_threshold: parseFloat(document.getElementById('budget-alert-threshold').value)
        };

        const saveBtn = document.getElementById('modal-save-btn') || document.querySelector('#modal-footer .btn-primary');
        this._budgetSubmitInProgress = true;
        AjaxService?.showButtonLoading(saveBtn);
        try {
            const response = await budgetsAPI.create(data);

            if (response.success) {
                if (window.modalService) modalService.close();
                else if (window.premiumModal) premiumModal.close();
                NotificationService.success('Budget created successfully');
            }
        } catch (error) {
            console.error('Failed to create budget:', error);
            if (this.isDuplicateBudgetConflict(error)) {
                await this.confirmDuplicateBudgetUpdate(error.details.existing_budget, data);
            } else {
                // Keep the modal and its values open so any validation error
                // can be corrected without re-entering the form.
                NotificationService.error(error.message || 'Failed to create budget');
            }
        } finally {
            this._budgetSubmitInProgress = false;
            AjaxService?.hideButtonLoading(saveBtn);
        }
    }

    isDuplicateBudgetConflict(error) {
        return error?.status === 409
            && error?.details?.code === 'BUDGET_DUPLICATE'
            && Number.isInteger(Number(error.details?.existing_budget?.id));
    }

    async confirmDuplicateBudgetUpdate(existingBudget, submittedData) {
        const escape = value => window.Formatters?.escapeHTML
            ? Formatters.escapeHTML(String(value ?? ''))
            : String(value ?? '').replace(/[&<>'"]/g, character => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#39;', '"':'&quot;' }[character]));
        const category = existingBudget.category_name || 'All Categories';
        const scope = existingBudget.subcategory_name
            ? `${category} → ${existingBudget.subcategory_name}`
            : existingBudget.category_name ? `${category} → All Subcategories` : category;
        const period = String(existingBudget.period || submittedData.period || 'monthly').toLowerCase();
        const range = this.formatBudgetDateRange(existingBudget.start_date, existingBudget.end_date);
        const money = value => window.Formatters?.currency ? Formatters.currency(value) : `Rs ${Number(value || 0).toFixed(2)}`;

        if (!window.modalService) {
            NotificationService.error('A matching budget already exists. Please update it from the budget list.');
            return;
        }

        window.modalService.open({
            title: 'Budget Already Exists',
            subtitle: 'Choose whether to keep the current budget or update it with your entered values.',
            icon: 'fa-triangle-exclamation',
            bodyHTML: `<p>A ${escape(period)} budget already exists for <strong>${escape(scope)}</strong> for <strong>${escape(range)}</strong>.</p>
                <div class="text-start border rounded-3 p-3 mt-3">
                    <div class="d-flex justify-content-between gap-3"><span>Existing amount</span><strong>${escape(money(existingBudget.amount))}</strong></div>
                    <div class="d-flex justify-content-between gap-3 mt-2"><span>New amount</span><strong>${escape(money(submittedData.amount))}</strong></div>
                </div>`,
            saveText: 'Update Existing',
            onCancel: () => this.showAddBudgetModal(submittedData),
            onSave: async () => {
                if (this._duplicateBudgetUpdateInProgress) return;
                this._duplicateBudgetUpdateInProgress = true;
                const saveBtn = document.getElementById('modal-save-btn');
                AjaxService?.showButtonLoading(saveBtn, 'Updating...');
                try {
                    const response = await budgetsAPI.update(existingBudget.id, submittedData);
                    if (!response?.success) throw new Error(response?.message || 'Unable to update the existing budget.');
                    window.modalService.close();
                    await this.loadBudgets();
                    NotificationService.success('Budget updated successfully.');
                } catch (error) {
                    NotificationService.error(error.message || 'Unable to update the existing budget.');
                } finally {
                    this._duplicateBudgetUpdateInProgress = false;
                    AjaxService?.hideButtonLoading(saveBtn);
                }
            }
        });
    }

    formatBudgetDateRange(startDate, endDate) {
        const start = new Date(`${startDate}T00:00:00`);
        const end = new Date(`${endDate}T00:00:00`);
        if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return `${startDate} to ${endDate}`;
        const month = new Intl.DateTimeFormat('en-US', { month: 'short' });
        if (start.getFullYear() === end.getFullYear() && start.getMonth() === end.getMonth()) {
            return `${month.format(start)} ${start.getDate()}–${end.getDate()}, ${start.getFullYear()}`;
        }
        return `${month.format(start)} ${start.getDate()}, ${start.getFullYear()}–${month.format(end)} ${end.getDate()}, ${end.getFullYear()}`;
    }

    /* =============== BULK BUDGET CREATION =============== */

    showBulkBudgetModal() {
        this._bulkState = {
            period: 'monthly',
            startDate: DateUtils.getKathmanduDateString(),
            endDate: '',
            categories: [],
            suggestions: null,
            filterType: 'all',
            searchQuery: ''
        };

        const monthRange = DateUtils.getKathmanduRange('month');
        this._bulkState.startDate = monthRange.start;
        this._bulkState.endDate = monthRange.end;

        const bodyHTML = this._renderBulkModalBody();

        // Make modal extra wide for the bulk table
        const dialog = document.querySelector('#appModal .modal-dialog');
        if (dialog) {
            dialog.classList.add('modal-xl');
            dialog.style.maxWidth = '1100px';
        }

        if (window.modalService) {
            window.modalService.open({
                title: 'Bulk Add Budgets',
                subtitle: 'Create multiple budgets in one go with smart defaults.',
                icon: 'fa-layer-group',
                bodyHTML,
                showFooter: false,
                onSave: null
            });
        } else {
            NotificationService.error('Modal service not available. Try again.');
            return;
        }

        this._attachBulkModalEvents();
        this._loadBulkCategories();
    }

    _renderBulkModalBody() {
        return `
            <div class="bulk-budget-container">
                <!-- Step 1: Period & Dates -->
                <div class="bulk-config-bar d-flex flex-wrap gap-3 align-items-end mb-4 p-3 rounded-3" style="background:var(--bg-secondary, #f8f9fa);">
                    <div class="bulk-config-item">
                        <label class="form-label small fw-semibold text-muted mb-1">Period</label>
                        <div class="btn-group" role="group" id="bulk-period-group">
                            <input type="radio" class="btn-check" name="bulk-period" id="bulk-period-weekly" value="weekly">
                            <label class="btn btn-outline-secondary btn-sm" for="bulk-period-weekly">Weekly</label>
                            <input type="radio" class="btn-check" name="bulk-period" id="bulk-period-monthly" value="monthly" checked>
                            <label class="btn btn-outline-secondary btn-sm" for="bulk-period-monthly">Monthly</label>
                            <input type="radio" class="btn-check" name="bulk-period" id="bulk-period-yearly" value="yearly">
                            <label class="btn btn-outline-secondary btn-sm" for="bulk-period-yearly">Yearly</label>
                        </div>
                    </div>
                    <div class="bulk-config-item">
                        <label class="form-label small fw-semibold text-muted mb-1">Start Date</label>
                        <input type="date" class="form-control form-control-sm" id="bulk-start-date" value="${this._bulkState.startDate}">
                    </div>
                    <div class="bulk-config-item">
                        <label class="form-label small fw-semibold text-muted mb-1">End Date</label>
                        <input type="date" class="form-control form-control-sm" id="bulk-end-date" value="${this._bulkState.endDate}">
                    </div>
                    <div class="bulk-config-item d-flex gap-1">
                        <button class="btn btn-outline-primary btn-sm" id="bulk-copy-month-btn" title="Copy Previous Month">
                            <i class="fas fa-copy"></i> Copy Month
                        </button>
                        <button class="btn btn-outline-primary btn-sm" id="bulk-copy-year-btn" title="Copy Previous Year">
                            <i class="fas fa-calendar-alt"></i> Copy Year
                        </button>
                        <button class="btn btn-outline-primary btn-sm" id="bulk-auto-gen-btn" title="Generate From Spending History">
                            <i class="fas fa-magic"></i> Auto
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" id="bulk-csv-import-btn" title="Import CSV">
                            <i class="fas fa-file-csv"></i> Import
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" id="bulk-csv-template-btn" title="Download CSV Template">
                            <i class="fas fa-download"></i> Template
                        </button>
                    </div>
                </div>

                <!-- Filters Bar -->
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <div class="input-group input-group-sm" style="max-width:260px;">
                        <span class="input-group-text bg-transparent"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" class="form-control" id="bulk-search-input" placeholder="Search categories...">
                    </div>
                    <select class="form-select form-select-sm" id="bulk-type-filter" style="width:auto;">
                        <option value="all">All Types</option>
                        <option value="expense">Expense</option>
                        <option value="income">Income</option>
                    </select>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-primary" id="bulk-select-all"><i class="fas fa-check-square"></i> All</button>
                        <button class="btn btn-outline-secondary" id="bulk-clear-all"><i class="fas fa-square"></i> Clear</button>
                        <button class="btn btn-outline-info" id="bulk-active-only"><i class="fas fa-filter"></i> Active</button>
                    </div>
                    <span class="badge bg-primary ms-auto" id="bulk-selected-count">0 selected</span>
                </div>

                <!-- Table -->
                <div class="bulk-table-wrapper" style="max-height:420px;overflow-y:auto;border:1px solid var(--border-color,#dee2e6);border-radius:var(--radius-lg,8px);">
                    <table class="table table-hover mb-0 bulk-category-table" id="bulk-category-table">
                        <thead class="table-light" style="position:sticky;top:0;z-index:2;">
                            <tr>
                                <th style="width:40px;" class="ps-3">
                                    <input type="checkbox" id="bulk-header-check" checked>
                                </th>
                                <th>Category</th>
                                <th style="width:120px;">Type</th>
                                <th style="width:140px;">Suggested</th>
                                <th style="width:160px;">Budget Amount</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody id="bulk-category-body">
                            <tr><td colspan="6" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i>Loading categories...</td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- Hidden CSV file input -->
                <input type="file" id="bulk-csv-file" accept=".csv" style="display:none;">

                <!-- Summary Bar -->
                <div class="bulk-summary-bar d-flex justify-content-between align-items-center mt-3 p-3 rounded-3" style="background:var(--bg-secondary,#f8f9fa);">
                    <div>
                        <span class="fw-semibold" id="bulk-summary-count">0 budgets</span>
                        <span class="mx-2 text-muted">|</span>
                        <span>Total: <strong id="bulk-summary-total">Rs 0</strong></span>
                    </div>
                    <div>
                        <button class="btn btn-primary" id="bulk-create-btn" disabled>
                            <i class="fas fa-check-circle"></i> Create Budgets
                        </button>
                    </div>
                </div>
            </div>
        `;
    }

    _attachBulkModalEvents() {
        // Period change
        document.querySelectorAll('input[name="bulk-period"]').forEach(r => {
            r.addEventListener('change', (e) => {
                this._bulkState.period = e.target.value;
            });
        });

        // Dates
        const sd = document.getElementById('bulk-start-date');
        const ed = document.getElementById('bulk-end-date');
        if (sd) sd.addEventListener('change', (e) => this._bulkState.startDate = e.target.value);
        if (ed) ed.addEventListener('change', (e) => this._bulkState.endDate = e.target.value);

        // Header checkbox
        const headerChk = document.getElementById('bulk-header-check');
        if (headerChk) {
            headerChk.addEventListener('change', (e) => {
                document.querySelectorAll('.bulk-category-check').forEach(c => {
                    c.checked = e.target.checked;
                    this._updateRowState(c);
                });
                this._updateBulkSummary();
            });
        }

        // Filter buttons
        const selAll = document.getElementById('bulk-select-all');
        if (selAll) selAll.addEventListener('click', () => this._bulkSelectAll(true));
        const clrAll = document.getElementById('bulk-clear-all');
        if (clrAll) clrAll.addEventListener('click', () => this._bulkSelectAll(false));
        const activeBtn = document.getElementById('bulk-active-only');
        if (activeBtn) activeBtn.addEventListener('click', () => {
            this._bulkState.filterType = document.getElementById('bulk-type-filter')?.value || 'all';
            this._bulkState.searchQuery = document.getElementById('bulk-search-input')?.value || '';
            this._bulkSelectByActive();
        });

        // Type filter
        const typeFilter = document.getElementById('bulk-type-filter');
        if (typeFilter) typeFilter.addEventListener('change', (e) => {
            this._bulkState.filterType = e.target.value;
            this._filterBulkRows();
        });

        // Search
        const searchInput = document.getElementById('bulk-search-input');
        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                this._bulkState.searchQuery = e.target.value;
                this._filterBulkRows();
            });
        }

        // Create button
        const createBtn = document.getElementById('bulk-create-btn');
        if (createBtn) createBtn.addEventListener('click', () => this._bulkConfirmAndCreate());

        // Copy buttons
        const copyMonth = document.getElementById('bulk-copy-month-btn');
        if (copyMonth) copyMonth.addEventListener('click', () => this._bulkCopyPrevious('month'));
        const copyYear = document.getElementById('bulk-copy-year-btn');
        if (copyYear) copyYear.addEventListener('click', () => this._bulkCopyPrevious('year'));

        // Auto generate
        const autoBtn = document.getElementById('bulk-auto-gen-btn');
        if (autoBtn) autoBtn.addEventListener('click', () => this._bulkAutoGenerate());

        // CSV
        const csvImport = document.getElementById('bulk-csv-import-btn');
        if (csvImport) csvImport.addEventListener('click', () => document.getElementById('bulk-csv-file')?.click());
        const csvFile = document.getElementById('bulk-csv-file');
        if (csvFile) csvFile.addEventListener('change', (e) => this._bulkCSVImport(e));
        const csvTpl = document.getElementById('bulk-csv-template-btn');
        if (csvTpl) csvTpl.addEventListener('click', () => this._bulkCSVTemplate());
    }

    async _loadBulkCategories() {
        try {
            const response = await categoriesAPI.getAll();
            const tbody = document.getElementById('bulk-category-body');
            if (!response.success || !tbody) return;

            this._bulkState.categories = response.data.filter(c => c.status === 'active');
            this._renderBulkRows();
        } catch (err) {
            console.error('Failed to load categories for bulk:', err);
        }
    }

    _renderBulkRows() {
        const tbody = document.getElementById('bulk-category-body');
        if (!tbody) return;
        const cats = this._bulkState.categories;
        const suggestions = this._bulkState.suggestions;
        const sugMap = {};
        if (suggestions) {
            suggestions.forEach(s => { sugMap[s.category_id] = s; });
        }

        if (!cats || cats.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted"><i class="fas fa-inbox me-2"></i>No categories found</td></tr>';
            this._updateBulkSummary();
            return;
        }

        tbody.innerHTML = cats.map((c, i) => {
            const sug = sugMap[c.id];
            const suggested = sug ? Number(sug.avg_spent) : 0;
            const defaultAmt = suggested > 0 ? Math.round(suggested * 1.1) : 0;

            return `
                <tr class="bulk-row" data-type="${c.type}" data-name="${c.name.toLowerCase()}" data-id="${c.id}">
                    <td class="ps-3">
                        <input type="checkbox" class="bulk-category-check" data-index="${i}" checked>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge" style="background:${c.color || '#6B7280'};width:10px;height:10px;border-radius:50%;padding:0;"></span>
                            <i class="${c.icon || 'fas fa-tag'}" style="color:${c.color || '#6B7280'};font-size:0.85rem;"></i>
                            <span>${Formatters.escapeHTML(c.name)}</span>
                        </div>
                    </td>
                    <td><span class="badge bg-${c.type === 'income' ? 'success' : 'secondary'} bg-opacity-10 text-${c.type === 'income' ? 'success' : 'secondary'}">${c.type}</span></td>
                    <td class="text-muted small">${suggested > 0 ? 'Rs ' + Number(suggested).toLocaleString() : '—'}</td>
                    <td>
                        <div class="input-group input-group-sm" style="max-width:155px;">
                            <span class="input-group-text bg-transparent px-1">Rs</span>
                            <input type="number" class="form-control bulk-amount-input" value="${defaultAmt}" min="0" step="0.01" data-index="${i}">
                        </div>
                    </td>
                    <td>
                        <input type="text" class="form-control form-control-sm bulk-note-input" placeholder="Optional" data-index="${i}" style="max-width:140px;">
                    </td>
                </tr>
            `;
        }).join('');

        // Attach amount & note change handlers
        tbody.querySelectorAll('.bulk-amount-input').forEach(inp => {
            inp.addEventListener('input', () => this._updateBulkSummary());
        });
        tbody.querySelectorAll('.bulk-category-check').forEach(cb => {
            cb.addEventListener('change', (e) => {
                this._updateRowState(e.target);
                this._updateBulkSummary();
            });
        });

        this._updateBulkSummary();
    }

    _updateRowState(checkbox) {
        const tr = checkbox.closest('tr');
        if (!tr) return;
        tr.style.opacity = checkbox.checked ? '1' : '0.5';
        const amt = tr.querySelector('.bulk-amount-input');
        if (amt) amt.disabled = !checkbox.checked;
    }

    _updateBulkSummary() {
        const checks = document.querySelectorAll('.bulk-category-check:checked');
        const countEl = document.getElementById('bulk-selected-count');
        const summaryCount = document.getElementById('bulk-summary-count');
        const summaryTotal = document.getElementById('bulk-summary-total');
        const createBtn = document.getElementById('bulk-create-btn');

        let total = 0;
        checks.forEach(cb => {
            const tr = cb.closest('tr');
            if (!tr) return;
            const amt = tr.querySelector('.bulk-amount-input');
            if (amt) total += parseFloat(amt.value) || 0;
        });

        const count = checks.length;
        if (countEl) countEl.textContent = count + ' selected';
        if (summaryCount) summaryCount.textContent = count + ' budget(s)';
        if (summaryTotal) summaryTotal.textContent = 'Rs ' + total.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
        if (createBtn) createBtn.disabled = count === 0;
    }

    /* ---------- Filters ---------- */

    _bulkSelectAll(checked) {
        document.querySelectorAll('.bulk-category-check').forEach(cb => {
            const tr = cb.closest('tr');
            if (tr && tr.style.display !== 'none') {
                cb.checked = checked;
                this._updateRowState(cb);
            }
        });
        this._updateBulkSummary();
    }

    _bulkSelectByActive() {
        document.querySelectorAll('.bulk-category-check').forEach(cb => {
            const tr = cb.closest('tr');
            if (!tr) return;
            const amt = tr.querySelector('.bulk-amount-input');
            cb.checked = amt && parseFloat(amt.value) > 0;
            this._updateRowState(cb);
        });
        this._updateBulkSummary();
    }

    _filterBulkRows() {
        const filterType = this._bulkState.filterType;
        const query = this._bulkState.searchQuery.toLowerCase().trim();

        document.querySelectorAll('.bulk-row').forEach(tr => {
            let show = true;
            if (filterType !== 'all' && tr.dataset.type !== filterType) show = false;
            if (query && !tr.dataset.name.includes(query)) show = false;
            tr.style.display = show ? '' : 'none';
        });
        this._updateBulkSummary();
    }

    /* ---------- Summary & Creation ---------- */

    async _bulkConfirmAndCreate() {
        const rows = [];
        document.querySelectorAll('.bulk-category-check:checked').forEach(cb => {
            const tr = cb.closest('tr');
            if (!tr) return;
            const amt = tr.querySelector('.bulk-amount-input');
            const note = tr.querySelector('.bulk-note-input');
            const amount = parseFloat(amt?.value) || 0;
            if (amount <= 0) return;
            rows.push({
                category_id: parseInt(tr.dataset.id),
                name: tr.querySelector('span:last-child')?.textContent || 'Budget',
                amount,
                note: note?.value || ''
            });
        });

        if (rows.length === 0) {
            NotificationService.error('Select at least one category with a valid amount.');
            return;
        }

        const total = rows.reduce((s, r) => s + r.amount, 0);
        const periodLabel = this._bulkState.period.charAt(0).toUpperCase() + this._bulkState.period.slice(1);

        // Check for existing duplicates
        let existingNames = [];
        try {
            const existingRes = await budgetsAPI.getAll();
            if (existingRes.success) {
                existingNames = existingRes.data
                    .filter(b => b.period === this._bulkState.period)
                    .map(b => b.name.toLowerCase().trim());
            }
        } catch (_) {}

        const skippable = [];
        const finalRows = [];
        rows.forEach(r => {
            if (existingNames.includes(r.name.toLowerCase().trim())) {
                skippable.push(r.name);
            } else {
                finalRows.push(r);
            }
        });

        // SweetAlert2 Summary
        const result = await Swal.fire({
            title: 'Confirm Bulk Budgets',
            html: `
                <div class="text-start">
                    <p class="mb-2">You are about to create <strong>${finalRows.length}</strong> budget(s)</p>
                    <p class="mb-2">Period: <strong>${periodLabel}</strong></p>
                    <p class="mb-2">Total Planned Budget: <strong>Rs ${total.toLocaleString()}</strong></p>
                    ${skippable.length > 0 ? `<p class="text-warning mt-2 mb-0"><i class="fas fa-exclamation-triangle"></i> ${skippable.length} already exist(s) and will be skipped</p>` : ''}
                </div>
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-check"></i> Create Budgets',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#10b981'
        });

        if (!result.isConfirmed) return;
        await this._bulkSubmit(finalRows);
    }

    async _bulkSubmit(rows) {
        const createBtn = document.getElementById('bulk-create-btn');
        if (createBtn) { createBtn.disabled = true; createBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Creating...'; }

        try {
            const payload = rows.map(r => ({
                name: r.name,
                amount: r.amount,
                period: this._bulkState.period,
                category_id: r.category_id,
                start_date: this._bulkState.startDate,
                end_date: this._bulkState.endDate
            }));

            const response = await budgetsAPI.bulkCreate(payload);

            if (response.success) {
                await Swal.fire({
                    title: response.data.created + ' Budgets Created',
                    text: 'Your budgets have been created successfully.',
                    icon: 'success',
                    timer: 2500,
                    showConfirmButton: true,
                    confirmButtonColor: '#10b981'
                });
                if (window.modalService) modalService.close();
            }
        } catch (err) {
            NotificationService.error(err.message || 'Failed to create budgets');
        } finally {
            if (createBtn) { createBtn.disabled = false; createBtn.innerHTML = '<i class="fas fa-check-circle"></i> Create Budgets'; }
        }
    }

    /* ---------- Copy Previous ---------- */

    async _bulkCopyPrevious(source) {
        try {
            const response = await budgetsAPI.copyPrevious(this._bulkState.period, source);
            if (!response.success) return;

            const budgets = response.data;
            if (!budgets || budgets.length === 0) {
                NotificationService.info('No budgets found from the previous period.');
                return;
            }

            // Populate table with copied values
            const sugMap = {};
            (this._bulkState.suggestions || []).forEach(s => { sugMap[s.category_id] = s; });

            budgets.forEach(copied => {
                const row = document.querySelector(`.bulk-row[data-id="${copied.category_id}"]`);
                if (!row) return;
                const chk = row.querySelector('.bulk-category-check');
                const amt = row.querySelector('.bulk-amount-input');
                if (chk) chk.checked = true;
                if (amt) {
                    amt.value = copied.amount;
                    this._updateRowState(chk);
                }
            });

            this._updateBulkSummary();
            NotificationService.success(`Copied ${budgets.length} budget(s) from previous ${source}.`);
        } catch (err) {
            NotificationService.error('Failed to copy previous budgets.');
        }
    }

    /* ---------- Auto Generate From History ---------- */

    async _bulkAutoGenerate() {
        try {
            const response = await budgetsAPI.getSuggestions(this._bulkState.period, 3);
            if (!response.success) return;

            const suggestions = response.data;
            this._bulkState.suggestions = suggestions;

            // Re-render rows with suggestions
            this._renderBulkRows();
            NotificationService.success('Suggestions loaded from spending history.');
        } catch (err) {
            NotificationService.error('Failed to generate suggestions.');
        }
    }

    /* ---------- CSV ---------- */

    _bulkCSVImport(event) {
        const file = event.target?.files?.[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = (e) => {
            try {
                const text = e.target.result;
                const lines = text.split('\n').filter(l => l.trim());
                if (lines.length < 2) {
                    NotificationService.error('CSV must have a header row and at least one data row.');
                    return;
                }

                const headers = lines[0].split(',').map(h => h.trim().toLowerCase());
                const nameIdx = headers.indexOf('category');
                const amtIdx = headers.indexOf('amount');
                const periodIdx = headers.indexOf('period');
                const noteIdx = headers.indexOf('notes');

                if (nameIdx === -1 || amtIdx === -1) {
                    NotificationService.error('CSV must have "category" and "amount" columns.');
                    return;
                }

                const csvData = [];
                for (let i = 1; i < lines.length; i++) {
                    const cols = lines[i].split(',').map(c => c.trim());
                    const name = cols[nameIdx];
                    const amount = parseFloat(cols[amtIdx]);
                    if (!name || isNaN(amount) || amount <= 0) continue;
                    csvData.push({
                        name,
                        amount,
                        period: periodIdx !== -1 ? cols[periodIdx] || this._bulkState.period : this._bulkState.period,
                        note: noteIdx !== -1 ? cols[noteIdx] || '' : ''
                    });
                }

                if (csvData.length === 0) {
                    NotificationService.error('No valid rows found in CSV.');
                    return;
                }

                // Map to categories
                const cats = this._bulkState.categories;
                csvData.forEach(item => {
                    const cat = cats.find(c => c.name.toLowerCase() === item.name.toLowerCase());
                    if (!cat) return;
                    const row = document.querySelector(`.bulk-row[data-id="${cat.id}"]`);
                    if (!row) return;
                    const chk = row.querySelector('.bulk-category-check');
                    const amt = row.querySelector('.bulk-amount-input');
                    if (chk) chk.checked = true;
                    if (amt) amt.value = item.amount;
                    this._updateRowState(chk);
                });

                this._updateBulkSummary();
                NotificationService.success(`Imported ${csvData.length} budget(s) from CSV.`);
            } catch (err) {
                NotificationService.error('Failed to parse CSV file.');
            }
        };
        reader.readAsText(file);
        event.target.value = '';
    }

    _bulkCSVTemplate() {
        const csv = 'Category,Amount,Period,Notes\nFood,5000,monthly,Groceries\nTransport,3000,monthly,Fuel\nRent,25000,monthly';
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'budget_template.csv';
        a.click();
        URL.revokeObjectURL(url);
    }

    async deleteBudget(id) {
        const confirmed = await NotificationService.confirm({
            title: 'Delete Budget',
            text: 'Are you sure you want to delete this budget? This action cannot be undone.',
            confirmButtonText: 'Yes, Delete'
        });

        if (!confirmed) return;

        AjaxService?.showButtonLoading(event?.target);
        try {
            const response = await budgetsAPI.delete(id);

            if (response.success) {
                NotificationService.success('Budget deleted successfully');
            }
        } catch (error) {
            console.error('Failed to delete budget:', error);
            NotificationService.error(error.message || 'Failed to delete budget');
        } finally {
            AjaxService?.hideButtonLoading(event?.target);
        }
    }
}

window.BudgetsManager = BudgetsManager;
