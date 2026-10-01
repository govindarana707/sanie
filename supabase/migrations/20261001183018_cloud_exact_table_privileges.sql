-- Supabase cloud defaults may include REFERENCES/TRIGGER/TRUNCATE/MAINTAIN.
-- Replace them with the exact client table surface approved by SanIE.
revoke all privileges on all tables in schema public from anon, authenticated;

grant select on public.profiles, public.accounts, public.categories,
 public.subcategories, public.goals, public.people,
 public.recurring_transactions, public.transactions, public.budgets,
 public.karobar_transactions, public.tasks, public.notifications,
 public.attachments to authenticated;

grant update (first_name,last_name,phone,avatar_path,currency,language,theme,
 notification_preferences,settings) on public.profiles to authenticated;

grant insert (id,user_id,name,account_type,account_number,opening_balance,
 currency,color,icon,is_active,is_default,include_in_savings,
 include_in_net_balance) on public.accounts to authenticated;
grant update (name,account_type,account_number,currency,color,icon,is_active,
 is_default,include_in_savings,include_in_net_balance,deleted_at)
 on public.accounts to authenticated;

grant insert, update, delete on public.categories to authenticated;
grant insert, update, delete on public.subcategories to authenticated;

grant insert (id,user_id,name,target_amount,initial_amount,deadline,icon,color,
 description,status) on public.goals to authenticated;
grant update (name,target_amount,deadline,icon,color,description,status,deleted_at)
 on public.goals to authenticated;

grant insert, update, delete on public.people to authenticated;
grant insert, update on public.recurring_transactions to authenticated;
grant insert, update, delete on public.tasks to authenticated;
grant update on public.notifications to authenticated;
grant insert, update, delete on public.attachments to authenticated;

-- transactions, Karobar, budgets and internal state intentionally retain
-- SELECT-only or no table access; mutations remain approved RPC-only paths.
