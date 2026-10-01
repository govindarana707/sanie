-- Client creation may specify an opening amount, but never the derived caches.
create or replace function public.sanie_initialize_account_balance()
returns trigger language plpgsql set search_path=pg_catalog as $$
begin
 new.balance := new.opening_balance;
 return new;
end $$;
create trigger sanie_account_balance_insert before insert on public.accounts
for each row execute function public.sanie_initialize_account_balance();

create or replace function public.sanie_initialize_goal_amount()
returns trigger language plpgsql set search_path=pg_catalog as $$
begin
 new.current_amount := new.initial_amount;
 return new;
end $$;
create trigger sanie_goal_amount_insert before insert on public.goals
for each row execute function public.sanie_initialize_goal_amount();

revoke insert on public.accounts from authenticated;
grant insert (id,user_id,name,account_type,account_number,opening_balance,currency,color,icon,is_active,is_default,include_in_savings,include_in_net_balance) on public.accounts to authenticated;
revoke insert on public.goals from authenticated;
grant insert (id,user_id,name,target_amount,initial_amount,deadline,icon,color,description,status) on public.goals to authenticated;

revoke execute on function public.sanie_initialize_account_balance() from public, anon, authenticated;
revoke execute on function public.sanie_initialize_goal_amount() from public, anon, authenticated;
