// Goals Module - Refactored with ModalService (double-submit fix) and lifecycle hooks
class GoalsManager {
    constructor() {
        this.goals = [];
        this._mounted = false;
    }

    onMount() {
        if (this._mounted) return;
        this._mounted = true;
        this.setupEventListeners();
        if (window.authManager?.isAuthenticated()) {
            this.loadGoals();
        }
    }

    onUnmount() {
        this._mounted = false;
    }

    setupEventListeners() {
        const addBtn = document.getElementById('add-goal-btn');
        if (addBtn) {
            addBtn.addEventListener('click', () => this.showAddGoalModal());
        }
    }

    async loadGoals() {
        if (!window.authManager?.isAuthenticated()) return;

        try {
            const response = await goalsAPI.getAll();
            if (response.success) {
                this.goals = response.data;
                this.renderGoals();
            }
        } catch (error) {
            console.error('Failed to load goals:', error);
            NotificationService.error('Failed to load goals');
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
                showFooter: false,
                onSave: null
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

        setTimeout(() => {
            const form = document.getElementById('goal-form');
            if (form) {
                form.addEventListener('submit', (e) => {
                    e.preventDefault();
                    this.handleGoalSubmit();
                });
            }
        }, 50);
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

        try {
            const response = await goalsAPI.create(data);

            if (response.success) {
                if (window.modalService) modalService.close();
                else if (window.premiumModal) premiumModal.close();
                NotificationService.success('Goal created successfully');
                this.loadGoals();
            }
        } catch (error) {
            console.error('Failed to create goal:', error);
            NotificationService.error('Failed to create goal');
        }
    }

    showContributeModal(goalId) {
        const formHTML = `
            <form id="contribute-form">
                <div class="form-group">
                    <label>Amount</label>
                    <div class="form-control-icon">
                        <i class="fas fa-rupee-sign"></i>
                        <input type="number" id="contribute-amount" step="0.01" placeholder="Rs 5,000" required>
                    </div>
                </div>
            </form>
        `;

        if (window.ModalService) {
            window.modalService.open({
                title: 'Add Contribution',
                subtitle: 'Move closer to your savings goal.',
                icon: 'fa-plus-circle',
                bodyHTML: formHTML,
                showFooter: false,
                onSave: null
            });
        } else if (window.premiumModal) {
            document.getElementById('modal-body').innerHTML = formHTML;
            document.getElementById('modal-footer').classList.add('hidden');
            premiumModal.setTitle('Add Contribution');
            premiumModal.setSubtitle('Move closer to your savings goal.');
            premiumModal.setIcon('fa-plus-circle');
            premiumModal.open();
        }

        setTimeout(() => {
            const form = document.getElementById('contribute-form');
            if (form) {
                form.addEventListener('submit', (e) => {
                    e.preventDefault();
                    this.handleContributeSubmit(goalId);
                });
            }
        }, 50);
    }

    async handleContributeSubmit(goalId) {
        const form = document.getElementById('contribute-form');
        if (form && !form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const amount = parseFloat(document.getElementById('contribute-amount').value);

        try {
            const response = await goalsAPI.contribute(goalId, amount);

            if (response.success) {
                if (window.modalService) modalService.close();
                else if (window.premiumModal) premiumModal.close();
                NotificationService.success('Contribution added successfully');
                this.loadGoals();
            }
        } catch (error) {
            console.error('Failed to add contribution:', error);
            NotificationService.error('Failed to add contribution');
        }
    }

    async deleteGoal(id) {
        const confirmed = await NotificationService.confirm({
            title: 'Delete Goal',
            text: 'Are you sure you want to delete this goal? This action cannot be undone.',
            confirmButtonText: 'Yes, Delete'
        });

        if (!confirmed) return;

        try {
            const response = await goalsAPI.delete(id);

            if (response.success) {
                NotificationService.success('Goal deleted successfully');
                this.loadGoals();
            }
        } catch (error) {
            console.error('Failed to delete goal:', error);
            NotificationService.error(error.message || 'Failed to delete goal');
        }
    }
}

window.GoalsManager = GoalsManager;
