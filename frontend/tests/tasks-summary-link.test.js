const fs = require('fs');
const vm = require('vm');
const path = require('path');

const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'assets/js/tasks.js'), 'utf8');
let modalOptions = null;
let updatedPayload = null;
const fields = {
    'task-title': { value:'Completed task' }, 'task-content': { value:'' },
    'task-due-date': { value:'' }, 'task-priority': { value:'normal' },
    'task-reminder': { value:'' }, 'task-summary-url': { value:'' },
};
const escapeHTML = value => String(value).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
const context = {
    URL,
    window: { localStorage:{ getItem:()=>null, setItem:()=>{} } },
    document: { getElementById:id => fields[id] || null },
    Formatters: { escapeHTML },
    DateUtils: { getKathmanduDateString:()=> '2026-08-26', addCalendarDays:()=> '2026-08-27' },
    modalService: { open:options => { modalOptions=options; }, submitForm:async()=>true, close:()=>{} },
    tasksAPI: { update:async(id,payload) => { updatedPayload=payload; return {data:{id,status:'completed',title:'Completed task',...payload}}; } },
    NotificationService: { success:()=>{}, error:message => { throw new Error(message); } },
};
vm.createContext(context);
vm.runInContext(source + '\nwindow.__TasksManager = TasksManager;', context);

let assertions = 0;
function assert(value, message) { if (!value) throw new Error(message); assertions++; }

(async () => {
    const manager = new context.window.__TasksManager();
    manager.today = () => '2026-08-26';
    const linked = { id:1, title:'Cloud & Security', status:'completed', summary_url:'https://drive.google.com/file/d/example/view?usp=sharing' };
    const table = manager.taskTable([linked]);
    assert(table.includes('bi-file-earmark-text') && table.indexOf('is-summary') < table.indexOf('data-task-edit') && table.indexOf('data-task-edit') < table.indexOf('data-task-delete'), 'completed table action order is not Summary, Edit, Delete');
    assert(table.includes('target="_blank"') && table.includes('rel="noopener noreferrer"'), 'summary action lacks safe new-tab attributes');
    assert(table.includes('aria-label="Open summary for Cloud &amp; Security"'), 'summary action lacks a task-specific accessible label');
    assert(table.includes('href="https://drive.google.com/file/d/example/view?usp=sharing"'), 'valid summary URL is not rendered as a link');
    assert(!table.includes('task-action-finished') && table.includes('<th scope="col">Action</th>'), 'completed actions are split across an extra dash column');

    const missing = manager.taskTable([{ id:2, title:'No summary', status:'completed', summary_url:null }]);
    assert(missing.includes('is-summary is-disabled') && missing.includes('disabled') && missing.includes('No summary attached'), 'missing summary does not render a disabled action');
    assert(!missing.includes('target="_blank"'), 'missing summary rendered an active link');

    const card = manager.taskRow(linked);
    assert(card.includes('target="_blank"') && card.includes('data-task-edit'), 'completed card does not support the summary action');

    const unsafe = manager.taskRow({ id:3, title:'Unsafe', status:'completed', summary_url:'javascript:alert(1)' });
    assert(unsafe.includes('is-summary is-disabled') && !unsafe.includes('javascript:'), 'unsafe summary value was inserted into executable markup');
    assert(!manager.isSafeSummaryUrl('http://example.com/a.pdf') && !manager.isSafeSummaryUrl('data:text/html,x') && manager.isSafeSummaryUrl('https://www.dropbox.com/a.pdf'), 'frontend HTTPS validation is incorrect');

    manager.openEditor(linked);
    assert(modalOptions.bodyHTML.includes('id="task-summary-url"') && modalOptions.bodyHTML.includes('value="https://drive.google.com/file/d/example/view?usp=sharing"'), 'edit form does not populate summary_url');
    assert(modalOptions.bodyHTML.includes('Summary PDF / Drive Link') && modalOptions.bodyHTML.includes('maxlength="2048"'), 'summary form field metadata is missing');

    manager.tasks = [linked];
    manager.render = () => {};
    await manager.saveEditor(linked);
    assert(updatedPayload.summary_url === null, 'clearing the summary field does not send NULL');

    console.log(`PASS: ${assertions} task summary-link frontend assertions`);
})().catch(error => { console.error(error); process.exit(1); });
