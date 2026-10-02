\set ON_ERROR_STOP on
begin;
insert into auth.users(id,instance_id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at)
values
 ('31111111-1111-7111-8111-111111111111','00000000-0000-0000-0000-000000000000','authenticated','authenticated','account-a@example.test','x',now(),'{}','{"first_name":"A"}',now(),now()),
 ('32222222-2222-7222-8222-222222222222','00000000-0000-0000-0000-000000000000','authenticated','authenticated','account-b@example.test','x',now(),'{}','{"first_name":"B"}',now(),now());
insert into public.categories(id,user_id,name,category_type,is_system)
values ('33333333-3333-7333-8333-333333333333','31111111-1111-7111-8111-111111111111','Test income','income',false);

set role authenticated;
select set_config('request.jwt.claim.sub','31111111-1111-7111-8111-111111111111',false);
select public.create_account('34444444-4444-7444-8444-444444444444','  Main cash  ','cash',100.25,'35555555-5555-7555-8555-555555555551',1);
do $$ begin
 if (select balance from public.accounts where id='34444444-4444-7444-8444-444444444444')<>100.25 then raise exception 'opening balance trigger failed'; end if;
 if (select name from public.accounts where id='34444444-4444-7444-8444-444444444444')<>'Main cash' then raise exception 'name was not trimmed'; end if;
 if (select count(*) from public.pull_changes(0,500,1) where entity_id='34444444-4444-7444-8444-444444444444')<>1 then raise exception 'create feed event missing'; end if;
end $$;
do $$ declare r jsonb; begin
 r:=public.create_account('34444444-4444-7444-8444-444444444444','Main cash','cash',100.25,'35555555-5555-7555-8555-555555555551',1);
 if r->>'replayed'<>'true' then raise exception 'create replay not reported'; end if;
 if (select count(*) from public.pull_changes(0,500,1) where entity_id='34444444-4444-7444-8444-444444444444')<>1 then raise exception 'create replay generated event'; end if;
 begin perform public.create_account('34444444-4444-7444-8444-444444444444','changed','cash',100.25,'35555555-5555-7555-8555-555555555551',1); raise exception 'create mismatch passed'; exception when others then if sqlerrm<>'IDEMPOTENCY_MISMATCH' then raise; end if; end;
 begin perform public.create_account(gen_random_uuid(),'stale','cash',0,gen_random_uuid(),2); raise exception 'generation mismatch passed'; exception when others then if sqlerrm<>'DATA_GENERATION_MISMATCH' then raise; end if; end;
 begin update public.accounts set balance=999 where id='34444444-4444-7444-8444-444444444444'; raise exception 'balance overwrite passed'; exception when insufficient_privilege then null; end;
end $$;
select public.update_account('34444444-4444-7444-8444-444444444444','Daily cash','wallet','  1234  ',1,'35555555-5555-7555-8555-555555555552',1);
do $$ declare r jsonb; begin
 if (select balance from public.accounts where id='34444444-4444-7444-8444-444444444444')<>100.25 then raise exception 'metadata edit changed balance'; end if;
 r:=public.update_account('34444444-4444-7444-8444-444444444444','Daily cash','wallet','1234',1,'35555555-5555-7555-8555-555555555552',1);
 if r->>'replayed'<>'true' then raise exception 'edit replay not reported'; end if;
 begin perform public.update_account('34444444-4444-7444-8444-444444444444','stale','cash',null,1,gen_random_uuid(),1); raise exception 'stale edit passed'; exception when others then if sqlerrm<>'CONFLICT' then raise; end if; end;
end $$;
select public.create_income('36666666-6666-7666-8666-666666666666','34444444-4444-7444-8444-444444444444','33333333-3333-7333-8333-333333333333',null,10,current_date,'income','cash','37777777-7777-7777-8777-777777777777',1);
do $$ declare v bigint; r jsonb; begin
 select version into v from public.accounts where id='34444444-4444-7444-8444-444444444444';
 r:=public.archive_account('34444444-4444-7444-8444-444444444444',v,'35555555-5555-7555-8555-555555555553',1);
 if r->>'replayed'<>'false' then raise exception 'archive not applied'; end if;
 if not exists(select 1 from public.accounts where id='34444444-4444-7444-8444-444444444444' and deleted_at is not null and not is_active and balance=110.25) then raise exception 'archive state or balance incorrect'; end if;
 if not exists(select 1 from public.transactions where id='36666666-6666-7666-8666-666666666666' and deleted_at is null) then raise exception 'archive destroyed history'; end if;
 r:=public.archive_account('34444444-4444-7444-8444-444444444444',v,'35555555-5555-7555-8555-555555555553',1);
 if r->>'replayed'<>'true' then raise exception 'archive replay not reported'; end if;
 if (select count(*) from public.pull_changes(0,500,1) where entity_id='34444444-4444-7444-8444-444444444444' and operation='tombstone')<>1 then raise exception 'archive replay duplicated tombstone'; end if;
 begin perform public.archive_account('34444444-4444-7444-8444-444444444444',v,gen_random_uuid(),1); raise exception 'second archive passed'; exception when others then if sqlerrm<>'NOT_FOUND' then raise; end if; end;
end $$;

select set_config('request.jwt.claim.sub','32222222-2222-7222-8222-222222222222',false);
do $$ begin
 begin perform public.update_account('34444444-4444-7444-8444-444444444444','foreign','cash',null,1,gen_random_uuid(),1); raise exception 'foreign edit passed'; exception when others then if sqlerrm<>'NOT_FOUND' then raise; end if; end;
 begin perform public.archive_account('34444444-4444-7444-8444-444444444444',1,gen_random_uuid(),1); raise exception 'foreign archive passed'; exception when others then if sqlerrm<>'NOT_FOUND' then raise; end if; end;
 begin perform public.create_account('34444444-4444-7444-8444-444444444444','foreign','cash',0,gen_random_uuid(),1); raise exception 'foreign id overwrite passed'; exception when others then if sqlerrm<>'CONFLICT' then raise; end if; end;
end $$;

reset role;
do $$ declare f oid; begin
 foreach f in array array[
  'public.create_account(uuid,text,text,numeric,uuid,bigint)'::regprocedure,
  'public.update_account(uuid,text,text,text,bigint,uuid,bigint)'::regprocedure,
  'public.archive_account(uuid,bigint,uuid,bigint)'::regprocedure
 ] loop
  if not (select prosecdef from pg_proc where oid=f) then raise exception 'account RPC is not security definer'; end if;
  if not (select 'search_path=pg_catalog, public'=any(proconfig) or 'search_path=pg_catalog,public'=any(proconfig) from pg_proc where oid=f) then raise exception 'unsafe RPC search_path'; end if;
  if not has_function_privilege('authenticated',f,'execute') or has_function_privilege('anon',f,'execute') then raise exception 'account RPC grant mismatch'; end if;
  if exists(select 1 from pg_proc p cross join lateral aclexplode(p.proacl) acl where p.oid=f and acl.grantee=0 and acl.privilege_type='EXECUTE') then raise exception 'PUBLIC execute leak'; end if;
 end loop;
 if has_table_privilege('authenticated','sanie.account_mutation_receipts','SELECT') then raise exception 'receipt table exposed'; end if;
end $$;
rollback;
