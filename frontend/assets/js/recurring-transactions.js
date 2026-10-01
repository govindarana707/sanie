class RecurringTransactionsManager {
    constructor() {
        this.definitions = [];
        this.accounts = [];
        this.categories = [];
        this._abort = null;
    }

    onMount() {
        this._abort?.abort();
        this._abort = new AbortController();
        const signal = this._abort.signal;
        document.getElementById('add-recurring-btn')?.addEventListener('click', () => this.openForm(), { signal });
        document.getElementById('recurring-empty-add')?.addEventListener('click', () => this.openForm(), { signal });
        document.getElementById('process-recurring-btn')?.addEventListener('click', () => this.processDue(), { signal });
        document.getElementById('recurring-grid')?.addEventListener('click', event => this._handleCardAction(event), { signal });
        this.load();
    }

    onUnmount() { this._abort?.abort(); this._abort = null; }

    async load() {
        const grid = document.getElementById('recurring-grid');
        if (grid) grid.innerHTML = '<div class="recurring-loading"><span class="spinner-border spinner-border-sm"></span> Loading schedules…</div>';
        try {
            const [definitions, accounts, categories] = await Promise.all([
                recurringTransactionsAPI.getAll(), accountsAPI.getAll(), categoriesAPI.getAll()
            ]);
            this.definitions = definitions.data || [];
            this.accounts = accounts.data || [];
            this.categories = categories.data || [];
            this.render();
        } catch (error) {
            this._showAlert(error.message || 'Could not load recurring transactions.', 'danger');
            if (grid) grid.innerHTML = '';
        }
    }

    render() {
        const grid = document.getElementById('recurring-grid');
        const empty = document.getElementById('recurring-empty');
        if (!grid || !empty) return;
        const active = this.definitions.filter(item => item.is_active);
        const review = this.definitions.filter(item => item.review_required);
        const next = active.map(item => item.next_occurrence).filter(Boolean).sort()[0];
        this._setText('recurring-active-count', active.length);
        this._setText('recurring-review-count', review.length);
        this._setText('recurring-next-date', next ? Formatters.date(next + 'T00:00:00') : '—');
        empty.classList.toggle('d-none', this.definitions.length > 0);
        grid.innerHTML = this.definitions.map(item => this._card(item)).join('');
    }

    _card(item) {
        const isExpense = item.type === 'expense';
        const schedule = this._scheduleLabel(item);
        const next = item.next_occurrence ? Formatters.date(item.next_occurrence + 'T00:00:00') : 'Schedule complete';
        const statusClass = item.is_active ? 'active' : 'inactive';
        const review = item.review_required
            ? `<button class="btn btn-warning btn-sm" data-recurring-action="review" data-id="${item.id}"><i class="bi bi-exclamation-triangle"></i> Review ${item.due_occurrences.length} dates</button>` : '';
        return `<article class="recurring-card ${isExpense ? 'is-expense' : 'is-income'}">
            <div class="recurring-card-top">
                <span class="recurring-type-icon"><i class="bi ${isExpense ? 'bi-arrow-up-right' : 'bi-arrow-down-left'}"></i></span>
                <div class="recurring-card-title"><h3>${this._h(item.description || item.category_name || 'Recurring transaction')}</h3><p>${this._h(item.category_name || 'Uncategorized')}${item.subcategory_name ? ` · ${this._h(item.subcategory_name)}` : ''}</p></div>
                <span class="recurring-status ${statusClass}">${item.is_active ? 'Active' : 'Inactive'}</span>
            </div>
            <div class="recurring-amount ${item.type}">${isExpense ? '−' : '+'}${Formatters.currency(item.amount)}</div>
            <div class="recurring-meta">
                <div><span>Schedule</span><strong>${this._h(schedule)}</strong></div>
                <div><span>Next occurrence</span><strong>${this._h(next)}</strong></div>
                <div><span>Account</span><strong>${this._h(item.account_name || '—')}</strong></div>
                <div><span>History</span><strong>${Number(item.generated_count || 0)} generated</strong></div>
            </div>
            <div class="recurring-card-actions">
                ${review}
                <button class="btn btn-light btn-sm" data-recurring-action="edit" data-id="${item.id}"><i class="bi bi-pencil"></i> Edit</button>
                <button class="btn btn-light btn-sm" data-recurring-action="${item.is_active ? 'deactivate' : 'activate'}" data-id="${item.id}"><i class="bi ${item.is_active ? 'bi-pause-circle' : 'bi-play-circle'}"></i> ${item.is_active ? 'Pause' : 'Resume'}</button>
                <button class="btn btn-light btn-sm text-danger" data-recurring-action="delete" data-id="${item.id}"><i class="bi bi-trash3"></i></button>
            </div>
        </article>`;
    }

    async _handleCardAction(event) {
        const button = event.target.closest('[data-recurring-action]');
        if (!button) return;
        const item = this.definitions.find(row => String(row.id) === button.dataset.id);
        if (!item) return;
        const action = button.dataset.recurringAction;
        if (action === 'edit') return this.openForm(item);
        if (action === 'review') return this.openReview(item.id);
        if (action === 'delete') return this.remove(item);
        if (action === 'deactivate') return this.changeState(item, false);
        if (action === 'activate') return this.changeState(item, true);
    }

    openForm(item = null) {
        const editing = Boolean(item);
        const today = DateUtils.getKathmanduDateString();
        const kathmanduToday = DateUtils.getKathmanduDateParts();
        const type = item?.type || 'expense';
        const accountOptions = this.accounts.filter(a => a.status !== 'archived').map(a => `<option value="${a.id}" ${String(a.id) === String(item?.account_id) ? 'selected' : ''}>${this._h(a.name)}</option>`).join('');
        const categoryOptions = this._categoryOptions(type, item?.category_id);
        const bodyHTML = `<form id="recurring-form" class="recurring-form">
            <div class="recurring-form-grid">
                <label>Type<select class="form-select" id="recurring-type" required><option value="expense" ${type === 'expense' ? 'selected' : ''}>Expense</option><option value="income" ${type === 'income' ? 'selected' : ''}>Income</option></select></label>
                <label>Amount<input class="form-control" id="recurring-amount" type="number" min="0.01" step="0.01" value="${this._h(item?.amount || '')}" required></label>
                <label>Account<select class="form-select" id="recurring-account" required><option value="">Select account</option>${accountOptions}</select></label>
                <label>Category<select class="form-select" id="recurring-category" required><option value="">Select category</option>${categoryOptions}</select></label>
                <label>Subcategory<select class="form-select" id="recurring-subcategory"><option value="">None</option></select></label>
                <label>Frequency<select class="form-select" id="recurring-frequency" required>${['daily','weekly','bi_weekly','monthly','quarterly','yearly'].map(f => `<option value="${f}" ${f === (item?.frequency || 'monthly') ? 'selected' : ''}>${this._frequencyName(f)}</option>`).join('')}</select></label>
                <label id="recurring-weekday-wrap">Weekday<select class="form-select" id="recurring-weekday">${['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'].map((day, i) => `<option value="${i + 1}" ${Number(item?.day_of_week || 1) === i + 1 ? 'selected' : ''}>${day}</option>`).join('')}</select></label>
                <label id="recurring-monthday-wrap">Day of month<input class="form-control" id="recurring-monthday" type="number" min="1" max="31" value="${Number(item?.day_of_month || kathmanduToday.day)}"></label>
                <label>Start date<input class="form-control" id="recurring-start" type="date" value="${this._h(item?.start_date || today)}" required></label>
                <label>End date <small>(optional)</small><input class="form-control" id="recurring-end" type="date" value="${this._h(item?.end_date || '')}"></label>
                <label class="recurring-form-wide">Description<input class="form-control" id="recurring-description" maxlength="255" value="${this._h(item?.description || '')}" placeholder="Rent, salary, subscription…"></label>
            </div>
            <p class="recurring-form-note"><i class="bi bi-shield-check"></i> Dates are calculated by SanIE on the server. Creating this schedule does not immediately create a transaction.</p>
        </form>`;
        window.modalService.open({
            title: editing ? 'Edit recurring transaction' : 'New recurring transaction',
            subtitle: editing ? 'Future ungenerated dates use the updated values.' : 'Set an online, server-verified schedule.',
            icon: 'fa-repeat', bodyHTML, saveText: `<i class="fas fa-check me-1"></i> ${editing ? 'Save Changes' : 'Create Schedule'}`,
            onSave: () => this._saveForm(item)
        });
        const frequency = document.getElementById('recurring-frequency');
        const typeSelect = document.getElementById('recurring-type');
        const category = document.getElementById('recurring-category');
        frequency?.addEventListener('change', () => this._toggleScheduleFields());
        typeSelect?.addEventListener('change', () => { category.innerHTML = `<option value="">Select category</option>${this._categoryOptions(typeSelect.value)}`; this._loadSubcategories(); });
        category?.addEventListener('change', () => this._loadSubcategories());
        this._toggleScheduleFields();
        this._loadSubcategories(item?.subcategory_id);
    }

    async _saveForm(existing) {
        const form = document.getElementById('recurring-form');
        if (!form?.reportValidity()) return;
        const frequency = document.getElementById('recurring-frequency').value;
        const payload = {
            type: document.getElementById('recurring-type').value,
            amount: document.getElementById('recurring-amount').value,
            account_id: document.getElementById('recurring-account').value,
            category_id: document.getElementById('recurring-category').value,
            subcategory_id: document.getElementById('recurring-subcategory').value || null,
            frequency,
            day_of_week: ['weekly','bi_weekly'].includes(frequency) ? Number(document.getElementById('recurring-weekday').value) : null,
            day_of_month: ['monthly','quarterly'].includes(frequency) ? Number(document.getElementById('recurring-monthday').value) : null,
            start_date: document.getElementById('recurring-start').value,
            end_date: document.getElementById('recurring-end').value || null,
            description: document.getElementById('recurring-description').value.trim()
        };
        if (payload.end_date && payload.end_date < payload.start_date) return this._notify('error', 'End date must be on or after the start date.');
        if (existing && this._scheduleChanged(existing, payload)) {
            const confirmed = await this._confirm('Change this schedule?', 'Unresolved future dates from the old schedule will be replaced. Generated transactions will stay unchanged.');
            if (!confirmed) return;
            payload.confirm_schedule_change = true;
        }
        try {
            if (existing) await recurringTransactionsAPI.update(existing.id, payload); else await recurringTransactionsAPI.create(payload);
            window.modalService.close();
            this._notify('success', existing ? 'Recurring transaction updated.' : 'Recurring transaction created.');
            await this.load();
        } catch (error) { this._notify('error', error.message || 'Could not save recurring transaction.'); }
    }

    async _loadSubcategories(selected = null) {
        const select = document.getElementById('recurring-subcategory');
        const categoryId = document.getElementById('recurring-category')?.value;
        if (!select) return;
        select.innerHTML = '<option value="">None</option>';
        if (!categoryId) return;
        try {
            const response = await categoriesAPI.getSubcategories(categoryId);
            (response.data || []).forEach(sub => select.insertAdjacentHTML('beforeend', `<option value="${sub.id}" ${String(sub.id) === String(selected) ? 'selected' : ''}>${this._h(sub.name)}</option>`));
        } catch (_) { /* Category remains usable without a subcategory. */ }
    }

    async processDue() {
        const button = document.getElementById('process-recurring-btn');
        if (button) button.disabled = true;
        try {
            const response = await recurringTransactionsAPI.processDue();
            const counts = response.data?.counts || {};
            const generated = counts.generated || 0;
            const review = counts.review_required || 0;
            this._showAlert(`${generated} transaction${generated === 1 ? '' : 's'} generated. ${review ? `${review} schedule${review === 1 ? '' : 's'} need review.` : 'No missed-date review is needed.'}`, review ? 'warning' : 'success');
            document.dispatchEvent(new CustomEvent('app:data-changed', { detail: { source: 'recurring-transactions' } }));
            await this.load();
        } catch (error) { this._showAlert(error.message || 'Processing failed.', 'danger'); }
        finally { if (button) button.disabled = false; }
    }

    async openReview(id) {
        try {
            const response = await recurringTransactionsAPI.review(id);
            const occurrences = response.data?.occurrences || [];
            if (!occurrences.length) { this._notify('info', 'There are no missed dates to review.'); return this.load(); }
            const rows = occurrences.map(date => `<div class="recurring-review-row"><div><i class="bi bi-calendar-event"></i><strong>${this._h(Formatters.date(date + 'T00:00:00'))}</strong><small>${date}</small></div><select class="form-select" data-review-date="${date}"><option value="skip">Skip — no transaction</option><option value="generate">Generate transaction</option></select></div>`).join('');
            window.modalService.open({
                title: 'Review missed occurrences', subtitle: 'Choose Generate or Skip for every server-verified date.', icon: 'fa-calendar-check',
                bodyHTML: `<div class="recurring-review-warning"><i class="bi bi-exclamation-triangle"></i><span>Generating may add multiple historical financial transactions. Check every choice before applying.</span></div><div class="recurring-review-list">${rows}</div>`,
                saveText: '<i class="fas fa-check me-1"></i> Review & Apply',
                onSave: async () => {
                    const decisions = [...document.querySelectorAll('[data-review-date]')].map(select => ({ date: select.dataset.reviewDate, action: select.value }));
                    const generateCount = decisions.filter(d => d.action === 'generate').length;
                    const confirmed = await this._confirm('Apply missed-date decisions?', `${generateCount} historical transaction${generateCount === 1 ? '' : 's'} will be generated; ${decisions.length - generateCount} date${decisions.length - generateCount === 1 ? '' : 's'} will be skipped.`);
                    if (!confirmed) return;
                    try {
                        await recurringTransactionsAPI.reconcile(id, decisions);
                        window.modalService.close();
                        this._notify('success', 'Missed occurrences reconciled.');
                        document.dispatchEvent(new CustomEvent('app:data-changed', { detail: { source: 'recurring-reconciliation' } }));
                        await this.load();
                    } catch (error) { this._notify('error', error.message || 'Reconciliation failed.'); }
                }
            });
        } catch (error) { this._notify('error', error.message || 'Could not load missed dates.'); }
    }

    async changeState(item, activate) {
        try {
            if (activate) {
                const reviewDisabled = await this._confirm('Resume recurring transaction?', 'Choose OK to resume from the next valid date today or later. Choose Cancel if you want to review the disabled period first.');
                if (!reviewDisabled) {
                    const result = await recurringTransactionsAPI.activate(item.id, 'review');
                    if (result.data?.status === 'review_required') return this.openReview(item.id);
                    return;
                }
                await recurringTransactionsAPI.activate(item.id, 'resume');
            } else {
                if (!await this._confirm('Pause this recurring transaction?', 'Its next occurrence and history will be preserved.')) return;
                await recurringTransactionsAPI.deactivate(item.id);
            }
            this._notify('success', activate ? 'Recurring transaction resumed.' : 'Recurring transaction paused.');
            await this.load();
        } catch (error) { this._notify('error', error.message || 'Could not change recurring status.'); }
    }

    async remove(item) {
        if (!await this._confirm('Delete this recurring transaction?', item.generated_count > 0 ? 'Generated history exists, so SanIE will require deactivation instead.' : 'This schedule has no generated history and will be permanently removed.')) return;
        try { await recurringTransactionsAPI.delete(item.id); this._notify('success', 'Recurring transaction deleted.'); await this.load(); }
        catch (error) { this._notify('error', error.message || 'Could not delete. Deactivate schedules with generated history.'); }
    }

    _toggleScheduleFields() {
        const value = document.getElementById('recurring-frequency')?.value;
        document.getElementById('recurring-weekday-wrap')?.classList.toggle('d-none', !['weekly','bi_weekly'].includes(value));
        document.getElementById('recurring-monthday-wrap')?.classList.toggle('d-none', !['monthly','quarterly'].includes(value));
    }

    _scheduleChanged(old, next) { return ['frequency','day_of_week','day_of_month','start_date','end_date'].some(key => String(old[key] || '') !== String(next[key] || '')); }
    _categoryOptions(type, selected = null) { return this.categories.filter(c => c.type === type && c.status !== 'archived').map(c => `<option value="${c.id}" ${String(c.id) === String(selected) ? 'selected' : ''}>${this._h(c.name)}</option>`).join(''); }
    _frequencyName(value) { return ({ daily:'Daily',weekly:'Weekly',bi_weekly:'Every two weeks',monthly:'Monthly',quarterly:'Quarterly',yearly:'Yearly' })[value] || value; }
    _scheduleLabel(item) { const base = this._frequencyName(item.frequency); if (['weekly','bi_weekly'].includes(item.frequency)) return `${base} · ${['','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'][Number(item.day_of_week)]}`; if (['monthly','quarterly'].includes(item.frequency)) return `${base} · day ${item.day_of_month}`; return base; }
    _setText(id, value) { const el = document.getElementById(id); if (el) el.textContent = value; }
    _h(value) { return Formatters.escapeHTML(String(value ?? '')); }
    _showAlert(message, type) { const alert = document.getElementById('recurring-alert'); if (!alert) return; alert.className = `alert alert-${type}`; alert.textContent = message; }
    _notify(type, message) { if (window.NotificationService?.[type]) NotificationService[type](message); else if (window.Swal) Swal.fire({ icon: type, text: message }); }
    async _confirm(title, text) { if (!window.Swal) return window.confirm(`${title}\n\n${text}`); const result = await Swal.fire({ icon:'warning', title, text, showCancelButton:true, confirmButtonColor:'#0cab83', confirmButtonText:'Continue' }); return result.isConfirmed; }
}

window.RecurringTransactionsManager = RecurringTransactionsManager;
