-- Phase 1C execution hardening: preserve established PHP credit-purchase
-- semantics (payable + linked expense, with no immediate cash-account effect).

create or replace function public.create_transfer(p_id uuid,p_from uuid,p_to uuid,p_amount numeric,p_fee numeric,p_fee_category uuid,p_date date,p_description text,p_request uuid,p_generation bigint)
returns jsonb language plpgsql security definer set search_path=pg_catalog,public as $$
declare u uuid; a numeric; x public.transactions%rowtype;
begin
 u:=public.sanie_require_generation(p_generation);
 if p_from=p_to or p_amount<=0 or coalesce(p_fee,0)<0 then raise exception using errcode='P0001',message='VALIDATION_ERROR'; end if;
 select * into x from public.transactions where user_id=u and client_request_id=p_request;
 if found then
   if x.transaction_type='transfer' and x.from_account_id=p_from and x.to_account_id=p_to and x.amount=p_amount then
     return jsonb_build_object('transfer',to_jsonb(x),'replayed',true);
   end if;
   raise exception using errcode='P0001',message='IDEMPOTENCY_MISMATCH';
 end if;
 perform public.sanie_assert_owned('public.accounts',p_from,u,false); perform public.sanie_assert_owned('public.accounts',p_to,u,false);
 perform 1 from public.accounts where id in(p_from,p_to) and user_id=u and is_active and deleted_at is null order by id for update;
 select balance into a from public.accounts where id=p_from;
 if a<p_amount+coalesce(p_fee,0) then raise exception using errcode='P0001',message='INSUFFICIENT_FUNDS'; end if;
 if coalesce(p_fee,0)>0 then perform public.sanie_assert_owned('public.categories',p_fee_category,u,true); end if;
 insert into public.transactions(id,user_id,account_id,from_account_id,to_account_id,amount,transaction_type,payment_method,client_request_id,transaction_date,description)
 values(p_id,u,p_from,p_from,p_to,p_amount,'transfer','transfer',p_request,p_date,p_description) returning * into x;
 if coalesce(p_fee,0)>0 then
   insert into public.transactions(id,user_id,account_id,from_account_id,category_id,amount,transaction_type,payment_method,transfer_parent_id,transaction_date,description)
   values(gen_random_uuid(),u,p_from,p_from,p_fee_category,p_fee,'expense','transfer',p_id,p_date,coalesce(p_description,'Transfer')||' Fee');
 end if;
 perform public.sanie_rebuild_account(p_from,u); perform public.sanie_rebuild_account(p_to,u);
 return jsonb_build_object('transfer',to_jsonb(x));
end $$;

create or replace function public.create_budget(p_id uuid,p_category uuid,p_subcategory uuid,p_name text,p_amount numeric,p_start date,p_end date,p_generation bigint)
returns jsonb language plpgsql security definer set search_path=pg_catalog,public as $$
declare u uuid; b public.budgets%rowtype;
begin
 u:=public.sanie_require_generation(p_generation);
 if p_amount<=0 or p_end<p_start then raise exception using errcode='P0001',message='VALIDATION_ERROR'; end if;
 if p_category is not null then perform public.sanie_assert_owned('public.categories',p_category,u,true); end if;
 if p_subcategory is not null then perform public.sanie_assert_owned('public.subcategories',p_subcategory,u,true); end if;
 insert into public.budgets(id,user_id,category_id,subcategory_id,name,amount,start_date,end_date)
 values(p_id,u,p_category,p_subcategory,p_name,p_amount,p_start,p_end) returning * into b;
 return to_jsonb(b);
exception when exclusion_violation then raise exception using errcode='P0001',message='BUDGET_SCOPE_OVERLAP';
end $$;

