class TasksManager {
    constructor() {
        this.tasks = [];
        this.view = 'today';
        this.filters = { subject:'all', unit:'all', priority:'all', status:'all' };
        this.searchQuery = '';
        this.layout = this.readLayoutPreference();
        this._mounted = false;
        this._busyTransitions = new Map();
    }

    onMount(context = {}) {
        if (this._mounted) return;
        this._mounted = true;
        this._signal = context.signal;
        this._click = event => this.handleClick(event);
        this._change = event => this.handleChange(event);
        this._keydown = event => this.handleKeydown(event);
        document.getElementById('tasks-page')?.addEventListener('click', this._click);
        document.getElementById('tasks-page')?.addEventListener('change', this._change);
        document.getElementById('tasks-page')?.addEventListener('keydown', this._keydown);
        this._compactMedia = typeof window.matchMedia === 'function' ? window.matchMedia('(max-width: 991.98px)') : null;
        this._mediaChange = () => this.render();
        this._compactMedia?.addEventListener?.('change', this._mediaChange);
        this.load();
    }

    onUnmount() {
        const page = document.getElementById('tasks-page');
        page?.removeEventListener('click', this._click);
        page?.removeEventListener('change', this._change);
        page?.removeEventListener('keydown', this._keydown);
        this._compactMedia?.removeEventListener?.('change', this._mediaChange);
        this._mounted = false;
        this._signal = null;
        this.searchQuery = '';
    }

    async load() {
        const content = document.getElementById('tasks-content');
        if (content) {
            content.setAttribute('aria-busy', 'true');
            content.innerHTML = this.loadingState();
        }
        try {
            await tasksAPI.processReminders();
            const response = await tasksAPI.getAll({}, this._signal ? { signal: this._signal } : {});
            if (!this._mounted) return;
            this.tasks = response.data || [];
            this.render();
        } catch (error) {
            if (error?.code === 'ABORTED_ERROR' || error?.category === 'aborted_error') return;
            if (content) content.innerHTML = '<div class="task-empty task-error-state"><span class="task-empty-icon"><i class="bi bi-exclamation-circle" aria-hidden="true"></i></span><strong>Tasks could not be loaded</strong><span>Please check your connection and try again.</span><button type="button" class="btn btn-outline-secondary btn-sm" data-task-retry><i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i> Try again</button></div>';
        } finally {
            content?.setAttribute('aria-busy', 'false');
        }
    }

    handleClick(event) {
        const layout = event.target.closest('[data-task-layout]');
        if (layout) { this.setLayout(layout.dataset.taskLayout); return; }
        const view = event.target.closest('[data-task-view]');
        if (view) { this.view = view.dataset.taskView; this.render(); return; }
        if (event.target.closest('[data-task-clear-filters]')) { this.clearFilters(); return; }
        if (event.target.closest('#add-task-btn')) { this.openEditor(); return; }
        if (event.target.closest('#deleted-tasks-btn')) { this.openDeletedTasks(); return; }
        if (event.target.closest('[data-task-add-empty]')) { this.openEditor(); return; }
        if (event.target.closest('[data-task-retry]')) { this.load(); return; }
        const lifecycle = event.target.closest('[data-task-status]');
        if (lifecycle) { this.updateStatus(lifecycle); return; }
        const edit = event.target.closest('[data-task-edit]');
        if (edit) { this.openEditor(this.tasks.find(item => Number(item.id) === Number(edit.dataset.taskEdit))); return; }
        const remove = event.target.closest('[data-task-delete]');
        if (remove) this.deleteTask(Number(remove.dataset.taskDelete));
        const importButton = event.target.closest('#import-board-plan-btn');
        if (importButton) this.importBoardPlan(importButton);
    }

