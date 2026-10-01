\set ON_ERROR_STOP on
begin;
insert into auth.users(id,instance_id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at)
values
('62000000-0000-7000-8000-000000000001','00000000-0000-0000-0000-000000000000','authenticated','authenticated','phase1c-budget-a@example.test','x',now(),'{}','{"first_name":"Budget A"}',now(),now()),
('62000000-0000-7000-8000-000000000002','00000000-0000-0000-0000-000000000000','authenticated','authenticated','phase1c-budget-b@example.test','x',now(),'{}','{"first_name":"Budget B"}',now(),now());
insert into public.categories(id,user_id,name,category_type) values
('62000000-0000-7000-8000-000000000011','62000000-0000-7000-8000-000000000001','Expense A','expense'),
('62000000-0000-7000-8000-000000000012','62000000-0000-7000-8000-000000000001','Expense B','expense'),
('62000000-0000-7000-8000-000000000013','62000000-0000-7000-8000-000000000002','B Expense','expense');
insert into public.subcategories(id,user_id,category_id,name) values
('62000000-0000-7000-8000-000000000021','62000000-0000-7000-8000-000000000001','62000000-0000-7000-8000-000000000011','Sub One'),
('62000000-0000-7000-8000-000000000022','62000000-0000-7000-8000-000000000001','62000000-0000-7000-8000-000000000011','Sub Two');
set role authenticated;
select set_config('request.jwt.claim.sub','62000000-0000-7000-8000-000000000001',true);
select public.create_budget('62000000-0000-7000-8000-000000000101','62000000-0000-7000-8000-000000000011',null,'Base',100,'2026-10-10','2026-10-20',1);
do $$ declare scenario record; idx integer:=0; begin
 for scenario in select * from (values
 ('exact','2026-10-10'::date,'2026-10-20'::date),
 ('partial','2026-10-15'::date,'2026-10-25'::date),
 ('contains','2026-10-01'::date,'2026-10-31'::date),
 ('contained','2026-10-12'::date,'2026-10-18'::date),
 ('same_start','2026-10-10'::date,'2026-10-11'::date),
 ('same_end','2026-10-19'::date,'2026-10-20'::date),
 ('adjacent_inclusive','2026-10-20'::date,'2026-10-25'::date)) s(label,start_date,end_date) loop
  idx:=idx+1;
  begin
   perform public.create_budget(('62000000-0000-7000-8000-'||lpad(idx::text,12,'0'))::uuid,'62000000-0000-7000-8000-000000000011',null,scenario.label,100,scenario.start_date,scenario.end_date,1);
   raise exception 'overlap accepted: %',scenario.label;
  exception when others then if sqlerrm<>'BUDGET_SCOPE_OVERLAP' then raise; end if; end;
 end loop;
 perform public.create_budget('62000000-0000-7000-8000-000000000102','62000000-0000-7000-8000-000000000011',null,'Non-overlap',100,'2026-10-21','2026-10-31',1);
 perform public.create_budget('62000000-0000-7000-8000-000000000103','62000000-0000-7000-8000-000000000012',null,'Different Category',100,'2026-10-10','2026-10-20',1);
 perform public.create_budget('62000000-0000-7000-8000-000000000104','62000000-0000-7000-8000-000000000011','62000000-0000-7000-8000-000000000021','Sub One',100,'2026-10-10','2026-10-20',1);
 perform public.create_budget('62000000-0000-7000-8000-000000000105','62000000-0000-7000-8000-000000000011','62000000-0000-7000-8000-000000000022','Sub Two',100,'2026-10-10','2026-10-20',1);
 begin perform public.create_budget('62000000-0000-7000-8000-000000000106','62000000-0000-7000-8000-000000000011','62000000-0000-7000-8000-000000000021','Sub One Again',100,'2026-10-10','2026-10-20',1); raise exception 'subcategory overlap accepted'; exception when others then if sqlerrm<>'BUDGET_SCOPE_OVERLAP' then raise; end if; end;
end $$;
select set_config('request.jwt.claim.sub','62000000-0000-7000-8000-000000000002',true);
select public.create_budget('62000000-0000-7000-8000-000000000107','62000000-0000-7000-8000-000000000013',null,'Other User',100,'2026-10-10','2026-10-20',1);
reset role;
rollback;
select 'PASS: Phase 1C budget range matrix' as result;
