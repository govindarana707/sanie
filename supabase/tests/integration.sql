\set ON_ERROR_STOP on
-- Runs inside the local Supabase DB container as postgres. It switches to the
-- real `authenticated` role and sets the same JWT-sub claim PostgREST uses.
create temporary table test_state (key text primary key, value uuid);
insert into auth.users(id,instance_id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at)
values ('11111111-1111-7111-8111-111111111111','00000000-0000-0000-0000-000000000000','authenticated','authenticated','phase1c-a@example.test','x',now(),'{}','{"first_name":"A"}',now(),now()),
       ('22222222-2222-7222-8222-222222222222','00000000-0000-0000-0000-000000000000','authenticated','authenticated','phase1c-b@example.test','x',now(),'{}','{"first_name":"B"}',now(),now());

insert into public.accounts(id,user_id,name,account_type,opening_balance,balance) values
 ('aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1','11111111-1111-7111-8111-111111111111','A Cash','cash',100,100),
 ('aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa2','11111111-1111-7111-8111-111111111111','A Bank','bank',0,0),
 ('bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbb1','22222222-2222-7222-8222-222222222222','B Cash','cash',100,100);
insert into public.categories(id,user_id,name,category_type,is_system) values
 ('cccccccc-cccc-7ccc-8ccc-ccccccccccc1','11111111-1111-7111-8111-111111111111','A Income','income',false),
 ('cccccccc-cccc-7ccc-8ccc-ccccccccccc2','11111111-1111-7111-8111-111111111111','A Expense','expense',false),
 ('dddddddd-dddd-7ddd-8ddd-ddddddddddd1','22222222-2222-7222-8222-222222222222','B Expense','expense',false),
 ('eeeeeeee-eeee-7eee-8eee-eeeeeeeeeee1',null,'System Income','income',true);
insert into public.goals(id,user_id,name,target_amount,initial_amount,current_amount) values
 ('99999999-9999-7999-8999-999999999991','11111111-1111-7111-8111-111111111111','A Goal',100,0,0);
insert into public.people(id,user_id,name) values
 ('91111111-1111-7111-8111-111111111111','11111111-1111-7111-8111-111111111111','A Creditor'),
 ('92222222-2222-7222-8222-222222222222','22222222-2222-7222-8222-222222222222','B Person');
create function public.phase1c_fail_transfer_fee() returns trigger language plpgsql set search_path=pg_catalog as $$
begin
 if new.transfer_parent_id='f0b00000-0000-7000-8000-000000000001' then raise exception using errcode='P0001',message='TEST_FEE_FAILURE'; end if;
 return new;
end $$;
create trigger phase1c_fail_transfer_fee before insert on public.transactions for each row execute function public.phase1c_fail_transfer_fee();

set role authenticated;
select set_config('request.jwt.claim.sub','11111111-1111-7111-8111-111111111111',false);
do $$ begin
  begin insert into public.transactions(id,user_id,amount,transaction_type,transaction_date) values(gen_random_uuid(),'11111111-1111-7111-8111-111111111111',1,'income',current_date); raise exception 'financial direct insert unexpectedly passed'; exception when insufficient_privilege then null; end;
  begin update public.accounts set balance=999 where id='aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1'; raise exception 'balance overwrite unexpectedly passed'; exception when insufficient_privilege then null; end;
  begin update public.goals set current_amount=999 where false; exception when insufficient_privilege then null; end;
end $$;
select public.create_income('f1111111-1111-7111-8111-111111111111','aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1','cccccccc-cccc-7ccc-8ccc-ccccccccccc1',null,25,current_date,'income','cash','f2222222-2222-7222-8222-222222222222',1);
do $$ begin
 if (select balance from public.accounts where id='aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1')<>125 then raise exception 'income balance incorrect'; end if;
end $$;
select public.create_expense('f3333333-3333-7333-8333-333333333333','aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1','cccccccc-cccc-7ccc-8ccc-ccccccccccc2',null,20,current_date,'expense','cash','f4444444-4444-7444-8444-444444444444',1);
select public.create_transfer('f5555555-5555-7555-8555-555555555555','aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1','aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa2',50,5,'cccccccc-cccc-7ccc-8ccc-ccccccccccc2',current_date,'move','f6666666-6666-7666-8666-666666666666',1);
do $$ begin
 if (select balance from public.accounts where id='aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1')<>50 then raise exception 'transfer source/fee balance incorrect'; end if;
 if (select balance from public.accounts where id='aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa2')<>50 then raise exception 'transfer destination balance incorrect'; end if;
 if (select count(*) from public.transactions where transfer_parent_id='f5555555-5555-7555-8555-555555555555')<>1 then raise exception 'transfer fee missing'; end if;
