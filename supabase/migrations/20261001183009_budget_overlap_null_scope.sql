-- PostgreSQL exclusion constraints treat NULL values as distinct. Normalize
-- optional budget scope keys so category-wide and exact-scope budgets cannot
-- silently bypass the overlap invariant.
alter table public.budgets drop constraint budgets_no_scope_overlap;
alter table public.budgets add constraint budgets_no_scope_overlap exclude using gist (
  user_id with =,
  coalesce(category_id, '00000000-0000-0000-0000-000000000000'::uuid) with =,
  coalesce(subcategory_id, '00000000-0000-0000-0000-000000000000'::uuid) with =,
  daterange(start_date,end_date,'[]') with &&
) where (deleted_at is null and is_active);

comment on constraint budgets_no_scope_overlap on public.budgets is 'Application error: BUDGET_SCOPE_OVERLAP (SQLSTATE 23P01)';
