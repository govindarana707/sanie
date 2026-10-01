-- PostgreSQL grants EXECUTE to PUBLIC by default. Remove that implicit surface
-- and explicitly expose only client-facing RPCs to authenticated callers.
revoke execute on all functions in schema public from public, anon, authenticated;

grant execute on function
 public.create_income(uuid,uuid,uuid,uuid,numeric,date,text,text,uuid,bigint),
 public.create_expense(uuid,uuid,uuid,uuid,numeric,date,text,text,uuid,bigint),
 public.create_transfer(uuid,uuid,uuid,numeric,numeric,uuid,date,text,uuid,bigint),
 public.create_goal_contribution(uuid,uuid,uuid,numeric,date,text,uuid,bigint),
 public.update_goal_contribution(uuid,bigint,numeric,date,text,bigint),
 public.delete_goal_contribution(uuid,bigint,bigint),
 public.update_transaction(uuid,bigint,numeric,date,text,bigint),
 public.delete_transaction(uuid,bigint,bigint),
 public.update_account_opening_balance(uuid,numeric,bigint,bigint),
 public.create_karobar_transaction(uuid,uuid,public.sanie_karobar_type,numeric,uuid,date,date,text,uuid,bigint),
 public.update_karobar_transaction(uuid,bigint,numeric,date,text,bigint),
 public.delete_karobar_transaction(uuid,bigint,bigint),
 public.process_recurring_occurrence(uuid,date,text,bigint),
 public.fresh_start(bigint),
 public.pull_changes(bigint,integer,bigint),
 public.create_budget(uuid,uuid,uuid,text,numeric,date,date,bigint),
 public.create_credit_purchase(uuid,uuid,uuid,uuid,numeric,date,text,uuid,bigint),
 public.update_credit_purchase(uuid,bigint,numeric,date,text,bigint),
 public.delete_credit_purchase(uuid,bigint,bigint)
to authenticated;
