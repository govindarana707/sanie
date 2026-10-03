-- Category metadata has one authenticated mutation boundary. Existing rows and
-- historical financial references remain in place when archived.
revoke insert, update, delete on public.categories, public.subcategories from authenticated;

create table sanie.category_mutation_receipts (
  user_id uuid not null references public.profiles(id) on delete cascade,
  request_id uuid not null,
  operation text not null,
  entity_id uuid not null,
  payload jsonb not null,
  created_at timestamptz not null default now(),
  primary key (user_id, request_id)
);
revoke all on sanie.category_mutation_receipts from public, anon, authenticated;

create or replace function public.sanie_mutate_category(
  p_entity text, p_action text, p_id uuid, p_parent uuid,
  p_name text, p_type text, p_icon text, p_description text,
  p_base_version bigint, p_request uuid, p_generation bigint
) returns jsonb language plpgsql security definer set search_path=pg_catalog,public as $$
declare
  u uuid; clean_name text:=trim(p_name); clean_icon text:=nullif(trim(p_icon),'');
  clean_description text:=nullif(trim(p_description),'');
  op text:=p_action||'_'||p_entity; payload jsonb;
  receipt sanie.category_mutation_receipts%rowtype;
  cat public.categories%rowtype; sub public.subcategories%rowtype;
  result jsonb;
begin
  u:=public.sanie_require_generation(p_generation);
  if p_entity not in ('category','subcategory') or p_action not in ('create','update','archive') or
     p_id is null or p_request is null or
     (p_action='create' and p_base_version is not null) or
     (p_action<>'create' and (p_base_version is null or p_base_version<1)) or
     (p_action<>'archive' and (clean_name is null or char_length(clean_name) not between 1 and 100)) or
     char_length(clean_icon)>100 or char_length(clean_description)>1000 or
     (p_entity='category' and p_action='create' and (p_type is null or p_type not in ('income','expense'))) or
     (p_entity='subcategory' and p_action='create' and p_parent is null) then
    raise exception using errcode='P0001',message='VALIDATION_ERROR';
  end if;
  payload:=jsonb_build_object('id',p_id,'parent',p_parent,'name',clean_name,
    'type',p_type,'icon',clean_icon,'description',clean_description,'base_version',p_base_version);
  select * into receipt from sanie.category_mutation_receipts
    where user_id=u and request_id=p_request;
  if found then
    if receipt.operation<>op or receipt.entity_id<>p_id or receipt.payload<>payload then
      raise exception using errcode='P0001',message='IDEMPOTENCY_MISMATCH';
    end if;
    if p_entity='category' then
      select to_jsonb(c) into result from public.categories c where c.id=p_id and c.user_id=u;
    else
      select to_jsonb(s) into result from public.subcategories s where s.id=p_id and s.user_id=u;
    end if;
    return jsonb_build_object('entity',result,'replayed',true);
  end if;
  if p_entity='category' then
    if p_action='create' then
      if exists(select 1 from public.categories where id=p_id) then
        raise exception using errcode='P0001',message='CONFLICT';
      end if;
      insert into public.categories(id,user_id,name,category_type,icon,description,is_system)
        values(p_id,u,clean_name,p_type::public.sanie_category_type,clean_icon,clean_description,false)
        returning * into cat;
    else
      select * into cat from public.categories where id=p_id and user_id=u and not is_system
        and deleted_at is null and status='active' for update;
      if not found then raise exception using errcode='P0001',message='NOT_FOUND'; end if;
      if cat.version<>p_base_version then raise exception using errcode='P0001',message='CONFLICT'; end if;
      if p_action='update' then
        if p_type is null or p_type not in ('income','expense') then
          raise exception using errcode='P0001',message='VALIDATION_ERROR';
        end if;
        update public.categories set name=clean_name,category_type=p_type::public.sanie_category_type,
          icon=clean_icon,description=clean_description where id=p_id returning * into cat;
      else
        update public.categories set status='archived' where id=p_id returning * into cat;
      end if;
    end if;
    result:=to_jsonb(cat);
  else
    if p_action='create' then
      if exists(select 1 from public.subcategories where id=p_id) then
        raise exception using errcode='P0001',message='CONFLICT';
      end if;
      perform public.sanie_assert_owned('public.categories',p_parent,u,true);
      if not exists(select 1 from public.categories where id=p_parent and status='active' and deleted_at is null) then
        raise exception using errcode='P0001',message='INVALID_STATE';
      end if;
      insert into public.subcategories(id,user_id,category_id,name,icon,description)
        values(p_id,u,p_parent,clean_name,clean_icon,clean_description) returning * into sub;
    else
      select * into sub from public.subcategories where id=p_id and user_id=u
        and deleted_at is null and status='active' for update;
      if not found then raise exception using errcode='P0001',message='NOT_FOUND'; end if;
      if sub.version<>p_base_version then raise exception using errcode='P0001',message='CONFLICT'; end if;
      if p_parent is distinct from sub.category_id then
        raise exception using errcode='P0001',message='VALIDATION_ERROR';
      end if;
      perform public.sanie_assert_owned('public.categories',sub.category_id,u,true);
      if not exists(select 1 from public.categories where id=sub.category_id and status='active' and deleted_at is null) then
        raise exception using errcode='P0001',message='INVALID_STATE';
      end if;
      if p_action='update' then
        update public.subcategories set name=clean_name,icon=clean_icon,description=clean_description
          where id=p_id returning * into sub;
      else
        update public.subcategories set status='archived' where id=p_id returning * into sub;
      end if;
    end if;
    result:=to_jsonb(sub);
  end if;
  insert into sanie.category_mutation_receipts(user_id,request_id,operation,entity_id,payload)
    values(u,p_request,op,p_id,payload);
  return jsonb_build_object('entity',result,'replayed',false);
