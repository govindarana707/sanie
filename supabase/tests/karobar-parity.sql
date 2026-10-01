\set ON_ERROR_STOP on
begin;
insert into auth.users(id,instance_id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at)
values ('51000000-0000-7000-8000-000000000001','00000000-0000-0000-0000-000000000000','authenticated','authenticated','phase1c-karobar@example.test','x',now(),'{}','{"first_name":"Karobar"}',now(),now());
insert into public.accounts(id,user_id,name,account_type,opening_balance,balance) values ('51000000-0000-7000-8000-000000000011','51000000-0000-7000-8000-000000000001','Cash','cash',100,100);
insert into public.people(id,user_id,name) values ('51000000-0000-7000-8000-000000000021','51000000-0000-7000-8000-000000000001','Counterparty');
set role authenticated;
select set_config('request.jwt.claim.sub','51000000-0000-7000-8000-000000000001',true);
do $$ declare v bigint; before_count bigint; begin
 perform public.create_karobar_transaction('51000000-0000-7000-8000-000000000101','51000000-0000-7000-8000-000000000021','lent',7,'51000000-0000-7000-8000-000000000011',current_date,null,'lent','51000000-0000-7000-8000-000000000201',1);
 perform public.create_karobar_transaction('51000000-0000-7000-8000-000000000102','51000000-0000-7000-8000-000000000021','borrowed',9,'51000000-0000-7000-8000-000000000011',current_date,null,'borrowed','51000000-0000-7000-8000-000000000202',1);
 perform public.create_karobar_transaction('51000000-0000-7000-8000-000000000103','51000000-0000-7000-8000-000000000021','returned',3,'51000000-0000-7000-8000-000000000011',current_date,null,'returned','51000000-0000-7000-8000-000000000203',1);
 perform public.create_karobar_transaction('51000000-0000-7000-8000-000000000104','51000000-0000-7000-8000-000000000021','repaid',4,'51000000-0000-7000-8000-000000000011',current_date,null,'repaid','51000000-0000-7000-8000-000000000204',1);
 perform public.create_karobar_transaction('51000000-0000-7000-8000-000000000105','51000000-0000-7000-8000-000000000021','adjustment',2,null,current_date,null,'adjustment','51000000-0000-7000-8000-000000000205',1);
 if (select balance from public.accounts where id='51000000-0000-7000-8000-000000000011')<>101 then raise exception 'Karobar cash effects incorrect'; end if;
 select count(*) into before_count from public.karobar_transactions where user_id='51000000-0000-7000-8000-000000000001';
 perform public.create_karobar_transaction('51000000-0000-7000-8000-000000000101','51000000-0000-7000-8000-000000000021','lent',7,'51000000-0000-7000-8000-000000000011',current_date,null,'lent','51000000-0000-7000-8000-000000000201',1);
 if (select count(*) from public.karobar_transactions where user_id='51000000-0000-7000-8000-000000000001')<>before_count then raise exception 'Karobar replay duplicated'; end if;
 begin perform public.create_karobar_transaction('51000000-0000-7000-8000-000000000106','51000000-0000-7000-8000-000000000021','lent',8,'51000000-0000-7000-8000-000000000011',current_date,null,'changed','51000000-0000-7000-8000-000000000201',1); raise exception 'Karobar mismatched replay accepted'; exception when others then if sqlerrm<>'IDEMPOTENCY_MISMATCH' then raise; end if; end;
 begin perform public.create_karobar_transaction('51000000-0000-7000-8000-000000000107','51000000-0000-7000-8000-000000000021','returned',5,'51000000-0000-7000-8000-000000000011',current_date,null,'over-return','51000000-0000-7000-8000-000000000207',1); raise exception 'return over outstanding accepted'; exception when others then if sqlerrm<>'INVALID_STATE' then raise; end if; end;
 begin perform public.create_karobar_transaction('51000000-0000-7000-8000-000000000108','51000000-0000-7000-8000-000000000021','repaid',6,'51000000-0000-7000-8000-000000000011',current_date,null,'over-repay','51000000-0000-7000-8000-000000000208',1); raise exception 'repay over outstanding accepted'; exception when others then if sqlerrm<>'INVALID_STATE' then raise; end if; end;
 begin perform public.create_karobar_transaction('51000000-0000-7000-8000-000000000109','51000000-0000-7000-8000-000000000021','lent',200,'51000000-0000-7000-8000-000000000011',current_date,null,'overdraw','51000000-0000-7000-8000-000000000209',1); raise exception 'Karobar overdraft accepted'; exception when others then if sqlerrm<>'INSUFFICIENT_FUNDS' then raise; end if; end;
 select version into v from public.karobar_transactions where id='51000000-0000-7000-8000-000000000101';
 perform public.update_karobar_transaction('51000000-0000-7000-8000-000000000101',v,8,current_date,'updated',1);
 begin perform public.update_karobar_transaction('51000000-0000-7000-8000-000000000101',v,9,current_date,'stale',1); raise exception 'Karobar stale update accepted'; exception when others then if sqlerrm<>'CONFLICT' then raise; end if; end;
 select version into v from public.karobar_transactions where id='51000000-0000-7000-8000-000000000101';
 perform public.delete_karobar_transaction('51000000-0000-7000-8000-000000000101',v,1);
 if not exists(select 1 from public.karobar_transactions where id='51000000-0000-7000-8000-000000000101' and deleted_at is not null) then raise exception 'Karobar tombstone missing'; end if;
end $$;
reset role;
rollback;
select 'PASS: Phase 1C Karobar parity' as result;
