class AccountsManager {
    constructor() {
        this._eventHandler = null;
        this.accounts = [];
        this._searchHandler = null;
    }

    async onMount() {
        AjaxService.showSkeleton('#accounts-list', 3, 'card');
        await this.loadAccounts();
        this._bindAddButton();
        const search = document.getElementById('accounts-search-input');
        if (search) {
            this._searchHandler = () => this._filterAccountCards(search.value);
            search.addEventListener('input', this._searchHandler);
        }
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
        const search = document.getElementById('accounts-search-input');
        if (search && this._searchHandler) search.removeEventListener('input', this._searchHandler);
        this._searchHandler = null;
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
        this.accounts = accounts || [];
        this._updateOverview(this.accounts);
        if (!accounts || accounts.length === 0) {
            container.innerHTML = '<div class="accounts-empty-state"><span><i class="bi bi-wallet2"></i></span><h4>No Accounts Yet</h4><p>Create your first account to start tracking your finances.</p><button class="btn btn-primary" onclick="window.accountsManager?.showAddAccountModal()"><i class="bi bi-plus-lg me-1"></i> Create Account</button></div>';
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
            const searchText = Formatters.escapeHTML([a.name, label, a.account_number || ''].join(' ').toLowerCase());
            const accountName = Formatters.escapeHTML(a.name);
            const isExcludedFromNetBalance = String(a.include_in_net_balance) === '0';
            return '<div class="account-card" data-account-search="' + searchText + '" onclick="window.appRouter?.navigate(\'account-details?id=' + a.id + '\')" style="cursor:pointer;--account-color:' + color + ';">'
                + '<div class="account-card-header">'
                + '<div class="account-card-icon" style="background:' + color + '15;color:' + color + ';"><i class="bi ' + icon + '"></i></div>'
                + '<div class="account-card-info"><h4 class="account-card-name">' + accountName + '</h4><span class="account-card-type">' + Formatters.escapeHTML(label) + '</span></div>'
                + (a.is_default ? '<span class="account-card-badge">Default</span>' : '')
                + '</div>'
                + (a.account_number ? '<div class="account-card-number">' + a.account_number + '</div>' : '')
                + '<div class="account-card-balance"><span class="account-card-balance-label">Current Balance</span><span class="account-card-balance-value">' + Formatters.currency(bal) + '</span>'
                + (isExcludedFromNetBalance ? '<span class="account-card-net-excluded"><i class="bi bi-slash-circle" aria-hidden="true"></i> Excluded from Net Balance</span>' : '')
                + '</div>'
                + '<div class="account-card-stats"><div class="account-card-stat"><span class="stat-mini-label">Income</span><span class="stat-mini-value income">' + Formatters.currency(inc) + '</span></div><div class="account-card-stat"><span class="stat-mini-label">Expense</span><span class="stat-mini-value expense">' + Formatters.currency(exp) + '</span></div></div>'
                + '<div class="account-card-footer">'
                + '<span class="account-card-last-tx"><i class="bi bi-clock" aria-hidden="true"></i> ' + lastTxText + '</span>'
                + '<div class="account-card-actions">'
                + '<button type="button" class="btn btn-sm btn-outline-primary account-card-ledger" onclick="event.stopPropagation();window.accountsManager?.openAccountLedger(' + a.id + ')" title="Show account transactions" aria-label="View ledger for ' + accountName + '"><i class="bi bi-journal-text" aria-hidden="true"></i><span>Ledger</span></button>'
                + '<button type="button" class="btn btn-sm btn-outline-secondary account-card-icon-action" onclick="event.stopPropagation();window.accountsManager?.showEditAccountModal(' + a.id + ')" title="Edit account" aria-label="Edit ' + accountName + '"><i class="bi bi-pencil" aria-hidden="true"></i></button>'
                + '<button type="button" class="btn btn-sm btn-outline-danger account-card-icon-action" onclick="event.stopPropagation();window.accountsManager?.showDeleteAccountConfirm(' + a.id + ',\'' + accountName.replace(/'/g, "\\'") + '\')" title="Delete account" aria-label="Delete ' + accountName + '"><i class="bi bi-trash3" aria-hidden="true"></i></button>'
                + '</div>'
                + '</div>'
                + '</div>';
        }).join('') + '</div>';
    }

