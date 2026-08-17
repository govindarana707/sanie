// Goals Module - Refactored with ModalService (double-submit fix) and lifecycle hooks
class GoalsManager {
    constructor() {
        this.goals = [];
        this._mounted = false;
        this._listeners = {};
        this._contributionRequestId = null;
    }

    onMount() {
        if (this._mounted) return;
        this._mounted = true;
        this.setupEventListeners();
        window.addEventListener('app:data-changed', this._onDataChanged = () => this.loadGoals());
        if (window.authManager?.isAuthenticated()) {
            this.loadGoals();
        }
    }

    onUnmount() {
        this._mounted = false;
        if (this._onDataChanged) {
            window.removeEventListener('app:data-changed', this._onDataChanged);
        }
        const addBtn = document.getElementById('add-goal-btn');
        if (addBtn && this._listeners.addClick) {
            addBtn.removeEventListener('click', this._listeners.addClick);
        }
    }

    setupEventListeners() {
        const addBtn = document.getElementById('add-goal-btn');
        if (addBtn) {
            this._listeners.addClick = () => this.showAddGoalModal();
            addBtn.addEventListener('click', this._listeners.addClick);
        }
    }

    async loadGoals() {
        if (!window.authManager?.isAuthenticated()) return;

        AjaxService?.showSkeleton('goals-grid');
        try {
            const response = await goalsAPI.getAll();
            if (response.success) {
                this.goals = response.data;
                this.renderGoals();
            }
        } catch (error) {
            console.error('Failed to load goals:', error);
            NotificationService.error('Failed to load goals');
        } finally {
            AjaxService?.hideSkeleton('goals-grid');
        }
    }

    renderGoals() {
        const container = document.getElementById('goals-grid');
        container.innerHTML = '';

        if (this.goals.length === 0) {
            container.innerHTML = '<p class="no-data">No goals set. Click "Add Goal" to create one.</p>';
            return;
        }

        this.goals.forEach(goal => {
            const card = document.createElement('div');
            card.className = 'goal-card';

            const percentage = goal.target_amount > 0 ? (goal.current_amount / goal.target_amount) * 100 : 0;

            card.innerHTML = `
                <div class="goal-header">
                    <p class="goal-name">${Formatters.escapeHTML(goal.name)}</p>
                    <div class="goal-icon">
                        <i class="fas ${goal.icon || 'fa-bullseye'}"></i>
                    </div>
                </div>
                <p class="goal-amount">${Formatters.currency(goal.current_amount)}</p>
                <p class="goal-target">of ${Formatters.currency(goal.target_amount)}</p>
                <div class="goal-progress-bar">
                    <div class="goal-progress-fill" style="width: ${Math.min(100, percentage)}%"></div>
                </div>
                <p class="goal-percentage">${percentage.toFixed(1)}% complete</p>
                ${goal.deadline ? `<p class="goal-deadline">Deadline: ${Formatters.date(goal.deadline)}</p>` : ''}
                <div class="goal-actions" style="margin-top: 1rem; display: flex; gap: 0.5rem;">
                    <button class="btn btn-primary btn-sm" onclick="goalsManager.showContributeModal(${goal.id})">
                        <i class="fas fa-plus"></i> Add
                    </button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="goalsManager.showContributions(${goal.id})">
                        <i class="fas fa-history"></i> History
                    </button>
                    <button class="btn btn-icon" onclick="goalsManager.deleteGoal(${goal.id})">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `;

            container.appendChild(card);
        });
    }

    showAddGoalModal() {
        const formHTML = `
            <form id="goal-form">
                <div class="row g-4">
                    <div class="col-lg-6">
                        <div class="form-group">
                            <label>Goal Name</label>
                            <div class="form-control-icon">
                                <i class="fas fa-pen"></i>
                                <input type="text" id="goal-name" placeholder="e.g. New Laptop" required>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="form-group">
                            <label>Target Amount</label>
                            <div class="form-control-icon">
                                <i class="fas fa-rupee-sign"></i>
                                <input type="number" id="goal-target-amount" step="0.01" placeholder="Rs 100,000" required>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="form-group">
                            <label>Initial Amount (Optional)</label>
                            <div class="form-control-icon">
                                <i class="fas fa-coins"></i>
                                <input type="number" id="goal-current-amount" step="0.01" value="0">
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="form-group">
                            <label>Deadline (Optional)</label>
                            <div class="form-control-icon">
                                <i class="fas fa-calendar-alt"></i>
                                <input type="date" id="goal-deadline">
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="form-group">
                            <label>Icon</label>
                            <div class="form-control-icon">
                                <i class="fas fa-icons"></i>
                                <select id="goal-icon">
                                    <option value="fa-bullseye">Target</option>
                                    <option value="fa-home">House</option>
                                    <option value="fa-car">Car</option>
                                    <option value="fa-laptop">Laptop</option>
                                    <option value="fa-plane">Travel</option>
                                    <option value="fa-graduation-cap">Education</option>
                                    <option value="fa-ring">Wedding</option>
                                    <option value="fa-piggy-bank">Savings</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="form-group">
                            <label>Description (Optional)</label>
                            <textarea id="goal-description" rows="3" placeholder="What is this goal for?"></textarea>
                        </div>
                    </div>
                </div>
            </form>
        `;

        if (window.ModalService) {
            window.modalService.open({
                title: 'Add Savings Goal',
                subtitle: 'Define a target and track progress.',
                icon: 'fa-bullseye',
                bodyHTML: formHTML,
                onSave: () => this.handleGoalSubmit()
            });
        } else if (window.premiumModal) {
            document.getElementById('modal-body').innerHTML = formHTML;
            document.getElementById('modal-footer').classList.add('hidden');
            premiumModal.setTitle('Add Savings Goal');
            premiumModal.setSubtitle('Define a target and track progress.');
            premiumModal.setIcon('fa-bullseye');
            premiumModal.open();
        }

        if (window.DatePickerManager) {
            DatePickerManager.bind('#goal-deadline');
        }
    }