end $$;
do $$ begin
 begin perform public.create_expense('f7777777-7777-7777-8777-777777777777','aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1','cccccccc-cccc-7ccc-8ccc-ccccccccccc2',null,51,current_date,'too much','cash','f8888888-8888-7888-8888-888888888888',1); raise exception 'insufficient funds passed'; exception when others then if sqlerrm<>'INSUFFICIENT_FUNDS' then raise; end if; end;
 begin perform public.create_income('f9999999-9999-7999-8999-999999999999','bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbb1','cccccccc-cccc-7ccc-8ccc-ccccccccccc1',null,1,current_date,'foreign','cash','fa999999-9999-7999-8999-999999999999',1); raise exception 'foreign reference passed'; exception when others then if sqlerrm<>'FORBIDDEN_REFERENCE' then raise; end if; end;
end $$;
select public.create_goal_contribution('fa111111-1111-7111-8111-111111111111','99999999-9999-7999-8999-999999999991','aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1',10,current_date,'goal','fb111111-1111-7111-8111-111111111111',1);
do $$ begin
 if (select current_amount from public.goals where id='99999999-9999-7999-8999-999999999991')<>10 then raise exception 'goal cache incorrect'; end if;
 if (select balance from public.accounts where id='aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1')<>40 then raise exception 'goal account effect incorrect'; end if;
 if (select count(*) from public.pull_changes(0,100,1))<1 then raise exception 'change feed missing'; end if;
end $$;
-- Exact financial replay is idempotent; changed payload is not.
do $$ begin
 perform public.create_income('f1111111-1111-7111-8111-111111111111','aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1','cccccccc-cccc-7ccc-8ccc-ccccccccccc1',null,25,current_date,'income','cash','f2222222-2222-7222-8222-222222222222',1);
 if (select count(*) from public.transactions where client_request_id='f2222222-2222-7222-8222-222222222222')<>1 then raise exception 'income replay duplicated'; end if;
 begin perform public.create_income('f0100000-0000-7000-8000-000000000001','aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1','cccccccc-cccc-7ccc-8ccc-ccccccccccc1',null,26,current_date,'changed','cash','f2222222-2222-7222-8222-222222222222',1); raise exception 'income mismatch passed'; exception when others then if sqlerrm<>'IDEMPOTENCY_MISMATCH' then raise; end if; end;
 begin perform public.create_income('f0100000-0000-7000-8000-000000000002','aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1','cccccccc-cccc-7ccc-8ccc-ccccccccccc1',null,25,current_date,'changed description','cash','f2222222-2222-7222-8222-222222222222',1); raise exception 'income description mismatch passed'; exception when others then if sqlerrm<>'IDEMPOTENCY_MISMATCH' then raise; end if; end;
 perform public.create_expense('f3333333-3333-7333-8333-333333333333','aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1','cccccccc-cccc-7ccc-8ccc-ccccccccccc2',null,20,current_date,'expense','cash','f4444444-4444-7444-8444-444444444444',1);
 begin perform public.create_expense('f0100000-0000-7000-8000-000000000003','aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1','cccccccc-cccc-7ccc-8ccc-ccccccccccc2',null,20,current_date,'changed expense','cash','f4444444-4444-7444-8444-444444444444',1); raise exception 'expense mismatch passed'; exception when others then if sqlerrm<>'IDEMPOTENCY_MISMATCH' then raise; end if; end;
 perform public.create_transfer('f5555555-5555-7555-8555-555555555555','aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1','aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa2',50,5,'cccccccc-cccc-7ccc-8ccc-ccccccccccc2',current_date,'move','f6666666-6666-7666-8666-666666666666',1);
 begin perform public.create_transfer('f0100000-0000-7000-8000-000000000004','aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1','aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa2',50,6,'cccccccc-cccc-7ccc-8ccc-ccccccccccc2',current_date,'move','f6666666-6666-7666-8666-666666666666',1); raise exception 'transfer fee mismatch passed'; exception when others then if sqlerrm<>'IDEMPOTENCY_MISMATCH' then raise; end if; end;
 perform public.create_goal_contribution('fa111111-1111-7111-8111-111111111111','99999999-9999-7999-8999-999999999991','aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1',10,current_date,'goal','fb111111-1111-7111-8111-111111111111',1);
 begin perform public.create_goal_contribution('f0100000-0000-7000-8000-000000000005','99999999-9999-7999-8999-999999999991','aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1',11,current_date,'goal','fb111111-1111-7111-8111-111111111111',1); raise exception 'goal replay mismatch passed'; exception when others then if sqlerrm<>'IDEMPOTENCY_MISMATCH' then raise; end if; end;
 begin perform public.create_transfer('f0200000-0000-7000-8000-000000000001','aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1','aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa2',1,1,'dddddddd-dddd-7ddd-8ddd-ddddddddddd1',current_date,'bad fee','f0300000-0000-7000-8000-000000000001',1); raise exception 'foreign fee passed'; exception when others then if sqlerrm<>'FORBIDDEN_REFERENCE' then raise; end if; end;
 if exists(select 1 from public.transactions where id='f0200000-0000-7000-8000-000000000001') then raise exception 'failed transfer was not atomic'; end if;
 begin perform public.create_transfer('f0b00000-0000-7000-8000-000000000001','aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1','aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa2',1,1,'cccccccc-cccc-7ccc-8ccc-ccccccccccc2',current_date,'forced rollback','f0b00000-0000-7000-8000-000000000002',1); raise exception 'forced fee failure passed'; exception when others then if sqlerrm<>'TEST_FEE_FAILURE' then raise; end if; end;
 if exists(select 1 from public.transactions where id='f0b00000-0000-7000-8000-000000000001' or transfer_parent_id='f0b00000-0000-7000-8000-000000000001') then raise exception 'partial forced transfer survived'; end if;
 if exists(select 1 from public.pull_changes(0,500,1) where entity_id='f0b00000-0000-7000-8000-000000000001') then raise exception 'rolled-back transfer leaked feed event'; end if;
