create table public.sync_changes (
  sequence bigint generated always as identity primary key,
  user_id uuid not null references public.profiles(id) on delete cascade,
  entity_type text not null,
  entity_id uuid not null,
  operation text not null check(operation in ('insert','update','tombstone')),
  entity_version bigint,
  data_generation bigint not null,
  changed_at timestamptz not null default now()
);
create index sync_changes_user_sequence_idx on public.sync_changes(user_id,sequence);
alter table public.sync_changes enable row level security;
-- Change feed is exposed only through pull_changes; references are not payloads.

create or replace function public.sanie_record_change()
returns trigger language plpgsql security definer set search_path=pg_catalog,public as $$
declare gen bigint; op text;
begin
 select data_generation into gen from public.profiles where id=new.user_id;
 op:=case when new.deleted_at is not null then 'tombstone' when tg_op='INSERT' then 'insert' else 'update' end;
 insert into public.sync_changes(user_id,entity_type,entity_id,operation,entity_version,data_generation)
 values(new.user_id,tg_table_name,new.id,op,new.version,gen);
 return new;
end $$;
do $$ declare t text; begin
 foreach t in array array['accounts','categories','subcategories','transactions','budgets','goals','people','karobar_transactions','recurring_transactions','tasks','notifications'] loop
  execute format('create trigger sanie_%I_change after insert or update on public.%I for each row execute function public.sanie_record_change()',t,t);
 end loop;
end $$;
create or replace function public.pull_changes(p_after_cursor bigint default 0,p_limit integer default 100,p_generation bigint default null)
returns table(sequence bigint,entity_type text,entity_id uuid,operation text,entity_version bigint,data_generation bigint,changed_at timestamptz,next_cursor bigint)
language plpgsql security definer set search_path=pg_catalog,public as $$
declare u uuid; capped integer:=greatest(1,least(coalesce(p_limit,100),500));
begin u:=public.sanie_require_generation(p_generation); return query with page as(select c.* from public.sync_changes c where c.user_id=u and c.sequence>greatest(0,coalesce(p_after_cursor,0)) order by c.sequence limit capped), last as(select coalesce(max(page.sequence),p_after_cursor,0) v from page) select p.sequence,p.entity_type,p.entity_id,p.operation,p.entity_version,p.data_generation,p.changed_at,(select v from last) from page p order by p.sequence; end $$;
grant execute on function public.pull_changes(bigint,integer,bigint) to authenticated;
