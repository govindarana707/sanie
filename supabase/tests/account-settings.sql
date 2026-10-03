\set ON_ERROR_STOP on
begin;
insert into auth.users(id,instance_id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at)
values
 ('b1111111-1111-7111-8111-111111111111','00000000-0000-0000-0000-000000000000','authenticated','authenticated','settings-a@example.test','x',now(),'{}','{}',now(),now()),
 ('b2222222-2222-7222-8222-222222222222','00000000-0000-0000-0000-000000000000','authenticated','authenticated','settings-b@example.test','x',now(),'{}','{}',now(),now());
insert into public.accounts(id,user_id,name,account_type,opening_balance)
values
 ('b3333333-3333-7333-8333-333333333333','b1111111-1111-7111-8111-111111111111','A','cash',100),
 ('b4444444-4444-7444-8444-444444444444','b1111111-1111-7111-8111-111111111111','B','bank',200),
 ('b5555555-5555-7555-8555-555555555555','b2222222-2222-7222-8222-222222222222','Foreign','cash',50);
do $$ begin
 if (select count(*) from public.accounts where user_id='b1111111-1111-7111-8111-111111111111' and is_default)<>1 then raise exception 'initial default invariant'; end if;
end $$;
set role authenticated;
select set_config('request.jwt.claim.sub','b1111111-1111-7111-8111-111111111111',false);
do $$ declare v bigint; r jsonb; request_id uuid:='b6666666-6666-7666-8666-666666666666'; begin
 select version into v from public.accounts where id='b4444444-4444-7444-8444-444444444444';
 r:=public.update_account_settings('b4444444-4444-7444-8444-444444444444',true,false,true,true,v,request_id,1);
 if r->>'replayed'<>'false' then raise exception 'settings write failed'; end if;
 if (select count(*) from public.accounts where user_id='b1111111-1111-7111-8111-111111111111' and is_default and is_active and deleted_at is null)<>1 then raise exception 'default switch broke invariant'; end if;
 if not exists(select 1 from public.accounts where id='b4444444-4444-7444-8444-444444444444' and is_default and not include_in_net_balance and include_in_savings and balance=200) then raise exception 'settings flags or balance wrong'; end if;
 r:=public.update_account_settings('b4444444-4444-7444-8444-444444444444',true,false,true,true,v,request_id,1);
 if r->>'replayed'<>'true' then raise exception 'idempotent replay failed'; end if;
 begin perform public.update_account_settings('b4444444-4444-7444-8444-444444444444',true,true,true,true,v,request_id,1); raise exception 'idempotency mismatch accepted'; exception when others then if sqlerrm<>'IDEMPOTENCY_MISMATCH' then raise; end if; end;
 begin perform public.update_account_settings('b4444444-4444-7444-8444-444444444444',true,false,true,true,v,gen_random_uuid(),1); raise exception 'stale version accepted'; exception when others then if sqlerrm<>'CONFLICT' then raise; end if; end;
 begin perform public.update_account_settings('b4444444-4444-7444-8444-444444444444',true,false,true,true,v,gen_random_uuid(),2); raise exception 'generation mismatch accepted'; exception when others then if sqlerrm<>'DATA_GENERATION_MISMATCH' then raise; end if; end;
 select version into v from public.accounts where id='b4444444-4444-7444-8444-444444444444';
 perform public.update_account_settings('b4444444-4444-7444-8444-444444444444',false,false,true,false,v,gen_random_uuid(),1);
 if not exists(select 1 from public.accounts where id='b4444444-4444-7444-8444-444444444444' and not is_active and not is_default and deleted_at is null) then raise exception 'deactivation archived account'; end if;
 if not exists(select 1 from public.accounts where id='b3333333-3333-7333-8333-333333333333' and is_default) then raise exception 'default handoff failed'; end if;
 select version into v from public.accounts where id='b4444444-4444-7444-8444-444444444444';
 perform public.update_account_settings('b4444444-4444-7444-8444-444444444444',false,true,false,true,v,gen_random_uuid(),1);
 if not exists(select 1 from public.accounts where id='b4444444-4444-7444-8444-444444444444' and is_active and include_in_net_balance and not include_in_savings and balance=200) then raise exception 'activation failed'; end if;
 begin update public.accounts set is_active=false where id='b4444444-4444-7444-8444-444444444444'; raise exception 'direct settings write accepted'; exception when insufficient_privilege then null; end;
 select version into v from public.accounts where id='b3333333-3333-7333-8333-333333333333';
 perform public.archive_account('b3333333-3333-7333-8333-333333333333',v,gen_random_uuid(),1);
 if not exists(select 1 from public.accounts where id='b4444444-4444-7444-8444-444444444444' and is_default and is_active) then raise exception 'archive default handoff failed'; end if;
 if not exists(select 1 from public.accounts where id='b3333333-3333-7333-8333-333333333333' and not is_default and deleted_at is not null and balance=100) then raise exception 'archive changed historical balance'; end if;
 select version into v from public.accounts where id='b4444444-4444-7444-8444-444444444444';
 perform public.update_account_settings('b4444444-4444-7444-8444-444444444444',false,true,false,false,v,gen_random_uuid(),1);
 select version into v from public.accounts where id='b4444444-4444-7444-8444-444444444444';
 begin perform public.update_account_settings('b4444444-4444-7444-8444-444444444444',false,true,false,true,v,gen_random_uuid(),1); raise exception 'reactivation without default accepted'; exception when others then if sqlerrm<>'INVALID_STATE' then raise; end if; end;
 perform public.update_account_settings('b4444444-4444-7444-8444-444444444444',true,true,false,true,v,gen_random_uuid(),1);
 if not exists(select 1 from public.accounts where id='b4444444-4444-7444-8444-444444444444' and is_default and is_active) then raise exception 'sole account reactivation default failed'; end if;
end $$;
select set_config('request.jwt.claim.sub','b2222222-2222-7222-8222-222222222222',false);
do $$ begin
 begin perform public.update_account_settings('b4444444-4444-7444-8444-444444444444',false,true,false,true,1,gen_random_uuid(),1); raise exception 'foreign write accepted'; exception when others then if sqlerrm<>'NOT_FOUND' then raise; end if; end;
end $$;
reset role;
do $$ declare f oid:='public.update_account_settings(uuid,boolean,boolean,boolean,boolean,bigint,uuid,bigint)'::regprocedure; begin
 if not (select prosecdef from pg_proc where oid=f) then raise exception 'RPC is not security definer'; end if;
 if not (select 'search_path=pg_catalog,public'=any(proconfig) or 'search_path=pg_catalog, public'=any(proconfig) from pg_proc where oid=f) then raise exception 'unsafe search path'; end if;
 if not has_function_privilege('authenticated',f,'execute') or has_function_privilege('anon',f,'execute') then raise exception 'RPC grants wrong'; end if;
 if has_column_privilege('authenticated','public.accounts','is_default','UPDATE') or has_column_privilege('authenticated','public.accounts','is_active','UPDATE') then raise exception 'direct settings update exposed'; end if;
end $$;
rollback;
