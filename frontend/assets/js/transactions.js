// Transactions Module - Refactored with DataTableService, ModalService, and lifecycle hooks
class TransactionsManager {
    constructor() {
        if (!window.APP_CONFIG?.API_BASE || !window.Api) {
            console.error('API configuration missing.');
            return;
        }

        this.transactions = [];
        this.filters = {};
        this._listeners = {};
        this._mounted = false;
    }

    onMount() {
        if (this._mounted) return;
        this._mounted = true;
        this.setupEventListeners();
        this.initDataTable();
        if (window.DatePickerManager) {
            window.DatePickerManager.bind('#filter-start-date');
            window.DatePickerManager.bind('#filter-end-date');
        }
        if (window.authManager?.isAuthenticated()) {
            this.loadTransactions();
        }
    }

    onUnmount() {
        this._mounted = false;

        if (this._listeners.typeChange) {
            document.removeEventListener('change', this._listeners.typeChange);
        }
        if (this._listeners.categoryChange) {
            document.removeEventListener('change', this._listeners.categoryChange);
        }

        if (window.DataTableService) {
            DataTableService.destroy('.transactions-table table');
        }

        if (window.DatePickerManager) {
            DatePickerManager.destroy('#filter-start-date');
            DatePickerManager.destroy('#filter-end-date');
        }
    }

    initDataTable() {
        if (window.DataTableService) {
            this.dataTable = DataTableService.init('.transactions-table table', {
                columnDefs: [
                    { orderable: true, targets: [0, 1, 2, 3, 4] },
                    { orderable: false, targets: [5] }
                ],
                order: [[0, 'desc']],
                language: {
                    search: "_INPUT_",
                    searchPlaceholder: "Search transactions..."
                }
            });
        }
    }

    setupEventListeners() {
        const addBtn = document.getElementById('add-transaction-btn');
        if (addBtn) {
            addBtn.addEventListener('click', () => this.showAddTransactionModal());
        }

        const applyBtn = document.getElementById('apply-filters');
        if (applyBtn) {
            applyBtn.addEventListener('click', () => this.applyFilters());
        }

        const clearBtn = document.getElementById('clear-filters');
        if (clearBtn) {
            clearBtn.addEventListener('click', () => this.clearFilters());
        }

        this._listeners.typeChange = (e) => {
            if (e.target.id === 'transaction-type') {
                this.loadCategoriesByType(e.target.value);
            }
        };
        this._listeners.categoryChange = (e) => {
            if (e.target.id === 'transaction-category') {
                this.loadSubcategoriesForCategory(e.target.value);
            }
        };
        document.addEventListener('change', this._listeners.typeChange);
        document.addEventListener('change', this._listeners.categoryChange);
    }

    async loadTransactions() {
        if (!window.authManager?.isAuthenticated()) return;

        try {
            const response = await transactionsAPI.getAll(this.filters);
            if (response.success) {
                this.transactions = response.data;
                this.renderTransactions();
            }
        } catch (error) {
            console.error('Failed to load transactions:', error);
            NotificationService.error('Failed to load transactions');
        }
    }

    applyFilters() {
        this.filters = {
            type: document.getElementById('filter-type').value || undefined,
            category_id: document.getElementById('filter-category').value || undefined,
            account_id: document.getElementById('filter-account').value || undefined,
            start_date: document.getElementById('filter-start-date').value || undefined,
            end_date: document.getElementById('filter-end-date').value || undefined
        };

        Object.keys(this.filters).forEach(key => {
            if (this.filters[key] === undefined) {
                delete this.filters[key];
            }
        });

        this.loadTransactions();
    }

    clearFilters() {
        this.filters = {};
        document.getElementById('filter-type').value = '';
        document.getElementById('filter-category').value = '';
        document.getElementById('filter-account').value = '';
        document.getElementById('filter-start-date').value = '';
        document.getElementById('filter-end-date').value = '';
        this.loadTransactions();
    }

