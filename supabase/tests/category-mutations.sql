\set ON_ERROR_STOP on
begin;
insert into auth.users(id,instance_id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at)
values
 ('a1111111-1111-7111-8111-111111111111','00000000-0000-0000-0000-000000000000','authenticated','authenticated','category-a@example.test','x',now(),'{}','{"first_name":"A"}',now(),now()),
 ('a2222222-2222-7222-8222-222222222222','00000000-0000-0000-0000-000000000000','authenticated','authenticated','category-b@example.test','x',now(),'{}','{"first_name":"B"}',now(),now());
insert into public.categories(id,user_id,name,category_type,is_system)
 values('a3333333-3333-7333-8333-333333333333',null,'System expense','expense',true);
insert into public.subcategories(id,user_id,category_id,name)
 values('a4444444-4444-7444-8444-444444444444',null,'a3333333-3333-7333-8333-333333333333','System child');

set role authenticated;
select set_config('request.jwt.claim.sub','a1111111-1111-7111-8111-111111111111',false);
do $$ declare r jsonb; begin
 r:=public.create_category('a5555555-5555-7555-8555-555555555555','  Home  ','expense',null,null,'a6666666-6666-7666-8666-666666666661',1);
 if r->>'replayed'<>'false' or r->'entity'->>'name'<>'Home' then raise exception 'category create failed'; end if;
 r:=public.create_category('a5555555-5555-7555-8555-555555555555','Home','expense',null,null,'a6666666-6666-7666-8666-666666666661',1);
 if r->>'replayed'<>'true' then raise exception 'category create replay failed'; end if;
 begin perform public.create_category('a5555555-5555-7555-8555-555555555555','Other','expense',null,null,'a6666666-6666-7666-8666-666666666661',1); raise exception 'idempotency mismatch accepted'; exception when others then if sqlerrm<>'IDEMPOTENCY_MISMATCH' then raise; end if; end;
 begin perform public.create_category(gen_random_uuid(),'Wrong','expense',null,null,gen_random_uuid(),2); raise exception 'stale generation accepted'; exception when others then if sqlerrm<>'DATA_GENERATION_MISMATCH' then raise; end if; end;
 begin update public.categories set name='direct' where id='a5555555-5555-7555-8555-555555555555'; raise exception 'direct write accepted'; exception when insufficient_privilege then null; end;
end $$;
do $$ declare r jsonb; begin
 r:=public.create_subcategory('a7777777-7777-7777-8777-777777777777','a5555555-5555-7555-8555-555555555555','  Rent  ',null,null,'a6666666-6666-7666-8666-666666666662',1);
 if r->'entity'->>'name'<>'Rent' then raise exception 'subcategory create failed'; end if;
 r:=public.create_subcategory('a7777777-7777-7777-8777-777777777777','a5555555-5555-7555-8555-555555555555','Rent',null,null,'a6666666-6666-7666-8666-666666666662',1);
 if r->>'replayed'<>'true' then raise exception 'subcategory replay failed'; end if;
 begin perform public.update_category('a3333333-3333-7333-8333-333333333333','Wrong','expense',null,null,1,gen_random_uuid(),1); raise exception 'system category changed'; exception when others then if sqlerrm<>'NOT_FOUND' then raise; end if; end;
 begin perform public.archive_subcategory('a4444444-4444-7444-8444-444444444444','a3333333-3333-7333-8333-333333333333',1,gen_random_uuid(),1); raise exception 'system child changed'; exception when others then if sqlerrm<>'NOT_FOUND' then raise; end if; end;
end $$;
select public.update_category('a5555555-5555-7555-8555-555555555555','Housing','expense',null,null,1,'a6666666-6666-7666-8666-666666666663',1);
select public.update_subcategory('a7777777-7777-7777-8777-777777777777','a5555555-5555-7555-8555-555555555555','Monthly rent',null,null,1,'a6666666-6666-7666-8666-666666666664',1);
do $$ begin
 begin perform public.update_category('a5555555-5555-7555-8555-555555555555','Stale','expense',null,null,1,gen_random_uuid(),1); raise exception 'stale category version accepted'; exception when others then if sqlerrm<>'CONFLICT' then raise; end if; end;
 begin perform public.update_category('a5555555-5555-7555-8555-555555555555','Retyped','income',null,null,2,gen_random_uuid(),1); raise exception 'category type change accepted'; exception when others then if sqlerrm<>'VALIDATION_ERROR' then raise; end if; end;
 begin perform public.update_subcategory('a7777777-7777-7777-8777-777777777777','a5555555-5555-7555-8555-555555555555','Stale',null,null,1,gen_random_uuid(),1); raise exception 'stale subcategory version accepted'; exception when others then if sqlerrm<>'CONFLICT' then raise; end if; end;
