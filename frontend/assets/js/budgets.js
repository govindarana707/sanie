// Budgets Module - Refactored with ModalService (double-submit fix) and lifecycle hooks
class BudgetsManager {
    constructor() {
        this.budgets = [];
        this._mounted = false;
        this._listeners = {};
    }

    onMount() {
        if (this._mounted) return;
        this._mounted = true;
        this.setupEventListeners();
        window.addEventListener('app:data-changed', this._onDataChanged = () => this.loadBudgets());
        if (window.authManager?.isAuthenticated()) {
            this.loadBudgets();
        }
    }

    onUnmount() {
        this._mounted = false;
        if (this._onDataChanged) {
            window.removeEventListener('app:data-changed', this._onDataChanged);
        }
        const addBtn = document.getElementById('add-budget-btn');
        if (addBtn && this._listeners.addClick) {
            addBtn.removeEventListener('click', this._listeners.addClick);
        }
        const bulkBtn = document.getElementById('bulk-budget-btn');
        if (bulkBtn && this._listeners.bulkClick) {
            bulkBtn.removeEventListener('click', this._listeners.bulkClick);
        }
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
    }

    async loadBudgets() {
        if (!window.authManager?.isAuthenticated()) return;

        AjaxService?.showSkeleton('budgets-grid');
        try {
            const response = await budgetsAPI.getAll();
            if (response.success) {
                this.budgets = response.data;
                await this.renderBudgets();
            }
        } catch (error) {
            console.error('Failed to load budgets:', error);
            NotificationService.error('Failed to load budgets');
        } finally {
            AjaxService?.hideSkeleton('budgets-grid');
        }
    }

    async renderBudgets() {
        const container = document.getElementById('budgets-grid');
        container.innerHTML = '';
        this._setOverview(0, 0);

        if (this.budgets.length === 0) {
            container.innerHTML = `
                <div class="budget-empty-state">
                    <span><i class="bi bi-pie-chart"></i></span>
                    <h3>Plan your spending</h3>
                    <p>Create your first budget to track expenses and avoid overspending.</p>
                    <button class="btn btn-primary" onclick="budgetsManager.showAddBudgetModal()"><i class="fas fa-plus"></i> Create Budget</button>
                </div>`;
            return;
        }

        this._setOverview(this.budgets.reduce((sum, b) => sum + Number(b.amount || 0), 0), 0);

        this.budgets.forEach(budget => {
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
        const ids = this.budgets.map(b => b.id);
        try {
            const response = await budgetsAPI.getBatchProgress(ids);
            if (response.success) {
                const totalSpent = response.data.reduce((sum, p) => sum + Number(p.spent || 0), 0);
                const totalLimit = this.budgets.reduce((sum, b) => sum + Number(b.amount || 0), 0);
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
        const fallbackLimit = Number(this.budgets.find(b => String(b.id) === String(progress.budget_id))?.amount || 0);
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

    showAddBudgetModal() {
        const formHTML = `
            <form id="budget-form">
                <div class="row g-4">
                    <div class="col-lg-6">
                        <div class="form-group">
                            <label>Budget Name</label>
                            <div class="form-control-icon">
                                <i class="fas fa-tag"></i>
                                <input type="text" id="budget-name" placeholder="e.g. Monthly Groceries" required>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="form-group">
                            <label>Amount</label>
                            <div class="form-control-icon">
                                <i class="fas fa-rupee-sign"></i>
                                <input type="number" id="budget-amount" step="0.01" placeholder="Rs 25,000" required>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="form-group">
                            <label>Period</label>
                            <div class="form-control-icon">
                                <i class="fas fa-calendar"></i>
                                <select id="budget-period" required>
                                    <option value="daily">Daily</option>
                                    <option value="weekly">Weekly</option>
                                    <option value="monthly" selected>Monthly</option>
                                    <option value="yearly">Yearly</option>
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
                                <input type="date" id="budget-start-date" value="${new Date().toISOString().split('T')[0]}" required>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="form-group">
                            <label>End Date</label>
                            <div class="form-control-icon">
                                <i class="fas fa-calendar-times"></i>
                                <input type="date" id="budget-end-date" required>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="form-group">
                            <label>Alert Threshold (%)</label>
                            <div class="form-control-icon">
                                <i class="fas fa-bell"></i>
                                <input type="number" id="budget-alert-threshold" value="80" min="0" max="100" required>
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

        this.loadCategoriesForForm();
    }

    async loadCategoriesForForm() {
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
                }
            }
        } catch (error) {
            console.error('Failed to load categories:', error);
        }
    }

    async loadSubcategoriesForForm(categoryId){
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
        }catch(error){console.error('Failed to load budget subcategories:',error);}
    }

    async handleBudgetSubmit() {
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

        const saveBtn = document.querySelector('#modal-footer .btn-primary');
        AjaxService?.showButtonLoading(saveBtn);
        try {
            const response = await budgetsAPI.create(data);

            if (response.success) {
                if (window.modalService) modalService.close();
                else if (window.premiumModal) premiumModal.close();
                NotificationService.success('Budget created successfully');
                window.dispatchEvent(new CustomEvent('app:data-changed'));
            }
        } catch (error) {
            console.error('Failed to create budget:', error);
            NotificationService.error('Failed to create budget');
        } finally {
            AjaxService?.hideButtonLoading(saveBtn);
        }
    }

    /* =============== BULK BUDGET CREATION =============== */

    showBulkBudgetModal() {
        this._bulkState = {
            period: 'monthly',
            startDate: new Date().toISOString().split('T')[0],
            endDate: '',
            categories: [],
            suggestions: null,
            filterType: 'all',
            searchQuery: ''
        };

        const now = new Date();
        const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
        const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0);
        this._bulkState.startDate = firstDay.toISOString().split('T')[0];
        this._bulkState.endDate = lastDay.toISOString().split('T')[0];

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
                window.dispatchEvent(new CustomEvent('app:data-changed'));
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
                window.dispatchEvent(new CustomEvent('app:data-changed'));
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