    renderTransactions() {
        const tbody = document.getElementById('transactions-table-body');
        const tableWrapper = document.querySelector('.transactions-table');

        if (this.dataTable) {
            try {
                this.dataTable.clear();
                this.dataTable.destroy();
            } catch (e) {}
            this.dataTable = null;
        }

        tbody.innerHTML = '';
        const existingEmptyState = tableWrapper?.querySelector('.transactions-empty-state');
        if (existingEmptyState) {
            existingEmptyState.remove();
        }

        if (this.transactions.length === 0) {
            if (tableWrapper) {
                tableWrapper.insertAdjacentHTML('beforeend', `
                    <div class="empty-state transactions-empty-state">
                        <i class="fas fa-receipt"></i>
                        <h4>No Transactions Found</h4>
                        <p>Start by adding your first transaction to track your finances.</p>
                    </div>
                `);
            }
            this.initDataTable();
            return;
        }

        this.transactions.forEach(transaction => {
            const row = document.createElement('tr');

            const iconClass = transaction.type === 'income' ? 'income' : 'expense';
            const amountClass = transaction.type === 'income' ? 'income' : 'expense';
            const sign = transaction.type === 'income' ? '+' : '-';

            row.innerHTML = `
                <td>${Formatters.date(transaction.date)}</td>
                <td>${Formatters.escapeHTML(transaction.description || transaction.category_name)}</td>
                <td>${Formatters.escapeHTML(transaction.category_name)}</td>
                <td>${Formatters.escapeHTML(transaction.account_name)}</td>
                <td class="${amountClass}">${sign} ${Formatters.currency(transaction.amount)}</td>
                <td>
                    <button class="btn btn-icon" onclick="transactionsManager.editTransaction(${transaction.id})">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-icon" onclick="transactionsManager.deleteTransaction(${transaction.id})">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            `;

            tbody.appendChild(row);
        });

        this.initDataTable();
    }

    showAddTransactionModal() {
        if (window.ModalService) {
            window.modalService.open({
                title: 'Add Transaction',
                subtitle: 'Track your income and expenses.',
                icon: 'fa-exchange-alt',
                bodyHTML: this._getTransactionFormHTML(),
                showFooter: true,
                onSave: () => this.saveTransaction()
            });
        } else if (window.premiumModal) {
            const modalBody = document.getElementById('modal-body');
            premiumModal.setTitle('Add Transaction');
            premiumModal.setSubtitle('Track your income and expenses.');
            premiumModal.setIcon('fa-exchange-alt');
            modalBody.innerHTML = this._getTransactionFormHTML();
            document.getElementById('modal-footer').classList.remove('hidden');
            premiumModal.open();
        }

        if (window.DatePickerManager) {
            DatePickerManager.bind('#transaction-date');
        }

        this.loadCategoriesForForm();
        this.loadAccountsForForm();
    }

    _getTransactionFormHTML() {
        return `
            <form id="transaction-form">
                <div class="row g-4">
                    <div class="col-lg-6">
                        <div class="form-group">
                            <label for="transaction-type">Transaction Type</label>
                            <div class="form-control-icon">
                                <i class="fas fa-exchange-alt"></i>
                                <select id="transaction-type" required>
                                    <option value="expense">Expense</option>
                                    <option value="income">Income</option>
                                    <option value="transfer">Transfer</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="form-group">
                            <label for="transaction-amount">Amount</label>
                            <div class="form-control-icon">
                                <i class="fas fa-rupee-sign"></i>
                                <input type="number" id="transaction-amount" step="0.01" placeholder="Rs 10,000" required>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="form-group">
                            <label for="transaction-category">Category</label>
                            <div class="form-control-icon">
                                <i class="fas fa-tags"></i>
                                <select id="transaction-category" required>
                                    <option value="">Select category</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="form-group">
                            <label for="transaction-subcategory">Subcategory</label>
                            <div class="form-control-icon">
                                <i class="fas fa-layer-group"></i>
                                <select id="transaction-subcategory">
                                    <option value="">Select subcategory</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="form-group">
                            <label for="transaction-account">Account</label>
                            <div class="form-control-icon">
                                <i class="fas fa-university"></i>
                                <select id="transaction-account" required>
                                    <option value="">Select account</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="form-group">
                            <label for="transaction-date">Date</label>
                            <div class="form-control-icon">
                                <i class="fas fa-calendar-alt"></i>
                                <input type="date" id="transaction-date" value="${new Date().toISOString().split('T')[0]}" required>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="form-group">
                            <label for="transaction-description">Description</label>
                            <div class="form-control-icon">
                                <i class="fas fa-comment-dots"></i>
                                <input type="text" id="transaction-description" placeholder="Add a short note for this entry">
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="form-group">
                            <label for="transaction-notes">Notes</label>
                            <textarea id="transaction-notes" rows="3" placeholder="Capture important details about this transaction..."></textarea>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="transaction-form-card">
                            <label class="d-block mb-3 fw-semibold">Receipt Upload</label>
                            <div class="border border-dashed rounded-4 p-4 text-center">
                                <i class="fas fa-cloud-upload-alt fs-2 mb-2 text-muted"></i>
                                <p class="mb-2 text-muted">Drag & drop files here</p>
                                <small class="text-muted">jpg, png, pdf up to 5MB</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="transaction-form-card">
                            <label class="d-block mb-3 fw-semibold">Tags</label>
                            <div class="form-control-icon">
                                <i class="fas fa-hashtag"></i>
                                <input type="text" id="transaction-tags" placeholder="travel, groceries, urgent">
                            </div>
                            <div class="d-flex gap-2 mt-3 flex-wrap">
                                <span class="badge rounded-pill bg-light text-dark">Travel</span>
                                <span class="badge rounded-pill bg-light text-dark">Groceries</span>
                                <span class="badge rounded-pill bg-light text-dark">Urgent</span>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        `;
    }