end $$;

create or replace function public.create_category(p_id uuid,p_name text,p_type text,p_icon text,p_description text,p_request uuid,p_generation bigint)
returns jsonb language sql security definer set search_path=pg_catalog,public as $$
  select public.sanie_mutate_category('category','create',p_id,null,p_name,p_type,p_icon,p_description,null,p_request,p_generation)
$$;
create or replace function public.update_category(p_id uuid,p_name text,p_type text,p_icon text,p_description text,p_base_version bigint,p_request uuid,p_generation bigint)
returns jsonb language sql security definer set search_path=pg_catalog,public as $$
  select public.sanie_mutate_category('category','update',p_id,null,p_name,p_type,p_icon,p_description,p_base_version,p_request,p_generation)
$$;
create or replace function public.archive_category(p_id uuid,p_base_version bigint,p_request uuid,p_generation bigint)
returns jsonb language sql security definer set search_path=pg_catalog,public as $$
  select public.sanie_mutate_category('category','archive',p_id,null,null,null,null,null,p_base_version,p_request,p_generation)
$$;
create or replace function public.create_subcategory(p_id uuid,p_category uuid,p_name text,p_icon text,p_description text,p_request uuid,p_generation bigint)
returns jsonb language sql security definer set search_path=pg_catalog,public as $$
  select public.sanie_mutate_category('subcategory','create',p_id,p_category,p_name,null,p_icon,p_description,null,p_request,p_generation)
$$;
create or replace function public.update_subcategory(p_id uuid,p_category uuid,p_name text,p_icon text,p_description text,p_base_version bigint,p_request uuid,p_generation bigint)
returns jsonb language sql security definer set search_path=pg_catalog,public as $$
  select public.sanie_mutate_category('subcategory','update',p_id,p_category,p_name,null,p_icon,p_description,p_base_version,p_request,p_generation)
$$;
create or replace function public.archive_subcategory(p_id uuid,p_category uuid,p_base_version bigint,p_request uuid,p_generation bigint)
returns jsonb language sql security definer set search_path=pg_catalog,public as $$
  select public.sanie_mutate_category('subcategory','archive',p_id,p_category,null,null,null,null,p_base_version,p_request,p_generation)
$$;

revoke execute on function public.sanie_mutate_category(text,text,uuid,uuid,text,text,text,text,bigint,uuid,bigint),
  public.create_category(uuid,text,text,text,text,uuid,bigint),
  public.update_category(uuid,text,text,text,text,bigint,uuid,bigint),
  public.archive_category(uuid,bigint,uuid,bigint),
  public.create_subcategory(uuid,uuid,text,text,text,uuid,bigint),
  public.update_subcategory(uuid,uuid,text,text,text,bigint,uuid,bigint),
  public.archive_subcategory(uuid,uuid,bigint,uuid,bigint) from public,anon,authenticated;
grant execute on function public.create_category(uuid,text,text,text,text,uuid,bigint),
  public.update_category(uuid,text,text,text,text,bigint,uuid,bigint),
  public.archive_category(uuid,bigint,uuid,bigint),
  public.create_subcategory(uuid,uuid,text,text,text,uuid,bigint),
  public.update_subcategory(uuid,uuid,text,text,text,bigint,uuid,bigint),
  public.archive_subcategory(uuid,uuid,bigint,uuid,bigint) to authenticated;
