const fs = require('fs');
const vm = require('vm');
const path = require('path');

const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'assets/js/tasks.js'), 'utf8');
const html = fs.readFileSync(path.join(root, 'index.html'), 'utf8');
const css = fs.readFileSync(path.join(root, 'assets/css/styles.css'), 'utf8');
const context = {
    window:{ localStorage:{ getItem:()=>null, setItem:()=>{} } }, URL,
    Formatters:{ escapeHTML:value=>String(value) },
    DateUtils:{ getKathmanduDateString:()=> '2026-08-26', addCalendarDays:()=> '2026-08-27' },
};
vm.createContext(context);
vm.runInContext(source + '\nwindow.__TasksManager = TasksManager;', context);

let assertions = 0;
function assert(value, message) { if (!value) throw new Error(message); assertions++; }

const manager = new context.window.__TasksManager();
manager.today = () => '2026-08-26';
manager.view = 'all';
manager.tasks = [
    {id:1,subject:'Cloud Computing',unit_label:'Unit 1',priority:'high',status:'pending',title:'Cloud A'},
    {id:2,subject:'Cloud Computing',unit_label:'Unit 2',priority:'normal',status:'completed',title:'Cloud B'},
    {id:3,subject:'Database Administration',unit_label:'Unit 1',priority:'high',status:'in_progress',title:'DB A'},
    {id:4,subject:null,unit_label:null,priority:'low',status:'pending',title:'Personal task'},
];

manager.filters.subject = 'Cloud Computing';
assert(manager.filteredItems().map(item=>item.id).join(',') === '1,2', 'subject filter is incorrect');
manager.filters.unit = 'Unit 1';
assert(manager.filteredItems().map(item=>item.id).join(',') === '1', 'subject and unit filters do not combine');
manager.filters.priority = 'high';
manager.filters.status = 'pending';
assert(manager.filteredItems().map(item=>item.id).join(',') === '1', 'priority and status filters do not combine');
manager.filters.subject = 'all';
assert(manager.filteredItems().map(item=>item.id).join(',') === '1', 'all-subject selection does not preserve other filters');
manager.filters = {subject:'all',unit:'all',priority:'all',status:'all'};
assert(manager.filteredItems().length === 4 && !manager.hasActiveFilters(), 'cleared filters do not restore the full view');
manager.filters.status = 'completed';
manager.searchQuery = 'cloud';
assert(manager.filteredItems().map(item=>item.id).join(',') === '2', 'task search does not combine with filters');
assert(manager.hasActiveFilters(), 'active filter state is not detected');

for (const id of ['task-filter-subject','task-filter-unit','task-filter-priority','task-filter-status']) {
    assert(html.includes(`id="${id}"`), `filter control is missing: ${id}`);
}
assert(html.includes('data-task-clear-filters'), 'clear-filters action is missing');
assert(css.includes('.task-filter-field') && css.includes('grid-template-columns: repeat(2'), 'responsive filter layout is missing');
assert(source.includes('this.populateFilterOptions()') && source.includes('new Set(values'), 'dynamic subject/unit options are missing');

console.log(`PASS: ${assertions} task multi-filter assertions`);
