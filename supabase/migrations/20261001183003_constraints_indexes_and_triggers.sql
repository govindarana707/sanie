-- `version` is incremented only here: direct metadata writes and RPC writes
-- share the same deterministic path.
create or replace function public.sanie_touch_version()
returns trigger language plpgsql set search_path = pg_catalog as $$
begin
  if new is distinct from old then new.version = old.version + 1; new.updated_at = now(); end if;
  return new;
end $$;

do $$ declare t text; begin
  foreach t in array array['accounts','categories','subcategories','transactions','budgets','goals','people','karobar_transactions','recurring_transactions','tasks','notifications'] loop
    execute format('create trigger sanie_%I_touch before update on public.%I for each row execute function public.sanie_touch_version()', t, t);
  end loop;
end $$;
create trigger sanie_attachments_touch before update on public.attachments for each row execute function public.sanie_set_updated_at();

create index accounts_user_active_idx on public.accounts(user_id,is_active) where deleted_at is null;
create index categories_user_status_idx on public.categories(user_id,category_type,status,sort_order) where deleted_at is null;
create index subcategories_category_idx on public.subcategories(category_id,status,sort_order) where deleted_at is null;
create index transactions_user_date_idx on public.transactions(user_id,transaction_date,created_at) where deleted_at is null;
create index transactions_accounts_idx on public.transactions(user_id,account_id,from_account_id,to_account_id) where deleted_at is null;
create unique index transactions_request_idx on public.transactions(user_id,client_request_id) where client_request_id is not null;
create unique index transactions_occurrence_idx on public.transactions(user_id,recurring_definition_id,recurring_occurrence_date) where recurring_definition_id is not null and recurring_occurrence_date is not null and deleted_at is null;
create unique index transactions_fee_idx on public.transactions(user_id,transfer_parent_id) where transfer_parent_id is not null and deleted_at is null;
create unique index karobar_request_idx on public.karobar_transactions(user_id,client_request_id) where client_request_id is not null;
create unique index karobar_expense_idx on public.karobar_transactions(user_id,expense_transaction_id) where expense_transaction_id is not null and deleted_at is null;
create unique index tasks_seed_idx on public.tasks(user_id,seed_key) where seed_key is not null;
-- Exact category/subcategory scopes only: a category-wide budget does not
-- conflict with a subcategory-specific one, matching current SanIE behavior.
alter table public.budgets add constraint budgets_no_scope_overlap exclude using gist (
 user_id with =, category_id with =, subcategory_id with =,
 daterange(start_date,end_date,'[]') with &&
) where (deleted_at is null and is_active);

create or replace function public.sanie_assert_owned(p_table regclass, p_id uuid, p_user uuid, p_allow_system boolean default false)
returns void language plpgsql security definer set search_path = pg_catalog, public as $$
declare ok boolean;
begin
 execute format('select exists(select 1 from %s where id=$1 and deleted_at is null and (user_id=$2%s))', p_table, case when p_allow_system then ' or user_id is null' else '' end) into ok using p_id,p_user;
 if not coalesce(ok,false) then raise exception using errcode='P0001', message='FORBIDDEN_REFERENCE'; end if;
end $$;

create or replace function public.sanie_validate_reference_ownership()
returns trigger language plpgsql security definer set search_path = pg_catalog, public as $$
begin
  if tg_table_name='subcategories' then perform public.sanie_assert_owned('public.categories',new.category_id,new.user_id,true); end if;
  if tg_table_name='budgets' then if new.category_id is not null then perform public.sanie_assert_owned('public.categories',new.category_id,new.user_id,true); end if; if new.subcategory_id is not null then perform public.sanie_assert_owned('public.subcategories',new.subcategory_id,new.user_id,true); end if; end if;
  if tg_table_name='recurring_transactions' then perform public.sanie_assert_owned('public.accounts',new.account_id,new.user_id); perform public.sanie_assert_owned('public.categories',new.category_id,new.user_id,true); if new.subcategory_id is not null then perform public.sanie_assert_owned('public.subcategories',new.subcategory_id,new.user_id,true); end if; end if;
  if tg_table_name='attachments' then if new.transaction_id is not null then perform public.sanie_assert_owned('public.transactions',new.transaction_id,new.user_id); end if; if new.goal_id is not null then perform public.sanie_assert_owned('public.goals',new.goal_id,new.user_id); end if; end if;
  return new;
end $$;
create trigger subcategories_owner_reference before insert or update on public.subcategories for each row execute function public.sanie_validate_reference_ownership();
create trigger budgets_owner_reference before insert or update on public.budgets for each row execute function public.sanie_validate_reference_ownership();
create trigger recurring_owner_reference before insert or update on public.recurring_transactions for each row execute function public.sanie_validate_reference_ownership();
create trigger attachments_owner_reference before insert or update on public.attachments for each row execute function public.sanie_validate_reference_ownership();
