create or replace function public.create_karobar_transaction(p_id uuid,p_person uuid,p_type public.sanie_karobar_type,p_amount numeric,p_account uuid,p_date date,p_due_date date,p_description text,p_request uuid,p_generation bigint)
returns jsonb language plpgsql security definer set search_path=pg_catalog,public as $$
declare u uuid; k public.karobar_transactions%rowtype; remaining numeric; origin_date date; cash numeric;
begin
 u:=public.sanie_require_generation(p_generation);
 if p_request is null or p_amount is null or p_amount<=0 or scale(p_amount)>2 or p_date is null then raise exception using errcode='P0001',message='VALIDATION_ERROR'; end if;
 select * into k from public.karobar_transactions where user_id=u and client_request_id=p_request;
 if found then
   if k.deleted_at is null and k.person_id=p_person and k.transaction_type=p_type and k.amount=p_amount and k.account_id is not distinct from p_account and k.transaction_date=p_date and k.due_date is not distinct from p_due_date and k.description is not distinct from p_description then
     return jsonb_build_object('karobar',to_jsonb(k),'replayed',true);
   end if;
   raise exception using errcode='P0001',message='IDEMPOTENCY_MISMATCH';
 end if;
 perform public.sanie_assert_owned('public.people',p_person,u,false);
 if not exists(select 1 from public.people where id=p_person and user_id=u and status='active' and deleted_at is null) then raise exception using errcode='P0001',message='INVALID_STATE'; end if;
 if p_type<>'adjustment' and p_account is null then raise exception using errcode='P0001',message='VALIDATION_ERROR'; end if;
 if p_account is not null then
   perform public.sanie_assert_owned('public.accounts',p_account,u,false);
   select balance into cash from public.accounts where id=p_account and user_id=u and is_active and deleted_at is null for update;
   if not found then raise exception using errcode='P0001',message='INVALID_STATE'; end if;
 end if;
 if p_type in ('lent','repaid') and cash<p_amount then raise exception using errcode='P0001',message='INSUFFICIENT_FUNDS'; end if;
 if p_type in ('returned','repaid') then
   select coalesce(sum(case when transaction_type=case when p_type='returned' then 'lent'::public.sanie_karobar_type else 'borrowed'::public.sanie_karobar_type end then amount else -amount end),0)
     into remaining from public.karobar_transactions
     where user_id=u and person_id=p_person and deleted_at is null
       and transaction_type in (case when p_type='returned' then 'lent'::public.sanie_karobar_type else 'borrowed'::public.sanie_karobar_type end,p_type);
   select min(transaction_date) into origin_date from public.karobar_transactions where user_id=u and person_id=p_person and transaction_type=case when p_type='returned' then 'lent'::public.sanie_karobar_type else 'borrowed'::public.sanie_karobar_type end and deleted_at is null;
   if remaining<p_amount or origin_date is null or p_date<origin_date then raise exception using errcode='P0001',message='INVALID_STATE'; end if;
 end if;
 insert into public.karobar_transactions(id,user_id,person_id,transaction_type,amount,account_id,client_request_id,transaction_date,due_date,description)
 values(p_id,u,p_person,p_type,p_amount,p_account,p_request,p_date,p_due_date,p_description) returning * into k;
 if p_account is not null then perform public.sanie_rebuild_account(p_account,u); end if;
 return jsonb_build_object('karobar',to_jsonb(k));
end $$;

create or replace function public.update_karobar_transaction(p_id uuid,p_base_version bigint,p_amount numeric,p_date date,p_description text,p_generation bigint)
returns jsonb language plpgsql security definer set search_path=pg_catalog,public as $$
declare u uuid; k public.karobar_transactions%rowtype; other_total numeric; cash numeric;
begin
 u:=public.sanie_require_generation(p_generation);
 select * into k from public.karobar_transactions where id=p_id and user_id=u and deleted_at is null for update;
 if not found then raise exception using errcode='P0001',message='NOT_FOUND'; end if;
 if k.version<>p_base_version then raise exception using errcode='P0001',message='CONFLICT'; end if;
 if k.expense_transaction_id is not null or k.income_transaction_id is not null then raise exception using errcode='P0001',message='INVALID_STATE'; end if;
 if p_amount is null or p_amount<=0 or scale(p_amount)>2 or p_date is null then raise exception using errcode='P0001',message='VALIDATION_ERROR'; end if;
 if k.transaction_type in ('lent','borrowed') then
   select coalesce(sum(amount),0) into other_total from public.karobar_transactions where user_id=u and person_id=k.person_id and deleted_at is null and transaction_type=case when k.transaction_type='lent' then 'returned'::public.sanie_karobar_type else 'repaid'::public.sanie_karobar_type end;
   if p_amount<other_total then raise exception using errcode='P0001',message='INVALID_STATE'; end if;
 end if;
 if k.transaction_type in ('returned','repaid') then
   select coalesce(sum(case when transaction_type=case when k.transaction_type='returned' then 'lent'::public.sanie_karobar_type else 'borrowed'::public.sanie_karobar_type end then amount else -amount end),0)
     into other_total from public.karobar_transactions where user_id=u and person_id=k.person_id and deleted_at is null and id<>p_id and transaction_type in (case when k.transaction_type='returned' then 'lent'::public.sanie_karobar_type else 'borrowed'::public.sanie_karobar_type end,k.transaction_type);
   if p_amount>other_total then raise exception using errcode='P0001',message='INVALID_STATE'; end if;
 end if;
 if k.transaction_type in ('lent','repaid') then
   select balance+k.amount into cash from public.accounts where id=k.account_id and user_id=u for update;
   if cash<p_amount then raise exception using errcode='P0001',message='INSUFFICIENT_FUNDS'; end if;
 end if;
 update public.karobar_transactions set amount=p_amount,transaction_date=p_date,description=p_description where id=p_id returning * into k;
 if k.account_id is not null then perform public.sanie_rebuild_account(k.account_id,u); end if;
 return to_jsonb(k);
end $$;
