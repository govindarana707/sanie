\set ON_ERROR_STOP on
begin;
insert into auth.users(id,instance_id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at)
values
('31000000-0000-7000-8000-000000000001','00000000-0000-0000-0000-000000000000','authenticated','authenticated','phase1c-security-a@example.test','x',now(),'{}','{"first_name":"Security A"}',now(),now()),
('32000000-0000-7000-8000-000000000002','00000000-0000-0000-0000-000000000000','authenticated','authenticated','phase1c-security-b@example.test','x',now(),'{}','{"first_name":"Security B"}',now(),now());
insert into public.accounts(id,user_id,name,account_type,opening_balance,balance) values
('31000000-0000-7000-8000-000000000011','31000000-0000-7000-8000-000000000001','Security A Cash','cash',100,100),
('31000000-0000-7000-8000-000000000013','31000000-0000-7000-8000-000000000001','Security A Bank','bank',0,0),
('32000000-0000-7000-8000-000000000012','32000000-0000-7000-8000-000000000002','Security B Cash','cash',100,100);
insert into public.categories(id,user_id,name,category_type,is_system) values
('31000000-0000-7000-8000-000000000021','31000000-0000-7000-8000-000000000001','A Income','income',false),
('31000000-0000-7000-8000-000000000022','31000000-0000-7000-8000-000000000001','A Expense','expense',false),
('32000000-0000-7000-8000-000000000023','32000000-0000-7000-8000-000000000002','B Expense','expense',false),
('33000000-0000-7000-8000-000000000024',null,'System Expense','expense',true);
insert into public.subcategories(id,user_id,category_id,name) values
('31000000-0000-7000-8000-000000000031','31000000-0000-7000-8000-000000000001','31000000-0000-7000-8000-000000000022','A Sub'),
('32000000-0000-7000-8000-000000000032','32000000-0000-7000-8000-000000000002','32000000-0000-7000-8000-000000000023','B Sub');
insert into public.goals(id,user_id,name,target_amount) values
('31000000-0000-7000-8000-000000000041','31000000-0000-7000-8000-000000000001','A Goal',100),
('32000000-0000-7000-8000-000000000042','32000000-0000-7000-8000-000000000002','B Goal',100);
insert into public.people(id,user_id,name) values
('31000000-0000-7000-8000-000000000051','31000000-0000-7000-8000-000000000001','A Person'),
('32000000-0000-7000-8000-000000000052','32000000-0000-7000-8000-000000000002','B Person');
insert into public.tasks(id,user_id,title) values ('32000000-0000-7000-8000-000000000061','32000000-0000-7000-8000-000000000002','B Task');
insert into public.notifications(id,user_id,notification_type,title) values ('32000000-0000-7000-8000-000000000062','32000000-0000-7000-8000-000000000002','test','B Notice');
insert into public.budgets(id,user_id,category_id,name,amount,start_date,end_date) values ('32000000-0000-7000-8000-000000000063','32000000-0000-7000-8000-000000000002','32000000-0000-7000-8000-000000000023','B Budget',10,'2026-01-01','2026-01-31');
insert into public.recurring_transactions(id,user_id,account_id,category_id,amount,transaction_type,frequency,start_date,next_occurrence) values ('32000000-0000-7000-8000-000000000064','32000000-0000-7000-8000-000000000002','32000000-0000-7000-8000-000000000012','32000000-0000-7000-8000-000000000023',1,'expense','daily','2026-01-01','2026-01-01');
insert into public.transactions(id,user_id,account_id,from_account_id,category_id,amount,transaction_type,transaction_date) values ('32000000-0000-7000-8000-000000000065','32000000-0000-7000-8000-000000000002','32000000-0000-7000-8000-000000000012','32000000-0000-7000-8000-000000000012','32000000-0000-7000-8000-000000000023',1,'expense','2026-01-01');
insert into public.karobar_transactions(id,user_id,person_id,transaction_type,amount,account_id,transaction_date) values ('32000000-0000-7000-8000-000000000066','32000000-0000-7000-8000-000000000002','32000000-0000-7000-8000-000000000052','lent',1,'32000000-0000-7000-8000-000000000012','2026-01-01');
insert into public.attachments(id,user_id,transaction_id,storage_bucket,storage_object_path,file_name) values ('32000000-0000-7000-8000-000000000067','32000000-0000-7000-8000-000000000002','32000000-0000-7000-8000-000000000065','test','phase1c-security-b','file');