end $$;

reset role;
insert into public.accounts(id,user_id,name,account_type,opening_balance)
 values('a8888888-8888-7888-8888-888888888888','a1111111-1111-7111-8111-111111111111','Cash','cash',100);
insert into public.transactions(id,user_id,account_id,category_id,subcategory_id,amount,transaction_type,transaction_date)
 values('a9999999-9999-7999-8999-999999999999','a1111111-1111-7111-8111-111111111111','a8888888-8888-7888-8888-888888888888','a5555555-5555-7555-8555-555555555555','a7777777-7777-7777-8777-777777777777',5,'expense',current_date);
set role authenticated;
select set_config('request.jwt.claim.sub','a2222222-2222-7222-8222-222222222222',false);
do $$ begin
 begin perform public.create_subcategory(gen_random_uuid(),'a5555555-5555-7555-8555-555555555555','Foreign',null,null,gen_random_uuid(),1); raise exception 'foreign parent accepted'; exception when others then if sqlerrm<>'FORBIDDEN_REFERENCE' then raise; end if; end;
 begin perform public.update_category('a5555555-5555-7555-8555-555555555555','Foreign','expense',null,null,2,gen_random_uuid(),1); raise exception 'foreign category update accepted'; exception when others then if sqlerrm<>'NOT_FOUND' then raise; end if; end;
 begin perform public.archive_subcategory('a7777777-7777-7777-8777-777777777777','a5555555-5555-7555-8555-555555555555',2,gen_random_uuid(),1); raise exception 'foreign subcategory archive accepted'; exception when others then if sqlerrm<>'NOT_FOUND' then raise; end if; end;
end $$;
select set_config('request.jwt.claim.sub','a1111111-1111-7111-8111-111111111111',false);
select public.archive_subcategory('a7777777-7777-7777-8777-777777777777','a5555555-5555-7555-8555-555555555555',2,'a6666666-6666-7666-8666-666666666665',1);
select public.archive_category('a5555555-5555-7555-8555-555555555555',2,'a6666666-6666-7666-8666-666666666666',1);
do $$ declare r jsonb; begin
 r:=public.archive_category('a5555555-5555-7555-8555-555555555555',2,'a6666666-6666-7666-8666-666666666666',1);
 if r->>'replayed'<>'true' then raise exception 'archive replay failed'; end if;
 if not exists(select 1 from public.transactions where id='a9999999-9999-7999-8999-999999999999' and category_id='a5555555-5555-7555-8555-555555555555' and subcategory_id='a7777777-7777-7777-8777-777777777777') then raise exception 'historical reference removed'; end if;
 if not exists(select 1 from public.categories where id='a5555555-5555-7555-8555-555555555555' and status='archived' and deleted_at is null) then raise exception 'category archive state wrong'; end if;
end $$;

reset role;
do $$ declare f oid; begin
 foreach f in array array[
  'public.create_category(uuid,text,text,text,text,uuid,bigint)'::regprocedure,
  'public.update_category(uuid,text,text,text,text,bigint,uuid,bigint)'::regprocedure,
  'public.archive_category(uuid,bigint,uuid,bigint)'::regprocedure,
  'public.create_subcategory(uuid,uuid,text,text,text,uuid,bigint)'::regprocedure,
  'public.update_subcategory(uuid,uuid,text,text,text,bigint,uuid,bigint)'::regprocedure,
  'public.archive_subcategory(uuid,uuid,bigint,uuid,bigint)'::regprocedure
 ] loop
  if not (select prosecdef from pg_proc where oid=f) then raise exception 'category RPC not security definer'; end if;
  if not (select 'search_path=pg_catalog,public'=any(proconfig) or 'search_path=pg_catalog, public'=any(proconfig) from pg_proc where oid=f) then raise exception 'unsafe category search_path'; end if;
  if not has_function_privilege('authenticated',f,'execute') or has_function_privilege('anon',f,'execute') then raise exception 'category RPC grant mismatch'; end if;
 end loop;
 if has_function_privilege('authenticated','public.sanie_mutate_category(text,text,uuid,uuid,text,text,text,text,bigint,uuid,bigint)'::regprocedure,'execute') then raise exception 'internal helper exposed'; end if;
 if has_table_privilege('authenticated','public.categories','INSERT') or has_table_privilege('authenticated','public.subcategories','UPDATE') then raise exception 'direct category mutation exposed'; end if;
 if has_table_privilege('authenticated','sanie.category_mutation_receipts','SELECT') then raise exception 'receipt table exposed'; end if;
end $$;
rollback;