end $$;
-- Ordinary transaction mutation is version-guarded and tombstones the row.
do $$ declare v bigint; begin
 select version into v from public.transactions where id='f3333333-3333-7333-8333-333333333333';
 perform public.update_transaction('f3333333-3333-7333-8333-333333333333',v,10,current_date,'updated',1);
 if (select balance from public.accounts where id='aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1')<>50 then raise exception 'transaction update did not rebuild'; end if;
 begin perform public.update_transaction('f3333333-3333-7333-8333-333333333333',v,9,current_date,'stale',1); raise exception 'stale update passed'; exception when others then if sqlerrm<>'CONFLICT' then raise; end if; end;
 select version into v from public.transactions where id='f3333333-3333-7333-8333-333333333333'; perform public.delete_transaction('f3333333-3333-7333-8333-333333333333',v,1);
 if not exists(select 1 from public.transactions where id='f3333333-3333-7333-8333-333333333333' and deleted_at is not null) then raise exception 'transaction was not tombstoned'; end if;
 if (select balance from public.accounts where id='aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1')<>60 then raise exception 'tombstone did not rebuild'; end if;
 if not exists(select 1 from public.pull_changes(0,500,1) where entity_id='f3333333-3333-7333-8333-333333333333' and operation='tombstone') then raise exception 'transaction tombstone feed missing'; end if;
end $$;
-- Moving an ordinary entry rebuilds both the old and new account and enforces version/type ownership.
do $$ declare v bigint; begin
 perform public.create_expense('f0a00000-0000-7000-8000-000000000001','aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1','cccccccc-cccc-7ccc-8ccc-ccccccccccc2',null,5,current_date,'move me','cash','f0a00000-0000-7000-8000-000000000002',1);
 select version into v from public.transactions where id='f0a00000-0000-7000-8000-000000000001';
 perform public.update_transaction('f0a00000-0000-7000-8000-000000000001',v,'aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa2','cccccccc-cccc-7ccc-8ccc-ccccccccccc2',null,4,current_date,'moved',1);
 if (select balance from public.accounts where id='aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1')<>60 then raise exception 'old account was not rebuilt after move'; end if;
 if (select balance from public.accounts where id='aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa2')<>46 then raise exception 'new account was not rebuilt after move'; end if;
 begin perform public.update_transaction('f0a00000-0000-7000-8000-000000000001',v,'aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa2','cccccccc-cccc-7ccc-8ccc-ccccccccccc2',null,3,current_date,'stale',1); raise exception 'detailed stale update passed'; exception when others then if sqlerrm<>'CONFLICT' then raise; end if; end;