set role authenticated;
select set_config('request.jwt.claim.sub','31000000-0000-7000-8000-000000000001',true);
do $$ declare t text; n integer; begin
 if (select count(*) from public.profiles where id='31000000-0000-7000-8000-000000000001')<>1 then raise exception 'profile bootstrap missing'; end if;
 foreach t in array array['profiles','accounts','categories','subcategories','goals','people','tasks','notifications','budgets','recurring_transactions','transactions','karobar_transactions','attachments'] loop
  execute format('select count(*) from public.%I where %I=$1',t,case when t='profiles' then 'id' else 'user_id' end) into n using '32000000-0000-7000-8000-000000000002'::uuid;
  if n<>0 then raise exception 'RLS leaked table %: % rows',t,n; end if;
 end loop;
 if (select count(*) from public.categories where id='33000000-0000-7000-8000-000000000024')<>1 then raise exception 'system category hidden'; end if;
 if has_function_privilege('authenticated','public.sanie_rebuild_account(uuid,uuid)','execute') then raise exception 'internal account rebuild executable by client'; end if;
 if has_function_privilege('authenticated','public.sanie_rebuild_goal(uuid,uuid)','execute') then raise exception 'internal goal rebuild executable by client'; end if;
 if has_function_privilege('authenticated','public.sanie_assert_owned(regclass,uuid,uuid,boolean)','execute') then raise exception 'internal reference validator executable by client'; end if;
end $$;

