\set ON_ERROR_STOP on
begin;
insert into auth.users(id,instance_id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at)
values ('71000000-0000-7000-8000-000000000001','00000000-0000-0000-0000-000000000000','authenticated','authenticated','phase1c-feed@example.test','x',now(),'{}','{"first_name":"Feed"}',now(),now());
set role authenticated;
select set_config('request.jwt.claim.sub','71000000-0000-7000-8000-000000000001',true);
insert into public.tasks(id,user_id,title)
select ('71000000-0000-7000-8001-'||lpad(g::text,12,'0'))::uuid,'71000000-0000-7000-8000-000000000001','Task '||g from generate_series(1,510) g;
do $$ declare cursor_one bigint; page_one integer; page_two integer; begin
 select count(*),max(sequence) into page_one,cursor_one from public.pull_changes(0,9999,1);
 if page_one<>500 then raise exception 'change-feed maximum cap failed: %',page_one; end if;
 select count(*) into page_two from public.pull_changes(cursor_one,9999,1);
 if page_two<>10 then raise exception 'change-feed second page failed: %',page_two; end if;
 if (select count(*) from public.pull_changes(-10,0,1))<>1 then raise exception 'change-feed cursor/limit normalization failed'; end if;
 if exists(select sequence from public.pull_changes(0,500,1) intersect select sequence from public.pull_changes(cursor_one,500,1)) then raise exception 'change-feed pages overlap'; end if;
end $$;
create temporary table phase1c_feed_cursor as
select max(sequence) cursor from public.pull_changes((select max(sequence) from public.pull_changes(0,9999,1)),9999,1);
update public.tasks set deleted_at=now() where id='71000000-0000-7000-8001-000000000001';
do $$ begin
 if not exists(select 1 from public.pull_changes((select cursor from phase1c_feed_cursor),9999,1) where entity_id='71000000-0000-7000-8001-000000000001' and operation='tombstone') then raise exception 'feed tombstone missing'; end if;
end $$;
reset role;
rollback;
select 'PASS: Phase 1C change-feed scale' as result;