end $$;
do $$ declare v bigint; begin
 select version into v from public.transactions where id='fa111111-1111-7111-8111-111111111111';
 perform public.update_goal_contribution('fa111111-1111-7111-8111-111111111111',v,15,current_date,'goal updated',1);
 if (select current_amount from public.goals where id='99999999-9999-7999-8999-999999999991')<>15 or (select balance from public.accounts where id='aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1')<>55 then raise exception 'goal contribution update rebuild failed'; end if;
 begin perform public.update_goal_contribution('fa111111-1111-7111-8111-111111111111',v,14,current_date,'stale',1); raise exception 'goal stale update passed'; exception when others then if sqlerrm<>'CONFLICT' then raise; end if; end;
 select version into v from public.transactions where id='fa111111-1111-7111-8111-111111111111';
 perform public.delete_goal_contribution('fa111111-1111-7111-8111-111111111111',v,1);
 if (select current_amount from public.goals where id='99999999-9999-7999-8999-999999999991')<>0 or (select balance from public.accounts where id='aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1')<>70 then raise exception 'goal contribution delete rebuild failed'; end if;
end $$;
do $$ declare v bigint; begin
 select version into v from public.accounts where id='aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1';
 perform public.update_account_opening_balance('aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1',110,v,1);
 if (select opening_balance from public.accounts where id='aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1')<>110 or (select balance from public.accounts where id='aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1')<>80 then raise exception 'opening balance rebuild failed'; end if;
 begin perform public.update_account_opening_balance('aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1',120,v,1); raise exception 'opening stale update passed'; exception when others then if sqlerrm<>'CONFLICT' then raise; end if; end;
end $$;
-- Credit purchase creates a payable and linked expense without cash movement.
do $$ declare v bigint; before_balance numeric; begin
 select balance into before_balance from public.accounts where id='aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1';
 perform public.create_credit_purchase('c1000000-0000-7000-8000-000000000001','c2000000-0000-7000-8000-000000000001','91111111-1111-7111-8111-111111111111','cccccccc-cccc-7ccc-8ccc-ccccccccccc2',12,current_date,'credit','c3000000-0000-7000-8000-000000000001',1);
 perform public.create_credit_purchase('c1000000-0000-7000-8000-000000000001','c2000000-0000-7000-8000-000000000001','91111111-1111-7111-8111-111111111111','cccccccc-cccc-7ccc-8ccc-ccccccccccc2',12,current_date,'credit','c3000000-0000-7000-8000-000000000001',1);
 begin perform public.create_credit_purchase('c4000000-0000-7000-8000-000000000001','c5000000-0000-7000-8000-000000000001','91111111-1111-7111-8111-111111111111','cccccccc-cccc-7ccc-8ccc-ccccccccccc2',12,current_date,'different','c3000000-0000-7000-8000-000000000001',1); raise exception 'credit mismatch accepted'; exception when others then if sqlerrm<>'IDEMPOTENCY_MISMATCH' then raise; end if; end;
 if (select balance from public.accounts where id='aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1')<>before_balance then raise exception 'credit purchase changed cash'; end if;
 if not exists(select 1 from public.karobar_transactions k join public.transactions t on t.id=k.expense_transaction_id where k.id='c1000000-0000-7000-8000-000000000001' and t.payment_method='credit') then raise exception 'credit pair missing'; end if;
 select version into v from public.karobar_transactions where id='c1000000-0000-7000-8000-000000000001'; perform public.update_credit_purchase('c1000000-0000-7000-8000-000000000001',v,13,current_date,'changed',1);
 select version into v from public.karobar_transactions where id='c1000000-0000-7000-8000-000000000001'; perform public.delete_credit_purchase('c1000000-0000-7000-8000-000000000001',v,1);
 if not exists(select 1 from public.transactions where id='c2000000-0000-7000-8000-000000000001' and deleted_at is not null) then raise exception 'credit expense not tombstoned'; end if;
