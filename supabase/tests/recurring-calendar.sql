\set ON_ERROR_STOP on
begin;
insert into auth.users(id,instance_id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at)
values ('41000000-0000-7000-8000-000000000001','00000000-0000-0000-0000-000000000000','authenticated','authenticated','phase1c-recurring@example.test','x',now(),'{}','{"first_name":"Recurrence"}',now(),now());
insert into public.accounts(id,user_id,name,account_type,opening_balance,balance) values ('41000000-0000-7000-8000-000000000011','41000000-0000-7000-8000-000000000001','Cash','cash',100,100);
insert into public.categories(id,user_id,name,category_type) values ('41000000-0000-7000-8000-000000000021','41000000-0000-7000-8000-000000000001','Income','income');
insert into public.recurring_transactions(id,user_id,account_id,category_id,amount,transaction_type,frequency,day_of_month,start_date,next_occurrence) values
('41000000-0000-7000-8000-000000000101','41000000-0000-7000-8000-000000000001','41000000-0000-7000-8000-000000000011','41000000-0000-7000-8000-000000000021',1,'income','monthly',31,'2026-01-31','2026-01-31'),
('41000000-0000-7000-8000-000000000102','41000000-0000-7000-8000-000000000001','41000000-0000-7000-8000-000000000011','41000000-0000-7000-8000-000000000021',1,'income','monthly',31,'2026-01-31','2026-02-28'),
('41000000-0000-7000-8000-000000000103','41000000-0000-7000-8000-000000000001','41000000-0000-7000-8000-000000000011','41000000-0000-7000-8000-000000000021',1,'income','quarterly',31,'2026-11-30','2026-11-30'),
('41000000-0000-7000-8000-000000000104','41000000-0000-7000-8000-000000000001','41000000-0000-7000-8000-000000000011','41000000-0000-7000-8000-000000000021',1,'income','yearly',null,'2024-02-29','2024-02-29'),
('41000000-0000-7000-8000-000000000105','41000000-0000-7000-8000-000000000001','41000000-0000-7000-8000-000000000011','41000000-0000-7000-8000-000000000021',1,'income','yearly',null,'2024-02-29','2025-02-28'),
('41000000-0000-7000-8000-000000000106','41000000-0000-7000-8000-000000000001','41000000-0000-7000-8000-000000000011','41000000-0000-7000-8000-000000000021',1,'income','weekly',null,'2026-09-28','2026-09-28'),
('41000000-0000-7000-8000-000000000107','41000000-0000-7000-8000-000000000001','41000000-0000-7000-8000-000000000011','41000000-0000-7000-8000-000000000021',1,'income','bi_weekly',null,'2026-09-28','2026-09-28');
do $$ declare got date; want date; rec_id uuid; begin
 for rec_id,want in select r.id,x.want from (values
 ('41000000-0000-7000-8000-000000000101'::uuid,'2026-02-28'::date),
 ('41000000-0000-7000-8000-000000000102'::uuid,'2026-03-31'::date),
 ('41000000-0000-7000-8000-000000000103'::uuid,'2027-02-28'::date),
 ('41000000-0000-7000-8000-000000000104'::uuid,'2025-02-28'::date),
 ('41000000-0000-7000-8000-000000000105'::uuid,'2026-02-28'::date),
 ('41000000-0000-7000-8000-000000000106'::uuid,'2026-10-05'::date),
 ('41000000-0000-7000-8000-000000000107'::uuid,'2026-10-12'::date)) x(id,want) join public.recurring_transactions r on r.id=x.id loop
  select public.sanie_next_occurrence(r) into got from public.recurring_transactions r where r.id=rec_id;
  if got is distinct from want then raise exception 'recurrence % next was %, expected %',rec_id,got,want; end if;
 end loop;
end $$;
set role authenticated;
select set_config('request.jwt.claim.sub','41000000-0000-7000-8000-000000000001',true);
do $$ begin
 begin perform public.process_recurring_occurrence('41000000-0000-7000-8000-000000000101','2026-01-31','generate',1); raise exception 'blind backlog generation passed'; exception when others then if sqlerrm<>'RECURRING_REVIEW_REQUIRED' then raise; end if; end;
 perform public.process_recurring_occurrence('41000000-0000-7000-8000-000000000101','2026-01-31','generate',1,true);
 if (select count(*) from public.transactions where recurring_definition_id='41000000-0000-7000-8000-000000000101')<>1 then raise exception 'reviewed occurrence missing'; end if;
 begin perform public.process_recurring_occurrence('41000000-0000-7000-8000-000000000101','2026-01-31','generate',1,true); raise exception 'duplicate recurrence accepted'; exception when others then if sqlerrm<>'INVALID_STATE' then raise; end if; end;
 perform public.process_recurring_occurrence('41000000-0000-7000-8000-000000000101','2026-02-28','skip',1,true);
 if (select count(*) from public.transactions where recurring_definition_id='41000000-0000-7000-8000-000000000101')<>1 then raise exception 'skip added transaction'; end if;
 if (select next_occurrence from public.recurring_transactions where id='41000000-0000-7000-8000-000000000101')<>'2026-03-31' then raise exception 'skip did not preserve month anchor'; end if;
end $$;
reset role;
rollback;
select 'PASS: Phase 1C recurrence calendar' as result;