do $$ declare affected integer; begin
 begin perform public.create_income('31000000-0000-7000-8000-000000000071','32000000-0000-7000-8000-000000000012','31000000-0000-7000-8000-000000000021',null,1,current_date,'foreign account','cash','31000000-0000-7000-8000-000000000081',1); raise exception 'foreign account accepted'; exception when others then if sqlerrm<>'FORBIDDEN_REFERENCE' then raise; end if; end;
 begin perform public.create_expense('31000000-0000-7000-8000-000000000072','31000000-0000-7000-8000-000000000011','32000000-0000-7000-8000-000000000023',null,1,current_date,'foreign category','cash','31000000-0000-7000-8000-000000000082',1); raise exception 'foreign category accepted'; exception when others then if sqlerrm<>'FORBIDDEN_REFERENCE' then raise; end if; end;
 begin perform public.create_expense('31000000-0000-7000-8000-000000000077','31000000-0000-7000-8000-000000000011','31000000-0000-7000-8000-000000000022','32000000-0000-7000-8000-000000000032',1,current_date,'foreign subcategory','cash','31000000-0000-7000-8000-000000000087',1); raise exception 'foreign subcategory accepted'; exception when others then if sqlerrm<>'FORBIDDEN_REFERENCE' then raise; end if; end;
 begin perform public.create_goal_contribution('31000000-0000-7000-8000-000000000073','32000000-0000-7000-8000-000000000042','31000000-0000-7000-8000-000000000011',1,current_date,'foreign goal','31000000-0000-7000-8000-000000000083',1); raise exception 'foreign goal accepted'; exception when others then if sqlerrm<>'FORBIDDEN_REFERENCE' then raise; end if; end;
 begin perform public.create_credit_purchase('31000000-0000-7000-8000-000000000074','31000000-0000-7000-8000-000000000075','32000000-0000-7000-8000-000000000052','31000000-0000-7000-8000-000000000022',1,current_date,'foreign person','31000000-0000-7000-8000-000000000084',1); raise exception 'foreign person accepted'; exception when others then if sqlerrm<>'FORBIDDEN_REFERENCE' then raise; end if; end;
 begin insert into public.attachments(id,user_id,transaction_id,storage_bucket,storage_object_path,file_name) values ('31000000-0000-7000-8000-000000000076','31000000-0000-7000-8000-000000000001','32000000-0000-7000-8000-000000000065','test','phase1c-cross','cross'); raise exception 'foreign attachment accepted'; exception when others then if sqlerrm<>'FORBIDDEN_REFERENCE' then raise; end if; end;
 begin perform public.create_budget('31000000-0000-7000-8000-000000000078','32000000-0000-7000-8000-000000000023',null,'Foreign',1,current_date,current_date,1); raise exception 'foreign budget category accepted'; exception when others then if sqlerrm<>'FORBIDDEN_REFERENCE' then raise; end if; end;
 begin insert into public.recurring_transactions(id,user_id,account_id,category_id,amount,transaction_type,frequency,start_date,next_occurrence) values ('31000000-0000-7000-8000-000000000079','31000000-0000-7000-8000-000000000001','32000000-0000-7000-8000-000000000012','31000000-0000-7000-8000-000000000022',1,'expense','daily',current_date,current_date); raise exception 'foreign recurring account accepted'; exception when others then if sqlerrm<>'FORBIDDEN_REFERENCE' then raise; end if; end;
 begin insert into public.people(id,user_id,name) values ('31000000-0000-7000-8000-000000000080','32000000-0000-7000-8000-000000000002','Impersonated'); raise exception 'foreign owner insert accepted'; exception when insufficient_privilege then null; end;
 affected:=0; begin update public.people set user_id='32000000-0000-7000-8000-000000000002' where id='31000000-0000-7000-8000-000000000051'; get diagnostics affected=row_count; exception when insufficient_privilege then affected:=0; end; if affected<>0 then raise exception 'ownership change accepted'; end if;
 update public.people set name='changed' where id='32000000-0000-7000-8000-000000000052'; get diagnostics affected=row_count; if affected<>0 then raise exception 'foreign update accepted'; end if;
 delete from public.people where id='32000000-0000-7000-8000-000000000052'; get diagnostics affected=row_count; if affected<>0 then raise exception 'foreign delete accepted'; end if;
 update public.profiles set first_name='changed' where id='32000000-0000-7000-8000-000000000002'; get diagnostics affected=row_count; if affected<>0 then raise exception 'foreign profile update accepted'; end if;
 update public.categories set name='changed' where id='33000000-0000-7000-8000-000000000024'; get diagnostics affected=row_count; if affected<>0 then raise exception 'system category update accepted'; end if;
 delete from public.categories where id='33000000-0000-7000-8000-000000000024'; get diagnostics affected=row_count; if affected<>0 then raise exception 'system category delete accepted'; end if;
 begin insert into public.categories(id,user_id,name,category_type,is_system) values ('31000000-0000-7000-8000-000000000089',null,'Fake System','expense',true); raise exception 'system category impersonation accepted'; exception when insufficient_privilege then null; end;
 begin insert into public.transactions(id,user_id,amount,transaction_type,transaction_date) values ('31000000-0000-7000-8000-000000000095','31000000-0000-7000-8000-000000000001',1,'income',current_date); raise exception 'direct transaction insert accepted'; exception when insufficient_privilege then null; end;
 begin update public.transactions set amount=2 where id='32000000-0000-7000-8000-000000000065'; raise exception 'direct transaction update accepted'; exception when insufficient_privilege then null; end;
 begin delete from public.transactions where id='32000000-0000-7000-8000-000000000065'; raise exception 'direct transaction delete accepted'; exception when insufficient_privilege then null; end;
 begin insert into public.karobar_transactions(id,user_id,person_id,transaction_type,amount,transaction_date) values ('31000000-0000-7000-8000-000000000096','31000000-0000-7000-8000-000000000001','31000000-0000-7000-8000-000000000051','adjustment',1,current_date); raise exception 'direct Karobar insert accepted'; exception when insufficient_privilege then null; end;
 begin update public.karobar_transactions set amount=2 where id='32000000-0000-7000-8000-000000000066'; raise exception 'direct Karobar update accepted'; exception when insufficient_privilege then null; end;
 begin delete from public.karobar_transactions where id='32000000-0000-7000-8000-000000000066'; raise exception 'direct Karobar delete accepted'; exception when insufficient_privilege then null; end;
 begin update public.profiles set data_generation=99 where id='31000000-0000-7000-8000-000000000001'; raise exception 'generation write accepted'; exception when insufficient_privilege then null; end;
 begin update public.accounts set balance=999 where id='31000000-0000-7000-8000-000000000011'; raise exception 'balance write accepted'; exception when insufficient_privilege then null; end;
 begin update public.goals set current_amount=999 where id='31000000-0000-7000-8000-000000000041'; raise exception 'goal write accepted'; exception when insufficient_privilege then null; end;
 begin insert into public.accounts(id,user_id,name,account_type,opening_balance,balance) values ('31000000-0000-7000-8000-000000000091','31000000-0000-7000-8000-000000000001','Fake Cash','cash',0,999); raise exception 'balance insert accepted'; exception when insufficient_privilege then null; end;
 begin insert into public.goals(id,user_id,name,target_amount,initial_amount,current_amount) values ('31000000-0000-7000-8000-000000000092','31000000-0000-7000-8000-000000000001','Fake Goal',100,0,999); raise exception 'goal amount insert accepted'; exception when insufficient_privilege then null; end;
 insert into public.accounts(id,user_id,name,account_type,opening_balance) values ('31000000-0000-7000-8000-000000000093','31000000-0000-7000-8000-000000000001','New Cash','cash',23);
 if (select balance from public.accounts where id='31000000-0000-7000-8000-000000000093')<>23 then raise exception 'new account balance not initialized'; end if;
 insert into public.goals(id,user_id,name,target_amount,initial_amount) values ('31000000-0000-7000-8000-000000000094','31000000-0000-7000-8000-000000000001','New Goal',100,15);
 if (select current_amount from public.goals where id='31000000-0000-7000-8000-000000000094')<>15 then raise exception 'new goal amount not initialized'; end if;
 insert into public.tasks(id,user_id,title) values ('31000000-0000-7000-8000-000000000097','31000000-0000-7000-8000-000000000001','Versioned Task');
 update public.tasks set title='Version Two' where id='31000000-0000-7000-8000-000000000097' and version=1; get diagnostics affected=row_count;
 if affected<>1 or (select version from public.tasks where id='31000000-0000-7000-8000-000000000097')<>2 then raise exception 'guarded direct update failed'; end if;
 update public.tasks set title='Stale Write' where id='31000000-0000-7000-8000-000000000097' and version=1; get diagnostics affected=row_count;
 if affected<>0 or (select title from public.tasks where id='31000000-0000-7000-8000-000000000097')<>'Version Two' then raise exception 'stale direct update overwrote canonical state'; end if;
