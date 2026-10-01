-- SanIE 2.0 foundation. UUIDs are supplied by clients (UUIDv7 in Flutter).
-- gen_random_uuid() is only a server-side UUIDv4 fallback because UUIDv7 is
-- not guaranteed by every Supabase PostgreSQL release.
create extension if not exists pgcrypto;
create extension if not exists btree_gist;

create schema if not exists sanie;

create or replace function public.sanie_set_updated_at()
returns trigger language plpgsql set search_path = pg_catalog as $$
begin new.updated_at = now(); return new; end $$;

create table if not exists public.profiles (
  id uuid primary key references auth.users(id) on delete cascade,
  first_name text not null check (length(trim(first_name)) between 1 and 100),
  last_name text,
  phone text,
  avatar_path text,
  currency char(3) not null default 'NPR' check (currency ~ '^[A-Z]{3}$'),
  language text not null default 'en' check (length(language) between 2 and 20),
  theme text not null default 'light' check (theme in ('light','dark','system')),
  notification_preferences jsonb not null default '{}'::jsonb,
  settings jsonb not null default '{}'::jsonb,
  data_generation bigint not null default 1 check (data_generation > 0),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create or replace function public.sanie_create_profile()
returns trigger language plpgsql security definer set search_path = pg_catalog, public as $$
begin
  insert into public.profiles (id, first_name, last_name)
  values (new.id,
          coalesce(nullif(trim(new.raw_user_meta_data ->> 'first_name'), ''), 'SanIE User'),
          nullif(trim(new.raw_user_meta_data ->> 'last_name'), ''))
  on conflict (id) do nothing;
  return new;
end $$;

drop trigger if exists sanie_on_auth_user_created on auth.users;
create trigger sanie_on_auth_user_created after insert on auth.users
for each row execute function public.sanie_create_profile();

drop trigger if exists sanie_profiles_updated_at on public.profiles;
create trigger sanie_profiles_updated_at before update on public.profiles
for each row execute function public.sanie_set_updated_at();

create table if not exists sanie.migration_runs (
  id uuid primary key default gen_random_uuid(), source_name text not null,
  started_at timestamptz not null default now(), completed_at timestamptz,
  status text not null default 'planned' check (status in ('planned','running','validated','completed','failed')),
  notes text
);
create table if not exists sanie.legacy_user_map (
  legacy_user_id bigint primary key, supabase_user_id uuid unique references public.profiles(id),
  email text, data_migration_status text not null default 'pending',
  credential_migration_status text not null default 'pending', migrated_at timestamptz, notes text
);
create table if not exists sanie.legacy_id_map (
  entity_type text not null, legacy_id bigint not null, new_uuid uuid not null,
  legacy_user_id bigint, migration_run_id uuid references sanie.migration_runs(id), created_at timestamptz not null default now(),
  primary key (entity_type, legacy_id), unique (entity_type, new_uuid)
);