create or replace function public.create_credit_purchase(p_karobar_id uuid,p_expense_id uuid,p_person uuid,p_category uuid,p_amount numeric,p_date date,p_description text,p_request uuid,p_generation bigint)
returns jsonb language plpgsql security definer set search_path=pg_catalog,public as $$
declare u uuid; k public.karobar_transactions%rowtype; t public.transactions%rowtype;
begin
 u:=public.sanie_require_generation(p_generation);
 if p_amount<=0 or scale(p_amount)>2 then raise exception using errcode='P0001',message='VALIDATION_ERROR'; end if;
 select * into k from public.karobar_transactions where user_id=u and client_request_id=p_request;
 if found then
   if k.transaction_type='borrowed' and k.person_id=p_person and k.amount=p_amount and k.expense_transaction_id is not null then return jsonb_build_object('karobar',to_jsonb(k),'replayed',true); end if;
   raise exception using errcode='P0001',message='IDEMPOTENCY_MISMATCH';
 end if;
 perform public.sanie_assert_owned('public.people',p_person,u,false); perform public.sanie_assert_owned('public.categories',p_category,u,true);
 if not exists(select 1 from public.categories where id=p_category and category_type='expense' and deleted_at is null) then raise exception using errcode='P0001',message='VALIDATION_ERROR'; end if;
 insert into public.karobar_transactions(id,user_id,person_id,transaction_type,amount,client_request_id,transaction_date,description,payment_method)
 values(p_karobar_id,u,p_person,'borrowed',p_amount,p_request,p_date,p_description,'credit') returning * into k;
 insert into public.transactions(id,user_id,category_id,amount,transaction_type,payment_method,karobar_transaction_id,client_request_id,transaction_date,description)
 values(p_expense_id,u,p_category,p_amount,'expense','credit',p_karobar_id,p_request,p_date,p_description) returning * into t;
 update public.karobar_transactions set expense_transaction_id=p_expense_id where id=p_karobar_id returning * into k;
 return jsonb_build_object('karobar',to_jsonb(k),'expense',to_jsonb(t));
end $$;

create or replace function public.update_credit_purchase(p_karobar_id uuid,p_base_version bigint,p_amount numeric,p_date date,p_description text,p_generation bigint)
returns jsonb language plpgsql security definer set search_path=pg_catalog,public as $$
declare u uuid; k public.karobar_transactions%rowtype; repaid numeric;
begin
 u:=public.sanie_require_generation(p_generation); select * into k from public.karobar_transactions where id=p_karobar_id and user_id=u and deleted_at is null for update;
 if not found then raise exception using errcode='P0001',message='NOT_FOUND'; end if;
 if k.version<>p_base_version then raise exception using errcode='P0001',message='CONFLICT'; end if;
 if k.expense_transaction_id is null or k.payment_method<>'credit' or p_amount<=0 then raise exception using errcode='P0001',message='INVALID_STATE'; end if;
 select coalesce(sum(amount),0) into repaid from public.karobar_transactions where user_id=u and person_id=k.person_id and transaction_type='repaid' and deleted_at is null;
 if p_amount<repaid then raise exception using errcode='P0001',message='INVALID_STATE'; end if;
 update public.karobar_transactions set amount=p_amount,transaction_date=p_date,description=p_description where id=p_karobar_id returning * into k;
 update public.transactions set amount=p_amount,transaction_date=p_date,description=p_description where id=k.expense_transaction_id and user_id=u;
 return jsonb_build_object('karobar',to_jsonb(k));
end $$;

create or replace function public.delete_credit_purchase(p_karobar_id uuid,p_base_version bigint,p_generation bigint)
returns jsonb language plpgsql security definer set search_path=pg_catalog,public as $$
declare u uuid; k public.karobar_transactions%rowtype;
begin
 u:=public.sanie_require_generation(p_generation); select * into k from public.karobar_transactions where id=p_karobar_id and user_id=u and deleted_at is null for update;
 if not found then raise exception using errcode='P0001',message='NOT_FOUND'; end if;
 if k.version<>p_base_version then raise exception using errcode='P0001',message='CONFLICT'; end if;
 if k.expense_transaction_id is null or exists(select 1 from public.karobar_transactions where user_id=u and person_id=k.person_id and transaction_type='repaid' and deleted_at is null) then raise exception using errcode='P0001',message='INVALID_STATE'; end if;
 update public.transactions set deleted_at=now() where id=k.expense_transaction_id and user_id=u;
 update public.karobar_transactions set deleted_at=now() where id=p_karobar_id;
 return jsonb_build_object('id',p_karobar_id,'deleted',true);
end $$;

revoke all on function public.create_budget(uuid,uuid,uuid,text,numeric,date,date,bigint),public.create_credit_purchase(uuid,uuid,uuid,uuid,numeric,date,text,uuid,bigint),public.update_credit_purchase(uuid,bigint,numeric,date,text,bigint),public.delete_credit_purchase(uuid,bigint,bigint) from public, anon;
grant execute on function public.create_budget(uuid,uuid,uuid,text,numeric,date,date,bigint),public.create_credit_purchase(uuid,uuid,uuid,uuid,numeric,date,text,uuid,bigint),public.update_credit_purchase(uuid,bigint,numeric,date,text,bigint),public.delete_credit_purchase(uuid,bigint,bigint) to authenticated;