    handleKeydown(event) {
        const current = event.target.closest('[data-task-view]');
        if (!current || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        const tabs = [...document.querySelectorAll('[data-task-view]')];
        if (!tabs.length) return;
        event.preventDefault();
        let index = tabs.indexOf(current);
        if (event.key === 'Home') index = 0;
        else if (event.key === 'End') index = tabs.length - 1;
        else index = (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
        tabs[index].focus();
        tabs[index].click();
    }

    handleChange(event) {
        const key = event.target.dataset.taskFilter;
        if (key && Object.hasOwn(this.filters, key)) { this.filters[key] = event.target.value; this.render(); }
    }

    setSearch(query) {
        this.searchQuery = String(query || '').trim().toLocaleLowerCase();
        if (this._mounted) this.render();
    }

    render() {
        this.updateSummary();
        this.updateTabCounts();
        document.querySelectorAll('[data-task-view]').forEach(button => {
            const active = button.dataset.taskView === this.view;
            button.classList.toggle('active', active);
            button.setAttribute('aria-selected', String(active));
            button.tabIndex = active ? 0 : -1;
        });
        this.populateFilterOptions();
        const items = this.filteredItems();
        const content = document.getElementById('tasks-content');
        if (!content) return;
        if (this.view === 'board') content.innerHTML = this.renderBoard(items);
        else content.innerHTML = this.renderTaskList(items);
    }

    updateSummary() {
        const board = this.tasks.filter(item => item.task_type === 'board_study');
        const completedBoard = board.filter(item => item.status === 'completed').length;
        const values = {
            'task-summary-total': this.tasks.length,
            'task-summary-today': this.tasks.filter(item => item.due_date === this.today()).length,
            'task-summary-completed': this.tasks.filter(item => item.status === 'completed').length,
            'task-summary-board': `${this.percent(completedBoard, board.length)}%`,
        };
        Object.entries(values).forEach(([id,value]) => {
            const element = document.getElementById(id);
            if (element) element.textContent = String(value);
        });
    }

    updateTabCounts() {
        const today = this.today();
        const tomorrow = this.addDays(today, 1);
        const counts = {
            all: this.tasks.length,
            today: this.tasks.filter(item => item.due_date === today).length,
            tomorrow: this.tasks.filter(item => item.due_date === tomorrow && item.status !== 'completed').length,
            upcoming: this.tasks.filter(item => item.due_date > tomorrow && item.status !== 'completed').length,
            overdue: this.tasks.filter(item => item.due_date && item.due_date < today && item.status !== 'completed').length,
            completed: this.tasks.filter(item => item.status === 'completed').length,
            board: this.tasks.filter(item => item.task_type === 'board_study').length,
        };
        document.querySelectorAll('[data-task-view]').forEach(button => {
            const badge = button.querySelector('.task-tab-count');
            if (badge) badge.textContent = String(counts[button.dataset.taskView] ?? 0);
        });
    }

    filteredItems() {
        const today = this.today();
        const tomorrow = this.addDays(today, 1);
        let items;
        if (this.view === 'board') items = this.tasks.filter(item => item.task_type === 'board_study');
        else if (this.view === 'today') items = this.tasks.filter(item => item.due_date === today);
        else if (this.view === 'tomorrow') items = this.tasks.filter(item => item.due_date === tomorrow && item.status !== 'completed');
        else if (this.view === 'upcoming') items = this.tasks.filter(item => item.due_date > tomorrow && item.status !== 'completed');
        else if (this.view === 'overdue') items = this.tasks.filter(item => item.due_date && item.due_date < today && item.status !== 'completed');
        else if (this.view === 'completed') items = this.tasks.filter(item => item.status === 'completed');
        else items = [...this.tasks];
        if (this.filters.subject !== 'all') items = items.filter(item => item.subject === this.filters.subject);
        if (this.filters.unit !== 'all') items = items.filter(item => item.unit_label === this.filters.unit);
        if (this.filters.priority !== 'all') items = items.filter(item => item.priority === this.filters.priority);
        if (this.filters.status !== 'all') items = items.filter(item => item.status === this.filters.status);
        if (this.searchQuery) {
            items = items.filter(item => [item.title,item.subject,item.unit_label,item.content,item.display_date_bs,item.due_date,item.status]
                .some(value => String(value || '').toLocaleLowerCase().includes(this.searchQuery)));
        }
        return items;
    }

    populateFilterOptions() {
        this.syncFilterOptions('task-filter-subject', this.tasks.map(item => item.subject), 'All subjects', 'subject');
        this.syncFilterOptions('task-filter-unit', this.tasks.map(item => item.unit_label), 'All units', 'unit');
        const clear = document.querySelector('[data-task-clear-filters]');
        if (clear) clear.hidden = !this.hasActiveFilters();
    }

    syncFilterOptions(id, values, allLabel, key) {
        const select = document.getElementById(id);
        if (!select) return;
        const options = [...new Set(values.map(value => String(value || '').trim()).filter(Boolean))]
            .sort((a,b) => a.localeCompare(b));
        const selected = options.includes(this.filters[key]) ? this.filters[key] : 'all';
        if (selected === 'all') this.filters[key] = 'all';
        select.replaceChildren(new Option(allLabel, 'all'), ...options.map(value => new Option(value, value)));
        select.value = selected;
    }

    hasActiveFilters() { return Object.values(this.filters).some(value => value !== 'all'); }

    clearFilters() {
        Object.keys(this.filters).forEach(key => { this.filters[key] = 'all'; });
        document.querySelectorAll('[data-task-filter]').forEach(select => { select.value = 'all'; });
        this.render();
    }

    renderBoard(items) {
        const boardAll = this.tasks.filter(item => item.task_type === 'board_study');
        if (!boardAll.length) return `<div class="task-empty board-import-empty"><span class="task-empty-icon"><i class="bi bi-journal-check" aria-hidden="true"></i></span><strong>Board Study plan is ready</strong><span>Import the approved 15-day plan with 45 study targets. It will be created only once for your account.</span><button class="btn btn-primary btn-sm" id="import-board-plan-btn"><i class="bi bi-download me-1" aria-hidden="true"></i> Import Study Plan</button></div>`;
        const completed = boardAll.filter(item => item.status === 'completed').length;
        const pending = boardAll.filter(item => item.status === 'pending').length;
        const inProgress = boardAll.filter(item => item.status === 'in_progress').length;
        const subjectCards = ['Database Administration','Cloud Computing','Cyber Law & Professional Ethics'].map(subject => {
            const subjectItems = boardAll.filter(item => item.subject === subject);
            const done = subjectItems.filter(item => item.status === 'completed').length;
            return this.progressCard(subject, done, subjectItems.length);
        }).join('');
        const percentage = this.percent(completed, boardAll.length);
        const todayItems = boardAll.filter(item => item.due_date === this.today());
        const todayCompleted = todayItems.filter(item => item.status === 'completed').length;
        const todayInProgress = todayItems.filter(item => item.status === 'in_progress').length;
        return `<section class="board-progress-overview">
            <div class="board-overall d-flex flex-column flex-md-row align-items-md-end justify-content-between gap-2">
                <div><span class="task-eyebrow">Board Preparation</span><h3>15-Day Study Plan</h3><p>${completed} / ${boardAll.length} completed</p></div>
                <strong class="board-progress-percent">${percentage}%</strong>
            </div>
            ${this.progressBar(percentage, 'Board Study completion')}
            <div class="board-status-counts" aria-label="Board Study status counts"><span class="pending"><strong>${pending}</strong> Pending</span><span class="in-progress"><strong>${inProgress}</strong> In Progress</span><span class="completed"><strong>${completed}</strong> Completed</span></div>
            ${todayItems.length ? `<div class="board-today-progress"><div><span class="task-eyebrow">Today's Progress</span><strong>${todayCompleted} / ${todayItems.length} Completed</strong>${todayInProgress ? `<small>${todayInProgress} In Progress</small>` : ''}</div>${this.progressBar(this.percent(todayCompleted, todayItems.length), "Today's completed study targets")}</div>` : ''}
            <div class="subject-progress-grid">${subjectCards}</div>
        </section>
        ${todayItems.length === 0 ? '<div class="board-today-note"><i class="bi bi-calendar2-check" aria-hidden="true"></i><span>No Board Study targets are scheduled for today.</span></div>' : ''}
        <div class="task-plan-heading d-flex align-items-end justify-content-between gap-3"><div><span class="task-eyebrow">Study Schedule</span><h3>Board Study Targets</h3></div>${this.listHeadingActions(items.length, 'target')}</div>
        ${items.length ? this.renderTaskPresentation(items, true) : this.emptyFiltered('board')}`;
    }

    renderTaskList(items) {
        const labels = { all:'All Tasks', today:"Today's Tasks", tomorrow:"Tomorrow's Tasks", upcoming:'Upcoming Tasks', overdue:'Overdue Tasks', completed:'Completed Tasks' };
        const subtitles = { all:'Everything in one organized view', today:'Your priorities for today', tomorrow:'Plan the next day with confidence', upcoming:'Open work scheduled beyond tomorrow', overdue:'Incomplete tasks past their due date', completed:'A record of work you have finished' };
        if (!items.length) return this.emptyFiltered(this.view);
        return `<div class="task-list-summary d-flex align-items-end justify-content-between gap-3"><div><span class="task-eyebrow">${this.esc(labels[this.view] || 'Tasks')}</span><h3>${this.esc(subtitles[this.view] || '')}</h3></div>${this.listHeadingActions(items.length, 'task')}</div>${this.renderTaskPresentation(items, false)}`;
    }

    listHeadingActions(count, noun) {
        return `<div class="task-list-controls"><span class="task-result-count">${count} ${noun}${count === 1 ? '' : 's'}</span>${this.viewSwitcher()}</div>`;
    }

    viewSwitcher() {
        const effective = this.effectiveLayout();
        return `<div class="btn-group task-layout-switcher" role="group" aria-label="Task presentation">
            <button type="button" class="btn task-layout-button ${effective === 'table' ? 'active' : ''}" data-task-layout="table" aria-pressed="${effective === 'table'}"><i class="bi bi-list-ul" aria-hidden="true"></i><span>Table</span></button>
            <button type="button" class="btn task-layout-button ${effective === 'card' ? 'active' : ''}" data-task-layout="card" aria-pressed="${effective === 'card'}"><i class="bi bi-grid" aria-hidden="true"></i><span>Cards</span></button>
        </div>`;
    }

    renderTaskPresentation(items, groupedCards = false) {
        if (this.effectiveLayout() === 'table') return this.taskTable(items);
        if (groupedCards) return this.groupByDate(items).map(group => this.dayGroup(group)).join('');
        return `<div class="general-task-list">${items.map(item => this.taskRow(item)).join('')}</div>`;
    }

    taskTable(items) {
        return `<div class="task-table-shell"><table class="task-data-table">
            <caption class="visually-hidden">Tasks in the selected view</caption>
            <colgroup><col class="task-col-date"><col class="task-col-subject"><col class="task-col-unit"><col class="task-col-topics"><col class="task-col-status"><col class="task-col-action"></colgroup>
            <thead><tr><th scope="col">Date</th><th scope="col">Subject</th><th scope="col">Unit</th><th scope="col">Topics</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
            <tbody>${items.map(item => this.taskTableRow(item)).join('')}</tbody>
        </table></div>`;
    }

    taskTableRow(item) {
        const complete = item.status === 'completed';
        const inProgress = item.status === 'in_progress';
        const overdue = !complete && item.due_date && item.due_date < this.today();
        const statusText = complete ? 'Completed' : inProgress ? 'In Progress' : overdue ? 'Overdue' : 'Pending';
        const statusClass = complete ? 'completed' : inProgress ? 'in-progress' : overdue ? 'overdue' : 'pending';
        const rawName = item.subject || item.title;
        const name = this.esc(rawName);
        return `<tr class="subject-${this.subjectKey(item.subject)} ${complete ? 'is-complete' : ''} ${inProgress ? 'is-in-progress' : ''} ${overdue ? 'is-overdue' : ''}" data-task-id="${Number(item.id)}">
            <td class="task-table-date ${overdue ? 'is-overdue' : ''}"><span class="task-date-primary">${item.due_date ? this.formatAD(item.due_date) : 'No date'}</span>${item.display_date_bs ? `<span class="task-date-secondary">${this.esc(item.display_date_bs)} BS</span>` : ''}</td>
            <td><div class="task-table-subject" title="${this.escAttr(rawName)}"><span class="task-subject-dot" aria-hidden="true"></span><strong>${name}</strong></div></td>
            <td>${item.unit_label ? `<span class="badge task-unit">${this.esc(item.unit_label)}</span>` : '<span class="task-table-empty" aria-label="No unit">—</span>'}</td>
            <td><div class="task-table-topics">${item.content ? `<span>${this.esc(item.content)}</span>` : '<span class="task-table-empty">—</span>'}</div></td>
            <td><span class="badge task-status ${statusClass}">${statusText}</span></td>
            <td><div class="task-table-actions">${complete ? '' : this.lifecycleControl(item, true)}<div class="task-table-management">${this.managementControls(item)}</div></div></td>
        </tr>`;
    }

    groupByDate(items) {
        const groups = new Map();
        items.forEach(item => {
            const key = item.due_date || item.display_date_bs || 'No date';
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(item);
        });
        const today = this.today();
        const tomorrow = this.addDays(today, 1);
        const relation = date => date === today ? 'today' : date === tomorrow ? 'tomorrow' : date < today ? 'earlier' : 'upcoming';
        const priority = { today:0, tomorrow:1, earlier:2, upcoming:3 };
        return [...groups.entries()]
            .map(([date, rows]) => ({ date, rows, relation: relation(rows[0].due_date || date) }))
            .sort((a,b) => priority[a.relation] - priority[b.relation] || String(a.date).localeCompare(String(b.date)));
    }

    dayGroup(group) {
        const done = group.rows.filter(item => item.status === 'completed').length;
        const labels = { today:'Today', tomorrow:'Tomorrow', earlier:'Earlier', upcoming:'Upcoming' };
        const item = group.rows[0];
        return `<section class="study-day ${group.relation === 'today' ? 'is-today' : ''}">
            <header class="d-flex align-items-center justify-content-between gap-3">
                <div class="study-day-date"><span class="study-day-label">${labels[group.relation]}</span><strong>${this.formatAD(item.due_date)}</strong>${item.display_date_bs ? `<span>${this.esc(item.display_date_bs)} BS</span>` : ''}</div>
                <div class="study-day-count"><strong>${done}/${group.rows.length}</strong><span>completed</span></div>
            </header>
            <div class="study-day-rows">${group.rows.map(row => this.taskRow(row)).join('')}</div>
        </section>`;
    }

    taskRow(item) {
        const complete = item.status === 'completed';
        const inProgress = item.status === 'in_progress';
        const overdue = !complete && item.due_date && item.due_date < this.today();
        const statusText = complete ? 'Completed' : inProgress ? 'In Progress' : 'Pending';
        const subjectKey = this.subjectKey(item.subject);
        const dateMeta = this.view !== 'board' && item.due_date ? `<div class="task-date-meta"><i class="bi bi-calendar3" aria-hidden="true"></i><span>${this.formatAD(item.due_date)}${item.display_date_bs ? ` <span aria-hidden="true">·</span> ${this.esc(item.display_date_bs)} BS` : ''}</span></div>` : '';
        const rawName = item.subject || item.title;
        const name = this.esc(rawName);
        const topics = this.topicSummary(item.content);
        const lifecycle = this.lifecycleControl(item);
        return `<article class="study-task subject-${subjectKey} ${complete ? 'is-complete' : ''} ${inProgress ? 'is-in-progress' : ''} ${overdue ? 'is-overdue' : ''}" data-task-id="${Number(item.id)}">
            <div class="task-copy">
                <div class="task-title-line"><span class="task-subject-dot" aria-hidden="true"></span><strong>${name}</strong>${item.unit_label ? `<span class="badge task-unit">${this.esc(item.unit_label)}</span>` : ''}<span class="badge task-status ${inProgress ? 'in-progress' : complete ? 'completed' : 'pending'}">${statusText}</span>${overdue ? '<span class="badge task-overdue-label">Overdue</span>' : ''}</div>
                ${dateMeta}${item.content ? `<p class="task-topics" title="${this.escAttr(item.content)}" aria-label="Full task content: ${this.escAttr(item.content)}"><span>${this.esc(topics.visible)}</span>${topics.remaining ? ` <strong>· +${topics.remaining} more</strong>` : ''}</p>` : ''}
                <div class="task-actions d-flex flex-wrap align-items-center justify-content-between gap-2">${lifecycle}<div class="d-flex gap-1">${this.managementControls(item)}</div></div>
            </div>
        </article>`;
    }

    lifecycleControl(item, compact = false) {
        const complete = item.status === 'completed';
        const inProgress = item.status === 'in_progress';
        const busyNext = this._busyTransitions.get(Number(item.id));
        const compactClass = compact ? ' task-table-action' : '';
        if (busyNext) return `<button type="button" class="btn ${busyNext === 'completed' ? 'btn-success' : 'btn-primary'} btn-sm task-lifecycle-btn${compactClass}" disabled aria-busy="true"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> ${busyNext === 'completed' ? 'Completing...' : 'Starting...'}</button>`;
        if (complete) return compact
            ? '<span class="task-action-finished" aria-label="No further action">—</span>'
            : '<button type="button" class="btn btn-outline-secondary btn-sm task-lifecycle-btn is-completed" disabled><i class="bi bi-check2" aria-hidden="true"></i> Completed</button>';
        return `<button type="button" class="btn ${inProgress ? 'btn-success' : 'btn-primary'} btn-sm task-lifecycle-btn${compactClass}" data-task-status="${Number(item.id)}" data-next-status="${inProgress ? 'completed' : 'in_progress'}">${inProgress ? '<i class="bi bi-check2-circle" aria-hidden="true"></i> Complete' : '<i class="bi bi-play-fill" aria-hidden="true"></i> Start'}</button>`;
    }

    managementControls(item) {
        const rawName = item.subject || item.title;
        const nameAttr = this.escAttr(rawName);
        const summary = item.status === 'completed' ? this.summaryControl(item, nameAttr) : '';
        return `${summary}<button type="button" class="btn btn-sm task-action-btn" data-task-edit="${Number(item.id)}" title="Edit ${nameAttr}" aria-label="Edit task"><i class="bi bi-pencil" aria-hidden="true"></i></button><button type="button" class="btn btn-sm task-action-btn is-delete" data-task-delete="${Number(item.id)}" title="Delete ${nameAttr}" aria-label="Delete task"><i class="bi bi-trash3" aria-hidden="true"></i></button>`;
    }

    summaryControl(item, nameAttr = this.escAttr(item.subject || item.title)) {
        const url = String(item.summary_url || '').trim();
        if (!this.isSafeSummaryUrl(url)) {
            return `<button type="button" class="btn btn-sm task-action-btn is-summary is-disabled" disabled title="No summary attached" aria-label="No summary attached for ${nameAttr}"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></button>`;
        }
        return `<a class="btn btn-sm task-action-btn is-summary" href="${this.escAttr(url)}" target="_blank" rel="noopener noreferrer" title="Open summary" aria-label="Open summary for ${nameAttr}"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></a>`;
    }

    progressCard(subject, done, total) { const percentage=this.percent(done,total); return `<div class="subject-progress subject-${this.subjectKey(subject)}"><div class="d-flex align-items-center justify-content-between gap-2"><strong><span class="task-subject-dot" aria-hidden="true"></span>${this.esc(subject)}</strong><span>${done} / ${total}</span></div>${this.progressBar(percentage, `${subject} completion`)}</div>`; }
    progressBar(percentage,label) { return `<div class="progress task-progress" role="progressbar" aria-label="${this.esc(label)}" aria-valuenow="${percentage}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:${percentage}%"></div></div>`; }
    percent(done,total) { return total ? Math.round(done / total * 100) : 0; }
    subjectKey(subject) { return subject === 'Database Administration' ? 'database' : subject === 'Cloud Computing' ? 'cloud' : subject === 'Cyber Law & Professional Ethics' ? 'cyber' : 'general'; }
    loadingState() {
        if (this.effectiveLayout() === 'table') return `<div class="task-skeleton task-table-skeleton" aria-label="Loading tasks"><div class="task-skeleton-heading"></div><div class="task-table-shell">${[1,2,3].map(() => '<div class="task-table-skeleton-row"><i></i><b></b><i></i><b></b><i></i><b></b></div>').join('')}</div></div>`;
        return `<div class="task-skeleton" aria-label="Loading tasks"><div class="task-skeleton-heading"></div>${[1,2,3].map(() => '<div class="task-skeleton-row"><span></span><div><b></b><i></i><em></em></div></div>').join('')}</div>`;
    }
    emptyFiltered(view = 'board') {
        const messages = {
            today:['Nothing scheduled today','Your tasks due today will appear here.'],
            tomorrow:['Nothing scheduled tomorrow','Tasks due tomorrow will appear here.'],
            upcoming:['No upcoming tasks','Future open tasks will appear here.'],
            overdue:['No overdue tasks','You are all caught up.'],
            completed:['No completed tasks yet','Completed tasks will remain available here.'],
            all:['No tasks yet','Create a task or import the Board Study plan.'],
        };
        const searching = Boolean(this.searchQuery);
        const filtering = this.hasActiveFilters();
        const [title,copy] = searching ? ['No search results',`No tasks match “${this.esc(this.searchQuery)}”.`] : filtering ? ['No matching tasks','Try changing or clearing the selected filters.'] : (messages[view] || ['No matching study targets','Try another Board Study filter.']);
        return `<div class="task-empty"><span class="task-empty-icon"><i class="bi ${searching ? 'bi-search' : filtering ? 'bi-funnel' : 'bi-check2-circle'}" aria-hidden="true"></i></span><strong>${title}</strong><span>${copy}</span>${filtering ? '<button type="button" class="btn btn-outline-secondary btn-sm" data-task-clear-filters>Clear filters</button>' : view === 'all' && !searching ? '<button type="button" class="btn btn-primary btn-sm" id="add-task-empty-btn" data-task-add-empty><i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Add Task</button>' : ''}</div>`;
    }

    async updateStatus(button) {
        const id = Number(button.dataset.taskStatus);
        const nextStatus = button.dataset.nextStatus;
        if (!['in_progress','completed'].includes(nextStatus)) return;
        if (this._busyTransitions.has(id)) return;
        const index = this.tasks.findIndex(item => Number(item.id) === id);
        if (index < 0) return;
        const previousTask = { ...this.tasks[index] };
        this._busyTransitions.set(id, nextStatus);
        this.tasks[index] = { ...previousTask, status: nextStatus, completed_at: nextStatus === 'completed' ? new Date().toISOString() : null };
        this.render();
        try {
            const response = await tasksAPI.update(id, { status: nextStatus });
            const responseIndex = this.tasks.findIndex(item => Number(item.id) === id);
            if (responseIndex >= 0) this.tasks[responseIndex] = response.data;
            NotificationService.success(nextStatus === 'completed' ? 'Task completed' : 'Task started');
        } catch (error) {
            const rollbackIndex = this.tasks.findIndex(item => Number(item.id) === id);
            if (rollbackIndex >= 0) this.tasks[rollbackIndex] = previousTask;
            NotificationService.error(error.message || 'Task status was not saved');
        } finally { this._busyTransitions.delete(id); this.render(); }
    }

    async importBoardPlan(button) {
        button.disabled = true;
        try {
            const response = await tasksAPI.importBoardStudy();
            this.tasks = [...this.tasks.filter(item => item.task_type !== 'board_study'), ...(response.data.items || [])];
            NotificationService.success(response.message || 'Board Study plan imported');
            this.render();
        } catch (error) { button.disabled = false; NotificationService.error(error.message || 'Study plan could not be imported'); }
    }

    openEditor(task = null) {
        const board = task?.task_type === 'board_study';
        const value = field => this.esc(task?.[field] || '');
        const subjectField = board
            ? `<select class="form-select" id="task-title" required>${['Database Administration','Cloud Computing','Cyber Law & Professional Ethics'].map(subject => `<option value="${this.esc(subject)}" ${task.subject === subject ? 'selected' : ''}>${this.esc(subject)}</option>`).join('')}</select>`
            : `<input class="form-control" id="task-title" maxlength="255" required placeholder="What needs to be done?" value="${value('title')}">`;
        const reminder = task?.reminder_at ? String(task.reminder_at).replace(' ', 'T').slice(0,16) : '';
        modalService.open({ title: task ? 'Edit Task' : 'Add Task', subtitle: board ? 'Board Study target' : 'Personal task', icon: 'fa-list-check', bodyHTML: `<form id="task-form" class="task-editor-form"><div class="row g-3"><div class="col-12"><label class="form-label fw-semibold" for="task-title">${board ? 'Subject' : 'Title'}</label>${subjectField}</div>${board ? `<div class="col-sm-6"><label class="form-label fw-semibold" for="task-unit">Unit</label><input class="form-control" id="task-unit" maxlength="50" required placeholder="e.g. Unit 1" value="${value('unit_label')}"></div><div class="col-sm-6"><label class="form-label fw-semibold" for="task-bs-date">Date (BS)</label><input class="form-control" id="task-bs-date" inputmode="numeric" pattern="\\d{4}-\\d{2}-\\d{2}" required placeholder="YYYY-MM-DD" value="${value('display_date_bs')}"></div>` : ''}<div class="col-sm-6"><label class="form-label fw-semibold" for="task-due-date">${board ? 'Matching date (AD)' : 'Due date'}</label><input type="date" class="form-control" id="task-due-date" value="${value('due_date')}"></div><div class="col-sm-6"><label class="form-label fw-semibold" for="task-priority">Priority</label><select class="form-select" id="task-priority">${['low','normal','high','urgent'].map(priority => `<option value="${priority}" ${task?.priority === priority || !task && priority === 'normal' ? 'selected' : ''}>${priority[0].toUpperCase()+priority.slice(1)}</option>`).join('')}</select></div><div class="col-sm-6"><label class="form-label fw-semibold" for="task-reminder">Reminder</label><input type="datetime-local" class="form-control" id="task-reminder" value="${reminder}"></div><div class="col-12"><label class="form-label fw-semibold" for="task-content">${board ? 'Study content' : 'Notes'}</label><textarea class="form-control" id="task-content" rows="4" placeholder="${board ? 'Study target details' : 'Add useful details (optional)'}">${value('content')}</textarea></div><div class="col-12"><label class="form-label fw-semibold" for="task-summary-url">Summary PDF / Drive Link</label><input type="url" class="form-control" id="task-summary-url" maxlength="2048" inputmode="url" placeholder="https://drive.google.com/..." value="${value('summary_url')}"><div class="form-text">Optional — add a Google Drive or other HTTPS link to your study summary. Make sure it has the sharing access you want.</div></div></div></form>`, onSave: () => this.saveEditor(task) });
    }

    async saveEditor(task) {
        if (!await modalService.submitForm()) return;
        const board = task?.task_type === 'board_study';
        const summaryUrl = document.getElementById('task-summary-url').value.trim();
        if (summaryUrl && !this.isSafeSummaryUrl(summaryUrl)) { NotificationService.error('Summary link must be a valid HTTPS URL'); return; }
        const data = { title: document.getElementById('task-title').value.trim(), content: document.getElementById('task-content').value.trim(), due_date: document.getElementById('task-due-date').value || null, priority: document.getElementById('task-priority').value, reminder_at: document.getElementById('task-reminder').value || null, summary_url: summaryUrl || null };
        if (board) { data.subject = data.title; data.unit_label = document.getElementById('task-unit').value.trim(); data.display_date_bs = document.getElementById('task-bs-date').value.trim(); }
        try {
            const response = task ? await tasksAPI.update(task.id, data) : await tasksAPI.create(data);
            if (task) this.tasks[this.tasks.findIndex(item => Number(item.id) === Number(task.id))] = response.data; else this.tasks.push(response.data);
            modalService.close(); this.render(); NotificationService.success(task ? 'Task updated' : 'Task created');
        } catch (error) { NotificationService.error(error.message || 'Task could not be saved'); }
    }

    async deleteTask(id) {
        const task = this.tasks.find(item => Number(item.id) === id); if (!task) return;
        const taskName = task.subject || task.title;
        if (!window.Swal) { NotificationService.error('Delete confirmation is unavailable'); return; }
        const confirmed = (await Swal.fire({ title:'Delete task?', text:`“${taskName}” will be removed and will not be recreated automatically.`, icon:'warning', showCancelButton:true, confirmButtonText:'Delete' })).isConfirmed;
        if (!confirmed) return;
        try { await tasksAPI.delete(id); this.tasks = this.tasks.filter(item => Number(item.id) !== id); this.render(); NotificationService.success('Task deleted'); }
        catch (error) { NotificationService.error(error.message || 'Task could not be deleted'); }
    }

    async openDeletedTasks() {
        try {
            const response=await tasksAPI.getDeleted();const items=response.data||[];
            modalService.open({title:'Recently Deleted Tasks',subtitle:'Restore a task with its previous state and reminder.',icon:'fa-rotate-left',showFooter:false,bodyHTML:items.length?`<div id="deleted-task-list" class="d-grid gap-2">${items.map(item=>`<div class="border rounded-3 p-3 d-flex align-items-center justify-content-between gap-3"><div><strong class="d-block">${this.esc(item.subject||item.title)}</strong><small class="text-muted">${this.esc(item.status)}${item.due_date?` · ${this.formatAD(item.due_date)}`:''}</small></div><button class="btn btn-outline-primary btn-sm" data-task-restore="${Number(item.id)}"><i class="bi bi-arrow-counterclockwise me-1"></i> Restore</button></div>`).join('')}</div>`:'<div class="text-center text-muted p-4">No deleted tasks.</div>'});
            document.getElementById('deleted-task-list')?.addEventListener('click',async event=>{const button=event.target.closest('[data-task-restore]');if(!button)return;button.disabled=true;try{await tasksAPI.restore(Number(button.dataset.taskRestore));modalService.close();await this.load();NotificationService.success('Task restored');}catch(error){button.disabled=false;NotificationService.error(error.message||'Task could not be restored');}});
        } catch(error){NotificationService.error(error.message||'Deleted tasks could not be loaded');}
    }

    today() { return DateUtils.getKathmanduDateString(); }
    addDays(date, days) { return DateUtils.addCalendarDays(date, days); }
    formatAD(date) { if (!date) return ''; const [y,m,d] = date.split('-').map(Number); return new Date(y,m-1,d).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}); }
    readLayoutPreference() {
        try { return window.localStorage?.getItem('sanie_tasks_view') === 'card' ? 'card' : 'table'; }
        catch (error) { return 'table'; }
    }
    setLayout(layout) {
        if (!['table','card'].includes(layout)) return;
        this.layout = layout;
        try { window.localStorage?.setItem('sanie_tasks_view', layout); } catch (error) { /* Preference storage is optional. */ }
        this.render();
    }
    effectiveLayout() {
        const compact = this._compactMedia?.matches ?? (typeof window.matchMedia === 'function' && window.matchMedia('(max-width: 991.98px)').matches);
        return compact ? 'card' : this.layout;
    }
    splitTopics(content) {
        const source = String(content || '').trim();
        if (!source) return [];
        const topics = [];
        let current = '';
        let depth = 0;
        for (const character of source) {
            if (character === '(') depth++;
            if (character === ')') depth = Math.max(0, depth - 1);
            if ((character === ';' || character === ',') && depth === 0) {
                if (current.trim()) topics.push(current.trim());
                current = '';
            } else current += character;
        }
        if (current.trim()) topics.push(current.trim());
        return topics;
    }
    topicSummary(content, limit = 4) {
        const full = String(content || '').trim();
        if (!full) return { visible:'', remaining:0 };
        const topics = this.splitTopics(full);
        if (topics.length < 2) return { visible:full, remaining:0 };
        const visible = topics.slice(0, limit);
        return { visible:visible.join(' · '), remaining:Math.max(0, topics.length - visible.length) };
    }
    isSafeSummaryUrl(value) {
        try { const url = new URL(String(value || '').trim()); return url.protocol === 'https:' && Boolean(url.hostname); }
        catch (error) { return false; }
    }
    escAttr(value) { return this.esc(value).replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
    esc(value) { return Formatters.escapeHTML(String(value ?? '')); }
}

window.TasksManager = TasksManager;
