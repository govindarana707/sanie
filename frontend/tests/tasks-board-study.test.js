const fs = require('fs');
const vm = require('vm');
const path = require('path');

const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'assets/js/tasks.js'), 'utf8');
const api = fs.readFileSync(path.join(root, 'assets/js/api.js'), 'utf8');
const app = fs.readFileSync(path.join(root, 'assets/js/app.js'), 'utf8');
const html = fs.readFileSync(path.join(root, 'index.html'), 'utf8');
const css = fs.readFileSync(path.join(root, 'assets/css/styles.css'), 'utf8');
const utilityContext = { window: {}, Intl, Date, Object, String, Number, TypeError };
vm.createContext(utilityContext);
vm.runInContext(fs.readFileSync(path.join(root, 'assets/js/utils.js'), 'utf8'), utilityContext);

function assert(value, message) { if (!value) throw new Error(message); }
const storage = new Map();
const context = { window: { localStorage: { getItem: key => storage.get(key) || null, setItem: (key, value) => storage.set(key, value) } }, URL, Formatters: { escapeHTML: value => String(value) }, DateUtils: utilityContext.window.DateUtils };
vm.createContext(context);
vm.runInContext(source + '\nwindow.__TasksManager = TasksManager;', context);
const manager = new context.window.__TasksManager();

assert(manager.layout === 'table', 'desktop does not default to Table View');

assert(manager.percent(0, 3) === 0, '0/3 must be 0%');
assert(manager.percent(1, 3) === 33, '1/3 must be 33%');
assert(manager.percent(2, 3) === 67, '2/3 must be 67%');
assert(manager.percent(3, 3) === 100, '3/3 must be 100%');
manager.today = () => '2026-08-22';
manager.tasks = [
    { id:1, due_date:'2026-08-21', status:'pending' },
    { id:2, due_date:'2026-08-22', status:'pending' },
    { id:3, due_date:'2026-08-23', status:'in_progress' },
    { id:4, due_date:'2026-08-24', status:'in_progress' },
    { id:5, due_date:'2026-08-20', status:'completed' }
];
for (const [view,id] of [['today',2],['tomorrow',3],['upcoming',4],['overdue',1],['completed',5]]) {
    manager.view = view;
    const result = manager.filteredItems();
    assert(result.length === 1 && result[0].id === id, `${view} tab returned incorrect content`);
}
manager.tasks[0].status = 'in_progress';
manager.view = 'overdue';
assert(manager.filteredItems().some(item => item.id === 1), 'overdue filter excluded an in-progress task');
manager.tasks[0].status = 'completed';
assert(!manager.filteredItems().some(item => item.id === 1), 'overdue filter included a completed task');

