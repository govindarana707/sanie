// Transaction Ledger Module
class LedgerManager {
    constructor() {
        this.api = new APIClient();
        this.dataTable = null;
        this.transactions = [];
        this.summary = null;
        this.filters = {
            type: '',
            category_id: '',
            account_id: '',
            start_date: '',
            end_date: '',
            search: ''
        };
        this.filterData = { accounts: [], categories: [] };
        this.isLoading = false;
        this.currentPage = 1;
        this.pageSize = 25;
        this.pagination = null;
    }

    _h(value) {
        return Formatters.escapeHTML(String(value ?? ''));
    }

    _safeIcon(value, fallback = 'tag') {
        const icon = String(value ?? '');
        return /^[a-z0-9-]+$/i.test(icon) ? icon : fallback;
    }

    _safeColor(value, fallback = '#64748b') {
        const color = String(value ?? '');
        return /^#[0-9a-f]{3,8}$/i.test(color) ? color : fallback;
    }

    async init() {
        try {
            await this.loadFilterData();
            this.populateFilters();
            this.applyUrlParams();
            this.setupEventListeners();
            await this.loadLedger();
        } catch (e) {
            console.error('Ledger init failed:', e);
            if (e.message === 'Authentication expired' || e.message === 'Authentication required') {
                window.location.href = 'index.html';
            }
        }
    }

    async loadFilterData() {
        try {
            const res = await this.api.get('/ledger/filters');
            if (res.success) {
                this.filterData = res.data;
            }
        } catch (e) {
            console.warn('Could not load filter data:', e);
        }
    }

