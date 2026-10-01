create or replace function public.sanie_create_ordinary(p_id uuid,p_type public.sanie_category_type,p_account uuid,p_category uuid,p_subcategory uuid,p_amount numeric,p_date date,p_description text,p_payment_method text,p_request uuid,p_generation bigint)
returns jsonb language plpgsql security definer set search_path=pg_catalog,public as $$
declare u uuid; existing public.transactions%rowtype; available numeric; result public.transactions%rowtype;
begin
 u:=public.sanie_require_generation(p_generation);
 if p_request is null or p_amount is null or p_amount<=0 or scale(p_amount)>2 or p_date is null then raise exception using errcode='P0001',message='VALIDATION_ERROR'; end if;
 select * into existing from public.transactions where user_id=u and client_request_id=p_request limit 1;
 if found then
   if existing.deleted_at is null and existing.transaction_type=p_type::text::public.sanie_transaction_type and existing.amount=p_amount and existing.account_id=p_account and existing.category_id=p_category and existing.subcategory_id is not distinct from p_subcategory and existing.transaction_date=p_date and existing.description is not distinct from p_description and existing.payment_method is not distinct from p_payment_method then
     return jsonb_build_object('transaction',to_jsonb(existing),'replayed',true);
   end if;
   raise exception using errcode='P0001',message='IDEMPOTENCY_MISMATCH';
 end if;
 perform public.sanie_assert_owned('public.accounts',p_account,u,false); perform public.sanie_assert_owned('public.categories',p_category,u,true);
 if p_subcategory is not null then
   perform public.sanie_assert_owned('public.subcategories',p_subcategory,u,true);
   if not exists(select 1 from public.subcategories where id=p_subcategory and category_id=p_category and status='active' and deleted_at is null) then raise exception using errcode='P0001',message='VALIDATION_ERROR'; end if;
 end if;
 select balance into available from public.accounts where id=p_account and user_id=u and is_active and deleted_at is null for update;
 if not found then raise exception using errcode='P0001',message='FORBIDDEN_REFERENCE'; end if;
 if p_type='expense' and available<p_amount then raise exception using errcode='P0001',message='INSUFFICIENT_FUNDS'; end if;
 if not exists(select 1 from public.categories where id=p_category and category_type=p_type and status='active' and deleted_at is null) then raise exception using errcode='P0001',message='VALIDATION_ERROR'; end if;
 insert into public.transactions(id,user_id,account_id,from_account_id,to_account_id,category_id,subcategory_id,amount,transaction_type,payment_method,client_request_id,transaction_date,description)
 values(p_id,u,p_account,case when p_type='expense' then p_account end,case when p_type='income' then p_account end,p_category,p_subcategory,p_amount,p_type::text::public.sanie_transaction_type,p_payment_method,p_request,p_date,p_description) returning * into result;
 perform public.sanie_rebuild_account(p_account,u);
 return jsonb_build_object('transaction',to_jsonb(result),'account_balance',(select balance from public.accounts where id=p_account));
end $$;

create or replace function public.create_credit_purchase(p_karobar_id uuid,p_expense_id uuid,p_person uuid,p_category uuid,p_amount numeric,p_date date,p_description text,p_request uuid,p_generation bigint)
returns jsonb language plpgsql security definer set search_path=pg_catalog,public as $$
declare u uuid; k public.karobar_transactions%rowtype; t public.transactions%rowtype;
begin
 u:=public.sanie_require_generation(p_generation);
 if p_request is null or p_amount is null or p_amount<=0 or scale(p_amount)>2 or p_date is null then raise exception using errcode='P0001',message='VALIDATION_ERROR'; end if;
 select * into k from public.karobar_transactions where user_id=u and client_request_id=p_request;
 if found then
   select * into t from public.transactions where id=k.expense_transaction_id and user_id=u;
   if k.deleted_at is null and t.deleted_at is null and k.transaction_type='borrowed' and k.payment_method='credit' and k.person_id=p_person and k.amount=p_amount and k.transaction_date=p_date and k.description is not distinct from p_description and t.transaction_type='expense' and t.payment_method='credit' and t.category_id=p_category and t.amount=p_amount and t.transaction_date=p_date and t.description is not distinct from p_description then
     return jsonb_build_object('karobar',to_jsonb(k),'expense',to_jsonb(t),'replayed',true);
   end if;
   raise exception using errcode='P0001',message='IDEMPOTENCY_MISMATCH';
 end if;
 perform public.sanie_assert_owned('public.people',p_person,u,false); perform public.sanie_assert_owned('public.categories',p_category,u,true);
 if not exists(select 1 from public.people where id=p_person and user_id=u and status='active' and deleted_at is null) then raise exception using errcode='P0001',message='INVALID_STATE'; end if;
 if not exists(select 1 from public.categories where id=p_category and category_type='expense' and status='active' and deleted_at is null) then raise exception using errcode='P0001',message='VALIDATION_ERROR'; end if;
 insert into public.karobar_transactions(id,user_id,person_id,transaction_type,amount,client_request_id,transaction_date,description,payment_method)
 values(p_karobar_id,u,p_person,'borrowed',p_amount,p_request,p_date,p_description,'credit') returning * into k;
 insert into public.transactions(id,user_id,category_id,amount,transaction_type,payment_method,karobar_transaction_id,client_request_id,transaction_date,description)
 values(p_expense_id,u,p_category,p_amount,'expense','credit',p_karobar_id,p_request,p_date,p_description) returning * into t;
 update public.karobar_transactions set expense_transaction_id=p_expense_id where id=p_karobar_id returning * into k;
 return jsonb_build_object('karobar',to_jsonb(k),'expense',to_jsonb(t));
