// Budgets Module - Refactored with ModalService (double-submit fix) and lifecycle hooks
class BudgetsManager {
    constructor() {
        this.budgets = [];
        this._mounted = false;
    }

    onMount() {
        if (this._mounted) return;
        this._mounted = true;
        this.setupEventListeners();
        if (window.authManager?.isAuthenticated()) {
            this.loadBudgets();
        }
    }

    onUnmount() {
        this._mounted = false;
    }

    setupEventListeners() {
        const addBtn = document.getElementById('add-budget-btn');
        if (addBtn) {
            addBtn.addEventListener('click', () => this.showAddBudgetModal());
        }
    }

    async loadBudgets() {
        if (!window.authManager?.isAuthenticated()) return;

        try {
            const response = await budgetsAPI.getAll();
            if (response.success) {
                this.budgets = response.data;
                this.renderBudgets();
            }
        } catch (error) {
            console.error('Failed to load budgets:', error);
            NotificationService.error('Failed to load budgets');
        }
    }

    renderBudgets() {
        const container = document.getElementById('budgets-grid');
        container.innerHTML = '';

        if (this.budgets.length === 0) {
            container.innerHTML = '<p class="no-data">No budgets set. Click "Add Budget" to create one.</p>';
            return;
        }

        this.budgets.forEach(budget => {
            const card = document.createElement('div');
            card.className = 'budget-card';

            card.innerHTML = `
                <div class="budget-header">
                    <p class="budget-name">${Formatters.escapeHTML(budget.name)}</p>
                    <button class="btn btn-icon" onclick="budgetsManager.deleteBudget(${budget.id})">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
                <p class="budget-amount">${Formatters.currency(budget.amount)} / ${budget.period}</p>
                <div class="budget-progress-bar">
                    <div class="budget-progress-fill" style="width: 0%"></div>
                </div>
                <p class="budget-percentage">Loading progress...</p>
            `;

            container.appendChild(card);
            this.loadBudgetProgress(budget.id, card);
        });
    }

    async loadBudgetProgress(budgetId, cardElement) {
        try {
            const response = await budgetsAPI.getProgress(budgetId);

            if (response.success) {
                const progress = response.data;
                const percentage = Math.min(100, progress.percentage);
                let progressClass = '';
                if (percentage >= 90) progressClass = 'danger';
                else if (percentage >= 70) progressClass = 'warning';

                const progressBar = cardElement.querySelector('.budget-progress-fill');
                progressBar.style.width = `${percentage}%`;
                progressBar.className = `budget-progress-fill ${progressClass}`;

                const percentageText = cardElement.querySelector('.budget-percentage');
                percentageText.textContent = `${Formatters.currency(progress.spent)} spent (${percentage.toFixed(1)}%)`;
            }
        } catch (error) {
            console.error('Failed to load budget progress:', error);
        }
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
                showFooter: false,
                onSave: null
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

        setTimeout(() => {
            const form = document.getElementById('budget-form');
            if (form) {
                form.addEventListener('submit', (e) => {
                    e.preventDefault();
                    this.handleBudgetSubmit();
                });
            }
        }, 50);
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
                }
            }
        } catch (error) {
            console.error('Failed to load categories:', error);
        }
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
            start_date: document.getElementById('budget-start-date').value,
            end_date: document.getElementById('budget-end-date').value,
            alert_threshold: parseFloat(document.getElementById('budget-alert-threshold').value)
        };

        try {
            const response = await budgetsAPI.create(data);

            if (response.success) {
                if (window.modalService) modalService.close();
                else if (window.premiumModal) premiumModal.close();
                NotificationService.success('Budget created successfully');
                this.loadBudgets();
            }
        } catch (error) {
            console.error('Failed to create budget:', error);
            NotificationService.error('Failed to create budget');
        }
    }

    async deleteBudget(id) {
        const confirmed = await NotificationService.confirm({
            title: 'Delete Budget',
            text: 'Are you sure you want to delete this budget? This action cannot be undone.',
            confirmButtonText: 'Yes, Delete'
        });

        if (!confirmed) return;

        try {
            const response = await budgetsAPI.delete(id);

            if (response.success) {
                NotificationService.success('Budget deleted successfully');
                this.loadBudgets();
            }
        } catch (error) {
            console.error('Failed to delete budget:', error);
            NotificationService.error(error.message || 'Failed to delete budget');
        }
    }
}

window.BudgetsManager = BudgetsManager;
