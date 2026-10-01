-- Cloud projects do not rely on local default table grants.  Grant only the
-- operations already permitted by the Phase 1C RLS policies and column rules.
grant usage on schema public to authenticated;

grant select on table
  public.profiles,
  public.accounts,
  public.categories,
  public.subcategories,
  public.goals,
  public.people,
  public.recurring_transactions,
  public.transactions,
  public.budgets,
  public.karobar_transactions,
  public.tasks,
  public.notifications,
  public.attachments
to authenticated;

-- Ordinary user-owned metadata.  Financial history remains RPC-only; budgets
-- also remain behind create_budget so exclusion violations are normalized.
grant insert, update on public.categories to authenticated;
grant insert, update, delete on public.subcategories to authenticated;
grant insert, update, delete on public.people to authenticated;
grant insert, update on public.recurring_transactions to authenticated;
grant insert, update, delete on public.tasks to authenticated;
grant update on public.notifications to authenticated;
grant insert, update, delete on public.attachments to authenticated;