    async handleGoalSubmit() {
        const form = document.getElementById('goal-form');
        if (form && !form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const data = {
            name: document.getElementById('goal-name').value,
            target_amount: parseFloat(document.getElementById('goal-target-amount').value),
            current_amount: parseFloat(document.getElementById('goal-current-amount').value) || 0,
            deadline: document.getElementById('goal-deadline').value || null,
            icon: document.getElementById('goal-icon').value,
            description: document.getElementById('goal-description').value
        };

        const saveBtn = document.querySelector('#modal-footer .btn-primary');
        AjaxService?.showButtonLoading(saveBtn);
        try {
            const response = await goalsAPI.create(data);

            if (response.success) {
                if (window.modalService) modalService.close();
                else if (window.premiumModal) premiumModal.close();
                NotificationService.success('Goal created successfully');
                window.dispatchEvent(new CustomEvent('app:data-changed'));
            }
        } catch (error) {
            console.error('Failed to create goal:', error);
            NotificationService.error('Failed to create goal');
        } finally {
            AjaxService?.hideButtonLoading(saveBtn);
        }
    }

    async showContributeModal(goalId) {
        this._contributionRequestId = null;
        let accounts = [];
        try {
            const res = await accountsAPI.getAll();
            if (res.success) {
                accounts = (res.data || []).filter(a => a.is_active);
            }
        } catch (e) { /* ignore */ }

        const accountOptions = accounts.map(a =>
            `<option value="${a.id}">${Formatters.escapeHTML(a.name)} (${Formatters.currency(a.balance)})</option>`
        ).join('');

        const formHTML = `
            <form id="contribute-form">
                <div class="form-group mb-3">
                    <label>From Account</label>
                    <div class="form-control-icon">
                        <i class="fas fa-university"></i>
                        <select id="contribute-account" required class="form-select">
                            <option value="">Select account</option>
                            ${accountOptions}
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Amount</label>
                    <div class="form-control-icon">
                        <i class="fas fa-rupee-sign"></i>
                        <input type="number" id="contribute-amount" step="0.01" placeholder="Rs 5,000" required>
                    </div>
                </div>
                <div class="form-group mt-3">
                    <label>Date</label>
                    <input type="date" id="contribute-date" class="form-control" value="${new Date().toISOString().slice(0, 10)}" required>
                </div>
                <div class="form-group mt-3">
                    <label>Note (Optional)</label>
                    <input type="text" id="contribute-description" class="form-control" maxlength="255" placeholder="Contribution note">
                </div>
            </form>
        `;

        if (window.ModalService) {
            window.modalService.open({
                title: 'Add Contribution',
                subtitle: 'Move closer to your savings goal.',
                icon: 'fa-plus-circle',
                bodyHTML: formHTML,
                onSave: () => this.handleContributeSubmit(goalId)
            });
        } else if (window.premiumModal) {
            document.getElementById('modal-body').innerHTML = formHTML;
            document.getElementById('modal-footer').classList.add('hidden');
            premiumModal.setTitle('Add Contribution');
            premiumModal.setSubtitle('Move closer to your savings goal.');
            premiumModal.setIcon('fa-plus-circle');
            premiumModal.open();
        }
    }

    async handleContributeSubmit(goalId) {
        const form = document.getElementById('contribute-form');
        if (form && !form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const amount = parseFloat(document.getElementById('contribute-amount').value);
        const accountId = document.getElementById('contribute-account').value;
        const date = document.getElementById('contribute-date').value;
        const description = (document.getElementById('contribute-description').value || '').trim();

        if (!accountId) {
            NotificationService.error('Please select an account');
            return;
        }

        const saveBtn = document.querySelector('#modal-footer .btn-primary');
        AjaxService?.showButtonLoading(saveBtn);
        try {
            const response = await goalsAPI.contribute(goalId, {
                amount,
                account_id: parseInt(accountId),
                date,
                description,
                client_request_id: this._contributionRequestId || (this._contributionRequestId = this._createRequestId())
            });

            if (response.success) {
                if (window.modalService) modalService.close();
                else if (window.premiumModal) premiumModal.close();
                NotificationService.success('Contribution added successfully');
                this._contributionRequestId = null;
                window.dispatchEvent(new CustomEvent('app:data-changed'));
            }
        } catch (error) {
            console.error('Failed to add contribution:', error);
            NotificationService.error('Failed to add contribution');
        } finally {
            AjaxService?.hideButtonLoading(saveBtn);
        }
    }

    _createRequestId() {
        if (window.crypto?.randomUUID) return window.crypto.randomUUID();
        return (`req_goal_${Date.now().toString(36)}_${Math.random().toString(36).slice(2)}`).slice(0, 64);
    }

    async showContributions(goalId) {
        try {
            const response = await goalsAPI.getContributions(goalId);
            const rows = (response.data || []).map(item => `
                <tr>
                    <td>${Formatters.date(item.date)}</td>
                    <td>${Formatters.escapeHTML(item.account_name || '')}</td>
                    <td>${Formatters.currency(item.amount)}</td>
                    <td>${Formatters.escapeHTML(item.description || '')}</td>
                    <td class="text-nowrap">
                        <button class="btn btn-sm btn-outline-primary" onclick="goalsManager.editContribution(${goalId}, ${item.id})">Edit</button>
                        <button class="btn btn-sm btn-outline-danger" onclick="goalsManager.deleteContribution(${goalId}, ${item.id}, ${item.version})">Delete</button>
                    </td>
                </tr>`).join('');
            const html = `<div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Account</th><th>Amount</th><th>Note</th><th></th></tr></thead><tbody>${rows || '<tr><td colspan="5" class="text-center text-muted">No contributions yet</td></tr>'}</tbody></table></div>`;
            if (window.modalService) {
                modalService.open({ title: 'Contribution History', subtitle: 'Every account-to-goal movement.', icon: 'fa-history', bodyHTML: html, showFooter: false });
            } else {
                await Swal.fire({ title: 'Contribution History', html, width: 850, showConfirmButton: false, showCloseButton: true });
            }
        } catch (error) {
            NotificationService.error(error.message || 'Failed to load contributions');
        }
    }

    async editContribution(goalId, contributionId) {
        const response = await goalsAPI.getContributions(goalId);
        const contribution = (response.data || []).find(item => String(item.id) === String(contributionId));
        if (!contribution) return NotificationService.error('Contribution not found');
        const accountsResponse = await accountsAPI.getAll();
        const options = (accountsResponse.data || []).filter(a => a.is_active).map(a =>
            `<option value="${a.id}" ${String(a.id) === String(contribution.account_id) ? 'selected' : ''}>${Formatters.escapeHTML(a.name)}</option>`
        ).join('');
        const { value } = await Swal.fire({
            title: 'Edit Contribution',
            html: `<select id="edit-goal-account" class="swal2-input">${options}</select><input id="edit-goal-amount" type="number" min="0.01" step="0.01" class="swal2-input" value="${contribution.amount}"><input id="edit-goal-date" type="date" class="swal2-input" value="${contribution.date}"><input id="edit-goal-note" class="swal2-input" maxlength="255" value="${Formatters.escapeHTML(contribution.description || '')}">`,
            showCancelButton: true,
            preConfirm: () => ({
                account_id: parseInt(document.getElementById('edit-goal-account').value),
                amount: document.getElementById('edit-goal-amount').value,
                date: document.getElementById('edit-goal-date').value,
                description: document.getElementById('edit-goal-note').value,
                base_version: parseInt(contribution.version)
            })
        });
        if (!value) return;
        try {
            await goalsAPI.updateContribution(goalId, contributionId, value);
            NotificationService.success('Contribution updated successfully');
            window.dispatchEvent(new CustomEvent('app:data-changed'));
            this.showContributions(goalId);
        } catch (error) {
            NotificationService.error(error.message || 'Failed to update contribution');
        }
    }

    async deleteContribution(goalId, contributionId, version) {
        const confirmed = await NotificationService.confirm({ title: 'Delete Contribution', text: 'This will restore the source account balance.', confirmButtonText: 'Delete' });
        if (!confirmed) return;
        try {
            await goalsAPI.deleteContribution(goalId, contributionId, version);
            NotificationService.success('Contribution deleted successfully');
            window.dispatchEvent(new CustomEvent('app:data-changed'));
            this.showContributions(goalId);
        } catch (error) {
            NotificationService.error(error.message || 'Failed to delete contribution');
        }
    }

    async deleteGoal(id) {
        const confirmed = await NotificationService.confirm({
            title: 'Delete Goal',
            text: 'Are you sure you want to delete this goal? This action cannot be undone.',
            confirmButtonText: 'Yes, Delete'
        });

        if (!confirmed) return;

        AjaxService?.showButtonLoading(event?.target);
        try {
            const response = await goalsAPI.delete(id);

            if (response.success) {
                NotificationService.success('Goal deleted successfully');
                window.dispatchEvent(new CustomEvent('app:data-changed'));
            }
        } catch (error) {
            console.error('Failed to delete goal:', error);
            NotificationService.error(error.message || 'Failed to delete goal');
        } finally {
            AjaxService?.hideButtonLoading(event?.target);
        }
    }
}

window.GoalsManager = GoalsManager;