end $$;

-- Known ledger: 100 + 25 - 10 - 20 transfer - 2 fee - 5 goal - 7 lent + 3 borrowed = 84.
do $$ declare first_cursor bigint; second_cursor bigint; before_count bigint; after_count bigint; begin
 perform public.create_income('31000000-0000-7000-8000-000000000101','31000000-0000-7000-8000-000000000011','31000000-0000-7000-8000-000000000021',null,25,current_date,'income','cash','31000000-0000-7000-8000-000000000201',1);
 perform public.create_expense('31000000-0000-7000-8000-000000000102','31000000-0000-7000-8000-000000000011','31000000-0000-7000-8000-000000000022',null,10,current_date,'expense','cash','31000000-0000-7000-8000-000000000202',1);
 perform public.create_transfer('31000000-0000-7000-8000-000000000103','31000000-0000-7000-8000-000000000011','31000000-0000-7000-8000-000000000013',20,2,'31000000-0000-7000-8000-000000000022',current_date,'transfer','31000000-0000-7000-8000-000000000203',1);
 perform public.create_goal_contribution('31000000-0000-7000-8000-000000000104','31000000-0000-7000-8000-000000000041','31000000-0000-7000-8000-000000000011',5,current_date,'goal','31000000-0000-7000-8000-000000000204',1);
 perform public.create_karobar_transaction('31000000-0000-7000-8000-000000000105','31000000-0000-7000-8000-000000000051','lent',7,'31000000-0000-7000-8000-000000000011',current_date,null,'lent','31000000-0000-7000-8000-000000000205',1);
 perform public.create_karobar_transaction('31000000-0000-7000-8000-000000000106','31000000-0000-7000-8000-000000000051','borrowed',3,'31000000-0000-7000-8000-000000000011',current_date,null,'borrowed','31000000-0000-7000-8000-000000000206',1);
 if (select balance from public.accounts where id='31000000-0000-7000-8000-000000000011')<>84 then raise exception 'known ledger balance incorrect'; end if;
 if (select current_amount from public.goals where id='31000000-0000-7000-8000-000000000041')<>5 then raise exception 'goal contribution missing'; end if;
 select count(*) into before_count from public.pull_changes(0,500,1);
 select max(sequence) into first_cursor from public.pull_changes(0,2,1);
 select max(sequence) into second_cursor from public.pull_changes(first_cursor,2,1);
 if first_cursor is null or second_cursor<=first_cursor then raise exception 'feed pagination cursor did not advance'; end if;
 select count(*) into after_count from public.pull_changes(first_cursor,500,1);
 if before_count<>after_count+2 then raise exception 'feed pages lost/duplicated rows'; end if;
 if exists(select 1 from public.pull_changes(0,500,1) where entity_id='32000000-0000-7000-8000-000000000012') then raise exception 'foreign feed event leaked'; end if;