    populateFilters() {
        const catSelect = document.getElementById('ledger-category');
        const acctSelect = document.getElementById('ledger-account');

        if (catSelect && this.filterData.categories) {
            const incomeCats = this.filterData.categories.filter(c => c.type === 'income');
            const expenseCats = this.filterData.categories.filter(c => c.type === 'expense');
            catSelect.innerHTML = '<option value="">All Categories</option>';
            if (incomeCats.length) {
                const optGroup = document.createElement('optgroup');
                optGroup.label = 'Income';
                incomeCats.forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c.id;
                    opt.textContent = c.name;
                    optGroup.appendChild(opt);
                });
                catSelect.appendChild(optGroup);
            }
            if (expenseCats.length) {
                const optGroup = document.createElement('optgroup');
                optGroup.label = 'Expense';
                expenseCats.forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c.id;
                    opt.textContent = c.name;
                    optGroup.appendChild(opt);
                });
                catSelect.appendChild(optGroup);
            }
        }

        if (acctSelect && this.filterData.accounts) {
            acctSelect.innerHTML = '<option value="">All Accounts</option>';
            this.filterData.accounts.forEach(a => {
                const opt = document.createElement('option');
                opt.value = a.id;
                opt.textContent = a.name;
                acctSelect.appendChild(opt);
            });
        }
    }

    applyUrlParams() {
        const params = new URLSearchParams(window.location.search);
        const accountId = params.get('account_id');
        if (accountId) {
            const acctSelect = document.getElementById('ledger-account');
            if (acctSelect) {
                const option = acctSelect.querySelector(`option[value="${accountId}"]`);
                if (option) {
                    acctSelect.value = accountId;
                }
            }
        }
    }

    setupEventListeners() {
        document.getElementById('ledger-type')?.addEventListener('change', () => this.applyFilters());
        document.getElementById('ledger-category')?.addEventListener('change', () => this.applyFilters());
        document.getElementById('ledger-account')?.addEventListener('change', () => this.applyFilters());
        document.getElementById('ledger-date-start')?.addEventListener('change', () => this.applyFilters());
        document.getElementById('ledger-date-end')?.addEventListener('change', () => this.applyFilters());

        let searchTimer;
        document.getElementById('ledger-search')?.addEventListener('input', () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => this.applyFilters(), 220);
        });

        document.getElementById('ledger-apply-filters')?.addEventListener('click', () => this.applyFilters());
        document.getElementById('ledger-clear-filters')?.addEventListener('click', () => this.clearFilters());
        document.getElementById('ledger-refresh')?.addEventListener('click', () => this.loadLedger());
        document.getElementById('ledger-export-csv')?.addEventListener('click', () => this.exportCSV());
        document.getElementById('ledger-page-size')?.addEventListener('change', e => { this.pageSize = parseInt(e.target.value, 10) || 25; this.currentPage = 1; this.loadLedger(); });
        document.getElementById('ledger-print')?.addEventListener('click', () => window.print());

        // Init tooltips
        if (window.bootstrap) {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
                try { new bootstrap.Tooltip(el); } catch (e) {}
            });
        }
    }

    getFilters() {
        return {
            type: document.getElementById('ledger-type')?.value || '',
            category_id: document.getElementById('ledger-category')?.value || '',
            account_id: document.getElementById('ledger-account')?.value || '',
            start_date: document.getElementById('ledger-date-start')?.value || '',
            end_date: document.getElementById('ledger-date-end')?.value || '',
            search: document.getElementById('ledger-search')?.value || ''
        };
    }

    _hideAllStates() {
        ['ledger-loading', 'ledger-empty', 'ledger-error'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'none';
        });
    }

    showLoading(show) {
        const table = document.getElementById('ledger-table');
        const loading = document.getElementById('ledger-loading');
        if (show) {
            this._hideAllStates();
            if (table) table.style.display = 'none';
            if (loading) loading.style.display = 'flex';
        } else if (loading) {
            loading.style.display = 'none';
        }
    }

    showError(msg) {
        const table = document.getElementById('ledger-table');
        const error = document.getElementById('ledger-error');
        this._hideAllStates();
        if (table) table.style.display = 'none';
        if (error) {
            error.style.display = 'flex';
            const em = error.querySelector('.error-message');
            if (em) em.textContent = msg || 'Failed to load ledger data.';
        }
    }

    async loadLedger() {
        if (this.isLoading) return;
        this.isLoading = true;
        this.showLoading(true);

        try {
            this.filters = this.getFilters();
            const params = new URLSearchParams();
            Object.entries(this.filters).forEach(([k, v]) => { if (v) params.set(k, v); });
            params.set('page', String(this.currentPage));
            params.set('limit', String(this.pageSize));

            const res = await this.api.get('/ledger?' + params.toString());
            if (!res.success) throw new Error(res.message || 'Failed to load ledger');

            this.transactions = res.data.transactions || [];
            this.summary = res.data.summary;
            this.pagination = res.data.pagination || null;
            this.renderSummary();
            this.renderTable();
        } catch (e) {
            console.error('Ledger load error:', e);
            this.showError(e.message);
        } finally {
            this.isLoading = false;
            this.showLoading(false);
        }
    }

    renderSummary() {
        if (!this.summary) return;
        const cur = v => Formatters.currency(v);
        const byId = id => document.getElementById(id);
        const setColor = (el, val) => {
            if (el) el.style.color = val >= 0 ? 'var(--sanie-income)' : 'var(--sanie-expense)';
        };

        const ob = byId('ldg-opening-balance'); if (ob) ob.textContent = cur(this.summary.range_opening ?? this.summary.opening_balance);
        const cb = byId('ldg-closing-balance'); if (cb) { cb.textContent = cur(this.summary.closing_balance); setColor(cb, this.summary.closing_balance); }
        byId('ldg-period-income') && (byId('ldg-period-income').textContent = cur(this.summary.period_income));
        byId('ldg-period-expense') && (byId('ldg-period-expense').textContent = cur(this.summary.period_expense));
        const netEl = byId('ldg-period-net');
        if (netEl) { netEl.textContent = cur(Math.abs(this.summary.period_net)); setColor(netEl, this.summary.period_net); }
        byId('ldg-period-count') && (byId('ldg-period-count').textContent = this.summary.period_count.toLocaleString('en-IN'));
    }

    renderTable() {
        if (this.dataTable) {
            this.dataTable.destroy();
            this.dataTable = null;
        }
        $('#ledger-table tbody').empty();

        const emptyEl = document.getElementById('ledger-empty');
        const table = document.getElementById('ledger-table');
        if (!table) return;

        this._hideAllStates();

        if (!this.transactions.length) {
            if (emptyEl) emptyEl.style.display = 'flex';
            table.style.display = 'none';
            this.renderPagination();
            return;
        }

        table.style.display = '';

        const tbody = document.querySelector('#ledger-table tbody');

        // Opening balance row
        const pageOpening = this.summary?.page_opening ?? this.summary?.opening_balance ?? 0;
        if (this.summary) {
            const ob = document.createElement('tr');
            ob.className = 'ldg-row-opening';
            ob.innerHTML = `<td colspan="5"><strong>Page Opening Balance</strong></td>
                            <td class="ldg-amount">${Formatters.currency(pageOpening)}</td>
                            <td class="ldg-balance ${pageOpening >= 0 ? 'positive' : 'negative'}">${Formatters.currency(pageOpening)}</td>`;
            tbody.appendChild(ob);
        }

        this.transactions.forEach(tx => {
            const amount = parseFloat(tx.amount) || 0;
            const rb = parseFloat(tx.running_balance) || 0;
            const type = tx.type || '';
            const catIcon = this._safeIcon(tx.category_icon, type === 'transfer' ? 'arrows-left-right' : 'tag');
            const catColor = this._safeColor(tx.category_color, type === 'transfer' ? '#6366f1' : '#64748b');
            const hasKarobar = tx.karobar_id && (tx.karobar_type === 'lent' || tx.karobar_type === 'borrowed');

            let accountDisplay = tx.account_name || '';
            if (type === 'transfer' && tx.from_account_name && tx.to_account_name) {
                accountDisplay = `${tx.from_account_name} → ${tx.to_account_name}`;
            }

            const row = document.createElement('tr');
            if (tx.entry_source !== 'karobar') row.dataset.txId = tx.id;

            // Get icon for type badge
            let typeIcon = '';
            if (type === 'income') typeIcon = '<i class="bi bi-arrow-down-circle"></i>';
            else if (type === 'expense') typeIcon = '<i class="bi bi-arrow-up-circle"></i>';
            else if (type === 'transfer') typeIcon = '<i class="bi bi-arrow-left-right"></i>';
            else if (type === 'opening') typeIcon = '<i class="bi bi-calculator"></i>';

            row.innerHTML = `
                <td data-order="${this._h(tx.date || '')}"><span class="ldg-date">${this._h(Formatters.date(tx.date))}</span></td>
                <td><span class="ldg-badge ${type}">${typeIcon} ${type.charAt(0).toUpperCase() + type.slice(1)}</span></td>
                <td>
                    <span class="ldg-desc" title="${this._h(tx.description || '')}">${this._h(tx.description || '—')}</span>
                    ${tx.entry_source === 'karobar' || hasKarobar ? `<a href="index.html#karobar-transactions" class="ldg-karobar-link"><i class="bi bi-link-45deg"></i> ${this._h(tx.person_name || 'Karobar')}</a>` : ''}
                </td>
                <td>
                    <div class="ldg-cat">
                        <span class="ldg-cat-icon" style="background:${catColor}"><i class="bi bi-${catIcon}"></i></span>
                        <span class="ldg-cat-name">${this._h(tx.category_name || (type === 'transfer' ? 'Transfer' : '—'))}</span>
                    </div>
                </td>
                <td><span class="ldg-acct">${this._h(accountDisplay || '—')}</span></td>
                <td class="ldg-amount ${type}" data-order="${tx.cash_delta ?? amount}">${(tx.cash_delta ?? (type === 'expense' ? -amount : amount)) < 0 ? '-' : ''}${Formatters.currency(amount)}</td>
                <td class="ldg-balance ${rb >= 0 ? 'positive' : 'negative'}" data-order="${rb}">${Formatters.currency(rb)}</td>
            `;
            if (tx.entry_source !== 'karobar') row.addEventListener('click', () => this.showDetail(tx.id));
            tbody.appendChild(row);
        });

        // Init DataTable with sticky header
        this.dataTable = $('#ledger-table').DataTable({
            paging: false,
            order: [],
            ordering: false,
            scrollY: '55vh',
            scrollCollapse: true,
            dom: 'rt',
            language: {
                search: '',
                searchPlaceholder: 'Search table...',
                lengthMenu: '_MENU_',
                info: '_START_ – _END_ of _TOTAL_',
                infoEmpty: 'No entries',
                infoFiltered: '(filtered from _MAX_)',
                zeroRecords: 'No matching transactions found',
                paginate: {
                    first: '<i class="bi bi-chevron-double-left"></i>',
                    last: '<i class="bi bi-chevron-double-right"></i>',
                    next: '<i class="bi bi-chevron-right"></i>',
                    previous: '<i class="bi bi-chevron-left"></i>'
                }
            },
            drawCallback: () => {
                document.querySelectorAll('#ledger-table tbody tr[data-tx-id]').forEach(row => {
                    row.removeEventListener('click', this._detailHandler);
                    this._detailHandler = () => this.showDetail(row.dataset.txId);
                    row.addEventListener('click', this._detailHandler);
                });
            }
        });
        this.renderPagination();
    }

    renderPagination() {
        const info=document.getElementById('ledger-page-info');const previous=document.getElementById('ledger-page-prev');const next=document.getElementById('ledger-page-next');const p=this.pagination||{};
        if(info)info.textContent=`Page ${p.page||1} of ${p.total_pages||0} · ${(p.total_rows||0).toLocaleString('en-IN')} entries`;
        if(previous)previous.disabled=!p.has_previous;if(next)next.disabled=!p.has_next;
    }

    goToPage(page) { if(page<1||this.isLoading)return;this.currentPage=page;this.loadLedger(); }

    applyFilters() {
        this.currentPage = 1;
        this.loadLedger();
    }

    clearFilters() {
        ['ledger-type', 'ledger-category', 'ledger-account', 'ledger-date-start', 'ledger-date-end', 'ledger-search'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
        this.applyFilters();
    }

    async showDetail(txId) {
        try {
            const res = await this.api.get(`/transactions/${txId}`);
            if (!res.success) throw new Error(res.message);
            const tx = res.data;
            this.renderDetailModal(tx);
        } catch (e) {
            console.error('Failed to load transaction detail:', e);
            if (window.Swal) {
                Swal.fire({ icon: 'error', title: 'Error', text: e.message, confirmButtonColor: '#EF4444' });
            }
        }
    }

    renderDetailModal(tx) {
        const container = document.getElementById('ledger-detail-container');
        if (!container) return;

        const typeLabel = tx.type || '';
        const amount = parseFloat(tx.amount) || 0;
        const catColor = this._safeColor(tx.category_color, typeLabel === 'transfer' ? '#6366f1' : '#64748b');
        const catIcon = this._safeIcon(tx.category_icon, typeLabel === 'transfer' ? 'arrows-left-right' : 'tag');

        let accountDisplay = tx.account_name || '';
        if (typeLabel === 'transfer' && tx.from_account_name && tx.to_account_name) {
            accountDisplay = `${tx.from_account_name} → ${tx.to_account_name}`;
        }

        let karobarHtml = '';
        if (tx.karobar_id) {
            karobarHtml = `
                <div class="ledger-detail-item">
                    <div class="detail-label">Karobar Link</div>
                    <div class="detail-value" style="color:var(--sanie-secondary);font-size:0.85rem;">
                        ${this._h(tx.karobar_type || 'Linked')} — ${this._h(tx.person_name || 'N/A')}
                    </div>
                </div>
            `;
        }

        container.innerHTML = `
            <div class="modal fade ledger-detail-modal" id="txDetailModal" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <span class="ldg-badge ${typeLabel}">
                                    <i class="bi ${typeLabel === 'income' ? 'bi-arrow-down-circle' : typeLabel === 'expense' ? 'bi-arrow-up-circle' : 'bi-arrow-left-right'}"></i>
                                    ${typeLabel.charAt(0).toUpperCase() + typeLabel.slice(1)}
                                </span>
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="ledger-detail-grid">
                                <div class="ledger-detail-item">
                                    <div class="detail-label">Amount</div>
                                    <div class="detail-value ${typeLabel}">${Formatters.currency(amount)}</div>
                                </div>
                                <div class="ledger-detail-item">
                                    <div class="detail-label">Date</div>
                                    <div class="detail-value">${Formatters.date(tx.date)}</div>
                                </div>
                                <div class="ledger-detail-item">
                                    <div class="detail-label">Category</div>
                                    <div class="detail-value">
                                        <span class="ldg-cat">
                                            <span class="ldg-cat-icon" style="background:${catColor}"><i class="bi bi-${catIcon}"></i></span>
                                             <span class="ldg-cat-name">${this._h(tx.category_name || (typeLabel === 'transfer' ? 'Transfer' : '-'))}</span>
                                        </span>
                                    </div>
                                </div>
                                ${tx.subcategory_name ? `
                                <div class="ledger-detail-item">
                                    <div class="detail-label">Subcategory</div>
                                    <div class="detail-value">${this._h(tx.subcategory_name)}</div>
                                </div>` : ''}
                                <div class="ledger-detail-item">
                                    <div class="detail-label">Account</div>
                                    <div class="detail-value" style="font-size:0.85rem;">${this._h(accountDisplay || '-')}</div>
                                </div>
                                ${tx.payment_method ? `
                                <div class="ledger-detail-item">
                                    <div class="detail-label">Payment Method</div>
                                    <div class="detail-value" style="font-size:0.85rem;text-transform:capitalize;">${this._h(tx.payment_method)}</div>
                                </div>` : ''}
                                ${karobarHtml}
                                <div class="ledger-detail-item" style="grid-column:1/-1;">
                                    <div class="detail-label">Description</div>
                                    <div class="detail-value" style="font-size:0.85rem;font-weight:400;">${this._h(tx.description || 'No description')}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        const modal = new bootstrap.Modal(document.getElementById('txDetailModal'));
        modal.show();

        document.getElementById('txDetailModal').addEventListener('hidden.bs.modal', () => {
            container.innerHTML = '';
        }, { once: true });
    }

    async exportCSV() {
        try {
            const headers = ['Date', 'Type', 'Description', 'Category', 'Account', 'Amount', 'Running Balance'];
            const all = [];
            const expected = Number(this.pagination?.total_rows || 0);
            const totalPages = Math.ceil(expected / 100);
            const activeFilters = { ...this.filters };
            for (let page = 1; page <= totalPages; page++) {
                const params = new URLSearchParams();
                Object.entries(activeFilters).forEach(([key, value]) => { if (value) params.set(key, value); });
                params.set('page', page);
                params.set('limit', '100');
                const response = await this.api.get('/ledger?' + params.toString());
                if (!response.success) throw new Error(response.message || 'Could not load complete ledger export');
                all.push(...(response.data.transactions || []));
            }
            if (all.length !== expected) throw new Error('Ledger export was incomplete');
            const rows = all.map(tx => {
                const amount = parseFloat(tx.amount) || 0;
                const sign = (tx.cash_delta ?? (tx.type === 'expense' ? -amount : amount)) < 0 ? '-' : '';
                return [
                    tx.date || '',
                    tx.type || '',
                    tx.description || '',
                    tx.category_name || '',
                    tx.account_name || '',
                    `${sign}${amount.toFixed(2)}`,
                    (parseFloat(tx.running_balance) || 0).toFixed(2)
                ];
            });
            const csv = CSVUtils.document(headers, rows, new Set([1, 2, 3, 4]));
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = `ledger_${DateUtils.getKathmanduDateString()}.csv`;
            link.click();
            URL.revokeObjectURL(link.href);
            window.NotificationService?.success(`${expected} matching ledger rows exported`);
        } catch (error) {
            window.NotificationService?.error(error.message || 'Ledger export failed');
        }
    }
}

// Init on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    window.ledgerManager = new LedgerManager();
    window.ledgerManager.init();
});