    _updateOverview(accounts) {
        const total = accounts.reduce((sum, a) => sum + (parseFloat(a.calculated_balance ?? a.balance) || 0), 0);
        const savings = accounts.filter(a => a.type === 'savings' || String(a.include_in_savings) === '1').length;
        const active = accounts.filter(a => String(a.is_active) !== '0').length;
        const set = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = value; };
        set('accounts-total-balance', Formatters.currency(total));
        set('accounts-total-count', accounts.length.toLocaleString());
        set('accounts-savings-count', savings.toLocaleString());
        set('accounts-active-count', active.toLocaleString());
    }

    _filterAccountCards(value) {
        const query = String(value || '').trim().toLowerCase();
        document.querySelectorAll('#accounts-list .account-card').forEach(card => {
            card.hidden = query !== '' && !card.dataset.accountSearch.includes(query);
        });
        const grid = document.querySelector('#accounts-list .accounts-grid');
        if (grid) grid.classList.toggle('has-no-results', ![...grid.querySelectorAll('.account-card')].some(card => !card.hidden));
    }

    openAccountLedger(accountId) {
        const manager = window.transactionsManager;
        if (!manager) return;
        manager.filters = { account_id: accountId };
        const globalSearch = document.getElementById('global-search');
        if (globalSearch) globalSearch.value = '';
        window.appRouter?.navigate('transactions');
    }

    /* ============ FORM BUILDERS ============ */

    _accountFormHTML(account) {
        const e = a => account ? (account[a] || '') : '';
        const includeInNetBalance = !account || !(account.include_in_net_balance === false || String(account.include_in_net_balance) === '0');
        const sel = (v, val) => v === val ? 'selected' : '';
        const types = ['cash','bank','esewa','khalti','ime_pay','wallet','credit_card','savings','current'];
        const typeLabels = { cash:'Cash', bank:'Bank Account', esewa:'eSewa', khalti:'Khalti', ime_pay:'IME Pay', wallet:'Wallet', credit_card:'Credit Card', savings:'Savings Account', current:'Current Account' };
        const settingRow = (id, title, description, checked) =>
            '<div class="account-setting-row">'
            + '<div class="account-setting-content">'
            + '<label class="account-setting-title" for="' + id + '">' + title + '</label>'
            + '<p class="account-setting-description" id="' + id + '-description">' + description + '</p>'
            + '</div>'
            + '<div class="account-setting-control">'
            + '<input type="checkbox" id="' + id + '" class="form-check-input account-setting-toggle" role="switch" aria-describedby="' + id + '-description" ' + (checked ? 'checked' : '') + '>'
            + '</div>'
            + '</div>';
        return '<form id="acct-form" novalidate>'
            + '<div class="mb-3"><label class="form-label fw-semibold">Account Name</label><input type="text" id="acct-name" class="form-control" value="' + Formatters.escapeHTML(e('name')) + '" required placeholder="e.g. My Savings"></div>'
            + '<div class="mb-3"><label class="form-label fw-semibold">Account Type</label><select id="acct-type" class="form-select">'
            + types.map(t => '<option value="' + t + '" ' + sel(e('type'), t) + '>' + typeLabels[t] + '</option>').join('')
            + '</select></div>'
            + '<div class="mb-3"><label class="form-label fw-semibold">Account Number <small class="text-muted">(optional)</small></label><input type="text" id="acct-number" class="form-control" value="' + Formatters.escapeHTML(e('account_number')) + '" placeholder="e.g. 00123456789"></div>'
            + '<div class="mb-3"><label class="form-label fw-semibold">Opening Balance</label><div class="input-group"><span class="input-group-text">Rs</span><input type="number" id="acct-opening" class="form-control" step="0.01" value="' + (parseFloat(e('opening_balance')) || 0) + '"></div><small class="text-muted">' + (account ? 'Changing opening balance will recalculate current balance.' : 'Enter the amount currently available in this account.') + '</small></div>'
            + '<section class="account-settings-section" aria-labelledby="account-settings-heading">'
            + '<h3 class="account-settings-heading" id="account-settings-heading">Account Settings</h3>'
            + '<div class="account-settings-list">'
            + settingRow('acct-default', 'Set as default account', 'Use this account as the default for new transactions.', e('is_default'))
            + settingRow('acct-net-balance', 'Include in Net Balance', 'Show this account balance in your available Net Balance.', includeInNetBalance)
            + settingRow('acct-savings', 'Count this account as savings', 'Include this balance in Savings without changing its account type.', e('include_in_savings') || e('type') === 'savings')
            + settingRow('acct-active', 'Active', 'Allow this account to be used for transactions.', e('is_active') !== '0')
            + '</div></section>'
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
        const opening = String(document.getElementById('acct-opening')?.value ?? '').trim() || '0';
        const openingNumber = Number(opening);
        if (!/^-?(?:0|[1-9]\d{0,11})(?:\.\d{1,2})?$/.test(opening)
            || !Number.isFinite(openingNumber) || Math.abs(openingNumber) > 999999999999.99) {
            NotificationService.error('Opening balance must be a valid amount with at most two decimal places');
            return;
        }
        const isDefault = document.getElementById('acct-default')?.checked || false;
        const isActive = document.getElementById('acct-active')?.checked !== false;
        const includeInSavings = document.getElementById('acct-savings')?.checked || type === 'savings';
        const includeInNetBalance = document.getElementById('acct-net-balance')?.checked !== false;

        const data = { name, type, account_number: number, is_default: isDefault, is_active: isActive, include_in_savings: includeInSavings, include_in_net_balance: includeInNetBalance, opening_balance: opening };

        let result;
        if (id) {
            result = await AjaxService.put('/accounts/' + id, data, {
                loading: '#modal-save-btn',
                successMsg: 'Account updated successfully',
                errorMsg: 'Failed to update account',
            });
        } else {
            data.balance = opening;
            result = await AjaxService.post('/accounts', data, {
                loading: '#modal-save-btn',
                successMsg: 'Account created successfully',
                errorMsg: 'Failed to create account',
            });
        }
        if (!result?.success) return;
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
