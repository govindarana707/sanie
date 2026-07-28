class AccountsManager {
    constructor() {
        this._eventHandler = null;
    }

    async onMount() {
        AjaxService.showSkeleton('#accounts-list', 3, 'card');
        await this.loadAccounts();
        this._bindAddButton();
        this._eventHandler = () => this.loadAccounts();
        document.addEventListener('app:data-changed', this._eventHandler);
    }

    onUnmount() {
        const container = document.getElementById('accounts-list');
        if (container) container.innerHTML = '';
        if (this._eventHandler) {
            document.removeEventListener('app:data-changed', this._eventHandler);
            this._eventHandler = null;
        }
    }

    _bindAddButton() {
        const btn = document.getElementById('add-account-btn');
        if (btn) {
            btn.onclick = () => this.showAddAccountModal();
        }
    }

    /* ============ LOAD & RENDER ============ */

    async loadAccounts() {
        const container = document.getElementById('accounts-list');
        if (!container) return;
        try {
            const res = await AjaxService.get('/accounts/overview', { silent: true });
            if (res.success) {
                this.renderAccounts(res.data);
            } else {
                container.innerHTML = '<div class="empty-state"><h4>Error</h4><p>' + Formatters.escapeHTML(res.message || 'Failed to load accounts') + '</p></div>';
            }
        } catch (err) {
            container.innerHTML = '<div class="empty-state"><h4>Error</h4><p>Failed to load accounts. Try again.</p></div>';
        }
    }

    renderAccounts(accounts) {
        const container = document.getElementById('accounts-list');
        if (!container) return;
        if (!accounts || accounts.length === 0) {
            container.innerHTML = '<div class="empty-state py-5"><div class="empty-state-icon"><i class="bi bi-wallet2"></i></div><h4>No Accounts Yet</h4><p>Create your first account to start tracking your finances</p></div>';
            return;
        }
        const icons = { bank:'bi-bank', cash:'bi-cash-stack', esewa:'bi-phone', khalti:'bi-phone', ime_pay:'bi-phone', wallet:'bi-wallet2', credit_card:'bi-credit-card', savings:'bi-piggy-bank', current:'bi-bank' };
        const labels = { bank:'Bank Account', cash:'Cash', esewa:'eSewa', khalti:'Khalti', ime_pay:'IME Pay', wallet:'Wallet', credit_card:'Credit Card', savings:'Savings Account', current:'Current Account' };
        container.innerHTML = '<div class="accounts-grid">' + accounts.map(a => {
            const bal = parseFloat(a.calculated_balance ?? a.balance) || 0;
            const inc = parseFloat(a.total_income) || 0;
            const exp = parseFloat(a.total_expense) || 0;
            const icon = icons[a.type] || 'bi-wallet2';
            const label = labels[a.type] || a.type;
            const color = a.color || '#10B981';
            const lastTx = a.last_transaction_date;
            let lastTxText = 'No transactions';
            if (lastTx) {
                const d = new Date(lastTx);
                const diff = Math.floor((Date.now() - d) / 86400000);
                lastTxText = diff === 0 ? 'Today' : diff === 1 ? 'Yesterday' : diff < 7 ? diff + ' days ago' : Formatters.date(lastTx);
            }
            return '<div class="account-card" onclick="window.appRouter?.navigate(\'account-details?id=' + a.id + '\')" style="cursor:pointer;">'
                + '<div class="account-card-header">'
                + '<div class="account-card-icon" style="background:' + color + '15;color:' + color + ';"><i class="bi ' + icon + '"></i></div>'
                + '<div class="account-card-info"><h4 class="account-card-name">' + Formatters.escapeHTML(a.name) + '</h4><span class="account-card-type">' + label + '</span></div>'
                + (a.is_default ? '<span class="account-card-badge">Default</span>' : '')
                + '</div>'
                + (a.account_number ? '<div class="account-card-number">' + a.account_number + '</div>' : '')
                + '<div class="account-card-balance"><span class="account-card-balance-label">Current Balance</span><span class="account-card-balance-value">' + Formatters.currency(bal) + '</span></div>'
                + '<div class="account-card-stats"><div class="account-card-stat"><span class="stat-mini-label">Income</span><span class="stat-mini-value income">' + Formatters.currency(inc) + '</span></div><div class="account-card-stat"><span class="stat-mini-label">Expense</span><span class="stat-mini-value expense">' + Formatters.currency(exp) + '</span></div></div>'
                + '<div class="account-card-footer d-flex justify-content-between align-items-center">'
                + '<span class="account-card-last-tx"><i class="bi bi-clock"></i> ' + lastTxText + '</span>'
                + '<span class="d-flex gap-2">'
                + '<button class="btn btn-sm btn-outline-secondary rounded-pill" onclick="event.stopPropagation();window.accountsManager?.showEditAccountModal(' + a.id + ')"><i class="bi bi-pencil"></i></button>'
                + '<button class="btn btn-sm btn-outline-danger rounded-pill" onclick="event.stopPropagation();window.accountsManager?.showDeleteAccountConfirm(' + a.id + ',\'' + Formatters.escapeHTML(a.name).replace(/'/g, "\\'") + '\')"><i class="bi bi-trash3"></i></button>'
                + '</span>'
                + '</div>'
                + '</div>';
        }).join('') + '</div>';
    }

    /* ============ FORM BUILDERS ============ */

    _accountFormHTML(account) {
        const e = a => account ? (account[a] || '') : '';
        const sel = (v, val) => v === val ? 'selected' : '';
        const types = ['cash','bank','esewa','khalti','ime_pay','wallet','credit_card','savings','current'];
        const typeLabels = { cash:'Cash', bank:'Bank Account', esewa:'eSewa', khalti:'Khalti', ime_pay:'IME Pay', wallet:'Wallet', credit_card:'Credit Card', savings:'Savings Account', current:'Current Account' };
        return '<form id="acct-form" novalidate>'
            + '<div class="mb-3"><label class="form-label fw-semibold">Account Name</label><input type="text" id="acct-name" class="form-control" value="' + Formatters.escapeHTML(e('name')) + '" required placeholder="e.g. My Savings"></div>'
            + '<div class="mb-3"><label class="form-label fw-semibold">Account Type</label><select id="acct-type" class="form-select">'
            + types.map(t => '<option value="' + t + '" ' + sel(e('type'), t) + '>' + typeLabels[t] + '</option>').join('')
            + '</select></div>'
            + '<div class="mb-3"><label class="form-label fw-semibold">Account Number <small class="text-muted">(optional)</small></label><input type="text" id="acct-number" class="form-control" value="' + Formatters.escapeHTML(e('account_number')) + '" placeholder="e.g. 00123456789"></div>'
            + (account ? '<div class="mb-3"><label class="form-label fw-semibold">Opening Balance</label><div class="input-group"><span class="input-group-text">Rs</span><input type="number" id="acct-opening" class="form-control" step="0.01" value="' + (parseFloat(account.opening_balance) || 0) + '"></div><small class="text-muted">Changing opening balance will recalculate current balance.</small></div>' : '')
            + '<div class="mb-3"><div class="form-check"><input type="checkbox" id="acct-default" class="form-check-input" ' + (e('is_default') ? 'checked' : '') + '><label class="form-check-label" for="acct-default">Set as default account</label></div></div>'
            + '<div class="mb-3"><div class="form-check"><input type="checkbox" id="acct-active" class="form-check-input" ' + (e('is_active') === '0' ? '' : 'checked') + '><label class="form-check-label" for="acct-active">Active</label></div></div>'
            + '</form>';
    }

    /* ============ ADD ACCOUNT ============ */

    showAddAccountModal() {
        if (window.modalService) {
            window.modalService.open({
                title: 'Add Account',
                subtitle: 'Create a new account to track finances',
                icon: 'fa-plus-circle',
                bodyHTML: this._accountFormHTML(null),
                saveText: '<i class="bi bi-check-lg me-1"></i> Create Account',
                onSave: () => this._saveAccount()
            });
        }
        setTimeout(() => { document.getElementById('acct-name')?.focus(); }, 150);
    }

    /* ============ EDIT ACCOUNT ============ */

    async showEditAccountModal(id) {
        AjaxService.showSkeleton('#accounts-list', 1, 'card');
        try {
            const res = await AjaxService.get('/accounts/' + id, { silent: true });
            if (!res.success || !res.data) { NotificationService.error('Account not found'); return; }
            if (window.modalService) {
                window.modalService.open({
                    title: 'Edit Account',
                    subtitle: 'Update account details',
                    icon: 'fa-pencil',
                    bodyHTML: this._accountFormHTML(res.data),
                    saveText: '<i class="bi bi-check-lg me-1"></i> Update Account',
                    onSave: () => this._saveAccount(res.data.id)
                });
            }
        } catch (err) {
            NotificationService.error('Failed to load account');
        }
    }

    /* ============ SAVE (CREATE / UPDATE) ============ */

    async _saveAccount(id) {
        const name = document.getElementById('acct-name')?.value.trim();
        if (!name) { NotificationService.error('Account name is required'); return; }
        const type = document.getElementById('acct-type')?.value || 'cash';
        const number = document.getElementById('acct-number')?.value.trim() || null;
        const opening = parseFloat(document.getElementById('acct-opening')?.value) || 0;
        const isDefault = document.getElementById('acct-default')?.checked || false;
        const isActive = document.getElementById('acct-active')?.checked !== false;

        const data = { name, type, account_number: number, is_default: isDefault, is_active: isActive };

        if (id) {
            data.opening_balance = opening;
            await AjaxService.put('/accounts/' + id, data, {
                loading: '#modal-save-btn',
                successMsg: 'Account updated successfully',
                errorMsg: 'Failed to update account',
            });
        } else {
            await AjaxService.post('/accounts', data, {
                loading: '#modal-save-btn',
                successMsg: 'Account created successfully',
                errorMsg: 'Failed to create account',
            });
        }
        window.modalService?.close();
        this.loadAccounts();
    }

    /* ============ DELETE ACCOUNT ============ */

    async showDeleteAccountConfirm(id, name) {
        const confirmed = window.Swal
            ? (await window.Swal.fire({ title: 'Delete Account', text: 'Are you sure you want to delete "' + name + '"? All associated transactions will be removed.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#EF4444', cancelButtonColor: '#6B7280', confirmButtonText: 'Yes, Delete' })).isConfirmed
            : confirm('Delete "' + name + '"?');
        if (!confirmed) return;

        await AjaxService.del('/accounts/' + id, {
            loading: '#accounts-list',
            successMsg: 'Account deleted successfully',
            errorMsg: 'Failed to delete account',
        });
        this.loadAccounts();
    }
}

window.AccountsManager = AccountsManager;
