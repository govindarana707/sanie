-- RLS controls rows, not columns. Restrict table-API updates so an
-- authenticated client cannot overwrite financial or reset-derived fields.
revoke update on public.profiles from authenticated;
grant update (first_name,last_name,phone,avatar_path,currency,language,theme,notification_preferences,settings) on public.profiles to authenticated;

revoke update on public.accounts from authenticated;
grant update (name,account_type,account_number,currency,color,icon,is_active,is_default,include_in_savings,include_in_net_balance,deleted_at) on public.accounts to authenticated;
revoke update on public.goals from authenticated;
grant update (name,target_amount,deadline,icon,color,description,status,deleted_at) on public.goals to authenticated;

-- Financial/history tables remain RPC-only even if a future broad table grant
-- is introduced by local Supabase defaults.
revoke insert, update, delete on public.transactions, public.karobar_transactions from authenticated, anon;
revoke all on public.sync_changes, public.fresh_start_operations from authenticated, anon;
revoke all on all tables in schema sanie from authenticated, anon;

-- Public error normalisation for direct budget writes. The exclusion index is
-- authoritative; clients should map SQLSTATE 23P01 to BUDGET_SCOPE_OVERLAP.
comment on constraint budgets_no_scope_overlap on public.budgets is 'Application error: BUDGET_SCOPE_OVERLAP (SQLSTATE 23P01)';
