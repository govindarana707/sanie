create or replace function public.update_transaction(
 p_id uuid,p_base_version bigint,p_account uuid,p_category uuid,p_subcategory uuid,
 p_amount numeric,p_date date,p_description text,p_generation bigint)
returns jsonb language plpgsql security definer set search_path=pg_catalog,public as $$
declare u uuid; old public.transactions%rowtype; available numeric; new_type public.sanie_category_type;
begin
 u:=public.sanie_require_generation(p_generation);
 select * into old from public.transactions where id=p_id and user_id=u and deleted_at is null for update;
 if not found then raise exception using errcode='P0001',message='NOT_FOUND'; end if;
 if old.version<>p_base_version then raise exception using errcode='P0001',message='CONFLICT'; end if;
 if old.transaction_type not in ('income','expense') or old.karobar_transaction_id is not null or old.recurring_definition_id is not null then raise exception using errcode='P0001',message='INVALID_STATE'; end if;
 if p_amount is null or p_amount<=0 or scale(p_amount)>2 or p_date is null then raise exception using errcode='P0001',message='VALIDATION_ERROR'; end if;
 new_type:=old.transaction_type::text::public.sanie_category_type;
 perform public.sanie_assert_owned('public.accounts',p_account,u,false);
 perform public.sanie_assert_owned('public.categories',p_category,u,true);
 if not exists(select 1 from public.categories where id=p_category and category_type=new_type and status='active' and deleted_at is null) then raise exception using errcode='P0001',message='VALIDATION_ERROR'; end if;
 if p_subcategory is not null then
  perform public.sanie_assert_owned('public.subcategories',p_subcategory,u,true);
  if not exists(select 1 from public.subcategories where id=p_subcategory and category_id=p_category and status='active' and deleted_at is null) then raise exception using errcode='P0001',message='VALIDATION_ERROR'; end if;
 end if;
 perform 1 from public.accounts where id in(old.account_id,p_account) and user_id=u order by id for update;
 if new_type='expense' then
  select balance + case when old.account_id=p_account then old.amount else 0 end into available from public.accounts where id=p_account and user_id=u and is_active and deleted_at is null;
  if not found then raise exception using errcode='P0001',message='INVALID_STATE'; end if;
  if available<p_amount then raise exception using errcode='P0001',message='INSUFFICIENT_FUNDS'; end if;
 end if;
 update public.transactions set account_id=p_account,
  from_account_id=case when new_type='expense' then p_account end,
  to_account_id=case when new_type='income' then p_account end,
  category_id=p_category,subcategory_id=p_subcategory,amount=p_amount,transaction_date=p_date,description=p_description
 where id=p_id;
 perform public.sanie_rebuild_account(old.account_id,u);
 if p_account<>old.account_id then perform public.sanie_rebuild_account(p_account,u); end if;
 return (select to_jsonb(t) from public.transactions t where id=p_id);
end $$;
revoke execute on function public.update_transaction(uuid,bigint,uuid,uuid,uuid,numeric,date,text,bigint) from public,anon;
grant execute on function public.update_transaction(uuid,bigint,uuid,uuid,uuid,numeric,date,text,bigint) to authenticated;