end $$;
select set_config('request.jwt.claim.sub','32000000-0000-7000-8000-000000000002',true);
do $$ begin
 if exists(select 1 from public.pull_changes(0,500,1) where entity_id='31000000-0000-7000-8000-000000000101') then raise exception 'A event leaked to B'; end if;
 if (select count(*) from public.categories where id='33000000-0000-7000-8000-000000000024')<>1 then raise exception 'B cannot see system category'; end if;
end $$;

-- Rebuilds are tested as privileged maintenance after deliberate corruption.
reset role;
do $$ declare f record; begin
 for f in select p.oid,p.proname,p.proconfig from pg_proc p join pg_namespace n on n.oid=p.pronamespace where n.nspname='public' and p.prosecdef loop
  if f.proconfig is null or not exists(select 1 from unnest(f.proconfig) setting where setting like 'search_path=%') then raise exception 'security definer % lacks search_path',f.proname; end if;
  if has_function_privilege('anon',f.oid,'execute') or has_function_privilege('public',f.oid,'execute') then raise exception 'anonymous function execute leaked: %',f.proname; end if;
  if f.proname like 'sanie_%' and has_function_privilege('authenticated',f.oid,'execute') then raise exception 'internal function execute leaked: %',f.proname; end if;
 end loop;
end $$;
update public.accounts set balance=999 where id='31000000-0000-7000-8000-000000000011';
update public.goals set current_amount=999 where id='31000000-0000-7000-8000-000000000041';
do $$ declare a numeric; g numeric; begin
 a:=public.sanie_rebuild_account('31000000-0000-7000-8000-000000000011','31000000-0000-7000-8000-000000000001');
 perform public.sanie_rebuild_goal('31000000-0000-7000-8000-000000000041','31000000-0000-7000-8000-000000000001');
 if a<>84 or (select current_amount from public.goals where id='31000000-0000-7000-8000-000000000041')<>5 then raise exception 'rebuild failed'; end if;
 a:=public.sanie_rebuild_account('31000000-0000-7000-8000-000000000011','31000000-0000-7000-8000-000000000001');
 perform public.sanie_rebuild_goal('31000000-0000-7000-8000-000000000041','31000000-0000-7000-8000-000000000001');
 if a<>84 or (select current_amount from public.goals where id='31000000-0000-7000-8000-000000000041')<>5 then raise exception 'repeat rebuild changed value'; end if;
end $$;
rollback;
select 'PASS: Phase 1C security and rebuild' as result;