end $$;
-- Stable budget contract is exposed through the public command.
select public.create_budget('b1000000-0000-7000-8000-000000000001','cccccccc-cccc-7ccc-8ccc-ccccccccccc2',null,'October',100,'2026-10-01','2026-10-31',1);
do $$ begin begin perform public.create_budget('b2000000-0000-7000-8000-000000000002','cccccccc-cccc-7ccc-8ccc-ccccccccccc2',null,'Overlap',100,'2026-10-15','2026-11-01',1); raise exception 'budget overlap passed'; exception when others then if sqlerrm<>'BUDGET_SCOPE_OVERLAP' then raise; end if; end; end $$;
-- Public recurrence command creates once; skip advances without cash movement.
insert into public.recurring_transactions(id,user_id,account_id,category_id,amount,transaction_type,frequency,start_date,next_occurrence,description) values ('71000000-0000-7000-8000-000000000001','11111111-1111-7111-8111-111111111111','aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1','cccccccc-cccc-7ccc-8ccc-ccccccccccc1',2,'income','daily',current_date,current_date,'recurring');
do $$ begin perform public.process_recurring_occurrence('71000000-0000-7000-8000-000000000001',current_date,'generate',1); if (select count(*) from public.transactions where recurring_definition_id='71000000-0000-7000-8000-000000000001')<>1 then raise exception 'recurrence missing'; end if; end $$;
insert into public.recurring_transactions(id,user_id,account_id,category_id,amount,transaction_type,frequency,day_of_month,start_date,next_occurrence,description) values ('72000000-0000-7000-8000-000000000002','11111111-1111-7111-8111-111111111111','aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1','cccccccc-cccc-7ccc-8ccc-ccccccccccc1',2,'income','monthly',extract(day from current_date)::integer,current_date,current_date,'skip');
do $$ begin perform public.process_recurring_occurrence('72000000-0000-7000-8000-000000000002',current_date,'skip',1); if exists(select 1 from public.transactions where recurring_definition_id='72000000-0000-7000-8000-000000000002') then raise exception 'skip created transaction'; end if; end $$;
do $$ begin
 begin perform public.create_income('e1000000-0000-7000-8000-000000000001','aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1','cccccccc-cccc-7ccc-8ccc-ccccccccccc1',null,0,current_date,'invalid','cash','e1000000-0000-7000-8000-000000000002',1); raise exception 'validation error missing'; exception when others then if sqlerrm<>'VALIDATION_ERROR' then raise; end if; end;
 begin perform public.update_transaction('e2000000-0000-7000-8000-000000000001',1,1,current_date,'missing',1); raise exception 'not found missing'; exception when others then if sqlerrm<>'NOT_FOUND' then raise; end if; end;
 begin perform public.update_transaction('f5555555-5555-7555-8555-555555555555',1,50,current_date,'generic transfer edit',1); raise exception 'invalid state missing'; exception when others then if sqlerrm<>'INVALID_STATE' then raise; end if; end;
end $$;
select set_config('request.jwt.claim.sub','',false);
do $$ begin begin perform public.fresh_start(1); raise exception 'unauthenticated call passed'; exception when others then if sqlerrm<>'UNAUTHENTICATED' then raise; end if; end; end $$;
select set_config('request.jwt.claim.sub','11111111-1111-7111-8111-111111111111',false);
select public.fresh_start(1);
do $$ begin
 begin perform public.create_income('fc000000-0000-7000-8000-000000000001','aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1','cccccccc-cccc-7ccc-8ccc-ccccccccccc1',null,1,current_date,'stale','cash','fd000000-0000-7000-8000-000000000001',1); raise exception 'stale generation passed'; exception when others then if sqlerrm<>'DATA_GENERATION_MISMATCH' then raise; end if; end;
end $$;
reset role;
drop trigger phase1c_fail_transfer_fee on public.transactions;
drop function public.phase1c_fail_transfer_fee();
select 'PASS: Phase 1C SQL integration baseline' as result;