const progressManager = new context.window.__TasksManager();
progressManager.today = () => '2026-08-22';
progressManager.view = 'board';
progressManager.tasks = [
    { id:21, task_type:'board_study', title:'Database Administration', subject:'Database Administration', unit_label:'Unit 1', content:'A', due_date:'2026-08-22', status:'pending' },
    { id:22, task_type:'board_study', title:'Cloud Computing', subject:'Cloud Computing', unit_label:'Unit 1', content:'B', due_date:'2026-08-22', status:'in_progress' },
    { id:23, task_type:'board_study', title:'Cyber Law & Professional Ethics', subject:'Cyber Law & Professional Ethics', unit_label:'Unit 1', content:'C', due_date:'2026-08-22', status:'completed' }
];
const boardHTML = progressManager.renderBoard(progressManager.tasks);
assert(boardHTML.includes('1 / 3 completed') && boardHTML.includes('33%'), 'Board progress counted a non-completed task');
assert(boardHTML.includes('1 / 3 Completed') && boardHTML.includes('1 In Progress'), "Today's lifecycle progress is incorrect");
assert(source.includes("item.task_type === 'board_study'"), 'Board Study is not part of Tasks filtering');
assert(source.includes("removeEventListener('click'"), 'click listener is not cleaned up on unmount');
assert(source.includes("removeEventListener('change'"), 'change listener is not cleaned up on unmount');
assert(source.includes("removeEventListener('keydown'"), 'keyboard tab listener is not cleaned up on unmount');
assert(!source.includes('data-task-complete'), 'duplicate completion checkbox remains in the lifecycle UI');
assert(source.includes('data-task-status') && source.includes("data-next-status=\"${inProgress ? 'completed' : 'in_progress'}\""), 'Start/Complete lifecycle controls are missing');
const pendingRow = manager.taskRow({ id:10, title:'Pending task', due_date:'2026-08-22', status:'pending' });
const activeRow = manager.taskRow({ id:11, title:'Active task', due_date:'2026-08-22', status:'in_progress' });
const completedRow = manager.taskRow({ id:12, title:'Done task', due_date:'2026-08-22', status:'completed' });
assert(pendingRow.includes('> Start<') && pendingRow.includes('Pending'), 'pending task does not show Start');
assert(activeRow.includes('> Complete<') && activeRow.includes('In Progress'), 'in-progress task does not show Complete');
assert(completedRow.includes('disabled') && completedRow.includes('Completed'), 'completed task does not show a disabled confirmation');
manager._busyTransitions.set(10, 'in_progress');
assert(manager.taskRow({ id:10, title:'Pending task', due_date:'2026-08-22', status:'in_progress' }).includes('Starting...'), 'Start loading state is incorrect');
manager._busyTransitions.set(11, 'completed');
assert(manager.taskRow({ id:11, title:'Active task', due_date:'2026-08-22', status:'completed' }).includes('Completing...'), 'Complete loading state is incorrect');
manager._busyTransitions.clear();
const topicSummary = manager.topicSummary('Topic one; Topic two; Topic three; Topic four; Topic five');
assert(topicSummary.visible === 'Topic one · Topic two · Topic three · Topic four' && topicSummary.remaining === 1, 'topic summary is not compact or count-safe');
assert(manager.topicSummary('Exact unsplit syllabus wording').visible === 'Exact unsplit syllabus wording', 'single-topic wording was changed');
assert(manager.topicSummary('Partitioning and Materialized Views, DML, Join and Subquery, Oracle Database installation, Database creation', 3).remaining === 2, 'comma-delimited topics are not summarized for the table');
manager.view = 'all';
const datedRow = manager.taskRow({ id:13, title:'Dated task', due_date:'2026-08-22', display_date_bs:'2083-05-06', status:'pending', content:'One; Two; Three; Four; Five' });
assert(datedRow.indexOf('Aug 22, 2026') < datedRow.indexOf('2083-05-06 BS'), 'AD/BS metadata hierarchy is incorrect');
assert(datedRow.includes('+1 more') && datedRow.includes('title="One; Two; Three; Four; Five"'), 'full topic content is not preserved for compact rows');
const tableHTML = manager.taskTable([{ id:14, title:'Table task', subject:'Database Administration', unit_label:'Unit 1 & 2', content:'One; Two; Three; Four', due_date:'2026-08-22', display_date_bs:'2083-05-06', status:'in_progress' }]);
for (const heading of ['Date','Subject','Unit','Topics','Status','Action']) assert(tableHTML.includes(heading), `table column missing: ${heading}`);
assert(!tableHTML.includes('colspan=') && !tableHTML.includes('task-col-management') && !tableHTML.includes('Task management'), 'table exposes more than the six required columns');
assert(tableHTML.indexOf('Date') < tableHTML.indexOf('Subject') && tableHTML.indexOf('Subject') < tableHTML.indexOf('Unit') && tableHTML.indexOf('Unit') < tableHTML.indexOf('Topics') && tableHTML.indexOf('Topics') < tableHTML.indexOf('Status') && tableHTML.indexOf('Status') < tableHTML.indexOf('Action'), 'table column order is incorrect');
assert(tableHTML.includes('Aug 22, 2026') && tableHTML.includes('2083-05-06 BS'), 'table does not show AD and BS dates');
assert(tableHTML.includes('In Progress') && tableHTML.includes('Complete') && tableHTML.includes('aria-label="Edit task"') && tableHTML.includes('aria-label="Delete task"'), 'table workflow or management controls are missing');
assert(tableHTML.includes('One; Two; Three; Four') && !tableHTML.includes('aria-label="1 more topics"'), 'Table View does not preserve and show the complete topic content');
manager.render = () => {};
manager.setLayout('card');
assert(storage.get('sanie_tasks_view') === 'card' && manager.layout === 'card', 'Card View preference is not persisted');
manager.setLayout('table');
assert(storage.get('sanie_tasks_view') === 'table' && manager.layout === 'table', 'Table View preference is not persisted');
assert(source.includes("window.matchMedia('(max-width: 991.98px)')") && source.includes("return compact ? 'card' : this.layout"), 'mobile Card View fallback is missing');
assert(source.includes('renderTaskPresentation(items') && !source.includes('tableTasks') && !source.includes('cardTasks'), 'Table and Card views do not share one task collection');
assert(source.includes('aria-pressed=') && source.includes('data-task-layout="table"') && source.includes('data-task-layout="card"'), 'accessible Table/Card switcher is missing');
assert(source.includes('role="progressbar"') && source.includes('aria-valuenow'), 'accessible progress semantics are missing');
assert(source.includes('previousTask') && source.includes('rollbackIndex'), 'optimistic completion rollback is missing');
assert(api.includes("api.patch(`/tasks/${id}/completion`"), 'completion persistence API is missing');
assert(source.includes("tasksAPI.update(id, { status: nextStatus })"), 'lifecycle does not reuse the persisted Task update API');
assert(app.includes("registerRoute('tasks'"), 'Tasks route is not registered');
assert(app.includes("currentPage === 'tasks'") && app.includes('window.tasksManager?.setSearch(term)'), 'global search is not task-aware');
assert(html.includes('id="tasks-page"') && html.includes('data-page="tasks"'), 'Tasks page/navigation is missing');
assert(html.includes('task-summary-total') && html.includes('task-summary-board'), 'compact task summary is missing');
assert(html.includes('nav-pills d-flex flex-nowrap task-view-tabs') && html.includes('role="tablist"'), 'Task tabs are missing their explicit flex layout');
assert(css.includes('.task-tabs-scroller {\n    display: block;') && css.includes('.task-view-tabs {\n    display: flex;'), 'Task tabs can collapse out of the Tasks workspace');
for (const width of ['767.98px','575.98px','374.98px']) assert(css.includes(`max-width: ${width}`), `responsive task breakpoint missing for ${width}`);
const taskCSSStart = css.indexOf('/* Tasks: compact SanIE-native workspace */');
const taskCSS = css.slice(taskCSSStart, css.indexOf('/* ============================================================', taskCSSStart));
assert(!taskCSS.includes('transition: all'), 'Tasks CSS uses broad transition: all');
assert(!taskCSS.includes('100vw'), 'Tasks CSS introduces page-level horizontal overflow risk');
assert(css.includes('.tasks-page-shell') && css.includes('max-width: 1280px'), 'professional task content width is missing');
assert(css.includes('.task-status.in-progress') && css.includes('.task-lifecycle-btn'), 'lifecycle status/button styles are missing');
assert(css.includes('.task-data-table') && css.includes('table-layout: fixed') && css.includes('.task-table-action.task-lifecycle-btn'), 'professional compact task table styling is missing');
assert(css.includes('overflow-wrap: anywhere') && css.includes('white-space: normal'), 'full table topics do not wrap safely');
assert(css.includes('.task-layout-switcher') && css.includes('.task-layout-button.btn.active'), 'segmented view switcher styling is missing');
assert(css.includes('width: 32px') && css.includes('width: 44px'), 'desktop/mobile task action sizing is missing');
assert(css.includes('.main-content:has(> #tasks-page.active) > .app-footer'), 'authenticated Tasks footer is still consuming workspace height');
assert(source.includes('Delete confirmation is unavailable') && !source.includes('window.confirm'), 'delete flow falls back to a browser confirm dialog');
console.log('PASS: compact task hierarchy, topic summaries, lifecycle, views, persistence, accessibility, and responsive rules');
