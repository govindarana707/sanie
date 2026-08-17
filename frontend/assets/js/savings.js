class SavingsManager {
    constructor() {
        this.data = null;
        this._goalRequestIds = new Map();
    }

    onMount() {
        this.loadSavingsData();
    }

    onUnmount() {}

    async loadSavingsData() {
        if (!window.authManager?.isAuthenticated()) return;

        document.getElementById('savings-page')?.classList.add('is-loading');
        try {
            const response = await savingsAPI.getData();
            if (response.success) {
                this.data = response.data;
                this.render();
            }
        } catch (error) {
            console.error('Failed to load savings data:', error);
            NotificationService.error('Failed to load savings data');
        } finally {
            document.getElementById('savings-page')?.classList.remove('is-loading');
        }
    }

    render() {
        if (!this.data) return;
        this.renderSummary();
        this.renderGoals();
        this.renderSavingsAccounts();
        this.renderRecentTransactions();
    }

    renderSummary() {
        const d = this.data;
        const el = (id) => document.getElementById(id);

        if (el('savings-total')) el('savings-total').textContent = Formatters.currency(d.total_savings || 0);
        if (el('savings-in-goals')) el('savings-in-goals').textContent = Formatters.currency(d.total_in_goals || 0);
        if (el('savings-in-accounts')) el('savings-in-accounts').textContent = Formatters.currency(d.total_in_accounts || 0);

        const activeGoals = (d.goals || []).filter(g => !g.is_completed).length;
        const totalGoals = (d.goals || []).length;
        if (el('savings-goals-count')) el('savings-goals-count').textContent = `${activeGoals} of ${totalGoals} active`;
        if (el('savings-accounts-count')) el('savings-accounts-count').textContent = `${(d.savings_accounts || []).length} account${(d.savings_accounts || []).length !== 1 ? 's' : ''}`;
    }

    renderGoals() {
        const container = document.getElementById('savings-goals-list');
        if (!container) return;
        const goals = this.data.goals || [];

        if (goals.length === 0) {
            container.innerHTML = `
                <div class="savings-empty-state">
                    <i class="fas fa-bullseye"></i>
                    <h4>No Savings Goals</h4>
                    <p>Create goals to track your savings progress</p>
                    <button class="btn btn-primary" onclick="window.goalsManager?.showAddGoalModal()">
                        <i class="fas fa-plus"></i> Create Goal
                    </button>
                </div>`;
            return;
        }

        container.innerHTML = goals.map(g => {
            const goal = g.goal;
            const pct = Math.min(100, g.percentage);
            let progressClass = 'bg-success';
            if (pct >= 100) progressClass = 'bg-primary';
            else if (pct >= 70) progressClass = 'bg-warning';
            else if (pct < 30) progressClass = 'bg-info';

            const deadlineText = goal.deadline
                ? `<span class="savings-goal-deadline"><i class="fas fa-calendar-alt me-1"></i>${Formatters.date(goal.deadline)}</span>`
                : '';

            const forecastText = g.forecast_date
                ? `<span class="savings-goal-forecast">Est. completion: ${Formatters.date(g.forecast_date)}</span>`
                : '';
            const remaining = Math.max(0, (parseFloat(goal.target_amount) || 0) - (parseFloat(goal.current_amount) || 0));

            return `
                <div class="savings-goal-card">
                    <div class="savings-goal-header">
                        <div class="savings-goal-info">
                            <div class="savings-goal-icon" style="background:${goal.color || '#10B981'}20;color:${goal.color || '#10B981'}">
                                <i class="fas ${goal.icon || 'fa-bullseye'}"></i>
                            </div>
                            <div>
                                <h5 class="savings-goal-name">${Formatters.escapeHTML(goal.name)}</h5>
                                <div class="savings-goal-meta">
                                    ${deadlineText}
                                    ${forecastText}
                                </div>
                            </div>
                        </div>
                        <div class="savings-goal-pct">${pct.toFixed(1)}%</div>
                    </div>
                    <div class="savings-goal-progress">
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar ${progressClass}" style="width: ${pct}%"></div>
                        </div>
                    </div>
                    <div class="savings-goal-footer">
                        <div><span class="savings-goal-amount">${Formatters.currency(goal.current_amount)} <small class="text-muted">of ${Formatters.currency(goal.target_amount)}</small></span><small class="savings-goal-remaining">${Formatters.currency(remaining)} remaining</small></div>
                        <div class="savings-goal-actions">
                            ${!g.is_completed ? `<button class="btn btn-sm btn-outline-success" onclick="savingsManager.contributeToGoal(${goal.id}, '${Formatters.escapeHTML(goal.name).replace(/'/g, "\\'")}')"><i class="fas fa-plus"></i> Add</button>` : '<span class="badge bg-success">Completed</span>'}
                        </div>
                    </div>
                </div>`;
        }).join('');
    }

    renderSavingsAccounts() {
        const container = document.getElementById('savings-accounts-list');
        if (!container) return;
        const accounts = this.data.savings_accounts || [];

        if (accounts.length === 0) {
            container.innerHTML = `
                <div class="savings-empty-state">
                    <i class="fas fa-piggy-bank"></i>
                    <h4>No Savings Accounts</h4>
                    <p>Add a savings account to track your deposits</p>
                    <button class="btn btn-primary" onclick="savingsManager.showAddSavingsAccountModal()">
                        <i class="fas fa-plus"></i> Add Account
                    </button>
                </div>`;
            return;
        }

        container.innerHTML = accounts.map(acc => `
            <div class="savings-account-card" style="--account-accent:${acc.color || '#6366f1'}">
                <div class="savings-account-header">
                    <div class="savings-account-info">
                        <div class="savings-account-icon" style="background:${acc.color || '#6366f1'}20;color:${acc.color || '#6366f1'}">
                            <i class="fas ${acc.icon || 'fa-piggy-bank'}"></i>
                        </div>
                        <div>
                            <h5 class="savings-account-name">${Formatters.escapeHTML(acc.name)}</h5>
                            ${acc.account_number ? `<small class="text-muted">${Formatters.escapeHTML(acc.account_number)}</small>` : ''}
                        </div>
                    </div>
                    <div class="savings-account-balance-wrap"><small>Available balance</small><div class="savings-account-balance">${Formatters.currency(acc.balance)}</div></div>
                </div>
                <div class="savings-account-actions">
                    <button class="btn btn-sm btn-outline-primary" onclick="savingsManager.depositToAccount(${acc.id}, '${Formatters.escapeHTML(acc.name).replace(/'/g, "\\'")}')">
                        <i class="fas fa-arrow-down"></i> Deposit
                    </button>
                </div>
            </div>`).join('');
    }

    renderRecentTransactions() {
        const container = document.getElementById('savings-recent-transactions');
        if (!container) return;
        const txns = this.data.recent_transactions || [];

        if (txns.length === 0) {
            container.innerHTML = '<p class="text-muted text-center py-3">No recent savings transactions</p>';
            return;
        }

        container.innerHTML = txns.map(tx => {
            let sign, cls;
            if (tx.type === 'transfer') {
                sign = '↔';
                cls = 'text-primary';
            } else {
                sign = tx.type === 'income' ? '+' : '-';
                cls = tx.type === 'income' ? 'text-success' : 'text-danger';
            }
            return `
                <div class="savings-recent-item">
                    <span class="savings-activity-icon ${tx.type}"><i class="fas ${tx.type === 'transfer' ? 'fa-exchange-alt' : (tx.type === 'income' ? 'fa-arrow-down' : 'fa-arrow-up')}"></i></span>
                    <div class="savings-recent-info">
                        <span class="savings-recent-desc">${Formatters.escapeHTML(tx.description || tx.category_name || 'Savings')}</span>
                        <small class="text-muted">${Formatters.date(tx.date)}</small>
                    </div>
                    <span class="savings-recent-amount ${cls}">${sign} ${Formatters.currency(tx.amount)}</span>
                </div>`;
        }).join('');
    }

    async contributeToGoal(goalId, goalName) {
        const accounts = (this.data.savings_accounts || [])
            .concat((await (async () => {
                try { const r = await accountsAPI.getAll(); return r.success ? (r.data || []) : []; }
                catch { return []; }
            })()).filter(a => a.is_active));

        const uniqueAccounts = [];
        const seenIds = new Set();
        for (const a of accounts) {
            if (!seenIds.has(a.id)) { seenIds.add(a.id); uniqueAccounts.push(a); }
        }

        const accountOptions = uniqueAccounts.map(a =>
            `<option value="${a.id}">${Formatters.escapeHTML(a.name)} (${Formatters.currency(a.balance)})</option>`
        ).join('');

        const { value: formValues } = await Swal.fire({
            title: `Add to "${goalName}"`,
            html: `
                <div style="text-align:left">
                    <label style="font-size:13px;margin-bottom:4px;display:block">From Account</label>
                    <select id="swal-goal-account" class="swal2-input" style="width:100%;font-size:14px;padding:8px 12px;margin-bottom:12px">
                        <option value="">Select account</option>
                        ${accountOptions}
                    </select>
                    <label style="font-size:13px;margin-bottom:4px;display:block">Amount (Rs)</label>
                    <input id="swal-goal-amount" type="number" class="swal2-input" placeholder="Enter amount" min="1" step="0.01" style="width:100%;font-size:14px;padding:8px 12px">
                </div>
            `,
            showCancelButton: true,
            confirmButtonColor: '#10B981',
            confirmButtonText: '<i class="fas fa-plus me-1"></i> Add',
            focusConfirm: false,
            preConfirm: () => {
                const amt = parseFloat(document.getElementById('swal-goal-amount').value);
                const acc = document.getElementById('swal-goal-account').value;
                if (!acc) { Swal.showValidationMessage('Please select an account'); return false; }
                if (!amt || amt <= 0) { Swal.showValidationMessage('Please enter a valid amount'); return false; }
                return { amount: amt, accountId: parseInt(acc) };
            },
            background: 'var(--glass-bg)',
            backdrop: 'rgba(0, 0, 0, 0.7)',
            customClass: { popup: 'glass-modal', confirmButton: 'btn-premium', cancelButton: 'btn-premium' }
        });

        if (!formValues) return;

        try {
            const requestId = this._goalRequestIds.get(goalId) || (window.crypto?.randomUUID
                ? window.crypto.randomUUID()
                : (`req_goal_${Date.now().toString(36)}_${Math.random().toString(36).slice(2)}`).slice(0, 64));
            this._goalRequestIds.set(goalId, requestId);
            const result = await goalsAPI.contribute(goalId, {
                amount: formValues.amount,
                account_id: formValues.accountId,
                date: new Date().toISOString().slice(0, 10),
                description: '',
                client_request_id: requestId
            });
            if (result.success) {
                this._goalRequestIds.delete(goalId);
                NotificationService.success(`Rs ${formValues.amount.toLocaleString()} added to "${goalName}"`);
                this.loadSavingsData();
            }
        } catch (error) {
            NotificationService.error('Failed to add contribution');
        }
    }

    async depositToAccount(accountId, accountName) {
        const allAccountsRes = await (async () => {
            try { return await accountsAPI.getAll(); }
            catch { return { success: false, data: [] }; }
        })();

        const sourceAccounts = allAccountsRes.success
            ? (allAccountsRes.data || []).filter(a => a.is_active && a.id !== accountId && a.type !== 'savings')
            : [];

        const accountOptions = sourceAccounts.map(a =>
            `<option value="${a.id}">${Formatters.escapeHTML(a.name)} (${Formatters.currency(a.balance)})</option>`
        ).join('');

        const { value: formValues } = await Swal.fire({
            title: `Deposit to "${accountName}"`,
            html: `
                <div style="text-align:left">
                    <label style="font-size:13px;margin-bottom:4px;display:block">From Account</label>
                    <select id="swal-deposit-from" class="swal2-input" style="width:100%;font-size:14px;padding:8px 12px;margin-bottom:12px">
                        <option value="">Select source account</option>
                        ${accountOptions}
                    </select>
                    <label style="font-size:13px;margin-bottom:4px;display:block">Amount (Rs)</label>
                    <input id="swal-deposit-amount" type="number" class="swal2-input" placeholder="Enter amount" min="1" step="0.01" style="width:100%;font-size:14px;padding:8px 12px">
                </div>
            `,
            showCancelButton: true,
            confirmButtonColor: '#10B981',
            confirmButtonText: '<i class="fas fa-arrow-down me-1"></i> Deposit',
            focusConfirm: false,
            preConfirm: () => {
                const amt = parseFloat(document.getElementById('swal-deposit-amount').value);
                const fromAcc = document.getElementById('swal-deposit-from').value;
                if (!fromAcc) { Swal.showValidationMessage('Please select a source account'); return false; }
                if (!amt || amt <= 0) { Swal.showValidationMessage('Please enter a valid amount'); return false; }
                return { amount: amt, fromAccountId: parseInt(fromAcc) };
            },
            background: 'var(--glass-bg)',
            backdrop: 'rgba(0, 0, 0, 0.7)',
            customClass: { popup: 'glass-modal', confirmButton: 'btn-premium', cancelButton: 'btn-premium' }
        });

        if (!formValues) return;

        try {
            const result = await transactionsAPI.create({
                type: 'transfer',
                amount: formValues.amount,
                from_account_id: formValues.fromAccountId,
                to_account_id: accountId,
                category_id: this.data.savings_category_id,
                date: new Date().toISOString().split('T')[0],
                description: `Deposit to ${accountName}`
            });

            if (result.success) {
                NotificationService.success(`Rs ${formValues.amount.toLocaleString()} deposited to "${accountName}"`);
                this.loadSavingsData();
            }
        } catch (error) {
            NotificationService.error('Failed to deposit');
        }
    }

    showAddSavingsAccountModal() {
        const bodyHTML = `
            <form id="savings-account-form">
                <div class="form-group mb-3">
                    <label for="sacc-name">Account Name</label>
                    <div class="form-control-icon">
                        <i class="fas fa-piggy-bank"></i>
                        <input type="text" id="sacc-name" placeholder="e.g. Fixed Deposit" required>
                    </div>
                </div>
                <div class="form-group mb-3">
                    <label for="sacc-number">Account Number (optional)</label>
                    <div class="form-control-icon">
                        <i class="fas fa-hashtag"></i>
                        <input type="text" id="sacc-number" placeholder="Account number">
                    </div>
                </div>
                <div class="form-group mb-3">
                    <label for="sacc-color">Color</label>
                    <input type="color" id="sacc-color" value="#6366f1" class="form-control form-control-color">
                </div>
            </form>`;

        if (window.Swal) {
            Swal.fire({
                title: 'Add Savings Account',
                html: bodyHTML,
                showCancelButton: true,
                confirmButtonColor: '#10B981',
                confirmButtonText: '<i class="fas fa-plus me-1"></i> Create',
                focusConfirm: false,
                preConfirm: () => {
                    const name = document.getElementById('sacc-name').value.trim();
                    if (!name) { Swal.showValidationMessage('Name is required'); return false; }
                    return {
                        name,
                        type: 'savings',
                        account_number: document.getElementById('sacc-number').value.trim(),
                        color: document.getElementById('sacc-color').value,
                        icon: 'fa-piggy-bank',
                        currency: 'NPR',
                        is_active: true,
                        is_default: false
                    };
                },
                background: 'var(--glass-bg)',
                backdrop: 'rgba(0, 0, 0, 0.7)',
                customClass: { popup: 'glass-modal', confirmButton: 'btn-premium', cancelButton: 'btn-premium' }
            }).then(async (result) => {
                if (!result.isConfirmed) return;
                try {
                    const res = await accountsAPI.create(result.value);
                    if (res.success) {
                        NotificationService.success('Savings account created');
                        this.loadSavingsData();
                    }
                } catch (error) {
                    NotificationService.error('Failed to create account');
                }
            });
        }
    }
}

window.SavingsManager = SavingsManager;
