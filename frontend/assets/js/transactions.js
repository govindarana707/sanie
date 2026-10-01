class TransactionsManager {
    constructor() {
        this.transactions = [];
        this.filters = {};
        this._listeners = {};
        this._mounted = false;
        this._editingTransactionId = null;
        this._editingTransactionData = null;
        this._ordinaryRequestId = null;
        this._transferRequestId = null;
        this.dataTable = null;
        this.selectedIds = new Set();
        this.currentPage = 1;
        this.pageSize = 50;
        this.pagination = null;
        this.authoritativeSummary = null;
        this._formEpoch = 0;
        this._creditorsLoad = null;
    }

    onMount() {
        if (this._mounted) return;
        this._mounted = true;
        this._bindAddButton();
        this._bindDocumentEvents();
        this._bindBulkActions();
        this._bindAccountFilter();
        this._bindScopeFilter();
        this._bindImportExport();
        this._listeners._pendingChanged = event => {
            const userId = window.authManager?.getCurrentUser()?.id;
            if (String(event.detail?.userId) === String(userId)) this._refreshPendingRepresentations();
        };
        window.addEventListener('offline:pending-changed', this._listeners._pendingChanged);
        this._listeners._remotePendingChanged = () => this._loadOfflineSnapshot();
        window.addEventListener('sync:remote-queue-changed', this._listeners._remotePendingChanged);
        if (window.authManager?.isAuthenticated()) {
            this.loadTransactions();
        }
    }

    onUnmount() {
        this._mounted = false;
        this._destroyDataTable();
        this._unbindAddButton();
        this._unbindDocumentEvents();
        this._unbindBulkActions();
        document.getElementById('tx-clear-account-filter')?.removeEventListener('click', this._listeners._clearAccountFilter);
        document.getElementById('tx-scope-filter')?.removeEventListener('change', this._listeners._scopeChange);
        document.getElementById('export-btn')?.removeEventListener('click', this._listeners._exportClick);
        document.getElementById('import-btn')?.removeEventListener('click', this._listeners._importClick);
        this._unbindMobilePagination();
        this._unbindMobileActions();
        if (this._listeners._pendingChanged) {
            window.removeEventListener('offline:pending-changed', this._listeners._pendingChanged);
            this._listeners._pendingChanged = null;
        }
        if (this._listeners._remotePendingChanged) {
            window.removeEventListener('sync:remote-queue-changed', this._listeners._remotePendingChanged);
            this._listeners._remotePendingChanged = null;
        }
    }

    /* ==================== BINDING HELPERS ==================== */

    _bindAddButton() {
        const btn = document.getElementById('add-transaction-btn');
        if (!btn) return;
        this._listeners._addClick = () => this._openForm();
        btn.removeEventListener('click', this._listeners._addClick);
        btn.addEventListener('click', this._listeners._addClick);

        const reportBtn = document.getElementById('transaction-report-btn');
        this._listeners._reportClick = () => this.showReportModal();
        reportBtn?.addEventListener('click', this._listeners._reportClick);
    }

    _unbindAddButton() {
        const btn = document.getElementById('add-transaction-btn');
        if (btn && this._listeners._addClick) {
            btn.removeEventListener('click', this._listeners._addClick);
        }
        document.getElementById('transaction-report-btn')?.removeEventListener('click', this._listeners._reportClick);
    }

    /* ==================== TRANSACTION REPORT ==================== */

    showReportModal() {
        const today = DateUtils.getKathmanduDateString();
        const bodyHTML = `
            <div class="mb-3">
                <label class="form-label fw-semibold" for="tx-report-period">Report Period</label>
                <select class="form-select" id="tx-report-period">
                    <option value="week">Weekly</option>
                    <option value="month" selected>Monthly</option>
                    <option value="year">Yearly</option>
                    <option value="custom">Custom</option>
                    <option value="lifetime">Lifetime</option>
                </select>
            </div>
            <div class="row g-3 d-none" id="tx-report-custom-dates">
                <div class="col-sm-6"><label class="form-label" for="tx-report-start">Start Date</label><input class="form-control" type="date" id="tx-report-start" value="${today}"></div>
                <div class="col-sm-6"><label class="form-label" for="tx-report-end">End Date</label><input class="form-control" type="date" id="tx-report-end" value="${today}"></div>
            </div>`;

        window.modalService?.open({
            title: 'Transaction Report',
            subtitle: 'Choose a period to preview your printable report.',
            icon: 'fa-file-pdf',
            bodyHTML,
            saveText: '<i class="bi bi-eye me-1"></i> Preview Report',
            onSave: () => this._openReportPreview()
        });

        document.getElementById('tx-report-period')?.addEventListener('change', e => {
            document.getElementById('tx-report-custom-dates')?.classList.toggle('d-none', e.target.value !== 'custom');
        });
    }

    _reportRange(period) {
        if (['week', 'month', 'year'].includes(period)) {
            const range = DateUtils.getKathmanduRange(period);
            const labels = { week: 'Weekly', month: 'Monthly', year: 'Yearly' };
            return { ...range, label: labels[period] };
        }
        if (period === 'custom') {
            return { start: document.getElementById('tx-report-start')?.value, end: document.getElementById('tx-report-end')?.value, label: 'Custom' };
        }
        return { start: '', end: '', label: 'Lifetime' };
    }

    async _openReportPreview() {
        const period = document.getElementById('tx-report-period')?.value || 'month';
        const range = this._reportRange(period);
        if (period === 'custom' && (!range.start || !range.end || range.start > range.end)) {
            this._notify('error', 'Please select a valid custom date range');
            return;
        }

        const preview = window.open('', '_blank', 'width=1050,height=760');
        if (!preview) {
            this._notify('error', 'Please allow pop-ups to open the report preview');
            return;
        }
        preview.document.write('<!doctype html><title>Preparing report...</title><p style="font:16px Arial;padding:32px">Preparing transaction report...</p>');
        preview.document.close();

        try {
            const params = new URLSearchParams();
            if (range.start) params.set('start_date', range.start);
            if (range.end) params.set('end_date', range.end);
            params.set('scope', this.filters.scope || 'all');
            let previousEnd = '';
            if (range.start) {
                previousEnd = DateUtils.addCalendarDays(range.start, -1);
            }
            const [reportData, accountsRes, previousData] = await Promise.all([
                this._allAuthoritativeTransactions(Object.fromEntries(params)),
                window.Api.get('/accounts'),
                previousEnd ? this._transactionPage({ end_date: previousEnd, scope: this.filters.scope || 'all' }, 1, 1) : Promise.resolve({ transactions: [], summary: { total_income: 0, total_expense: 0 } })
            ]);
            if (!accountsRes?.success) throw new Error(accountsRes?.message || 'Could not load report data');

            const baseOpening = (accountsRes.data || [])
                .filter(a => String(a.is_active) !== '0')
                .reduce((sum, a) => sum + (parseFloat(a.opening_balance) || 0), 0);
            const activityBeforePeriod = (parseFloat(previousData.summary?.total_income) || 0) - (parseFloat(previousData.summary?.total_expense) || 0);
            this._renderReportPreview(preview, reportData.transactions, range, baseOpening + activityBeforePeriod, reportData.summary);
            window.modalService?.close();
        } catch (error) {
            preview.document.open();
            preview.document.write('<!doctype html><title>Report Error</title><p style="font:16px Arial;padding:32px">Unable to load the transaction report.</p>');
            preview.document.close();
            this._notify('error', error.message || 'Failed to create report');
        }
    }

    _renderReportPreview(preview, transactions, range, openingBalance = 0, summary = {}) {
        const income = parseFloat(summary.total_income) || 0;
        const expense = parseFloat(summary.total_expense) || 0;
        const netFlow = income - expense;
        const closingBalance = openingBalance + netFlow;
        const money = n => 'Rs ' + Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const periodText = range.start ? `${range.start} to ${range.end}` : 'All transactions';
        const rows = transactions.map(t => `<tr><td class="date">${this._h(t.date || '')}</td><td>${this._h(t.scope === 'karobar' ? 'Karobar' : 'Personal')}</td><td><span class="type ${this._h(t.type)}">${this._h((t.type || '').toUpperCase())}</span></td><td>${this._h(t.person_name || t.category_name || (t.type === 'transfer' ? 'Transfer' : 'Uncategorized'))}</td><td>${this._h(t.account_name || t.from_account_name || '--')}</td><td class="description">${this._h(t.description || '--')}</td><td class="amount ${this._h(t.type)}">${t.type === 'expense' ? '-' : (t.type === 'income' ? '+' : '')}${money(t.amount)}</td></tr>`).join('');

        preview.document.open();
        preview.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>SanIE ${range.label} Transaction Report</title><style>
            *{box-sizing:border-box}body{font-family:Inter,Arial,sans-serif;color:#172033;margin:0;background:linear-gradient(135deg,#e8f8f1,#eef2f7)}.sheet{max-width:1080px;margin:24px auto;background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 18px 55px #0f172a24}.content{padding:30px 34px}.toolbar{display:flex;justify-content:flex-end;margin-bottom:18px}.print-btn{border:0;border-radius:10px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;padding:12px 20px;font-weight:700;cursor:pointer;box-shadow:0 6px 18px #10b98140}.hero{display:flex;justify-content:space-between;align-items:center;padding:28px 34px;color:#fff;background:linear-gradient(125deg,#052e2b,#047857 60%,#10b981)}.brand{font-size:30px;font-weight:900;letter-spacing:-1px}.brand-sub{opacity:.8;font-size:12px;letter-spacing:1.5px;text-transform:uppercase}.report-meta{text-align:right}.report-meta strong{font-size:20px}.report-meta div{margin-top:5px;opacity:.82}.summary{display:grid;grid-template-columns:repeat(3,1fr);gap:13px;margin:0 0 26px}.box{position:relative;padding:16px;border:1px solid #e2e8f0;border-radius:13px;background:#f8fafc;overflow:hidden}.box:before{content:'';position:absolute;inset:0 auto 0 0;width:4px;background:#94a3b8}.box.green:before{background:#10b981}.box.red:before{background:#ef4444}.box.blue:before{background:#3b82f6}.box.purple:before{background:#8b5cf6}.box.orange:before{background:#f59e0b}.box span{display:block;color:#64748b;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:7px}.box strong{font-size:18px}.income{color:#059669}.expense{color:#dc2626}.section-title{font-size:15px;margin:0 0 10px;color:#334155}table{width:100%;border-collapse:separate;border-spacing:0;font-size:12px;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden}th,td{padding:11px 10px;border-bottom:1px solid #e2e8f0;text-align:left}th{background:#ecfdf5;color:#065f46;font-size:10px;text-transform:uppercase;letter-spacing:.45px}tbody tr:nth-child(even){background:#f8fafc}tbody tr:last-child td{border-bottom:0}.date{white-space:nowrap}.description{color:#64748b}.type{display:inline-block;padding:4px 7px;border-radius:999px;font-size:9px;font-weight:800;background:#e2e8f0}.type.income{background:#d1fae5;color:#047857}.type.expense{background:#fee2e2;color:#b91c1c}.type.transfer{background:#dbeafe;color:#1d4ed8}.amount{text-align:right;font-weight:800;white-space:nowrap}.amount.transfer{color:#2563eb}.empty{text-align:center;color:#64748b;padding:36px}.footer{padding:16px 34px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:10px;color:#94a3b8;display:flex;justify-content:space-between}@media(max-width:700px){.sheet{margin:0;border-radius:0}.hero,.content{padding:20px}.hero{display:block}.report-meta{text-align:left;margin-top:15px}.summary{grid-template-columns:1fr 1fr}table{font-size:10px}}@media print{body{background:#fff}.sheet{box-shadow:none;margin:0;max-width:none;border-radius:0}.toolbar{display:none}.hero{-webkit-print-color-adjust:exact;print-color-adjust:exact}.box,th,.type{print-color-adjust:exact;-webkit-print-color-adjust:exact}@page{size:A4 landscape;margin:10mm}}
        </style></head><body><main class="sheet"><header class="hero"><div><div class="brand">SanIE</div><div class="brand-sub">Personal Finance Intelligence</div></div><div class="report-meta"><strong>${this._h(range.label)} Transaction Report</strong><div>${this._h(periodText)}</div></div></header><div class="content"><div class="toolbar"><button class="print-btn" id="print-report">Print / Save PDF</button></div><section class="summary"><div class="box purple"><span>Opening Balance</span><strong>${money(openingBalance)}</strong></div><div class="box green"><span>Total Income</span><strong class="income">${money(income)}</strong></div><div class="box red"><span>Total Expense</span><strong class="expense">${money(expense)}</strong></div><div class="box blue"><span>Net Cash Flow</span><strong>${money(netFlow)}</strong></div><div class="box orange"><span>Closing Balance</span><strong>${money(closingBalance)}</strong></div><div class="box"><span>Transactions</span><strong>${Number(summary.transaction_count || transactions.length)}</strong></div></section><h2 class="section-title">Transaction Details</h2><table><thead><tr><th>Date</th><th>Scope</th><th>Type</th><th>Category / Person</th><th>Account</th><th>Description</th><th style="text-align:right">Amount</th></tr></thead><tbody>${rows || '<tr><td colspan="7" class="empty">No transactions found for this period.</td></tr>'}</tbody></table></div><footer class="footer"><span>Generated by SanIE</span><span>${new Date().toLocaleString()}</span></footer></main></body></html>`);
        preview.document.close();
        preview.document.getElementById('print-report')?.addEventListener('click', () => preview.print());
    }

    _bindDocumentEvents() {
        this._listeners._typeChange = (e) => {
            if (e.target.id === 'transaction-type') {
                this._loadCategoriesByType(e.target.value);
                this._togglePaymentSection(e.target.value);
            }
        };
        document.addEventListener('change', this._listeners._typeChange);
    }

    _unbindDocumentEvents() {
        if (this._listeners._typeChange) {
            document.removeEventListener('change', this._listeners._typeChange);
        }
    }

    _bindBulkActions() {
        this._listeners._selectionChange = (e) => {
            if (e.target.id === 'tx-select-all') {
                const checked = e.target.checked;
                document.querySelectorAll('.tx-select-row:not(:disabled)').forEach(box => {
                    box.checked = checked;
                    const id = Number(box.dataset.txSelect);
                    checked ? this.selectedIds.add(id) : this.selectedIds.delete(id);
                });
                this._updateBulkActions();
            } else if (e.target.matches('.tx-select-row')) {
                const id = Number(e.target.dataset.txSelect);
                e.target.checked ? this.selectedIds.add(id) : this.selectedIds.delete(id);
                this._syncSelectionCheckboxes(id, e.target.checked);
                this._updateBulkActions();
            }
        };
        document.addEventListener('change', this._listeners._selectionChange);
        const button = document.getElementById('tx-bulk-delete-btn');
        this._listeners._bulkDelete = () => this.bulkDeleteSelected();
        button?.addEventListener('click', this._listeners._bulkDelete);
    }

    _unbindBulkActions() {
        if (this._listeners._selectionChange) document.removeEventListener('change', this._listeners._selectionChange);
        document.getElementById('tx-bulk-delete-btn')?.removeEventListener('click', this._listeners._bulkDelete);
    }

    _bindAccountFilter() {
        const button = document.getElementById('tx-clear-account-filter');
        this._listeners._clearAccountFilter = () => {
            delete this.filters.account_id;
            this.currentPage = 1;
            this._renderAccountFilter();
            this.loadTransactions();
        };
        button?.addEventListener('click', this._listeners._clearAccountFilter);
        this._renderAccountFilter();
    }

    _bindScopeFilter() {
        const select = document.getElementById('tx-scope-filter');
        if (!select) return;
        select.value = this.filters.scope || 'all';
        this._listeners._scopeChange = () => {
            const scope = select.value;
            this.filters = { ...this.filters };
            if (scope === 'all') delete this.filters.scope;
            else this.filters.scope = scope;
            this.currentPage = 1;
            this.loadTransactions(1);
        };
        select.addEventListener('change', this._listeners._scopeChange);
    }

    _renderAccountFilter() {
        const banner = document.getElementById('tx-account-filter-banner');
        if (banner) {
            banner.classList.toggle('d-none', !this.filters.account_id);
            banner.classList.toggle('d-flex', !!this.filters.account_id);
        }
    }

    _bindImportExport() {
        this._listeners._exportClick = () => this.exportCSV();
        this._listeners._importClick = () => this.showImportModal();
        document.getElementById('export-btn')?.addEventListener('click', this._listeners._exportClick);
        document.getElementById('import-btn')?.addEventListener('click', this._listeners._importClick);
    }

    async exportCSV() {
        try {
            const result = await this._allAuthoritativeTransactions(this.filters);
            const headers = ['Date', 'Scope', 'Type', 'Karobar Person', 'Karobar Type', 'Amount', 'Account', 'From Account', 'To Account', 'Category', 'Subcategory', 'Payment Method', 'Description', 'Recurring Origin', 'Scheduled Occurrence Date'];
            const rows = result.transactions.map(t => [
                t.date, t.scope === 'karobar' ? 'Karobar' : 'Personal', t.type, t.person_name, t.karobar_type,
                t.amount, t.account_name, t.from_account_name, t.to_account_name,
                t.category_name, t.subcategory_name, t.payment_method, t.description,
                t.recurring_definition_id ? 'Yes' : 'No', t.recurring_occurrence_date
            ]);
            const csv = '\uFEFF' + [headers, ...rows].map(row => row.map(value => this._csvCell(value)).join(',')).join('\r\n');
            const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
            const link = document.createElement('a');
            link.href = url;
            link.download = `SanIE_Transactions_${DateUtils.getKathmanduDateString()}.csv`;
            link.click();
            URL.revokeObjectURL(url);
            window.NotificationService?.success(`${result.summary.transaction_count || 0} matching transactions exported`);
        } catch (error) {
            this._notify('error', error.message || 'Transaction export failed');
        }
    }

    _csvCell(value) {
        return CSVUtils.cell(value);
    }

    async _transactionPage(filters = {}, page = 1, limit = 200) {
        const params = new URLSearchParams();
        Object.entries(filters || {}).forEach(([key, value]) => {
            if (value !== '' && value !== null && value !== undefined) params.set(key, String(value));
        });
        params.set('page', String(page));
        params.set('limit', String(limit));
        const response = await window.Api.get('/transactions/query?' + params.toString());
        if (!response?.success) throw new Error(response?.message || 'Could not load authoritative transaction data');
        return response.data;
    }

    async _allAuthoritativeTransactions(filters = {}) {
        const first = await this._transactionPage(filters, 1, 200);
        const transactions = [...(first.transactions || [])];
        const totalPages = Number(first.pagination?.total_pages || 0);
        for (let page = 2; page <= totalPages; page++) {
            const next = await this._transactionPage(filters, page, 200);
            transactions.push(...(next.transactions || []));
        }
        return { transactions, summary: first.summary || {}, pagination: first.pagination || {} };
    }

    showImportModal() {
        this._importRows = [];
        this._importText = '';
        this._importBatchIdentity = '';
        this._importLastResult = null;
        const bodyHTML = `
            <div class="alert alert-info small"><strong>CSV columns:</strong> Date, Type, Amount, Account, From Account, To Account, Category, Subcategory, Payment Method, Description</div>
            <input type="file" class="form-control" id="tx-import-file" accept=".csv,text/csv">
            <div class="d-flex justify-content-between align-items-center mt-3">
                <small class="text-muted" id="tx-import-status">Choose a CSV file to preview.</small>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-danger d-none" id="tx-import-failures"><i class="bi bi-file-earmark-arrow-down me-1"></i>Failed rows</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="tx-import-template"><i class="bi bi-download me-1"></i>Template</button>
                </div>
            </div>
            <div class="mt-3 d-none" id="tx-import-summary"></div>
            <div class="table-responsive mt-3 d-none" id="tx-import-preview" style="max-height:380px"><table class="table table-sm align-middle"><thead class="sticky-top bg-white"><tr><th>Row</th><th>Status</th><th>Type</th><th>Date</th><th>Amount</th><th>Mapping</th><th>Reason</th></tr></thead><tbody></tbody></table></div>`;
        window.modalService?.open({
            title: 'Import Transactions',
            subtitle: 'Review your CSV before importing.',
            icon: 'fa-file-import',
            bodyHTML,
            saveText: '<i class="bi bi-upload me-1"></i> Import Transactions',
            onSave: () => this.importCSV()
        });
        document.getElementById('tx-import-file')?.addEventListener('change', e => this._readImportFile(e.target.files?.[0]));
        document.getElementById('tx-import-template')?.addEventListener('click', () => this.downloadImportTemplate());
        document.getElementById('tx-import-failures')?.addEventListener('click', () => this.downloadImportFailures());
    }

    async _csvFingerprint(text) {
        if (!window.crypto?.subtle || typeof TextEncoder === 'undefined') {
            throw new Error('Secure file fingerprinting is unavailable in this browser. Use HTTPS or localhost to import safely.');
        }
        const digest = await window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));
        return [...new Uint8Array(digest)].map(byte => byte.toString(16).padStart(2, '0')).join('');
    }

    async _readImportFile(file) {
        if (!file) return;
        const status = document.getElementById('tx-import-status');
        const saveButton = document.getElementById('modal-save-btn');
        if (saveButton) saveButton.disabled = true;
        try {
            if (file.size > 2097152) throw new Error('CSV file exceeds the 2 MB import limit');
            if (status) status.textContent = 'Fingerprinting and validating CSV…';
            const text = await file.text();
            const batchIdentity = await this._csvFingerprint(text);
            const response = await window.Api.request('/transactions/import/preview', {
                method: 'POST', body: JSON.stringify({ csv_content: text, batch_identity: batchIdentity }), timeoutMs: 30000
            });
            if (!response?.success) throw new Error(response?.message || 'CSV preview failed');
            this._importText = text;
            this._importBatchIdentity = batchIdentity;
            this._importRows = response.data.rows || [];
            this._importLastResult = null;
            this._renderImportRows(this._importRows);
            this._renderImportSummary(response.data.counts, true);
            const ready = Number(response.data.counts?.ready || 0);
            if (status) status.textContent = `${response.data.counts?.total_rows || 0} row(s) validated · batch ${batchIdentity.slice(0, 12)}…`;
            if (saveButton) saveButton.disabled = ready === 0;
        } catch (error) {
            this._importRows = [];
            this._importText = '';
            this._importBatchIdentity = '';
            if (status) status.textContent = error.message;
            this._notify('error', error.message);
            document.getElementById('tx-import-preview')?.classList.add('d-none');
            document.getElementById('tx-import-summary')?.classList.add('d-none');
        } finally {
            if (saveButton && this._importRows.some(row => row.status === 'ready')) saveButton.disabled = false;
        }
    }

    async importCSV() {
        if (!this._importText || !this._importBatchIdentity) { this._notify('error', 'Please choose and validate a CSV file'); return; }
        const saveButton = document.getElementById('modal-save-btn');
        if (saveButton) saveButton.disabled = true;
        const status = document.getElementById('tx-import-status');
        if (status) status.textContent = 'Importing ready rows through secure accounting…';
        try {
            const response = await window.Api.request('/transactions/import', {
                method: 'POST',
                body: JSON.stringify({ csv_content: this._importText, batch_identity: this._importBatchIdentity }),
                timeoutMs: 120000
            });
            if (!response?.success) throw new Error(response?.message || 'CSV import failed');
            this._importLastResult = response.data;
            this._importRows = response.data.rows || [];
            this._renderImportRows(this._importRows);
            this._renderImportSummary(response.data.counts, false);
            const counts = response.data.counts || {};
            const unresolved = Number(counts.failed || 0) + Number(counts.unsupported || 0);
            if (status) status.textContent = `Batch ${this._importBatchIdentity.slice(0, 12)}… processed. Re-selecting the unchanged file is always safe.`;
            if (Number(counts.imported || 0) > 0) {
                await this.loadTransactions();
                document.dispatchEvent(new CustomEvent('app:data-changed'));
            }
            if (unresolved) window.NotificationService?.warning(`CSV import partially completed: ${counts.imported || 0} new, ${counts.already_imported || 0} already imported, ${unresolved} unresolved.`);
            else window.NotificationService?.success(`CSV import complete: ${counts.imported || 0} new, ${counts.already_imported || 0} already imported.`);
            const failureButton = document.getElementById('tx-import-failures');
            failureButton?.classList.toggle('d-none', unresolved === 0);
        } catch (error) {
            if (status) status.textContent = error.message || 'CSV import request failed. Retry the unchanged file safely.';
            this._notify('error', error.message || 'CSV import failed');
        } finally {
            if (saveButton) saveButton.disabled = false;
        }
    }

    _renderImportSummary(counts = {}, preview = false) {
        const summary = document.getElementById('tx-import-summary');
        if (!summary) return;
        summary.classList.remove('d-none');
        const items = preview
            ? [['Total', counts.total_rows], ['Ready', counts.ready], ['Invalid', counts.invalid], ['Unsupported', counts.unsupported]]
            : [['Total', counts.total_rows], ['New', counts.imported], ['Already imported', counts.already_imported], ['Failed', counts.failed], ['Unsupported', counts.unsupported]];
        summary.innerHTML = `<div class="row g-2">${items.map(([label, value]) => `<div class="col"><div class="border rounded p-2 text-center"><strong class="d-block">${Number(value || 0)}</strong><small class="text-muted">${label}</small></div></div>`).join('')}</div>`;
    }

    _renderImportRows(rows) {
        const preview = document.getElementById('tx-import-preview');
        const tbody = preview?.querySelector('tbody');
        if (!preview || !tbody) return;
        preview.classList.remove('d-none');
        const badge = status => ({ ready: 'success', imported: 'success', already_imported: 'info', invalid: 'danger', failed: 'danger', unsupported: 'warning' }[status] || 'secondary');
        tbody.innerHTML = rows.map(row => {
            const normalized = row.normalized || {};
            const mapping = normalized.type === 'transfer'
                ? `${normalized.from_account || ''} → ${normalized.to_account || ''}`
                : [normalized.account, normalized.category, normalized.subcategory].filter(Boolean).join(' / ');
            return `<tr><td>${Number(row.source_row_number || 0)}</td><td><span class="badge bg-${badge(row.status)}">${this._h(row.status)}</span></td><td>${this._h(normalized.type || row.original?.type || '')}</td><td>${this._h(normalized.date || row.original?.date || '')}</td><td>${this._h(normalized.amount || row.original?.amount || '')}</td><td>${this._h(mapping)}</td><td class="small text-danger">${this._h(row.reason || '')}</td></tr>`;
        }).join('');
    }

    downloadImportFailures() {
        const rows = (this._importLastResult?.rows || this._importRows || []).filter(row => ['failed', 'invalid', 'unsupported'].includes(row.status));
        if (!rows.length) { this._notify('error', 'There are no failed rows to export'); return; }
        const originalHeaders = [...new Set(rows.flatMap(row => Object.keys(row.original || {})))];
        const headers = ['Source Row', 'Status', 'Error Category', 'Field', 'Failure Reason', ...originalHeaders];
        const csvRows = rows.map(row => [row.source_row_number, row.status, row.error_category, row.field, row.reason, ...originalHeaders.map(header => row.original?.[header] || '')]);
        const csv = '\uFEFF' + [headers, ...csvRows].map(row => row.map(value => this._csvCell(value)).join(',')).join('\r\n');
        const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = `SanIE_Import_Failures_${this._importBatchIdentity.slice(0, 12)}.csv`;
        link.click();
        URL.revokeObjectURL(url);
    }

    downloadImportTemplate() {
        const csv = 'Date,Type,Amount,Account,From Account,To Account,Category,Subcategory,Payment Method,Description\n2026-08-10,expense,500,Cash,,,Food,Groceries,cash,Sample expense\n2026-08-10,transfer,1000,,Cash,Bank Account,,,,Sample transfer';
        const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
        const link = document.createElement('a'); link.href = url; link.download = 'SanIE_Transaction_Import_Template.csv'; link.click(); URL.revokeObjectURL(url);
    }

    _syncSelectionCheckboxes(id, checked) {
        document.querySelectorAll(`.tx-select-row[data-tx-select="${id}"]`).forEach(box => { box.checked = checked; });
    }

    _updateBulkActions() {
        const bar = document.getElementById('tx-bulk-actions');
        const count = document.getElementById('tx-selected-count');
        if (count) count.textContent = this.selectedIds.size;
        if (bar) {
            bar.classList.toggle('d-none', this.selectedIds.size === 0);
            bar.classList.toggle('d-flex', this.selectedIds.size > 0);
        }
        const available = [...document.querySelectorAll('.tx-select-row:not(:disabled)')];
        const selectAll = document.getElementById('tx-select-all');
        if (selectAll) {
            const selectedVisible = available.filter(box => box.checked).length;
            selectAll.checked = available.length > 0 && selectedVisible === available.length;
            selectAll.indeterminate = selectedVisible > 0 && selectedVisible < available.length;
        }
    }

    /* ==================== DATA LOADING ==================== */

    async loadTransactions(page = this.currentPage) {
        this._renderAccountFilter();
        const scopeSelect = document.getElementById('tx-scope-filter');
        if (scopeSelect) scopeSelect.value = this.filters.scope || 'all';
        this.currentPage = Math.max(1, Number(page) || 1);
        const skeleton = document.getElementById('tx-loading-skeleton');
        const tableWrapper = document.getElementById('tx-table-wrapper');
        if (skeleton) skeleton.style.display = '';
        if (tableWrapper) tableWrapper.style.display = 'none';

        try {
            const data = await this._transactionPage(this.filters, this.currentPage, this.pageSize);
            if (Array.isArray(data.transactions)) {
                const serverTransactions = data.transactions;
                this.pagination = data.pagination || null;
                this.authoritativeSummary = data.summary || null;
                this.transactions = await this._mergePendingTransactions(serverTransactions);
                this.selectedIds.clear();
                this._updateBulkActions();
                this._renderSummary(this.authoritativeSummary);
                this._renderTable();
                this._renderServerPagination();
                this._setOfflineReadMode(false);
                this._setPendingPageStatus(this.transactions.filter(transaction => transaction._pending).length);

                const hasFilters = Object.values(this.filters).some(value => value !== '' && value !== null && value !== undefined);
                const userId = window.authManager?.getCurrentUser()?.id;
                if (!hasFilters && userId !== undefined && userId !== null) {
                    window.OfflineStorage?.saveTransactionSnapshot(userId, serverTransactions);
                }
            } else {
                throw new Error('Invalid transaction response');
            }
        } catch (err) {
            console.error('loadTransactions error:', err);
            this.pagination = null;
            this.authoritativeSummary = null;
            const authFailure = [401, 403, 419].includes(Number(err?.status));
            const usedSnapshot = authFailure ? false : await this._loadOfflineSnapshot();
            if (!usedSnapshot && !authFailure) {
                this.transactions = [];
                this.pagination = null;
                this.authoritativeSummary = null;
                this.selectedIds.clear();
                this._updateBulkActions();
                this._renderSummary();
                this._renderTable();
                this._setOfflineReadMode(true, null);
            }
            this._renderServerPagination();
        } finally {
            if (skeleton) skeleton.style.display = 'none';
            if (tableWrapper) tableWrapper.style.display = '';
        }
    }

    async _loadOfflineSnapshot() {
        const userId = window.authManager?.getCurrentUser()?.id;
        if (userId === undefined || userId === null || !window.OfflineStorage) return false;
        const snapshot = await window.OfflineStorage.getTransactionSnapshot(userId);
        let pending = (await window.OfflineStorage.getPendingActions(userId))
            .filter(action => action.status !== 'synced');
        if (this.filters.scope === 'karobar') pending = [];
        const confirmed = (Array.isArray(snapshot?.data) ? snapshot.data : []).filter(transaction => {
            if (this.filters.scope === 'karobar') return transaction.scope === 'karobar';
            if (this.filters.scope === 'personal') return transaction.scope !== 'karobar';
            return true;
        });
        if (confirmed.length === 0 && pending.length === 0 && !snapshot) return false;

        this.transactions = await this._mergePendingTransactions(confirmed);
        this.selectedIds.clear();
        this._updateBulkActions();
        this._renderSummary();
        this._renderTable();
        this._setOfflineReadMode(true, snapshot?.cachedAt || null, pending.length);
        return true;
    }

    async _mergePendingTransactions(transactions) {
        const userId = window.authManager?.getCurrentUser()?.id;
        if (userId === undefined || userId === null || !window.OfflineStorage) return transactions;
        let pending = (await window.OfflineStorage.getPendingActions(userId))
            .filter(action => action.status !== 'synced');
        if (this.filters.scope === 'karobar') pending = [];
        const touchedServerIds = new Set(
            pending.filter(action => action.serverId).map(action => String(action.serverId))
        );
        const confirmed = transactions.filter(transaction => !touchedServerIds.has(String(transaction.id)));
        return [...pending.map(action => this._pendingActionToTransaction(action)), ...confirmed];
    }

    async _refreshPendingRepresentations() {
        if (!this._mounted) return;
        const confirmed = this.transactions.filter(transaction => !transaction._pending);
        this.transactions = await this._mergePendingTransactions(confirmed);
        this._renderSummary();
        this._renderTable();
        this._setPendingPageStatus(this.transactions.filter(transaction => transaction._pending).length);
    }

    _pendingActionToTransaction(action) {
        const source = { ...(action.baseRecord || {}), ...(action.payload || {}) };
        return {
            ...source,
            id: action.action === 'create' ? action.localId : action.serverId,
            localId: action.localId,
            serverId: action.serverId,
            version: action.baseVersion || source.version || null,
            date: source.date,
            type: source.type,
            amount: source.amount,
            payment_method: source.payment_method,
            description: source.description,
            account_id: source.account_id,
            category_id: source.category_id,
            subcategory_id: source.subcategory_id,
            category_name: action.display?.category_name || 'Category',
            category_icon: action.display?.category_icon || '',
            category_color: action.display?.category_color || '#F59E0B',
            subcategory_name: action.display?.subcategory_name || '',
            account_name: action.display?.account_name || 'Account',
            scope: 'personal',
            source_table: 'transactions',
            source_id: action.serverId || null,
            _pending: true,
            pending_action: action.action,
            pending_status: action.status || 'pending',
            pending_error: action.lastError || '',
            conflict_data: action.conflictData || null
        };
    }

    _setOfflineReadMode(enabled, cachedAt = null, pendingCount = 0) {
        const page = document.getElementById('transactions-page');
        if (!page) return;
        let status = document.getElementById('transactions-offline-data-status');

        if (!enabled) {
            status?.remove();
            page.querySelectorAll('[data-offline-disabled="true"]').forEach(element => {
                element.disabled = false;
                element.removeAttribute('data-offline-disabled');
            });
            return;
        }

        document.getElementById('transactions-pending-data-status')?.remove();

        if (!status) {
            status = document.createElement('div');
            status.id = 'transactions-offline-data-status';
            status.className = 'alert alert-warning py-2 px-3 mb-3';
            status.setAttribute('role', 'status');
            page.prepend(status);
        }

        if (cachedAt) {
            const date = new Date(cachedAt);
            const timestamp = Number.isNaN(date.getTime())
                ? 'an earlier time'
                : date.toLocaleString('en-US', { dateStyle: 'medium', timeStyle: 'short' });
            const mode = navigator.onLine ? 'Saved data — server unavailable' : 'Offline';
            const pendingText = pendingCount > 0 ? ` ${pendingCount} change${pendingCount === 1 ? '' : 's'} waiting to sync.` : '';
            status.textContent = `${mode} — showing up to ${window.OfflineStorage?.TRANSACTION_LIMIT || 50} saved transactions. Last updated: ${timestamp}.${pendingText}`;
        } else if (pendingCount > 0) {
            status.textContent = `Offline — showing ${pendingCount} pending change${pendingCount === 1 ? '' : 's'}. No saved server transaction snapshot is available.`;
        } else {
            status.textContent = 'Offline — no saved transactions are available yet. Connect to the internet and open Transactions once.';
        }

        page.querySelectorAll('#import-btn, .tx-btn-view, .tx-mob-action.view, .tx-select-row, #tx-bulk-actions button').forEach(element => {
            if (!element.disabled) {
                element.disabled = true;
                element.setAttribute('data-offline-disabled', 'true');
            }
        });
    }

    _setPendingPageStatus(pendingCount) {
        const page = document.getElementById('transactions-page');
        if (!page) return;
        let status = document.getElementById('transactions-pending-data-status');
        if (!pendingCount) {
            status?.remove();
            return;
        }
        if (!status) {
            status = document.createElement('div');
            status.id = 'transactions-pending-data-status';
            status.className = 'alert alert-warning py-2 px-3 mb-3';
            status.setAttribute('role', 'status');
            page.prepend(status);
        }
        status.textContent = `${pendingCount} pending transaction change${pendingCount === 1 ? ' is' : 's are'} reflected locally and waiting to sync.`;
    }

    /* ==================== SUMMARY ==================== */

    _renderSummary(summary = this.authoritativeSummary) {
        if (summary) {
            const income = parseFloat(summary.total_income) || 0;
            const expense = parseFloat(summary.total_expense) || 0;
            const net = parseFloat(summary.net_cash_flow ?? (income - expense)) || 0;
            const set = (id, val, color) => {
                const el = document.getElementById(id);
                if (el) { el.textContent = val; if (color) el.style.color = color; }
            };
            set('tx-total-income', 'Rs ' + income.toLocaleString('en-IN'));
            set('tx-total-expense', 'Rs ' + expense.toLocaleString('en-IN'));
            set('tx-net-flow', `${net < 0 ? '− ' : net > 0 ? '+ ' : ''}Rs ${Math.abs(net).toLocaleString('en-IN')}`);
            const flowEl = document.getElementById('tx-net-flow');
            if (flowEl) flowEl.style.color = net >= 0 ? '#10B981' : '#EF4444';
            set('tx-total-count', Number(summary.transaction_count || 0).toLocaleString());
            return;
        }
        let income = 0, expense = 0;
        const effectiveTransactions = this.transactions.filter(transaction => transaction.pending_action !== 'delete');
        effectiveTransactions.forEach(t => {
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
        set('tx-net-flow', `${net < 0 ? '− ' : net > 0 ? '+ ' : ''}Rs ${Math.abs(net).toLocaleString('en-IN')}`);
        const flowEl = document.getElementById('tx-net-flow');
        if (flowEl) flowEl.style.color = net >= 0 ? '#10B981' : '#EF4444';
        set('tx-total-count', effectiveTransactions.length.toLocaleString());
    }

    _renderServerPagination() {
        const pagination = this.pagination || {};
        const total = Number(pagination.total_rows || 0);
        const page = Number(pagination.page || this.currentPage || 1);
        const totalPages = Number(pagination.total_pages || 0);
        const info = document.getElementById('tx-server-page-info');
        const previous = document.getElementById('tx-server-page-prev');
        const next = document.getElementById('tx-server-page-next');
        const size = document.getElementById('tx-server-page-size');
        if (info) info.textContent = `Page ${page} of ${totalPages || 0} · ${total.toLocaleString('en-IN')} matching transactions`;
        if (previous) previous.disabled = !pagination.has_previous;
        if (next) next.disabled = !pagination.has_next;
        if (size) size.value = String(this.pageSize);

        if (previous) previous.onclick = () => this.loadTransactions(page - 1);
        if (next) next.onclick = () => this.loadTransactions(page + 1);
        if (size) size.onchange = () => {
            this.pageSize = Math.max(1, Math.min(200, Number(size.value) || 50));
            this.loadTransactions(1);
        };
    }

    /* ==================== TABLE RENDERING ==================== */

    _renderTable() {
        this._destroyDataTable();
        const tbody = document.getElementById('transactions-table-body');
        const empty = document.getElementById('tx-empty-state');
        const table = document.getElementById('transactions-datatable');
        if (!tbody) return;
        tbody.innerHTML = '';

        const mobileCards = document.getElementById('tx-mobile-cards');
        if (this.transactions.length === 0) {
            if (empty) empty.style.display = 'block';
            if (table) table.style.display = 'none';
            if (mobileCards) mobileCards.style.display = 'none';
            return;
        }
        if (empty) empty.style.display = 'none';
        if (table) table.style.display = '';
        if (mobileCards) mobileCards.style.display = '';

        // Render in original order (usually newest first from API)
        this.transactions.forEach(t => {
            const tr = document.createElement('tr');
            tr.innerHTML = this._buildRow(t);
            tbody.appendChild(tr);
        });

        this._initDataTable();
        this._initTooltips();
        this._setupDelegatedActions();
        this._renderMobileCards();
        this._bindMobilePagination();
    }

    _buildRow(t) {
        const date = t.date ? new Date(t.date + 'T00:00:00') : null;
        const day = date ? date.getDate() : '--';
        const month = date ? date.toLocaleString('en', { month: 'short' }) : '';
        const year = date ? date.getFullYear() : '';
        const presentation = this._transactionPresentation(t);
        const amt = parseFloat(t.amount) || 0;
        const amtFmt = amt.toLocaleString('en-IN');
        const transactionId = this._h(String(t.id ?? ''));

        const pendingClass = this._pendingBadgeClass(t);
        const effectivePendingLabel = this._pendingLabel(t);
        const pendingBadge = t._pending ? `<span class="badge ${pendingClass} ms-1" title="${this._h(t.pending_error || effectivePendingLabel)}">${effectivePendingLabel}</span>` : '';
        const typeBadge = `<span class="tx-type-badge ${presentation.badgeClass}">${this._h(presentation.label)}</span>${pendingBadge}`;

        // Category contains only a real stored category.
        const catIcon = t.category_icon ? this._faToBi(t.category_icon) : 'bi-tag-fill';
        const catColor = Formatters.safeColor(t.category_color, '#6366f1');
        const categoryName = t.category_name && t.category_name !== 'Karobar' ? t.category_name : '';
        const catHtml = categoryName
            ? `<div class="tx-cat-icon" style="background:${catColor}"><i class="bi ${catIcon}" aria-hidden="true"></i></div><span class="tx-cat-name">${this._h(categoryName)}</span>`
            : '<span class="tx-empty-value" aria-label="No category">—</span>';

        // Stored subcategory wins; standalone Karobar events may use their existing semantic detail.
        const derivedSubcategory = this._isStandaloneKarobar(t) ? this._karobarTypeLabel(t.karobar_type) : '';
        const subcategoryName = t.subcategory_name || derivedSubcategory;
        const subHtml = subcategoryName
            ? `<span class="tx-subcategory" title="${this._h(subcategoryName)}">${this._h(subcategoryName)}</span>`
            : '<span class="tx-empty-value" aria-label="No subcategory">—</span>';

        const accountParty = this._accountPartyPresentation(t);
        const accountHtml = accountParty.primary
            ? `<div class="tx-party-cell"><span class="tx-party-primary">${this._h(accountParty.primary)}</span>${accountParty.secondary ? `<small class="tx-party-secondary">${this._h(accountParty.secondary)}</small>` : ''}</div>`
            : '<span class="tx-empty-value" aria-label="No account or party">—</span>';

        const recurringBadge = t.recurring_definition_id
            ? `<span class="badge rounded-pill text-bg-light border ms-1" title="Scheduled occurrence ${this._h(t.recurring_occurrence_date || '')}"><i class="bi bi-arrow-repeat me-1"></i>Recurring</span>` : '';
        const descHtml = t.description
            ? `<div class="tx-desc-cell"><span class="tx-desc-text" title="${this._h(t.description)}" data-bs-toggle="tooltip">${this._h(t.description)}</span>${recurringBadge}</div>`
            : `<div class="tx-desc-cell"><span class="tx-empty-value" aria-label="No description">—</span>${recurringBadge}</div>`;

        const amountPrefix = presentation.direction === 'out' ? '− ' : presentation.direction === 'in' ? '+ ' : '';
        const amtDataOrder = presentation.direction === 'out' ? -amt : amt;
        const amountHtml = `<span class="tx-money ${presentation.moneyClass}">${amountPrefix}Rs ${amtFmt}</span>`;
        const pendingBusy = t._pending && t.pending_status === 'syncing';
        const pendingConflict = t._pending && t.pending_status === 'conflict';
        const pendingDelete = t._pending && t.pending_action === 'delete';
        const canEditPending = t._pending && !pendingDelete && !pendingBusy && !pendingConflict;
        const canDeletePending = t._pending && !pendingDelete && !pendingBusy && !pendingConflict;
        const attentionAction = pendingConflict
            ? `<button class="tx-action-btn" title="Resolve conflict" data-sync-resolve="${this._h(t.localId)}"><i class="bi bi-exclamation-diamond"></i></button>`
            : (t._pending && t.pending_status === 'failed'
                ? `<button class="tx-action-btn" title="Retry sync" data-sync-retry="${this._h(t.localId)}"><i class="bi bi-arrow-clockwise"></i></button>`
                : '');

        const dateOrder = this._h(t.date || '');
        return `
            <td class="text-center">
                <input type="checkbox" class="form-check-input tx-select-row" data-tx-select="${transactionId}" aria-label="Select transaction ${transactionId}" ${(t.scope === 'karobar' || t._pending) ? `disabled title="${t._pending ? 'Pending transactions cannot be changed until synced' : 'Karobar transactions are managed individually'}"` : ''}>
            </td>
            <td data-order="${dateOrder}">
                <div class="tx-date-cell">
                    <div class="tx-date-primary">${month} ${day}</div>
                    <div class="tx-date-year">${year}</div>
                </div>
            </td>
            <td>${typeBadge}</td>
            <td><div class="tx-cat-cell">${catHtml}</div></td>
            <td>${subHtml}</td>
            <td>${accountHtml}</td>
            <td>${descHtml}</td>
            <td data-order="${amtDataOrder}">${amountHtml}</td>
            <td>
                <div class="tx-actions-cell">
                    <div class="tx-action-btns">
                        <button type="button" class="tx-action-btn tx-btn-view" title="View transaction" aria-label="View transaction" data-tx-id="${transactionId}" ${t._pending ? 'disabled' : ''}><i class="bi bi-eye" aria-hidden="true"></i></button>
                        <button type="button" class="tx-action-btn tx-btn-edit" title="Edit transaction" aria-label="Edit transaction" data-tx-id="${transactionId}" ${(t._pending && !canEditPending) ? 'disabled' : ''}><i class="bi bi-pencil" aria-hidden="true"></i></button>
                        ${pendingDelete
                            ? `<button type="button" class="tx-action-btn" title="Undo pending deletion" aria-label="Undo pending deletion" data-sync-undo="${this._h(t.localId)}" ${pendingBusy || pendingConflict ? 'disabled' : ''}><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>`
                            : `<button type="button" class="tx-action-btn tx-btn-delete" title="Delete transaction" aria-label="Delete transaction" data-tx-id="${transactionId}" ${(t._pending && !canDeletePending) ? 'disabled' : ''}><i class="bi bi-trash3" aria-hidden="true"></i></button>`}
                        ${attentionAction}
                    </div>
                </div>
            </td>`;
    }

    _karobarTypeLabel(type) {
        return ({ lent: 'Money Lent', borrowed: 'Money Borrowed', returned: 'Money Returned', repaid: 'Money Repaid', adjustment: 'Adjustment' })[type] || 'Karobar Transaction';
    }

    _transactionPresentation(transaction) {
        const linkedCreditExpense = transaction?.scope === 'karobar'
            && transaction?.source_table === 'transactions'
            && transaction?.payment_method === 'credit';
        if (linkedCreditExpense) return { label: 'Credit Expense', badgeClass: 'credit-expense', direction: 'out', moneyClass: 'money-out' };

        if (this._isStandaloneKarobar(transaction)) {
            const map = {
                repaid: { label: 'Debt Payment', badgeClass: 'debt-payment', direction: 'out', moneyClass: 'money-out' },
                returned: { label: 'Money Received', badgeClass: 'money-received', direction: 'in', moneyClass: 'money-in' },
                lent: { label: 'Money Lent', badgeClass: 'money-lent', direction: 'out', moneyClass: 'money-out' },
                borrowed: { label: 'Money Borrowed', badgeClass: 'money-received', direction: 'in', moneyClass: 'money-in' },
                adjustment: { label: 'Adjustment', badgeClass: 'adjustment', direction: 'neutral', moneyClass: 'money-neutral' },
            };
            return map[transaction.karobar_type] || { label: 'Karobar', badgeClass: 'adjustment', direction: 'neutral', moneyClass: 'money-neutral' };
        }

        const map = {
            income: { label: 'Income', badgeClass: 'income', direction: 'in', moneyClass: 'money-in' },
            expense: { label: 'Expense', badgeClass: 'expense', direction: 'out', moneyClass: 'money-out' },
            transfer: { label: 'Transfer', badgeClass: 'transfer', direction: 'neutral', moneyClass: 'money-transfer' },
            goal_contribution: { label: 'Savings', badgeClass: 'savings', direction: 'out', moneyClass: 'money-out' },
        };
        return map[transaction?.type] || { label: String(transaction?.type || 'Transaction').replaceAll('_', ' '), badgeClass: 'adjustment', direction: 'neutral', moneyClass: 'money-neutral' };
    }

    _accountPartyPresentation(transaction) {
        if (transaction?.scope === 'karobar' && transaction?.person_name) {
            let secondary = '';
            if (transaction.payment_method === 'credit' && transaction.source_table === 'transactions') {
                secondary = transaction.karobar_direction === 'receivable' ? 'Credit · Receivable' : 'Credit · Payable';
            } else if (transaction.karobar_type === 'repaid') {
                secondary = transaction.account_name ? `Paid from ${transaction.account_name}` : 'Debt payment';
            } else if (transaction.karobar_type === 'returned') {
                secondary = transaction.account_name ? `Received in ${transaction.account_name}` : 'Money received';
            } else if (transaction.karobar_type === 'lent') {
                secondary = transaction.account_name ? `Lent from ${transaction.account_name}` : 'Money lent';
            } else if (transaction.karobar_type === 'borrowed') {
                secondary = transaction.account_name ? `Received in ${transaction.account_name}` : 'Credit · Payable';
            }
            return { primary: transaction.person_name, secondary };
        }
        if (transaction?.type === 'transfer') {
            return { primary: `${transaction.from_account_name || 'Unknown'} → ${transaction.to_account_name || 'Unknown'}`, secondary: 'Transfer' };
        }
        const type = transaction?.account_type ? `${String(transaction.account_type).replaceAll('_', ' ')} account` : '';
        return { primary: transaction?.account_name || '', secondary: type ? type.charAt(0).toUpperCase() + type.slice(1) : '' };
    }

    _visibleTransaction(id) {
        return this.transactions.find(transaction => String(transaction.id) === String(id));
    }

    _isStandaloneKarobar(transaction) {
        return transaction?.scope === 'karobar' && transaction?.source_table === 'karobar_transactions';
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
                paging: false,
                order: [[1, 'desc']],
                responsive: false,
                columnDefs: [
                    { orderable: true, targets: [1, 2, 3, 4, 5, 6, 7] },
                    { orderable: false, targets: [0, 8] }
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
                info: false,
                dom: 'r<"dt-table-wrap"t>',
                drawCallback: () => {
                    this._renderMobileCards();
                }
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
        this._formEpoch++;
        this._creditorsLoad = null;
        this._editingTransactionId = txData ? txData.id : null;
        this._editingTransactionData = txData || null;
        this._ordinaryRequestId = null;
        this._transferRequestId = null;

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
        const today = DateUtils.getKathmanduDateString();
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
                            <input type="number" inputmode="decimal" step="0.01" min="0.01" id="transaction-amount" class="tx-input" placeholder="0" required autocomplete="off">
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
                        <select id="transaction-creditor" class="tx-select"><option value="" disabled selected>Select creditor</option></select>
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
                            <input type="number" inputmode="decimal" step="0.01" min="0.01" id="transfer-fee-amount" class="tx-input" placeholder="0" autocomplete="off">
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

        // The form can also be opened from Dashboard/Quick Add while the
        // Transactions page is not mounted, so bind this directly to the
        // modal field instead of relying on page-level delegated events.
        const category = document.getElementById('transaction-category');
        if (category) {
            category.addEventListener('change', () => {
                this._loadSubcategories(category.value);
            });
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
        const creditor = document.getElementById('transaction-creditor');
        const dueDate = document.getElementById('transaction-due-date');
        const type = document.getElementById('transaction-type')?.value;

        if (method === 'credit' && type === 'expense') {
            if (cred) { cred.classList.remove('hidden'); this._loadCreditors(); }
            if (due) due.classList.remove('hidden');
            if (acc) acc.classList.add('hidden');
            if (creditor) creditor.required = true;
        } else {
            if (cred) cred.classList.add('hidden');
            if (due) due.classList.add('hidden');
            if (acc && type !== 'transfer') acc.classList.remove('hidden');
            if (creditor) { creditor.required = false; creditor.value = ''; }
            if (dueDate) dueDate.value = '';
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
        const requestSequence = (this._categoryLoadSequence || 0) + 1;
        this._categoryLoadSequence = requestSequence;
        // A category from the previous transaction type must never leave stale
        // subcategory options behind.
        await this._loadSubcategories('');

        try {
            const res = await window.Api.get('/categories?type=' + type + '&status=active');
            if (requestSequence !== this._categoryLoadSequence
                || document.getElementById('transaction-type')?.value !== type) return;
            const sel = document.getElementById('transaction-category');
            if (!sel) return;
            if (res.success && Array.isArray(res.data)) {
                this._renderCategoryOptions(sel, res.data, 'Select category');
                const userId = window.authManager?.getCurrentUser()?.id;
                if (userId !== undefined && userId !== null) {
                    window.OfflineStorage?.saveReferenceData(userId, `categories:${type}`, res.data);
                }
            }
        } catch (e) {
            console.error('Load categories error:', e);
            const userId = window.authManager?.getCurrentUser()?.id;
            const cached = await window.OfflineStorage?.getReferenceData(userId, `categories:${type}`);
            const sel = document.getElementById('transaction-category');
            if (requestSequence === this._categoryLoadSequence
                && document.getElementById('transaction-type')?.value === type
                && sel && Array.isArray(cached?.data)) {
                this._renderCategoryOptions(sel, cached.data, 'Select category');
            }
        }

        // Also load expense categories for transfer fee dropdown
        if (type === 'transfer') {
            try {
                const feeRes = await window.Api.get('/categories?type=expense&status=active');
                if (requestSequence !== this._categoryLoadSequence
                    || document.getElementById('transaction-type')?.value !== 'transfer') return;
                const feeSel = document.getElementById('transfer-fee-category');
                if (!feeSel) return;
                if (feeRes.success) {
                    this._renderCategoryOptions(feeSel, feeRes.data, 'Select fee category');
                }
            } catch (e) { console.error('Load fee categories error:', e); }
        }
    }

    _renderCategoryOptions(select, categories, placeholder) {
        select.innerHTML = '';
        const placeholderOption = document.createElement('option');
        placeholderOption.value = '';
        placeholderOption.textContent = placeholder;
        select.appendChild(placeholderOption);

        const appendOption = (parent, category, pinned) => {
            const option = document.createElement('option');
            option.value = category.id;
            option.textContent = `${pinned ? '\u2605 ' : ''}${category.name}`;
            option.dataset.name = category.name;
            option.dataset.icon = category.icon || '';
            option.dataset.color = category.color || '#6366f1';
            option.dataset.pinned = pinned ? '1' : '0';
            parent.appendChild(option);
        };
        const pinned = categories.filter(category => Number(category.is_pinned) === 1 || category.is_pinned === true);
        const others = categories.filter(category => !(Number(category.is_pinned) === 1 || category.is_pinned === true));
        if (pinned.length) {
            const pinnedGroup = document.createElement('optgroup');
            pinnedGroup.label = 'Pinned';
            pinned.forEach(category => appendOption(pinnedGroup, category, true));
            select.appendChild(pinnedGroup);
        }
        const otherParent = pinned.length && others.length ? document.createElement('optgroup') : select;
        if (otherParent !== select) otherParent.label = 'Other categories';
        others.forEach(category => appendOption(otherParent, category, false));
        if (otherParent !== select) select.appendChild(otherParent);
    }

    async _loadSubcategories(catId) {
        const sel = document.getElementById('transaction-subcategory');
        if (!sel) return;
        sel.innerHTML = '<option value="">Select subcategory</option>';
        if (!catId) { sel.disabled = true; return; }
        try {
            const res = await window.Api.get('/subcategories?category_id=' + catId + '&status=active');
            if (res.success && Array.isArray(res.data)) {
                sel.disabled = res.data.length === 0;
                res.data.forEach(s => {
                    const o = document.createElement('option');
                    o.value = s.id;
                    o.textContent = s.name;
                    sel.appendChild(o);
                });
                const userId = window.authManager?.getCurrentUser()?.id;
                if (userId !== undefined && userId !== null) {
                    window.OfflineStorage?.saveReferenceData(userId, `subcategories:${catId}`, res.data);
                }
            }
        } catch (e) {
            console.error('Load subcategories error:', e);
            const userId = window.authManager?.getCurrentUser()?.id;
            const cached = await window.OfflineStorage?.getReferenceData(userId, `subcategories:${catId}`);
            if (Array.isArray(cached?.data)) {
                sel.disabled = cached.data.length === 0;
                cached.data.forEach(s => {
                    const option = document.createElement('option');
                    option.value = s.id;
                    option.textContent = s.name;
                    sel.appendChild(option);
                });
            }
        }
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
            if (res.success && Array.isArray(res.data)) {
                const userId = window.authManager?.getCurrentUser()?.id;
                if (userId !== undefined && userId !== null) {
                    window.OfflineStorage?.saveReferenceData(userId, 'accounts', res.data);
                }
            }
        } catch (e) {
            console.error('Load accounts error:', e);
            const userId = window.authManager?.getCurrentUser()?.id;
            const cached = await window.OfflineStorage?.getReferenceData(userId, 'accounts');
            if (!Array.isArray(cached?.data)) return;
            const selects = ['transaction-account', 'tx-from-account', 'tx-to-account'];
            selects.forEach(id => {
                const sel = document.getElementById(id);
                if (!sel) return;
                const label = id === 'tx-from-account' ? 'Select from account' : (id === 'tx-to-account' ? 'Select to account' : 'Select account');
                sel.innerHTML = `<option value="">${label}</option>`;
                cached.data.forEach(account => {
                    const option = document.createElement('option');
                    option.value = account.id;
                    option.textContent = account.name;
                    sel.appendChild(option);
                });
            });
        }
    }

    async _loadCreditors() {
        const sel = document.getElementById('transaction-creditor');
        if (!sel) return [];

        const epoch = this._formEpoch;
        if (this._creditorsLoad?.epoch === epoch) return this._creditorsLoad.promise;

        const selectedId = sel.value;
        sel.disabled = true;
        sel.innerHTML = '<option value="" selected>Loading creditors…</option>';

        const request = (async () => {
            try {
                const first = await window.Api.get('/people?status=active&page=1&limit=200');
                if (!first?.success || !Array.isArray(first.data?.people)) {
                    throw new Error('Invalid people response');
                }

                const people = [...first.data.people];
                const totalPages = Math.max(1, Number(first.data.pagination?.total_pages) || 1);
                if (totalPages > 1) {
                    const remaining = await Promise.all(Array.from({ length: totalPages - 1 }, (_, index) =>
                        window.Api.get(`/people?status=active&page=${index + 2}&limit=200`)
                    ));
                    remaining.forEach(response => {
                        if (!response?.success || !Array.isArray(response.data?.people)) {
                            throw new Error('Invalid people response');
                        }
                        people.push(...response.data.people);
                    });
                }

                if (this._formEpoch !== epoch || document.getElementById('transaction-creditor') !== sel) return people;

                sel.innerHTML = '';
                if (people.length === 0) {
                    const empty = document.createElement('option');
                    empty.value = '';
                    empty.textContent = 'No people available — add a person in Karobar first';
                    empty.selected = true;
                    sel.appendChild(empty);
                    sel.disabled = true;
                    return people;
                }

                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = 'Select creditor';
                placeholder.disabled = true;
                placeholder.selected = true;
                sel.appendChild(placeholder);
                people.forEach(person => {
                    const option = document.createElement('option');
                    option.value = String(person.id);
                    const type = String(person.type || '').replace(/_/g, ' ');
                    const typeLabel = type ? type.charAt(0).toUpperCase() + type.slice(1) : '';
                    option.textContent = typeLabel ? `${person.name} — ${typeLabel}` : person.name;
                    sel.appendChild(option);
                });
                sel.disabled = false;
                if (selectedId && Array.from(sel.options).some(option => option.value === String(selectedId))) {
                    sel.value = String(selectedId);
                }
                return people;
            } catch (error) {
                if (this._formEpoch === epoch && document.getElementById('transaction-creditor') === sel) {
                    sel.innerHTML = '<option value="" selected>Unable to load creditors. Please try again.</option>';
                    sel.disabled = true;
                }
                console.error('Load creditors error:', error);
                return [];
            } finally {
                if (this._creditorsLoad?.epoch === epoch) this._creditorsLoad = null;
            }
        })();

        this._creditorsLoad = { epoch, promise: request };
        return request;
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
        if (dateEl) dateEl.value = t.date || DateUtils.getKathmanduDateString();

        const descEl = document.getElementById('transaction-description');
        if (descEl) descEl.value = t.description || '';

        const catEl = document.getElementById('transaction-category');
        if (catEl && t.category_id) {
            // Wait for categories to load then set value
            setTimeout(() => {
                catEl.value = t.category_id;
                this._loadSubcategories(t.category_id).then(() => {
                    const subEl = document.getElementById('transaction-subcategory');
                    if (subEl && t.subcategory_id) subEl.value = t.subcategory_id;
                });
            }, 200);
        }

        if (type === 'transfer') {
            const fromEl = document.getElementById('tx-from-account');
            const toEl = document.getElementById('tx-to-account');
            // Account options load asynchronously when the modal opens.
            setTimeout(() => {
                if (fromEl && t.from_account_id) fromEl.value = t.from_account_id;
                if (toEl && t.to_account_id) toEl.value = t.to_account_id;
                if (fromEl) fromEl.dispatchEvent(new Event('change'));
            }, 250);
            const feeAmount = parseFloat(t.fee_amount || t.fee_transaction?.amount || 0);
            const feeCategory = t.fee_category_id || t.fee_transaction?.category_id;
            if (feeAmount > 0) {
                const feeToggle = document.getElementById('transfer-has-fee');
                if (feeToggle) feeToggle.checked = true;
                this._syncSections(type);
                const feeAmountEl = document.getElementById('transfer-fee-amount');
                if (feeAmountEl) feeAmountEl.value = feeAmount;
                setTimeout(() => {
                    const feeCategoryEl = document.getElementById('transfer-fee-category');
                    if (feeCategoryEl && feeCategory) feeCategoryEl.value = feeCategory;
                }, 250);
            }
        } else {
            const paymentMethodEl = document.getElementById('transaction-payment-method');
            if (paymentMethodEl) {
                paymentMethodEl.value = t.payment_method || 'cash';
                this._toggleCreditorSection(paymentMethodEl.value);
            }
            const accEl = document.getElementById('transaction-account');
            if (accEl && t.account_id) {
                setTimeout(() => { accEl.value = t.account_id; }, 200);
            }
            if (t.payment_method === 'credit') {
                const dueDateEl = document.getElementById('transaction-due-date');
                if (dueDateEl) dueDateEl.value = t.due_date || '';
                this._loadCreditors().then(() => {
                    const creditorEl = document.getElementById('transaction-creditor');
                    if (creditorEl && t.creditor_id) {
                        if(!Array.from(creditorEl.options).some(option=>option.value==t.creditor_id)){
                            const archived=document.createElement('option');
                            archived.value=t.creditor_id;
                            archived.textContent=(t.linked_credit_purchase?.payable?.person_name||'Archived creditor')+' (Archived)';
                            creditorEl.appendChild(archived);
                        }
                        creditorEl.value = t.creditor_id;
                    }
                });
            }
        }

        this._checkNegative();
    }

    /* ==================== SAVE TRANSACTION ==================== */

    async _saveTransaction() {
        if (this._saveInProgress) return;
        const type = document.getElementById('transaction-type')?.value;
        if (!type) { this._notify('error', 'Please select a transaction type'); return; }

        const amountText = String(document.getElementById('transaction-amount')?.value || '').trim();
        const amount = Number(amountText);
        if (!/^(?:0|[1-9]\d{0,11})(?:\.\d{1,2})?$/.test(amountText) || !Number.isFinite(amount) || amount <= 0 || amount > 999999999999.99) {
            this._notify('error', 'Amount must be positive and use at most two decimal places'); return;
        }

        const date = document.getElementById('transaction-date')?.value;
        if (!date) { this._notify('error', 'Please select a date'); return; }
        if (Number.isNaN(new Date(`${date}T00:00:00`).getTime())) { this._notify('error', 'Please select a valid date'); return; }

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
            } else {
                data.fee_amount = 0;
                data.fee_category_id = null;
            }
            if (!this._editingTransactionId) {
                data.client_request_id = this._transferRequestId || (this._transferRequestId = this._createTransferRequestId());
            }
        } else {
            const paymentMethod = document.getElementById('transaction-payment-method')?.value || 'cash';
            const accountId = document.getElementById('transaction-account')?.value;
            if (!(paymentMethod === 'credit' && type === 'expense')) {
                if (!accountId) { this._notify('error', 'Please select an account'); return; }
                data.account_id = parseInt(accountId);
            }

            const catId = document.getElementById('transaction-category')?.value;
            if (!catId) { this._notify('error', 'Please select a category'); return; }
            data.category_id = parseInt(catId);

            const subId = document.getElementById('transaction-subcategory')?.value;
            data.subcategory_id = subId ? parseInt(subId) : null;

            if (paymentMethod === 'credit' && type === 'expense') {
                const creditorId = document.getElementById('transaction-creditor')?.value;
                if (!creditorId) { this._notify('error', 'Please select a creditor for credit purchase'); return; }
                data.creditor_id = parseInt(creditorId);
                data.account_id = null;
                data.payment_method = 'credit';
                data.due_date = document.getElementById('transaction-due-date')?.value || null;
                if (!this._editingTransactionId) {
                    data.client_request_id = this._creditPurchaseRequestId || (this._creditPurchaseRequestId = this._createCreditPurchaseRequestId());
                }
            } else {
                data.payment_method = paymentMethod;
                if (!this._editingTransactionId) {
                    data.client_request_id = this._ordinaryRequestId || (this._ordinaryRequestId = this._createOrdinaryRequestId());
                }
            }
        }

        data.description = (document.getElementById('transaction-description')?.value || '').trim();
        if (data.description.length > 255) { this._notify('error', 'Description must be 255 characters or fewer'); return; }

        this._saveInProgress = true;

        // Disable save button and show spinner
        const saveBtn = document.getElementById('modal-save-btn');
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Saving...';
        }

        try {
            if (navigator.onLine === false || this._editingTransactionData?._pending) {
                await this._queueOfflineTransaction(data);
                return;
            }

            let result;
            if (this._editingTransactionId) {
                if (this._editingTransactionData?.version) data.base_version = parseInt(this._editingTransactionData.version);
                result = await window.Api.put('/transactions/' + this._editingTransactionId, data);
            } else {
                result = await window.Api.post('/transactions', data);
            }

            if (result && result.success) {
                if (type === 'transfer') this._invalidateTransferCaches();
                if (data.payment_method === 'credit') this._invalidateCreditPurchaseCaches();
                this._notify('success',
                    this._editingTransactionId ? 'Transaction updated successfully'
                    : type === 'income' ? 'Income recorded successfully'
                    : type === 'transfer' ? 'Transfer completed successfully'
                    : 'Expense recorded successfully'
                );
                this._editingTransactionId = null;
                this._editingTransactionData = null;
                this._ordinaryRequestId = null;
                this._transferRequestId = null;
                this._creditPurchaseRequestId = null;
                window.modalService?.close();
                window.premiumModal?.close();
                this.loadTransactions();
                document.dispatchEvent(new CustomEvent('app:data-changed'));
            } else {
                this._notify('error', result?.message || 'Failed to save transaction');
            }
        } catch (err) {
            console.error('Save transaction error:', err);
            if (this._isNetworkFailure(err)) {
                await this._queueOfflineTransaction(data);
            } else {
                this._notify('error', err.message || 'Failed to save transaction');
            }
        } finally {
            this._saveInProgress = false;
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i> ' + (this._editingTransactionId ? 'Update Transaction' : 'Save Transaction');
            }
        }
    }

    _isNetworkFailure(error) {
        return navigator.onLine === false || (error?.code === 'NETWORK_ERROR' && !error?.status);
    }

    _createTransferRequestId() {
        if (window.crypto?.randomUUID) return window.crypto.randomUUID();
        const random = Math.random().toString(36).slice(2) + Date.now().toString(36);
        return `req_transfer_${random}`.slice(0, 64);
    }

    _createOrdinaryRequestId() {
        if (window.crypto?.randomUUID) return window.crypto.randomUUID();
        const random = Math.random().toString(36).slice(2) + Date.now().toString(36);
        return `req_online_${random}`.slice(0, 64);
    }

    _createCreditPurchaseRequestId() {
        if (window.crypto?.randomUUID) return window.crypto.randomUUID();
        const random = Math.random().toString(36).slice(2) + Date.now().toString(36);
        return `req_credit_${random}`.slice(0, 64);
    }

    _invalidateTransferCaches() {
        const invalidate = window.Api?.constructor?.invalidateCache;
        if (typeof invalidate !== 'function') return;
        ['/transactions', '/accounts', '/dashboard', '/ledger', '/reports', '/budgets'].forEach(pattern => invalidate.call(window.Api.constructor, pattern));
    }

    _invalidateGoalCaches() {
        const invalidate = window.Api?.constructor?.invalidateCache;
        if (typeof invalidate !== 'function') return;
        ['/goals', '/accounts', '/transactions', '/dashboard', '/savings', '/ledger', '/reports', '/budgets']
            .forEach(pattern => invalidate.call(window.Api.constructor, pattern));
    }

    _invalidateCreditPurchaseCaches() {
        const invalidate = window.Api?.constructor?.invalidateCache;
        if (typeof invalidate !== 'function') return;
        ['/transactions', '/accounts', '/dashboard', '/reports', '/ledger', '/budgets', '/karobar', '/people']
            .forEach(pattern => invalidate.call(window.Api.constructor, pattern));
    }

    async _queueOfflineTransaction(data) {
        if (this._editingTransactionId) {
            return this._queueOfflineUpdate(data);
        }
        if (!['income', 'expense'].includes(data.type)) {
            this._notify('warning', 'Transfers require an internet connection.');
            return false;
        }
        if (data.payment_method === 'credit') {
            this._notify('warning', 'Credit and Udharo transactions require an internet connection.');
            return false;
        }

        const userId = window.authManager?.getCurrentUser()?.id;
        if (userId === undefined || userId === null || !window.OfflineStorage) {
            this._notify('warning', 'Please reconnect and sign in before adding new records.');
            return false;
        }

        const action = await window.OfflineStorage.addPendingAction({
            userId,
            entityType: 'transaction',
            action: 'create',
            endpoint: '/transactions',
            method: 'POST',
            payload: data,
            display: this._pendingDisplayFromForm()
        });

        if (!action) {
            this._notify('error', 'Unable to save this transaction offline. Please reconnect and try again.');
            return false;
        }

        this._editingTransactionId = null;
        this._editingTransactionData = null;
        window.modalService?.close();
        window.premiumModal?.close();
        await this._loadOfflineSnapshot();
        this._notify('warning', 'Saved offline. This transaction will sync when you are back online.');
        window.refreshPendingSyncStatus?.();
        document.dispatchEvent(new CustomEvent('app:data-changed'));
        return true;
    }

    async _queueOfflineUpdate(data) {
        const transaction = this._editingTransactionData;
        if (!this._canModifyOffline(transaction)) {
            this._notify('warning', 'This transaction must be edited while online.');
            return false;
        }
        const userId = window.authManager?.getCurrentUser()?.id;
        if (userId === undefined || userId === null || !window.OfflineStorage) return false;
        const action = await window.OfflineStorage.queueTransactionUpdate({
            userId,
            transaction,
            payload: data,
            display: this._pendingDisplayFromForm()
        });
        if (!action) {
            this._notify('error', transaction?.version
                ? 'Unable to save these changes offline. Please reconnect and try again.'
                : 'Reconnect once before editing this saved transaction offline.');
            return false;
        }
        this._editingTransactionId = null;
        this._editingTransactionData = null;
        window.modalService?.close();
        window.premiumModal?.close();
        await this._loadOfflineSnapshot();
        this._notify('warning', 'Changes saved on this device. They will sync when you are online.');
        window.refreshPendingSyncStatus?.();
        document.dispatchEvent(new CustomEvent('app:data-changed'));
        if (navigator.onLine) window.SanIESync?.syncPendingActions({ trigger: 'pending-edit' });
        return true;
    }

    _pendingDisplayFromForm() {
        const categoryOption = document.getElementById('transaction-category')?.selectedOptions?.[0];
        const subcategoryOption = document.getElementById('transaction-subcategory')?.selectedOptions?.[0];
        const accountOption = document.getElementById('transaction-account')?.selectedOptions?.[0];
        return {
            category_name: categoryOption?.dataset?.name || categoryOption?.textContent || '',
            category_icon: categoryOption?.dataset?.icon || '',
            category_color: categoryOption?.dataset?.color || '',
            subcategory_name: subcategoryOption?.value ? subcategoryOption.textContent : '',
            account_name: accountOption?.textContent || ''
        };
    }

    _canModifyOffline(transaction) {
        if (!transaction || !['income', 'expense'].includes(transaction.type)) return false;
        if (transaction.payment_method === 'credit' || transaction.karobar_transaction_id) return false;
        if (transaction._pending) return ['create', 'update'].includes(transaction.pending_action);
        return Number.isInteger(Number(transaction.id)) && Number(transaction.id) > 0
            && Number.isInteger(Number(transaction.version)) && Number(transaction.version) > 0;
    }

    /* ==================== VIEW / EDIT / DELETE ==================== */

    async viewTransaction(id) {
        try {
            const local = this._visibleTransaction(id);
            if (this._isStandaloneKarobar(local)) {
                const response = await window.Api.get('/karobar/' + local.source_id);
                if (!response?.success || !response.data) throw new Error('Karobar transaction not found');
                this._showKarobarTransactionDetails({ ...response.data, ...local });
                return;
            }
            const res = await window.Api.get('/transactions/' + id);
            if (!res.success || !res.data) { this._notify('error', 'Transaction not found'); return; }
            const t = { ...res.data, ...(local?.scope === 'karobar' ? {
                scope: 'karobar', karobar_type: local.karobar_type, person_name: local.person_name,
                person_type: local.person_type, remaining_amount: local.remaining_amount,
                paid_amount: local.paid_amount, due_date: local.due_date
            } : {}) };
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
            const karobarDetails = t.scope === 'karobar' ? this._karobarMetadataHTML(t) : '';

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
                        ${karobarDetails}
                        <div class="col-6"><p class="text-muted small mb-1">Date</p><p class="fw-semibold">${date}</p></div>
                        <div class="col-12"><p class="text-muted small mb-1">Description</p><p class="fw-semibold">${this._h(t.description || 'No Description')}</p></div>
                        ${t.recurring_definition_id ? `<div class="col-12"><div class="alert alert-light border mb-0 py-2"><i class="bi bi-arrow-repeat text-success me-2"></i><strong>Recurring origin</strong><br><small class="text-muted">Definition #${this._h(t.recurring_definition_id)} · scheduled for ${this._h(t.recurring_occurrence_date || '—')}</small></div></div>` : ''}
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

    _karobarMetadataHTML(transaction) {
        const linked = transaction.linked_credit_purchase || null;
        const payable = linked?.payable || {};
        const personName = transaction.person_name || payable.person_name || 'Karobar person';
        const karobarType = transaction.karobar_type || payable.type;
        const remaining = linked?.outstanding ?? transaction.remaining_amount;
        const paid = transaction.paid_amount ?? (remaining !== null && remaining !== undefined ? Math.max(0, Number(transaction.amount || 0) - Number(remaining)) : null);
        const direction = transaction.karobar_direction || (karobarType === 'lent' ? 'Receivable' : (karobarType === 'borrowed' ? 'Payable' : null));
        const fields = [
            ['Person / Shop', personName],
            ['Karobar Type', this._karobarTypeLabel(karobarType)],
            ['Position', direction],
            ['Paid Amount', paid !== null && paid !== undefined ? `Rs ${Number(paid).toLocaleString('en-IN')}` : null],
            ['Remaining', remaining !== null && remaining !== undefined ? `Rs ${Number(remaining).toLocaleString('en-IN')}` : null],
            ['Due Date', transaction.due_date || payable.due_date || null],
        ].filter(([, value]) => value !== null && value !== undefined && value !== '');
        return fields.map(([label, value]) => `<div class="col-6"><p class="text-muted small mb-1">${this._h(label)}</p><p class="fw-semibold">${this._h(String(value))}</p></div>`).join('');
    }

    _showKarobarTransactionDetails(transaction) {
        const amount = Number(transaction.amount || 0).toLocaleString('en-IN');
        const date = transaction.date ? new Date(transaction.date + 'T00:00:00').toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : '--';
        const html = `<div class="tx-view-modal">
            <div class="d-flex align-items-center justify-content-between mb-3"><span class="tx-scope-badge">Karobar</span><span class="text-muted">${this._h(date)}</span></div>
            <div class="text-center mb-4"><h2 class="fw-bold mb-1">Rs ${this._h(amount)}</h2></div>
            <div class="row g-3">${this._karobarMetadataHTML(transaction)}
                ${transaction.account_name ? `<div class="col-6"><p class="text-muted small mb-1">Account</p><p class="fw-semibold">${this._h(transaction.account_name)}</p></div>` : ''}
                ${transaction.description ? `<div class="col-12"><p class="text-muted small mb-1">Description</p><p class="fw-semibold">${this._h(transaction.description)}</p></div>` : ''}
            </div></div>`;
        window.modalService?.open({ title: 'Karobar Transaction Details', subtitle: this._karobarTypeLabel(transaction.karobar_type), icon: 'fa-handshake', bodyHTML: html, showFooter: false });
    }

    async editTransaction(id) {
        const local = this._visibleTransaction(id);
        if (this._isStandaloneKarobar(local)) {
            if (navigator.onLine === false) { this._notify('warning', 'Karobar transactions must be edited while online.'); return; }
            if (!window.karobarManager) { this._notify('error', 'Karobar editor is unavailable'); return; }
            await window.karobarManager.editTransaction(local.source_id);
            return;
        }
        if (local?.type === 'goal_contribution') {
            this._notify('info', 'Edit savings-goal contributions from the Goals page history.');
            return;
        }
        if (local?._pending) {
            if (!this._canModifyOffline(local) || ['syncing', 'conflict'].includes(local.pending_status)) {
                this._notify('warning', local.pending_status === 'conflict' ? 'Resolve the sync conflict before editing.' : 'This pending transaction cannot be edited right now.');
                return;
            }
            this._openForm(local);
            return;
        }
        if (navigator.onLine === false) {
            if (!this._canModifyOffline(local)) {
                this._notify('warning', 'This transaction must be edited while online, or refreshed online once to obtain its version.');
                return;
            }
            this._openForm(local);
            return;
        }
        try {
            const res = await window.Api.get('/transactions/' + id);
            if (!res.success || !res.data) { this._notify('error', 'Transaction not found'); return; }
            this._openForm(res.data);
        } catch (err) {
            console.error('Edit transaction error:', err);
            if (this._isNetworkFailure(err) && this._canModifyOffline(local)) this._openForm(local);
            else this._notify('error', 'Failed to load transaction');
        }
    }

    async deleteTransaction(id) {
        const transaction = this._visibleTransaction(id);
        if (this._isStandaloneKarobar(transaction)) {
            await this._deleteStandaloneKarobar(transaction);
            return;
        }
        if (transaction?.pending_action === 'delete') return this.undoPendingDelete(transaction.localId);
        const confirmed = window.Swal
            ? await window.Swal.fire({
                title: 'Delete Transaction',
                text: navigator.onLine || transaction?._pending
                    ? 'This cannot be undone after it reaches the server.'
                    : 'The deletion will be saved on this device and synchronized later.',
                icon: 'warning', showCancelButton: true, confirmButtonColor: '#EF4444',
                cancelButtonColor: '#6B7280', confirmButtonText: 'Yes, Delete'
            })
            : { isConfirmed: confirm('Delete this transaction?') };

        if (!confirmed.isConfirmed) return;

        if (transaction?._pending || navigator.onLine === false) {
            await this._queueOfflineDelete(transaction);
            return;
        }

        try {
            const isTransferGroup = transaction?.type === 'transfer' || Boolean(transaction?.transfer_parent_id);
            const isCreditPurchase = transaction?.payment_method === 'credit' && transaction?.karobar_transaction_id;
            const deleteData = (isTransferGroup || isCreditPurchase) && transaction?.version
                ? { base_version: parseInt(transaction.version) }
                : null;
            const res = await window.Api.delete('/transactions/' + id, deleteData);
            if (res.success) {
                if (isTransferGroup) this._invalidateTransferCaches();
                if (isCreditPurchase) this._invalidateCreditPurchaseCaches();
                if (transaction?.type === 'goal_contribution') this._invalidateGoalCaches();
                this._notify('success', 'Transaction deleted successfully');
                this.loadTransactions();
                document.dispatchEvent(new CustomEvent('app:data-changed'));
            } else {
                this._notify('error', res.message || 'Failed to delete transaction');
            }
        } catch (err) {
            console.error('Delete transaction error:', err);
            if (this._isNetworkFailure(err)) await this._queueOfflineDelete(transaction);
            else this._notify('error', err.message || 'Failed to delete transaction');
        }
    }

    async _deleteStandaloneKarobar(transaction) {
        if (navigator.onLine === false) { this._notify('warning', 'Karobar transactions must be deleted while online.'); return; }
        const confirmed = window.Swal
            ? await window.Swal.fire({ title: 'Delete Karobar Transaction', text: 'This removes the same record shown in the Karobar ledger and cannot be undone.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#EF4444', cancelButtonColor: '#6B7280', confirmButtonText: 'Yes, Delete' })
            : { isConfirmed: confirm('Delete this Karobar transaction?') };
        if (!confirmed.isConfirmed) return;
        try {
            const result = await window.Api.delete('/karobar/' + transaction.source_id);
            if (!result?.success) throw new Error(result?.message || 'Failed to delete transaction');
            this._invalidateCreditPurchaseCaches();
            this._notify('success', 'Karobar transaction deleted successfully');
            await this.loadTransactions();
            document.dispatchEvent(new CustomEvent('app:data-changed'));
        } catch (error) {
            this._notify('error', error?.message || 'Failed to delete Karobar transaction');
        }
    }

    async _queueOfflineDelete(transaction) {
        if (!this._canModifyOffline(transaction)) {
            this._notify('warning', 'This transaction must be deleted while online.');
            return false;
        }
        const userId = window.authManager?.getCurrentUser()?.id;
        const result = await window.OfflineStorage?.queueTransactionDelete({
            userId,
            transaction,
            display: {
                category_name: transaction.category_name || '',
                category_icon: transaction.category_icon || '',
                category_color: transaction.category_color || '',
                subcategory_name: transaction.subcategory_name || '',
                account_name: transaction.account_name || ''
            }
        });
        if (!result) {
            this._notify('error', 'Unable to save this deletion offline. Please reconnect and try again.');
            return false;
        }
        await this._loadOfflineSnapshot();
        this._notify('warning', result.cancelledCreate
            ? 'The unsynced transaction was removed from this device.'
            : 'Deletion saved on this device. It will sync when you are online.');
        document.dispatchEvent(new CustomEvent('app:data-changed'));
        if (navigator.onLine && !result.cancelledCreate) window.SanIESync?.syncPendingActions({ trigger: 'pending-delete' });
        return true;
    }

    async undoPendingDelete(localId) {
        const action = await window.OfflineStorage?.getPendingAction(localId);
        const userId = window.authManager?.getCurrentUser()?.id;
        if (!action || action.action !== 'delete' || String(action.userId) !== String(userId) || action.status === 'syncing') return false;
        const deleted = await window.OfflineStorage.deletePendingAction(localId);
        if (!deleted) {
            this._notify('error', 'Unable to undo this pending deletion on the device.');
            return false;
        }
        await this._loadOfflineSnapshot();
        this._notify('success', 'Pending deletion undone.');
        document.dispatchEvent(new CustomEvent('app:data-changed'));
        return true;
    }

    async bulkDeleteSelected() {
        const ids = [...this.selectedIds];
        if (!ids.length) return;
        const confirmed = window.Swal
            ? await window.Swal.fire({ title: `Delete ${ids.length} Transactions?`, text: 'Account balances will be recalculated. This cannot be undone.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#EF4444', confirmButtonText: 'Delete Selected' })
            : { isConfirmed: confirm(`Delete ${ids.length} selected transactions?`) };
        if (!confirmed.isConfirmed) return;

        const button = document.getElementById('tx-bulk-delete-btn');
        if (button) button.disabled = true;
        try {
            const includesTransferGroup = this.transactions.some(transaction =>
                ids.some(id => String(id) === String(transaction.id))
                && (transaction.type === 'transfer' || Boolean(transaction.transfer_parent_id))
            );
            const includesGoalContribution = this.transactions.some(transaction =>
                ids.some(id => String(id) === String(transaction.id)) && transaction.type === 'goal_contribution'
            );
            const res = await window.Api.post('/transactions/bulk-delete', { ids });
            if (includesTransferGroup) this._invalidateTransferCaches();
            if (includesGoalContribution) this._invalidateGoalCaches();
            this._notify('success', res.message || `${ids.length} transactions deleted`);
            this.selectedIds.clear();
            await this.loadTransactions();
            document.dispatchEvent(new CustomEvent('app:data-changed'));
        } catch (err) {
            this._notify('error', err.message || 'Bulk delete failed');
        } finally {
            if (button) button.disabled = false;
        }
    }

    /* ==================== DELEGATED CLICK HANDLING ==================== */

    // Called from inline onclick in table rows
    handleAction(e) {
        const target = e.target.closest('button');
        if (!target) return;
        if (target.dataset.syncRetry) {
            window.SanIESync?.retryAction(target.dataset.syncRetry);
            return;
        }
        if (target.dataset.syncResolve) {
            window.SanIESync?.resolveConflict(target.dataset.syncResolve);
            return;
        }
        if (target.dataset.syncUndo) {
            this.undoPendingDelete(target.dataset.syncUndo);
            return;
        }
        // Desktop table buttons use data-tx-id + class
        let id = target.dataset.txId;
        let action = null;
        if (id) {
            if (target.classList.contains('tx-btn-view')) action = 'view';
            else if (target.classList.contains('tx-btn-edit')) action = 'edit';
            else if (target.classList.contains('tx-btn-delete')) action = 'delete';
        } else {
            // Mobile card buttons use data-tx-view/edit/delete
            id = target.dataset.txView || target.dataset.txEdit || target.dataset.txDelete;
            if (target.dataset.txView) action = 'view';
            else if (target.dataset.txEdit) action = 'edit';
            else if (target.dataset.txDelete) action = 'delete';
        }
        if (!id || !action) return;
        if (action === 'view') this.viewTransaction(id);
        else if (action === 'edit') this.editTransaction(id);
        else if (action === 'delete') this.deleteTransaction(id);
    }

    // Attach delegated listener for table and mobile card action buttons
    _setupDelegatedActions() {
        const table = document.getElementById('transactions-table-body');
        if (table) {
            table.removeEventListener('click', this._delegatedHandler);
            this._delegatedHandler = (e) => this.handleAction(e);
            table.addEventListener('click', this._delegatedHandler);
        }
        const mobileCards = document.getElementById('tx-mobile-cards');
        if (mobileCards) {
            mobileCards.removeEventListener('click', this._mobDelegatedHandler);
            this._mobDelegatedHandler = (e) => this.handleAction(e);
            mobileCards.addEventListener('click', this._mobDelegatedHandler);
        }
    }

    /* ==================== MOBILE CARDS ==================== */

    _renderMobileCards() {
        const container = document.getElementById('tx-mobile-cards');
        if (!container) return;

        if (this.transactions.length === 0) {
            container.style.display = 'none';
            return;
        }

        let list = container.querySelector('.tx-mobile-cards-list');
        if (!list) {
            list = document.createElement('div');
            list.className = 'tx-mobile-cards-list';
            container.insertBefore(list, container.firstChild);
        }

        list.innerHTML = this.transactions.map(t => this._buildMobileCard(t)).join('');

        // Update pagination info
        this._updateMobilePagination();
    }

    _buildMobileCard(t) {
        const date = t.date ? new Date(t.date + 'T00:00:00') : null;
        const day = date ? date.getDate() : '--';
        const month = date ? date.toLocaleString('en', { month: 'short' }) : '';
        const year = date ? date.getFullYear() : '';
        const presentation = this._transactionPresentation(t);
        const accountParty = this._accountPartyPresentation(t);
        const amt = parseFloat(t.amount) || 0;
        const amtFmt = amt.toLocaleString('en-IN');
        const amtPrefix = presentation.direction === 'out' ? '− ' : presentation.direction === 'in' ? '+ ' : '';
        const transactionId = this._h(String(t.id ?? ''));

        const catIcon = t.category_icon ? this._faToBi(t.category_icon) : 'bi-tag-fill';
        const catColor = Formatters.safeColor(t.category_color, '#6366f1');
        const categoryName = t.category_name && t.category_name !== 'Karobar' ? t.category_name : '';
        const subcategoryName = t.subcategory_name || (this._isStandaloneKarobar(t) ? this._karobarTypeLabel(t.karobar_type) : '');
        const catHtml = categoryName
            ? `<div class="tx-mob-cat-icon" style="background:${catColor}"><i class="bi ${catIcon}" aria-hidden="true"></i></div>`
            : '';

        const pendingBusy = t._pending && t.pending_status === 'syncing';
        const pendingConflict = t._pending && t.pending_status === 'conflict';
        const pendingDelete = t._pending && t.pending_action === 'delete';
        const mobileAttentionAction = pendingConflict
            ? `<button class="tx-mob-action" data-sync-resolve="${this._h(t.localId)}" title="Resolve conflict"><i class="bi bi-exclamation-diamond"></i></button>`
            : (t._pending && t.pending_status === 'failed'
                ? `<button class="tx-mob-action" data-sync-retry="${this._h(t.localId)}" title="Retry sync"><i class="bi bi-arrow-clockwise"></i></button>`
                : '');

        return `
            <div class="tx-mob-card" data-tx-id="${transactionId}">
                <div class="tx-mob-card-top">
                    <div class="tx-mob-type-wrap"><input type="checkbox" class="form-check-input tx-select-row" data-tx-select="${transactionId}" aria-label="Select transaction ${transactionId}" ${this.selectedIds.has(Number(t.id)) ? 'checked' : ''} ${(t.scope === 'karobar' || t._pending) ? 'disabled' : ''}><span class="tx-mob-type ${presentation.badgeClass}">${this._h(presentation.label)}</span></div>
                    <span class="tx-mob-amount ${presentation.moneyClass}">${amtPrefix}Rs ${amtFmt}</span>
                    ${t.recurring_definition_id ? `<span class="badge rounded-pill text-bg-light border" title="Scheduled ${this._h(t.recurring_occurrence_date || '')}"><i class="bi bi-arrow-repeat"></i> Recurring</span>` : ''}
                    ${t._pending ? `<span class="badge ${this._pendingBadgeClass(t)}">${this._pendingLabel(t)}</span>` : ''}
                </div>
                <div class="tx-mob-card-mid">
                    ${catHtml}<div class="tx-mob-cat-details"><div class="tx-mob-cat-name">${this._h(categoryName || '—')}${subcategoryName ? `<span class="tx-mob-subcategory"> · ${this._h(subcategoryName)}</span>` : ''}</div><div class="tx-mob-account"><strong>${this._h(accountParty.primary || '—')}</strong>${accountParty.secondary ? ` · ${this._h(accountParty.secondary)}` : ''}</div></div>
                </div>
                <div class="tx-mob-description" title="${this._h(t.description || '')}">${this._h(t.description || '—')}</div>
                <div class="tx-mob-card-bottom">
                    <span class="tx-mob-date">${month} ${day}, ${year}</span>
                    <div class="tx-mob-actions">
                        <button type="button" class="tx-mob-action view" data-tx-view="${transactionId}" title="View transaction" aria-label="View transaction" ${t._pending ? 'disabled' : ''}><i class="bi bi-eye" aria-hidden="true"></i></button>
                        <button type="button" class="tx-mob-action edit" data-tx-edit="${transactionId}" title="Edit transaction" aria-label="Edit transaction" ${(t._pending && (pendingDelete || pendingBusy || pendingConflict)) ? 'disabled' : ''}><i class="bi bi-pencil" aria-hidden="true"></i></button>
                        ${pendingDelete
                            ? `<button type="button" class="tx-mob-action" data-sync-undo="${this._h(t.localId)}" title="Undo deletion" aria-label="Undo deletion" ${pendingBusy || pendingConflict ? 'disabled' : ''}><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>`
                            : `<button type="button" class="tx-mob-action delete" data-tx-delete="${transactionId}" title="Delete transaction" aria-label="Delete transaction" ${(t._pending && (pendingBusy || pendingConflict)) ? 'disabled' : ''}><i class="bi bi-trash3" aria-hidden="true"></i></button>`}
                        ${mobileAttentionAction}
                    </div>
                </div>
            </div>`;
    }

    _bindMobilePagination() {
        const prevBtn = document.getElementById('tx-mob-page-prev');
        const nextBtn = document.getElementById('tx-mob-page-next');

        if (prevBtn && this._mobPrevHandler) {
            prevBtn.removeEventListener('click', this._mobPrevHandler);
        }
        if (nextBtn && this._mobNextHandler) {
            nextBtn.removeEventListener('click', this._mobNextHandler);
        }

        this._mobPrevHandler = () => {
            if (this.pagination?.has_previous) this.loadTransactions(this.currentPage - 1);
        };
        this._mobNextHandler = () => {
            if (this.pagination?.has_next) this.loadTransactions(this.currentPage + 1);
        };

        if (prevBtn) prevBtn.addEventListener('click', this._mobPrevHandler);
        if (nextBtn) nextBtn.addEventListener('click', this._mobNextHandler);
    }

    _updateMobilePagination() {
        const infoEl = document.getElementById('tx-mob-page-info');
        const totalEl = document.getElementById('tx-mob-total');
        const prevBtn = document.getElementById('tx-mob-page-prev');
        const nextBtn = document.getElementById('tx-mob-page-next');

        const page = Number(this.pagination?.page || this.currentPage || 1);
        const total = Number(this.pagination?.total_rows || this.transactions.length);
        if (infoEl) infoEl.textContent = String(page);
        if (totalEl) totalEl.textContent = total + ' transaction' + (total !== 1 ? 's' : '');
        if (prevBtn) prevBtn.disabled = !this.pagination?.has_previous;
        if (nextBtn) nextBtn.disabled = !this.pagination?.has_next;
    }

    _unbindMobilePagination() {
        const prevBtn = document.getElementById('tx-mob-page-prev');
        const nextBtn = document.getElementById('tx-mob-page-next');
        if (prevBtn && this._mobPrevHandler) {
            prevBtn.removeEventListener('click', this._mobPrevHandler);
        }
        if (nextBtn && this._mobNextHandler) {
            nextBtn.removeEventListener('click', this._mobNextHandler);
        }
    }

    _unbindMobileActions() {
        const mobileCards = document.getElementById('tx-mobile-cards');
        if (mobileCards && this._mobDelegatedHandler) {
            mobileCards.removeEventListener('click', this._mobDelegatedHandler);
        }
    }

    /* ==================== HELPERS ==================== */

    _pendingLabel(transaction) {
        if (transaction.pending_status === 'syncing') return 'Syncing...';
        if (transaction.pending_status === 'conflict') return 'Conflict';
        if (transaction.pending_status === 'failed') return 'Sync failed';
        if (transaction.pending_action === 'update') return 'Pending changes';
        if (transaction.pending_action === 'delete') return 'Pending deletion';
        return 'Pending sync';
    }

    _pendingBadgeClass(transaction) {
        if (transaction.pending_status === 'conflict') return 'bg-danger';
        if (transaction.pending_status === 'failed') return 'bg-danger';
        if (transaction.pending_status === 'syncing') return 'bg-info text-dark';
        if (transaction.pending_action === 'delete') return 'bg-secondary';
        return 'bg-warning text-dark';
    }

    _notify(type, message) {
        if (window.NotificationService) {
            const method = typeof NotificationService[type] === 'function' ? type : 'info';
            NotificationService[method](message);
        } else if (window.Swal) {
            const icon = ['success', 'error', 'warning', 'info'].includes(type) ? type : 'info';
            window.Swal.fire({ icon, title: message, timer: 3000, showConfirmButton: false, toast: true, position: 'top-end' });
        } else {
            console.info(`[SanIE] ${message}`);
        }
    }

    _setType(type) {
        const card = document.querySelector(`.tx-type-card[data-type="${type}"]`);
        if (card) card.click();
    }

    showAddTransactionModal() {
        this._openForm();
    }
}

window.TransactionsManager = TransactionsManager;
