-- A category's type gives historical transactions their meaning. Metadata
-- edits may rename it, but cannot reinterpret existing financial references.
create or replace function public.sanie_keep_category_type()
returns trigger language plpgsql set search_path=pg_catalog as $$
begin
  if new.category_type is distinct from old.category_type then
    raise exception using errcode='P0001',message='VALIDATION_ERROR';
  end if;
  return new;
end $$;
create trigger sanie_categories_keep_type before update of category_type
  on public.categories for each row execute function public.sanie_keep_category_type();
revoke execute on function public.sanie_keep_category_type() from public,anon,authenticated;