end $$;

create or replace function public.create_transfer(p_id uuid,p_from uuid,p_to uuid,p_amount numeric,p_fee numeric,p_fee_category uuid,p_date date,p_description text,p_request uuid,p_generation bigint)
returns jsonb language plpgsql security definer set search_path=pg_catalog,public as $$
declare u uuid; a numeric; x public.transactions%rowtype; existing_fee numeric; existing_fee_category uuid;
begin
 u:=public.sanie_require_generation(p_generation);
 if p_request is null or p_date is null or p_from=p_to or p_amount is null or p_amount<=0 or scale(p_amount)>2 or coalesce(p_fee,0)<0 or scale(coalesce(p_fee,0))>2 then raise exception using errcode='P0001',message='VALIDATION_ERROR'; end if;
 select * into x from public.transactions where user_id=u and client_request_id=p_request;
 if found then
   select amount,category_id into existing_fee,existing_fee_category from public.transactions where user_id=u and transfer_parent_id=x.id and deleted_at is null;
   if x.deleted_at is null and x.transaction_type='transfer' and x.from_account_id=p_from and x.to_account_id=p_to and x.amount=p_amount and x.transaction_date=p_date and x.description is not distinct from p_description and coalesce(existing_fee,0)=coalesce(p_fee,0) and (coalesce(p_fee,0)=0 or existing_fee_category=p_fee_category) then
     return jsonb_build_object('transfer',to_jsonb(x),'replayed',true);
   end if;
   raise exception using errcode='P0001',message='IDEMPOTENCY_MISMATCH';
 end if;
 perform public.sanie_assert_owned('public.accounts',p_from,u,false); perform public.sanie_assert_owned('public.accounts',p_to,u,false);
 if (select count(*) from public.accounts where id in (p_from,p_to) and user_id=u and is_active and deleted_at is null)<>2 then raise exception using errcode='P0001',message='INVALID_STATE'; end if;
 perform 1 from public.accounts where id in(p_from,p_to) and user_id=u order by id for update;
 select balance into a from public.accounts where id=p_from;
 if a<p_amount+coalesce(p_fee,0) then raise exception using errcode='P0001',message='INSUFFICIENT_FUNDS'; end if;
 if coalesce(p_fee,0)>0 then
   perform public.sanie_assert_owned('public.categories',p_fee_category,u,true);
   if not exists(select 1 from public.categories where id=p_fee_category and category_type='expense' and status='active' and deleted_at is null) then raise exception using errcode='P0001',message='VALIDATION_ERROR'; end if;
 end if;
 insert into public.transactions(id,user_id,account_id,from_account_id,to_account_id,amount,transaction_type,payment_method,client_request_id,transaction_date,description)
 values(p_id,u,p_from,p_from,p_to,p_amount,'transfer','transfer',p_request,p_date,p_description) returning * into x;
 if coalesce(p_fee,0)>0 then
   insert into public.transactions(id,user_id,account_id,from_account_id,category_id,amount,transaction_type,payment_method,transfer_parent_id,transaction_date,description)
   values(gen_random_uuid(),u,p_from,p_from,p_fee_category,p_fee,'expense','transfer',p_id,p_date,coalesce(p_description,'Transfer')||' Fee');
 end if;
 perform public.sanie_rebuild_account(p_from,u); perform public.sanie_rebuild_account(p_to,u);
 return jsonb_build_object('transfer',to_jsonb(x));
end $$;

create or replace function public.create_goal_contribution(p_id uuid,p_goal uuid,p_account uuid,p_amount numeric,p_date date,p_description text,p_request uuid,p_generation bigint)
returns jsonb language plpgsql security definer set search_path=pg_catalog,public as $$
declare u uuid; a numeric; t public.transactions%rowtype;
begin
 u:=public.sanie_require_generation(p_generation);
 if p_request is null or p_amount is null or p_amount<=0 or scale(p_amount)>2 or p_date is null then raise exception using errcode='P0001',message='VALIDATION_ERROR'; end if;
 select * into t from public.transactions where user_id=u and client_request_id=p_request;
 if found then
   if t.deleted_at is null and t.transaction_type='goal_contribution' and t.goal_id=p_goal and t.account_id=p_account and t.amount=p_amount and t.transaction_date=p_date and t.description is not distinct from p_description then return jsonb_build_object('transaction',to_jsonb(t),'replayed',true); end if;
   raise exception using errcode='P0001',message='IDEMPOTENCY_MISMATCH';
 end if;
 perform public.sanie_assert_owned('public.goals',p_goal,u,false); perform public.sanie_assert_owned('public.accounts',p_account,u,false);
 if not exists(select 1 from public.goals where id=p_goal and user_id=u and status='active' and deleted_at is null) then raise exception using errcode='P0001',message='INVALID_STATE'; end if;
 select balance into a from public.accounts where id=p_account and user_id=u and is_active and deleted_at is null for update;
 if not found then raise exception using errcode='P0001',message='INVALID_STATE'; end if;
 if a<p_amount then raise exception using errcode='P0001',message='INSUFFICIENT_FUNDS'; end if;
 insert into public.transactions(id,user_id,account_id,from_account_id,goal_id,amount,transaction_type,payment_method,client_request_id,transaction_date,description)
 values(p_id,u,p_account,p_account,p_goal,p_amount,'goal_contribution','goal',p_request,p_date,p_description) returning * into t;
 perform public.sanie_rebuild_goal(p_goal,u); perform public.sanie_rebuild_account(p_account,u);
 return jsonb_build_object('transaction',to_jsonb(t),'goal',(select to_jsonb(g) from public.goals g where id=p_goal));
end $$;
