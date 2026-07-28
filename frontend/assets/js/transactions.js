class TransactionsManager {
    constructor() {
        this.transactions = [];
        this.filters = {};
        this._listeners = {};
        this._mounted = false;
        this._editingTransactionId = null;
        this.dataTable = null;
    }

    onMount() {
        if (this._mounted) return;
        this._mounted = true;
        this._bindAddButton();
        this._bindFilterButtons();
        this._bindSearch();
        this._bindDocumentEvents();
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
        this._destroyDataTable();
        this._unbindAddButton();
        this._unbindFilterButtons();
        this._unbindSearch();
        this._unbindDocumentEvents();
        if (window.DatePickerManager) {
            DatePickerManager.destroy('#filter-start-date');
            DatePickerManager.destroy('#filter-end-date');
        }
    }

    /* ==================== BINDING HELPERS ==================== */

    _bindAddButton() {
        const btn = document.getElementById('add-transaction-btn');
        if (!btn) return;
        this._listeners._addClick = () => this._openForm();
        btn.removeEventListener('click', this._listeners._addClick);
        btn.addEventListener('click', this._listeners._addClick);
    }

    _unbindAddButton() {
        const btn = document.getElementById('add-transaction-btn');
        if (btn && this._listeners._addClick) {
            btn.removeEventListener('click', this._listeners._addClick);
        }
    }

    _bindFilterButtons() {
        const apply = document.getElementById('apply-filters');
        if (apply) {
            this._listeners._applyClick = () => this.applyFilters();
            apply.addEventListener('click', this._listeners._applyClick);
        }
        const clear = document.getElementById('clear-filters');
        if (clear) {
            this._listeners._clearClick = () => this.clearFilters();
            clear.addEventListener('click', this._listeners._clearClick);
        }
    }

    _unbindFilterButtons() {
        const apply = document.getElementById('apply-filters');
        if (apply && this._listeners._applyClick) {
            apply.removeEventListener('click', this._listeners._applyClick);
        }
        const clear = document.getElementById('clear-filters');
        if (clear && this._listeners._clearClick) {
            clear.removeEventListener('click', this._listeners._clearClick);
        }
    }

    _bindSearch() {
        const el = document.getElementById('tx-search-input');
        if (!el) return;
        this._listeners._searchInput = () => {
            if (this.dataTable) {
                this.dataTable.search(el.value).draw();
            }
        };
        el.addEventListener('input', this._listeners._searchInput);
    }

    _unbindSearch() {
        const el = document.getElementById('tx-search-input');
        if (el && this._listeners._searchInput) {
            el.removeEventListener('input', this._listeners._searchInput);
        }
    }

    _bindDocumentEvents() {
        this._listeners._typeChange = (e) => {
            if (e.target.id === 'transaction-type') {
                this._loadCategoriesByType(e.target.value);
                this._togglePaymentSection(e.target.value);
            }
        };
        this._listeners._catChange = (e) => {
            if (e.target.id === 'transaction-category') {
                this._loadSubcategories(e.target.value);
            }
        };
        this._listeners._pmChange = (e) => {
            if (e.target.id === 'transaction-payment-method') {
                this._toggleCreditorSection(e.target.value);
            }
        };
        document.addEventListener('change', this._listeners._typeChange);
        document.addEventListener('change', this._listeners._catChange);
        document.addEventListener('change', this._listeners._pmChange);
    }

    _unbindDocumentEvents() {
        if (this._listeners._typeChange) {
            document.removeEventListener('change', this._listeners._typeChange);
        }
        if (this._listeners._catChange) {
            document.removeEventListener('change', this._listeners._catChange);
        }
        if (this._listeners._pmChange) {
            document.removeEventListener('change', this._listeners._pmChange);
        }
    }

    /* ==================== DATA LOADING ==================== */

    async loadTransactions() {
        const skeleton = document.getElementById('tx-loading-skeleton');
        const tableWrapper = document.getElementById('tx-table-wrapper');
        if (skeleton) skeleton.style.display = '';
        if (tableWrapper) tableWrapper.style.display = 'none';

        try {
            const res = await window.Api.get('/transactions?' + new URLSearchParams(this.filters));
            if (res.success) {
                this.transactions = res.data;
                this._renderSummary();
                this._renderTable();
            }
        } catch (err) {
            console.error('loadTransactions error:', err);
        } finally {
            if (skeleton) skeleton.style.display = 'none';
            if (tableWrapper) tableWrapper.style.display = '';
        }
    }

    applyFilters() {
        this.filters = {};
        const type = document.getElementById('filter-type');
        const cat = document.getElementById('filter-category');
        const sd = document.getElementById('filter-start-date');
        const ed = document.getElementById('filter-end-date');
        if (type && type.value) this.filters.type = type.value;
        if (cat && cat.value) this.filters.category_id = cat.value;
        if (sd && sd.value) this.filters.start_date = sd.value;
        if (ed && ed.value) this.filters.end_date = ed.value;
        this.loadTransactions();
    }

    clearFilters() {
        this.filters = {};
        ['filter-type', 'filter-category', 'filter-start-date', 'filter-end-date', 'tx-search-input'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
        const dt = document.getElementById('tx-search-input');
        if (this.dataTable && dt) {
            this.dataTable.search('').draw();
        }
        this.loadTransactions();
    }

    /* ==================== SUMMARY ==================== */

    _renderSummary() {
        let income = 0, expense = 0;
        this.transactions.forEach(t => {
            const amt = parseFloat(t.amount) || 0;
            if (t.type === 'income') income += amt;
            else if (t.type === 'expense') expense += amt;
        });
        const net = income - expense;
        const set = (id, val, color) => {
            const el = document.getElementById(id);
            if (el) {
                el.textContent = val;
                if (color) el.style.color = color;
            }
        };
        set('tx-total-income', 'Rs ' + income.toLocaleString('en-IN'));
        set('tx-total-expense', 'Rs ' + expense.toLocaleString('en-IN'));
        set('tx-net-flow', 'Rs ' + Math.abs(net).toLocaleString('en-IN'));
        const flowEl = document.getElementById('tx-net-flow');
        if (flowEl) flowEl.style.color = net >= 0 ? '#10B981' : '#EF4444';
        set('tx-total-count', this.transactions.length.toLocaleString());
    }

    /* ==================== TABLE RENDERING ==================== */

    _renderTable() {
        this._destroyDataTable();
        const tbody = document.getElementById('transactions-table-body');
        const empty = document.getElementById('tx-empty-state');
        const table = document.getElementById('transactions-datatable');
        if (!tbody) return;
        tbody.innerHTML = '';

        if (this.transactions.length === 0) {
            if (empty) empty.style.display = 'block';
            if (table) table.style.display = 'none';
            return;
        }
        if (empty) empty.style.display = 'none';
        if (table) table.style.display = '';

        this.transactions.forEach(t => {
            const tr = document.createElement('tr');
            tr.innerHTML = this._buildRow(t);
            tbody.appendChild(tr);
        });

        this._initDataTable();
        this._initTooltips();
        this._setupDelegatedActions();
    }

    _buildRow(t) {
        const date = t.date ? new Date(t.date + 'T00:00:00') : null;
        const day = date ? date.getDate() : '--';
        const month = date ? date.toLocaleString('en', { month: 'short' }).toUpperCase() : '';
        const year = date ? date.getFullYear() : '';
        const isTransfer = t.type === 'transfer';
        const amt = parseFloat(t.amount) || 0;
        const amtFmt = amt.toLocaleString('en-IN');
        const sign = t.type === 'income' ? '+' : (t.type === 'expense' ? '-' : '');
        const cls = t.type === 'income' ? 'amount-income' : (t.type === 'expense' ? 'amount-expense' : 'amount-transfer');
        const catIcon = t.category_icon ? this._faToBi(t.category_icon) : 'bi-tag-fill';
        const catColor = t.category_color || '#6366f1';

        let catHtml;
        if (isTransfer) {
            catHtml = `<div class="tx-cat-icon" style="background:#3B82F6"><i class="bi bi-arrow-left-right"></i></div>
                       <span class="tx-cat-name">${this._h(t.from_account_name || '?')} &rarr; ${this._h(t.to_account_name || '?')}</span>`;
        } else {
            catHtml = `<div class="tx-cat-icon" style="background:${catColor}"><i class="bi ${catIcon}"></i></div>
                       <span class="tx-cat-name">${this._h(t.category_name || 'Uncategorized')}</span>`;
        }

        const subHtml = isTransfer
            ? '<span class="tx-sub-badge" style="opacity:.5">Transfer</span>'
            : (t.subcategory_name
                ? `<span class="tx-sub-badge">${this._h(t.subcategory_name)}</span>`
                : '<span class="tx-sub-badge no-sub">None</span>');

        const descHtml = t.description
            ? `<span class="tx-desc-text" title="${this._h(t.description)}">${this._h(t.description)}</span>`
            : '<span class="tx-desc-text tx-desc-no">No Description</span>';

        return `
            <td>
                <div class="tx-date-cell">
                    <div class="tx-date-day">${day}</div>
                    <div class="tx-date-month">${month} ${year}</div>
                </div>
            </td>
            <td><div class="tx-cat-cell">${catHtml}</div></td>
            <td>${subHtml}</td>
            <td><div class="tx-desc-cell">${descHtml}</div></td>
            <td class="tx-amount-cell ${cls}">
                <span class="tx-type-dot dot-${t.type}"></span>${sign}Rs ${amtFmt}
            </td>
            <td>
                <div class="tx-actions-cell">
                    <div class="tx-action-btns">
                        <button class="tx-action-btn tx-btn-view" title="View" data-tx-id="${t.id}"><i class="bi bi-eye"></i></button>
                        <button class="tx-action-btn tx-btn-edit" title="Edit" data-tx-id="${t.id}"><i class="bi bi-pencil"></i></button>
                        <button class="tx-action-btn tx-btn-delete" title="Delete" data-tx-id="${t.id}"><i class="bi bi-trash3"></i></button>
                    </div>
                </div>
            </td>`;
    }

    _faToBi(icon) {
        const m = {
            'fa-tag': 'bi-tag-fill', 'fa-shopping-cart': 'bi-cart-fill', 'fa-utensils': 'bi-cup-hot-fill',
            'fa-home': 'bi-house-fill', 'fa-car': 'bi-car-front-fill', 'fa-briefcase': 'bi-briefcase-fill',
            'fa-heart': 'bi-heart-fill', 'fa-gift': 'bi-gift-fill', 'fa-plane': 'bi-airplane-fill',
            'fa-graduation-cap': 'bi-mortarboard-fill', 'fa-wallet': 'bi-wallet2', 'fa-baby': 'bi-balloon-fill',
            'fa-pills': 'bi-capsule', 'fa-dumbbell': 'bi-heart-pulse-fill', 'fa-tshirt': 'bi-handbag-fill',
            'fa-coffee': 'bi-cup-fill', 'fa-bus': 'bi-bus-front-fill', 'fa-gas-pump': 'bi-fuel-pump-fill',
            'fa-mobile-alt': 'bi-phone-fill', 'fa-laptop': 'bi-laptop', 'fa-gamepad': 'bi-controller',
            'fa-music': 'bi-music-note-beamed', 'fa-film': 'bi-film', 'fa-book': 'bi-book-fill',
            'fa-paw': 'bi-balloon-fill', 'fa-donate': 'bi-hand-thumbs-up-fill', 'fa-hand-holding-usd': 'bi-cash-stack',
            'fa-file-invoice-dollar': 'bi-file-earmark-text-fill', 'fa-chart-line': 'bi-graph-up',
            'fa-university': 'bi-bank', 'fa-credit-card': 'bi-credit-card-2-front-fill',
            'fa-exchange-alt': 'bi-arrow-left-right', 'fa-rupee-sign': 'bi-currency-rupee',
            'fa-money-check': 'bi-credit-card-fill', 'fa-calendar-alt': 'bi-calendar-event-fill',
            'fa-calendar-check': 'bi-calendar-check-fill', 'fa-comment-dots': 'bi-chat-dots-fill',
            'fa-layer-group': 'bi-layers-fill', 'fa-user': 'bi-person-fill', 'fa-users': 'bi-people-fill',
            'fa-check': 'bi-check-lg', 'fa-download': 'bi-download', 'fa-upload': 'bi-upload',
            'fa-print': 'bi-printer-fill', 'fa-file-csv': 'bi-filetype-csv', 'fa-file-excel': 'bi-filetype-xlsx',
            'fa-file-pdf': 'bi-filetype-pdf', 'fa-ellipsis-v': 'bi-three-dots-vertical',
            'fa-ellipsis-h': 'bi-three-dots', 'fa-filter': 'bi-funnel-fill', 'fa-times': 'bi-x-lg',
            'fa-angle-left': 'bi-chevron-left', 'fa-angle-right': 'bi-chevron-right',
            'fa-angle-double-left': 'bi-chevron-double-left', 'fa-angle-double-right': 'bi-chevron-double-right',
            'fa-receipt': 'bi-receipt', 'fa-arrow-down': 'bi-arrow-down', 'fa-arrow-up': 'bi-arrow-up',
            'fa-balance-scale': 'bi-balance-scale',
        };
        const bare = icon.replace(/^fas?\s+/, '').replace(/^far\s+/, '').replace(/^fab\s+/, '');
        return m[bare] || 'bi-tag-fill';
    }

    _h(str) {
        if (!str) return '';
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    /* ==================== DATATABLE ==================== */

    _destroyDataTable() {
        if (this.dataTable) {
            try { this.dataTable.clear(); this.dataTable.destroy(); } catch (e) {}
            this.dataTable = null;
        }
        const table = document.getElementById('transactions-datatable');
        if (table && window.$ && $.fn.DataTable && $.fn.DataTable.isDataTable(table)) {
            try { $(table).DataTable().clear().destroy(); } catch (e) {}
        }
    }

    _initDataTable() {
        const $ = window.$;
        if (!$ || !$.fn || !$.fn.DataTable) return;
        const table = document.getElementById('transactions-datatable');
        if (!table) return;

        try {
            this.dataTable = $(table).DataTable({
                pageLength: 10,
                lengthMenu: [10, 25, 50, 100],
                order: [[0, 'desc']],
                responsive: false,
                columnDefs: [
                    { orderable: true, targets: [0, 1, 2, 3, 4] },
                    { orderable: false, targets: [5] }
                ],
                language: {
                    search: '', searchPlaceholder: 'Search...',
                    lengthMenu: 'Show _MENU_',
                    info: 'Showing _START_ to _END_ of _TOTAL_',
                    infoEmpty: 'No transactions',
                    infoFiltered: '(filtered from _MAX_)',
                    paginate: {
                        first: '<i class="bi bi-chevron-double-left"></i>',
                        last: '<i class="bi bi-chevron-double-right"></i>',
                        next: '<i class="bi bi-chevron-right"></i>',
                        previous: '<i class="bi bi-chevron-left"></i>'
                    }
                },
                searching: false,
                dom: '<"row"<"col-sm-12"r>><"row"<"col-sm-12"t>><"row align-items-center mt-2"<"col-sm-12 col-md-5"l><"col-sm-12 col-md-3"i><"col-sm-12 col-md-4"p>>'
            });
        } catch (e) {
            console.error('DataTable init error:', e);
        }
    }

    _initTooltips() {
        if (window.bootstrap) {
            document.querySelectorAll('#transactions-table-body [data-bs-toggle="tooltip"]').forEach(el => {
                if (!el._tooltip) {
                    try { el._tooltip = new bootstrap.Tooltip(el, { trigger: 'hover' }); } catch (e) {}
                }
            });
        }
    }

    /* ==================== TRANSACTION FORM ==================== */

    _openForm(txData) {
        this._editingTransactionId = txData ? txData.id : null;

        const title = this._editingTransactionId ? 'Edit Transaction' : 'New Transaction';
        const subtitle = this._editingTransactionId ? 'Update your transaction details.' : 'Record an income, expense, or transfer.';
        const saveText = this._editingTransactionId
            ? '<i class="bi bi-check-lg me-1"></i> Update Transaction'
            : '<i class="bi bi-check-lg me-1"></i> Save Transaction';

        if (window.modalService) {
            window.modalService.open({
                title,
                subtitle,
                icon: 'fa-plus-circle',
                bodyHTML: this._formHTML(),
                showFooter: true,
                saveText,
                onSave: () => this._saveTransaction()
            });
        } else if (window.premiumModal) {
            const body = document.getElementById('modal-body');
            window.premiumModal.setTitle(title);
            window.premiumModal.setSubtitle(subtitle);
            window.premiumModal.setIcon('fa-plus-circle');
            if (body) body.innerHTML = this._formHTML();
            document.getElementById('modal-footer')?.classList.remove('hidden');
            const saveBtn = document.getElementById('modal-save-btn');
            if (saveBtn) {
                saveBtn.innerHTML = saveText;
                saveBtn.onclick = () => this._saveTransaction();
            }
            window.premiumModal.open();
        }

        // Hide unused footer buttons
        const draftBtn = document.getElementById('modal-draft-btn');
        const resetBtn = document.getElementById('modal-reset-btn');
        if (draftBtn) draftBtn.style.display = 'none';
        if (resetBtn) resetBtn.style.display = 'none';

        // Bind datepickers
        if (window.DatePickerManager) {
            window.DatePickerManager.bind('#transaction-date');
            window.DatePickerManager.bind('#transaction-due-date');
        }

        // Load form data
        this._loadCategories();
        this._loadAccounts();
        this._bindFormEvents();

        // Set initial type to expense
        this._setType('expense');

        // Populate if editing
        if (txData) {
            this._populateForm(txData);
        }
    }

    _formHTML() {
        const today = new Date().toISOString().slice(0, 10);
        return `
            <form id="tx-form" novalidate>
                <select id="transaction-type" style="display:none;"><option value="expense">Expense</option><option value="income">Income</option><option value="transfer">Transfer</option></select>

                <div class="tx-type-group" role="radiogroup">
                    <label class="tx-type-card active" data-type="expense" tabindex="0">
                        <input type="radio" name="tx-type-radio" value="expense" checked>
                        <span class="tx-type-icon" style="color:#EF4444;">&#9654;</span>
                        <span class="tx-type-label">Expense</span>
                    </label>
                    <label class="tx-type-card" data-type="income" tabindex="0">
                        <input type="radio" name="tx-type-radio" value="income">
                        <span class="tx-type-icon" style="color:#10B981;">&#9660;</span>
                        <span class="tx-type-label">Income</span>
                    </label>
                    <label class="tx-type-card" data-type="transfer" tabindex="0">
                        <input type="radio" name="tx-type-radio" value="transfer">
                        <span class="tx-type-icon" style="color:#3B82F6;">&#8644;</span>
                        <span class="tx-type-label">Transfer</span>
                    </label>
                </div>

                <div class="tx-fields-grid">
                    <div class="tx-field tx-amount-field">
                        <label class="tx-label" for="transaction-amount">Amount</label>
                        <div class="tx-amount-wrap">
                            <span class="tx-currency-label">Rs</span>
                            <input type="number" step="0.01" min="0.01" id="transaction-amount" class="tx-input" placeholder="0" required autocomplete="off">
                        </div>
                    </div>

                    <!-- Payment method (expense only) -->
                    <div class="tx-field" id="payment-method-wrapper">
                        <label class="tx-label" for="transaction-payment-method">Payment</label>
                        <select id="transaction-payment-method" class="tx-select">
                            <option value="cash">Cash / Account</option>
                            <option value="credit">Credit (Udharo)</option>
                        </select>
                    </div>

                    <!-- Creditor (credit expense) -->
                    <div class="tx-field hidden" id="creditor-wrapper">
                        <label class="tx-label" for="transaction-creditor">Creditor</label>
                        <select id="transaction-creditor" class="tx-select"><option value="">Select creditor</option></select>
                    </div>

                    <!-- Due Date (credit expense) -->
                    <div class="tx-field hidden" id="due-date-wrapper">
                        <label class="tx-label" for="transaction-due-date">Due Date</label>
                        <input type="date" id="transaction-due-date" class="tx-input">
                    </div>

                    <!-- From Account (transfer) -->
                    <div class="tx-field hidden" id="from-account-wrapper">
                        <label class="tx-label" for="tx-from-account">From Account</label>
                        <select id="tx-from-account" class="tx-select"><option value="">Select from account</option></select>
                    </div>

                    <!-- To Account (transfer) -->
                    <div class="tx-field hidden" id="to-account-wrapper">
                        <label class="tx-label" for="tx-to-account">To Account</label>
                        <select id="tx-to-account" class="tx-select"><option value="">Select to account</option></select>
                    </div>

                    <!-- Transfer Fee Toggle -->
                    <div class="tx-field hidden full-width" id="transfer-fee-toggle-wrapper">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="transfer-has-fee">
                            <label class="form-check-label tx-label" for="transfer-has-fee">Add transaction fee</label>
                            <small class="d-block text-muted">e.g., ATM charge, transfer fee, deposit charge</small>
                        </div>
                    </div>

                    <!-- Transfer Fee Amount -->
                    <div class="tx-field hidden" id="transfer-fee-amount-wrapper">
                        <label class="tx-label" for="transfer-fee-amount">Fee Amount</label>
                        <div class="tx-amount-wrap">
                            <span class="tx-currency-label">Rs</span>
                            <input type="number" step="0.01" min="0.01" id="transfer-fee-amount" class="tx-input" placeholder="0" autocomplete="off">
                        </div>
                    </div>

                    <!-- Transfer Fee Category -->
                    <div class="tx-field hidden" id="transfer-fee-category-wrapper">
                        <label class="tx-label" for="transfer-fee-category">Fee Category</label>
                        <select id="transfer-fee-category" class="tx-select"><option value="">Select category</option></select>
                    </div>

                    <!-- Category (income/expense) -->
                    <div class="tx-field" id="category-wrapper">
                        <label class="tx-label" for="transaction-category">Category</label>
                        <select id="transaction-category" class="tx-select" required><option value="">Select category</option></select>
                    </div>

                    <!-- Subcategory -->
                    <div class="tx-field" id="subcategory-wrapper">
                        <label class="tx-label" for="transaction-subcategory">Subcategory</label>
                        <select id="transaction-subcategory" class="tx-select" disabled><option value="">Select subcategory</option></select>
                    </div>

                    <!-- Account (income/expense) -->
                    <div class="tx-field" id="account-wrapper">
                        <label class="tx-label" for="transaction-account">Account</label>
                        <select id="transaction-account" class="tx-select" required><option value="">Select account</option></select>
                    </div>

                    <!-- Date -->
                    <div class="tx-field">
                        <label class="tx-label" for="transaction-date">Date</label>
                        <input type="date" id="transaction-date" class="tx-input" value="${today}" required>
                    </div>

                    <!-- Description -->
                    <div class="tx-field full-width">
                        <label class="tx-label" for="transaction-description">Description <span class="text-muted">(optional)</span></label>
                        <input type="text" id="transaction-description" class="tx-input" placeholder="e.g. Lunch, Salary, Rent" autocomplete="off" maxlength="255">
                    </div>
                </div>
            </form>`;
    }

    /* ==================== FORM EVENT BINDING ==================== */

    _bindFormEvents() {
        // Type cards
        document.querySelectorAll('.tx-type-card').forEach(card => {
            const handler = () => {
                document.querySelectorAll('.tx-type-card').forEach(c => c.classList.remove('active'));
                card.classList.add('active');
                const type = card.dataset.type;
                document.getElementById('transaction-type').value = type;
                this._syncSections(type);
                this._loadCategoriesByType(type);
                if (type === 'expense') {
                    const pm = document.getElementById('transaction-payment-method')?.value || 'cash';
                    this._toggleCreditorSection(pm);
                } else {
                    this._toggleCreditorSection('');
                }
            };
            card.removeEventListener('click', handler);
            card.addEventListener('click', handler);
        });

        // Payment method -> creditor toggle
        const pm = document.getElementById('transaction-payment-method');
        if (pm) {
            pm.addEventListener('change', () => {
                this._toggleCreditorSection(pm.value);
            });
        }

        // Amount -> negative balance check
        const amt = document.getElementById('transaction-amount');
        if (amt) {
            amt.addEventListener('input', () => this._checkNegative());
        }

        // Account select -> negative check
        const acc = document.getElementById('transaction-account');
        if (acc) {
            acc.addEventListener('change', () => this._checkNegative());
        }

        // Transfer fee checkbox toggle
        const feeCheckbox = document.getElementById('transfer-has-fee');
        if (feeCheckbox) {
            feeCheckbox.addEventListener('change', () => {
                const type = document.getElementById('transaction-type')?.value;
                if (type === 'transfer') {
                    this._syncSections(type);
                }
            });
        }

        // From/to account mutual exclusion
        ['tx-from-account', 'tx-to-account'].forEach(id => {
            const sel = document.getElementById(id);
            if (!sel) return;
            sel.addEventListener('change', () => {
                const from = document.getElementById('tx-from-account');
                const to = document.getElementById('tx-to-account');
                Array.from(to.options).forEach(o => o.disabled = false);
                Array.from(from.options).forEach(o => o.disabled = false);
                if (from.value) {
                    const opt = to.querySelector(`option[value="${from.value}"]`);
                    if (opt) opt.disabled = true;
                }
                if (to.value) {
                    const opt = from.querySelector(`option[value="${to.value}"]`);
                    if (opt) opt.disabled = true;
                }
            });
        });
    }

    _syncSections(type) {
        const sections = {
            'payment-method-wrapper': type === 'expense',
            'creditor-wrapper': false,
            'from-account-wrapper': type === 'transfer',
            'to-account-wrapper': type === 'transfer',
            'category-wrapper': type !== 'transfer',
            'subcategory-wrapper': type !== 'transfer',
            'account-wrapper': type !== 'transfer',
            'transfer-fee-toggle-wrapper': type === 'transfer',
            'transfer-fee-amount-wrapper': false,
            'transfer-fee-category-wrapper': false,
        };
        // Show fee fields only when transfer type AND checkbox is checked
        if (type === 'transfer') {
            const hasFee = document.getElementById('transfer-has-fee')?.checked;
            if (hasFee) {
                sections['transfer-fee-amount-wrapper'] = true;
                sections['transfer-fee-category-wrapper'] = true;
            }
        }
        Object.entries(sections).forEach(([id, show]) => {
            const el = document.getElementById(id);
            if (el) el.classList.toggle('hidden', !show);
        });
        // Credit is only shown when payment method is 'credit' AND type is expense
        if (type === 'expense') {
            const pm = document.getElementById('transaction-payment-method')?.value;
            this._toggleCreditorSection(pm || 'cash');
        }
    }

    _togglePaymentSection(type) {
        const pm = document.getElementById('payment-method-wrapper');
        if (!pm) return;
        if (type === 'expense') {
            pm.classList.remove('hidden');
        } else {
            pm.classList.add('hidden');
            this._toggleCreditorSection('');
        }
    }

    _toggleCreditorSection(method) {
        const cred = document.getElementById('creditor-wrapper');
        const due = document.getElementById('due-date-wrapper');
        const acc = document.getElementById('account-wrapper');
        const type = document.getElementById('transaction-type')?.value;

        if (method === 'credit' && type === 'expense') {
            if (cred) { cred.classList.remove('hidden'); this._loadCreditors(); }
            if (due) due.classList.remove('hidden');
            if (acc) acc.classList.add('hidden');
        } else {
            if (cred) cred.classList.add('hidden');
            if (due) due.classList.add('hidden');
            if (acc && type !== 'transfer') acc.classList.remove('hidden');
        }
    }

    _checkNegative() {
        const warning = document.getElementById('tx-negative-warning');
        if (!warning) return;
        const type = document.getElementById('transaction-type')?.value;
        if (type !== 'expense') { warning.classList.remove('show'); return; }
        const sel = document.getElementById('transaction-account');
        const opt = sel?.selectedOptions?.[0];
        if (!opt?.value || opt.dataset.balance === undefined) { warning.classList.remove('show'); return; }
        const bal = parseFloat(opt.dataset.balance) || 0;
        const amt = parseFloat(document.getElementById('transaction-amount')?.value) || 0;
        warning.classList.toggle('show', amt > bal);
    }

    /* ==================== LOAD FORM DATA ==================== */

    async _loadCategories() {
        const type = document.getElementById('transaction-type')?.value || 'expense';
        await this._loadCategoriesByType(type);
    }

    async _loadCategoriesByType(type) {
        try {
            const res = await window.Api.get('/categories?type=' + type + '&status=active');
            const sel = document.getElementById('transaction-category');
            if (!sel) return;
            sel.innerHTML = '<option value="">Select category</option>';
            if (res.success) {
                res.data.forEach(c => {
                    const o = document.createElement('option');
                    o.value = c.id;
                    o.textContent = c.name;
                    o.dataset.icon = c.icon || '';
                    o.dataset.color = c.color || '#6366f1';
                    sel.appendChild(o);
                });
            }
        } catch (e) { console.error('Load categories error:', e); }

        // Also load expense categories for transfer fee dropdown
        if (type === 'transfer') {
            try {
                const feeRes = await window.Api.get('/categories?type=expense&status=active');
                const feeSel = document.getElementById('transfer-fee-category');
                if (!feeSel) return;
                feeSel.innerHTML = '<option value="">Select fee category</option>';
                if (feeRes.success) {
                    feeRes.data.forEach(c => {
                        const o = document.createElement('option');
                        o.value = c.id;
                        o.textContent = c.name;
                        feeSel.appendChild(o);
                    });
                }
            } catch (e) { console.error('Load fee categories error:', e); }
        }
    }

    async _loadSubcategories(catId) {
        const sel = document.getElementById('transaction-subcategory');
        if (!sel) return;
        sel.innerHTML = '<option value="">Select subcategory</option>';
        if (!catId) { sel.disabled = true; return; }
        try {
            const res = await window.Api.get('/subcategories?category_id=' + catId + '&status=active');
            if (res.success) {
                sel.disabled = res.data.length === 0;
                res.data.forEach(s => {
                    const o = document.createElement('option');
                    o.value = s.id;
                    o.textContent = s.name;
                    sel.appendChild(o);
                });
            }
        } catch (e) { console.error('Load subcategories error:', e); }
    }

    async _loadAccounts() {
        try {
            const res = await window.Api.get('/accounts');
            const selects = ['transaction-account', 'tx-from-account', 'tx-to-account'];
            selects.forEach(id => {
                const sel = document.getElementById(id);
                if (!sel) return;
                const isTransfer = id.includes('tx-');
                const label = id === 'tx-from-account' ? 'Select from account' : (id === 'tx-to-account' ? 'Select to account' : 'Select account');
                sel.innerHTML = `<option value="">${label}</option>`;
                if (res.success) {
                    res.data.forEach(a => {
                        const o = document.createElement('option');
                        o.value = a.id;
                        o.textContent = a.name;
                        o.dataset.balance = a.calculated_balance ?? a.balance ?? '0';
                        sel.appendChild(o);
                    });
                }
            });
        } catch (e) { console.error('Load accounts error:', e); }
    }

    async _loadCreditors() {
        try {
            const res = await window.Api.get('/people?status=active');
            const sel = document.getElementById('transaction-creditor');
            if (!sel) return;
            sel.innerHTML = '<option value="">Select creditor</option>';
            if (res.success) {
                res.data.forEach(p => {
                    const o = document.createElement('option');
                    o.value = p.id;
                    o.textContent = p.name;
                    sel.appendChild(o);
                });
            }
        } catch (e) { console.error('Load creditors error:', e); }
    }

    /* ==================== POPULATE FORM (EDIT) ==================== */

    _populateForm(t) {
        const type = t.type || 'expense';
        // Click the right type card
        const card = document.querySelector(`.tx-type-card[data-type="${type}"]`);
        if (card) card.click();

        const amountEl = document.getElementById('transaction-amount');
        if (amountEl) {
            amountEl.value = parseFloat(t.amount) || 0;
            amountEl.dispatchEvent(new Event('input', { bubbles: true }));
        }

        const dateEl = document.getElementById('transaction-date');
        if (dateEl) dateEl.value = t.date || new Date().toISOString().slice(0, 10);

        const descEl = document.getElementById('transaction-description');
        if (descEl) descEl.value = t.description || '';

        const catEl = document.getElementById('transaction-category');
        if (catEl && t.category_id) {
            // Wait for categories to load then set value
            setTimeout(() => {
                catEl.value = t.category_id;
                if (t.subcategory_id) {
                    const subEl = document.getElementById('transaction-subcategory');
                    if (subEl) {
                        setTimeout(() => { subEl.value = t.subcategory_id; }, 300);
                    }
                }
            }, 200);
        }

        if (type === 'transfer') {
            const fromEl = document.getElementById('tx-from-account');
            const toEl = document.getElementById('tx-to-account');
            if (fromEl && t.from_account_id) fromEl.value = t.from_account_id;
            if (toEl && t.to_account_id) toEl.value = t.to_account_id;
            // Trigger change to disable options
            if (fromEl) fromEl.dispatchEvent(new Event('change'));
        } else {
            const accEl = document.getElementById('transaction-account');
            if (accEl && t.account_id) {
                setTimeout(() => { accEl.value = t.account_id; }, 200);
            }
        }

        this._checkNegative();
    }

    /* ==================== SAVE TRANSACTION ==================== */

    async _saveTransaction() {
        const type = document.getElementById('transaction-type')?.value;
        if (!type) { this._notify('error', 'Please select a transaction type'); return; }

        const amount = parseFloat(document.getElementById('transaction-amount')?.value);
        if (!amount || amount <= 0) { this._notify('error', 'Please enter a valid amount'); return; }

        const date = document.getElementById('transaction-date')?.value;
        if (!date) { this._notify('error', 'Please select a date'); return; }

        const data = { type, amount, date };

        if (type === 'transfer') {
            const fromAcc = document.getElementById('tx-from-account')?.value;
            const toAcc = document.getElementById('tx-to-account')?.value;
            if (!fromAcc || !toAcc) { this._notify('error', 'Please select both accounts for transfer'); return; }
            if (fromAcc === toAcc) { this._notify('error', 'From and To accounts must be different'); return; }
            data.from_account_id = parseInt(fromAcc);
            data.to_account_id = parseInt(toAcc);
            data.account_id = parseInt(fromAcc);
            data.category_id = null;
            data.payment_method = 'transfer';
            // Transfer fee
            const hasFee = document.getElementById('transfer-has-fee')?.checked;
            if (hasFee) {
                const feeAmt = parseFloat(document.getElementById('transfer-fee-amount')?.value);
                const feeCat = document.getElementById('transfer-fee-category')?.value;
                if (!feeAmt || feeAmt <= 0) { this._notify('error', 'Please enter a valid fee amount'); return; }
                if (!feeCat) { this._notify('error', 'Please select a fee category'); return; }
                data.fee_amount = feeAmt;
                data.fee_category_id = parseInt(feeCat);
            }
        } else {
            const accountId = document.getElementById('transaction-account')?.value;
            if (!accountId) { this._notify('error', 'Please select an account'); return; }
            data.account_id = parseInt(accountId);

            const catId = document.getElementById('transaction-category')?.value;
            if (!catId) { this._notify('error', 'Please select a category'); return; }
            data.category_id = parseInt(catId);

            const subId = document.getElementById('transaction-subcategory')?.value;
            data.subcategory_id = subId ? parseInt(subId) : null;

            const paymentMethod = document.getElementById('transaction-payment-method')?.value || 'cash';
            if (paymentMethod === 'credit' && type === 'expense') {
                const creditorId = document.getElementById('transaction-creditor')?.value;
                if (!creditorId) { this._notify('error', 'Please select a creditor for credit purchase'); return; }
                data.creditor_id = parseInt(creditorId);
                data.account_id = null;
                data.payment_method = 'credit';
                data.due_date = document.getElementById('transaction-due-date')?.value || null;
            } else {
                data.payment_method = paymentMethod;
            }
        }

        data.description = document.getElementById('transaction-description')?.value || '';

        // Disable save button and show spinner
        const saveBtn = document.getElementById('modal-save-btn');
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Saving...';
        }

        try {
            let result;
            if (this._editingTransactionId) {
                result = await window.Api.put('/transactions/' + this._editingTransactionId, data);
            } else {
                result = await window.Api.post('/transactions', data);
            }

            if (result && result.success) {
                this._notify('success',
                    this._editingTransactionId ? 'Transaction updated successfully'
                    : type === 'income' ? 'Income recorded successfully'
                    : type === 'transfer' ? 'Transfer completed successfully'
                    : 'Expense recorded successfully'
                );
                this._editingTransactionId = null;
                window.modalService?.close();
                window.premiumModal?.close();
                this.loadTransactions();
                document.dispatchEvent(new CustomEvent('app:data-changed'));
            } else {
                this._notify('error', result?.message || 'Failed to save transaction');
            }
        } catch (err) {
            console.error('Save transaction error:', err);
            this._notify('error', err.message || 'Failed to save transaction');
        } finally {
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i> ' + (this._editingTransactionId ? 'Update Transaction' : 'Save Transaction');
            }
        }
    }

    /* ==================== VIEW / EDIT / DELETE ==================== */

    async viewTransaction(id) {
        try {
            const res = await window.Api.get('/transactions/' + id);
            if (!res.success || !res.data) { this._notify('error', 'Transaction not found'); return; }
            const t = res.data;
            const amt = parseFloat(t.amount) || 0;
            const amtStr = 'Rs ' + amt.toLocaleString('en-IN');
            const typeColor = t.type === 'income' ? '#10B981' : (t.type === 'expense' ? '#EF4444' : '#3B82F6');
            const typeLabel = t.type.charAt(0).toUpperCase() + t.type.slice(1);
            const date = t.date ? new Date(t.date + 'T00:00:00').toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : '--';

            let details = '';
            if (t.type === 'transfer') {
                details = `
                    <div class="col-6"><p class="text-muted small mb-1">From Account</p><p class="fw-semibold">${this._h(t.from_account_name || '--')}</p></div>
                    <div class="col-6"><p class="text-muted small mb-1">To Account</p><p class="fw-semibold">${this._h(t.to_account_name || '--')}</p></div>`;
            } else {
                details = `
                    <div class="col-6"><p class="text-muted small mb-1">Category</p><p class="fw-semibold">${this._h(t.category_name || '--')}</p></div>
                    <div class="col-6"><p class="text-muted small mb-1">Subcategory</p><p class="fw-semibold">${this._h(t.subcategory_name || '--')}</p></div>
                    <div class="col-6"><p class="text-muted small mb-1">Account</p><p class="fw-semibold">${this._h(t.account_name || '--')}</p></div>`;
            }

            const html = `
                <div class="tx-view-modal">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="badge px-3 py-2 fw-semibold" style="background:${typeColor}">${typeLabel}</span>
                        <span class="text-muted">${date}</span>
                    </div>
                    <div class="text-center mb-4">
                        <h2 class="fw-bold mb-1" style="color:${typeColor};font-size:2rem;">${t.type === 'income' ? '+' : (t.type === 'expense' ? '-' : '')}${amtStr}</h2>
                    </div>
                    <div class="row g-3">
                        ${details}
                        <div class="col-6"><p class="text-muted small mb-1">Date</p><p class="fw-semibold">${date}</p></div>
                        <div class="col-12"><p class="text-muted small mb-1">Description</p><p class="fw-semibold">${this._h(t.description || 'No Description')}</p></div>
                    </div>
                </div>`;

            if (window.modalService) {
                window.modalService.open({ title: 'Transaction Details', subtitle: 'Viewing ' + typeLabel.toLowerCase() + ' transaction', icon: 'fa-info-circle', bodyHTML: html, showFooter: false });
            }
        } catch (err) {
            console.error('View transaction error:', err);
            this._notify('error', 'Failed to load transaction details');
        }
    }

    async editTransaction(id) {
        try {
            const res = await window.Api.get('/transactions/' + id);
            if (!res.success || !res.data) { this._notify('error', 'Transaction not found'); return; }
            this._openForm(res.data);
        } catch (err) {
            console.error('Edit transaction error:', err);
            this._notify('error', 'Failed to load transaction');
        }
    }

    async deleteTransaction(id) {
        const confirmed = window.Swal
            ? await window.Swal.fire({ title: 'Delete Transaction', text: 'This cannot be undone.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#EF4444', cancelButtonColor: '#6B7280', confirmButtonText: 'Yes, Delete' })
            : { isConfirmed: confirm('Delete this transaction?') };

        if (!confirmed.isConfirmed) return;

        try {
            const res = await window.Api.delete('/transactions/' + id);
            if (res.success) {
                this._notify('success', 'Transaction deleted successfully');
                this.loadTransactions();
                document.dispatchEvent(new CustomEvent('app:data-changed'));
            } else {
                this._notify('error', res.message || 'Failed to delete transaction');
            }
        } catch (err) {
            console.error('Delete transaction error:', err);
            this._notify('error', err.message || 'Failed to delete transaction');
        }
    }

    /* ==================== DELEGATED CLICK HANDLING ==================== */

    // Called from inline onclick in table rows
    handleAction(e) {
        const target = e.target.closest('button');
        if (!target) return;
        const id = target.dataset.txId;
        if (!id) return;
        if (target.classList.contains('tx-btn-view')) this.viewTransaction(id);
        else if (target.classList.contains('tx-btn-edit')) this.editTransaction(id);
        else if (target.classList.contains('tx-btn-delete')) this.deleteTransaction(id);
    }

    // Attach delegated listener for table action buttons
    _setupDelegatedActions() {
        const table = document.getElementById('transactions-table-body');
        if (table) {
            table.removeEventListener('click', this._delegatedHandler);
            this._delegatedHandler = (e) => this.handleAction(e);
            table.addEventListener('click', this._delegatedHandler);
        }
    }

    /* ==================== HELPERS ==================== */

    _notify(type, message) {
        if (window.NotificationService) {
            if (type === 'success') NotificationService.success(message);
            else NotificationService.error(message);
        } else if (window.Swal) {
            window.Swal.fire({ icon: type, title: type === 'success' ? 'Success' : 'Error', text: message, timer: 3000, showConfirmButton: false });
        } else {
            alert(message);
        }
    }

    _setType(type) {
        const card = document.querySelector(`.tx-type-card[data-type="${type}"]`);
        if (card) card.click();
    }
}

window.TransactionsManager = TransactionsManager;
