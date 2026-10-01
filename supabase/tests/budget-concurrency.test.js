const { spawn, execFileSync } = require('node:child_process');
const docker = 'C:\\Users\\govin\\AppData\\Local\\Programs\\DockerDesktop\\resources\\bin\\docker.exe';
const args = ['exec', '-i', 'supabase_db_sanie', 'psql', '-U', 'postgres', '-d', 'postgres', '-X', '-v', 'ON_ERROR_STOP=1', '-q', '-t'];
const uid = '61000000-0000-7000-8000-000000000001';
const category = '61000000-0000-7000-8000-000000000002';
function sql(input) { return execFileSync(docker, args, { input, encoding: 'utf8' }); }
function worker(input) {
  return new Promise(resolve => {
    const process = spawn(docker, args);
    let output = '';
    process.stdout.on('data', data => { output += data; });
    process.stderr.on('data', data => { output += data; });
    process.on('close', code => resolve({ code, output }));
    process.stdin.end(input);
  });
}
const setup = `insert into auth.users(id,instance_id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at)
values ('${uid}','00000000-0000-0000-0000-000000000000','authenticated','authenticated','phase1c-budget-race@example.test','x',now(),'{}','{"first_name":"Budget Race"}',now(),now());
insert into public.categories(id,user_id,name,category_type) values ('${category}','${uid}','Expense','expense');`;
const call = (id, pause) => `begin;
set role authenticated;
select set_config('request.jwt.claim.sub','${uid}',true);
select public.create_budget('${id}','${category}',null,'Race',100,'2026-10-01','2026-10-31',1);
${pause ? 'select pg_sleep(2);' : ''}
commit;`;
(async () => {
  sql(setup);
  try {
    const first = worker(call('61000000-0000-7000-8000-000000000011', true));
    await new Promise(resolve => setTimeout(resolve, 250));
    const second = worker(call('61000000-0000-7000-8000-000000000012', false));
    const [a, b] = await Promise.all([first, second]);
    const count = Number(sql(`select count(*) from public.budgets where user_id='${uid}';`).trim());
    if (a.code !== 0 || b.code === 0 || !b.output.includes('BUDGET_SCOPE_OVERLAP') || count !== 1) {
      throw new Error(`race result: first=${a.code}, second=${b.code}, rows=${count}, second output=${b.output}`);
    }
    console.log('PASS: concurrent overlapping budgets commit at most one row');
  } finally {
    sql(`delete from auth.users where id='${uid}';`);
  }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