    async loadCategoriesForForm() {
        try {
            const type = document.getElementById('transaction-type')?.value || 'expense';
            await this.loadCategoriesByType(type);
        } catch (error) {
            console.error('Failed to load categories:', error);
        }
    }

    async loadCategoriesByType(type) {
        try {
            const result = await window.Api.get(`/categories?${new URLSearchParams({ type, status: 'active' })}`);

            if (result.success) {
                const categorySelect = document.getElementById('transaction-category');
                if (categorySelect) {
                    categorySelect.innerHTML = '<option value="">Select category</option>';
                    result.data.forEach(category => {
                        const option = document.createElement('option');
                        option.value = category.id;
                        option.textContent = category.name;
                        option.dataset.icon = category.icon;
                        option.dataset.color = category.color;
                        categorySelect.appendChild(option);
                    });
                }
            }
        } catch (error) {
            console.error('Failed to load categories:', error);
        }
    }

    async loadSubcategoriesForCategory(categoryId) {
        const subcategorySelect = document.getElementById('transaction-subcategory');
        if (!subcategorySelect) return;

        if (!categoryId) {
            subcategorySelect.innerHTML = '<option value="">Select subcategory</option>';
            subcategorySelect.disabled = true;
            return;
        }

        try {
            const result = await window.Api.get(`/subcategories?${new URLSearchParams({ category_id: categoryId, status: 'active' })}`);

            if (result.success) {
                subcategorySelect.innerHTML = '<option value="">Select subcategory</option>';
                if (result.data.length > 0) {
                    subcategorySelect.disabled = false;
                    result.data.forEach(subcategory => {
                        const option = document.createElement('option');
                        option.value = subcategory.id;
                        option.textContent = subcategory.name;
                        option.dataset.icon = subcategory.icon;
                        subcategorySelect.appendChild(option);
                    });
                } else {
                    subcategorySelect.disabled = true;
                }
            }
        } catch (error) {
            console.error('Failed to load subcategories:', error);
        }
    }

    async loadAccountsForForm() {
        try {
            const result = await window.Api.get('/accounts');

            if (result.success) {
                const accountSelect = document.getElementById('transaction-account');
                if (accountSelect) {
                    accountSelect.innerHTML = '<option value="">Select account</option>';
                    result.data.forEach(account => {
                        const option = document.createElement('option');
                        option.value = account.id;
                        option.textContent = account.name;
                        accountSelect.appendChild(option);
                    });
                }
            }
        } catch (error) {
            console.error('Failed to load accounts:', error);
        }
    }

    async saveTransaction() {
        const form = document.getElementById('transaction-form');
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const transactionData = {
            type: document.getElementById('transaction-type').value,
            amount: parseFloat(document.getElementById('transaction-amount').value),
            category_id: parseInt(document.getElementById('transaction-category').value),
            subcategory_id: document.getElementById('transaction-subcategory')?.value ? parseInt(document.getElementById('transaction-subcategory').value) : null,
            account_id: parseInt(document.getElementById('transaction-account').value),
            date: document.getElementById('transaction-date').value,
            description: document.getElementById('transaction-description').value,
            notes: document.getElementById('transaction-notes').value
        };

        try {
            const result = await window.Api.post('/transactions', transactionData);

            if (result.success) {
                NotificationService.success('Transaction saved successfully');
                if (window.modalService) modalService.close();
                else if (window.premiumModal) premiumModal.close();
                this.loadTransactions();
            } else {
                NotificationService.error(result.message || 'Failed to save transaction');
            }
        } catch (error) {
            console.error('Failed to save transaction:', error);
            NotificationService.error('Failed to save transaction');
        }
    }

    editTransaction(id) {
        NotificationService.warning('Edit functionality coming soon');
    }

    async deleteTransaction(id) {
        const confirmed = await NotificationService.confirm({
            title: 'Delete Transaction',
            text: 'Are you sure you want to delete this transaction? This action cannot be undone.',
            confirmButtonText: 'Yes, Delete'
        });

        if (!confirmed) return;

        try {
            const response = await transactionsAPI.delete(id);

            if (response.success) {
                NotificationService.success('Transaction deleted successfully');
                this.loadTransactions();
            }
        } catch (error) {
            console.error('Failed to delete transaction:', error);
            NotificationService.error(error.message || 'Failed to delete transaction');
        }
    }
}

window.TransactionsManager = TransactionsManager;
