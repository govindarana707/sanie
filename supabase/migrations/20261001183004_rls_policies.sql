alter table public.profiles enable row level security;
alter table public.accounts enable row level security;
alter table public.categories enable row level security;
alter table public.subcategories enable row level security;
alter table public.transactions enable row level security;
alter table public.budgets enable row level security;
alter table public.goals enable row level security;
alter table public.people enable row level security;
alter table public.karobar_transactions enable row level security;
alter table public.recurring_transactions enable row level security;
alter table public.tasks enable row level security;
alter table public.notifications enable row level security;
alter table public.attachments enable row level security;

create policy profiles_own on public.profiles for all using (id=auth.uid()) with check (id=auth.uid());
create policy accounts_own_select on public.accounts for select using (user_id=auth.uid());
create policy accounts_own_insert on public.accounts for insert with check (user_id=auth.uid());
create policy accounts_own_update on public.accounts for update using(user_id=auth.uid()) with check(user_id=auth.uid());
-- No ordinary delete policy: account history must be retired/tombstoned by a controlled command.

create policy categories_read on public.categories for select using (user_id=auth.uid() or is_system);
create policy categories_insert on public.categories for insert with check(user_id=auth.uid() and not is_system);
create policy categories_update on public.categories for update using(user_id=auth.uid() and not is_system) with check(user_id=auth.uid() and not is_system);
create policy subcategories_read on public.subcategories for select using(user_id=auth.uid() or user_id is null);
create policy subcategories_write on public.subcategories for all using(user_id=auth.uid()) with check(user_id=auth.uid());

create policy budgets_own on public.budgets for all using(user_id=auth.uid()) with check(user_id=auth.uid());
create policy goals_read on public.goals for select using(user_id=auth.uid());
create policy goals_write on public.goals for insert with check(user_id=auth.uid());
create policy goals_metadata_update on public.goals for update using(user_id=auth.uid()) with check(user_id=auth.uid());
create policy people_own on public.people for all using(user_id=auth.uid()) with check(user_id=auth.uid());
create policy recurring_read on public.recurring_transactions for select using(user_id=auth.uid());
create policy recurring_write on public.recurring_transactions for insert with check(user_id=auth.uid());
create policy recurring_update on public.recurring_transactions for update using(user_id=auth.uid()) with check(user_id=auth.uid());
create policy tasks_own on public.tasks for all using(user_id=auth.uid()) with check(user_id=auth.uid());
create policy notifications_read on public.notifications for select using(user_id=auth.uid());
create policy notifications_read_update on public.notifications for update using(user_id=auth.uid()) with check(user_id=auth.uid());
create policy attachments_own on public.attachments for all using(user_id=auth.uid()) with check(user_id=auth.uid());
-- Financial tables deliberately have SELECT-only client policies. SECURITY DEFINER
-- RPCs below are the only mutation boundary.
create policy transactions_read on public.transactions for select using(user_id=auth.uid());
create policy karobar_read on public.karobar_transactions for select using(user_id=auth.uid());

revoke all on all tables in schema sanie from anon, authenticated;
