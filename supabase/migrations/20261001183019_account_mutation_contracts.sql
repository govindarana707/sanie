-- Private replay receipts make account metadata mutations safe after a lost
-- response. The profile row lock in sanie_require_generation serializes a
-- user's requests, including concurrent replays.
create table sanie.account_mutation_receipts (
  user_id uuid not null references public.profiles(id) on delete cascade,
  request_id uuid not null,
  operation text not null check (operation in ('create_account','update_account','archive_account')),
  account_id uuid not null,
  payload jsonb not null,
  created_at timestamptz not null default now(),
  primary key (user_id, request_id)
);
revoke all on sanie.account_mutation_receipts from public, anon, authenticated;

create or replace function public.create_account(
  p_id uuid, p_name text, p_type text, p_opening numeric,
  p_request uuid, p_generation bigint
) returns jsonb language plpgsql security definer set search_path=pg_catalog,public as $$
declare
  u uuid; clean_name text:=trim(p_name); payload jsonb;
  receipt sanie.account_mutation_receipts%rowtype; result public.accounts%rowtype;
begin
  u:=public.sanie_require_generation(p_generation);
  if p_id is null or p_request is null or clean_name is null or
     char_length(clean_name) not between 1 and 100 or
     p_type is null or p_type not in
       ('cash','bank','esewa','khalti','ime_pay','wallet','credit_card','savings','current') or
     p_opening is null or p_opening::text='NaN' or scale(p_opening)>2 or
     abs(p_opening)>9999999999999.99 then
    raise exception using errcode='P0001',message='VALIDATION_ERROR';
  end if;
  payload:=jsonb_build_object('id',p_id,'name',clean_name,'type',p_type,'opening',p_opening);
  select * into receipt from sanie.account_mutation_receipts
    where user_id=u and request_id=p_request;
  if found then
    if receipt.operation<>'create_account' or receipt.account_id<>p_id or receipt.payload<>payload then
      raise exception using errcode='P0001',message='IDEMPOTENCY_MISMATCH';
    end if;
    return jsonb_build_object('account',(select to_jsonb(a) from public.accounts a where a.id=p_id and a.user_id=u),'replayed',true);
  end if;
  if exists(select 1 from public.accounts where id=p_id) then
    raise exception using errcode='P0001',message='CONFLICT';
  end if;
  insert into public.accounts(id,user_id,name,account_type,opening_balance)
    values(p_id,u,clean_name,p_type,p_opening) returning * into result;
  -- Existing insert trigger derives balance from the opening amount.
  insert into sanie.account_mutation_receipts(user_id,request_id,operation,account_id,payload)
    values(u,p_request,'create_account',p_id,payload);
  return jsonb_build_object('account',to_jsonb(result),'replayed',false);
end $$;

create or replace function public.update_account(
  p_id uuid, p_name text, p_type text, p_account_number text,
  p_base_version bigint, p_request uuid, p_generation bigint
) returns jsonb language plpgsql security definer set search_path=pg_catalog,public as $$
declare
  u uuid; clean_name text:=trim(p_name); clean_number text:=nullif(trim(p_account_number),'');
  payload jsonb; receipt sanie.account_mutation_receipts%rowtype;
  current_row public.accounts%rowtype; result public.accounts%rowtype;
begin
  u:=public.sanie_require_generation(p_generation);
  if p_id is null or p_request is null or p_base_version is null or p_base_version<1 or
     clean_name is null or char_length(clean_name) not between 1 and 100 or
     p_type is null or p_type not in
       ('cash','bank','esewa','khalti','ime_pay','wallet','credit_card','savings','current') or
     char_length(clean_number)>100 then
    raise exception using errcode='P0001',message='VALIDATION_ERROR';
  end if;
  payload:=jsonb_build_object('id',p_id,'name',clean_name,'type',p_type,
    'account_number',clean_number,'base_version',p_base_version);
  select * into receipt from sanie.account_mutation_receipts
    where user_id=u and request_id=p_request;
  if found then
    if receipt.operation<>'update_account' or receipt.account_id<>p_id or receipt.payload<>payload then
      raise exception using errcode='P0001',message='IDEMPOTENCY_MISMATCH';
    end if;
    return jsonb_build_object('account',(select to_jsonb(a) from public.accounts a where a.id=p_id and a.user_id=u),'replayed',true);
  end if;
  select * into current_row from public.accounts
    where id=p_id and user_id=u and deleted_at is null for update;
  if not found then raise exception using errcode='P0001',message='NOT_FOUND'; end if;
  if current_row.version<>p_base_version then
    raise exception using errcode='P0001',message='CONFLICT';
  end if;
  update public.accounts set name=clean_name,account_type=p_type,account_number=clean_number
    where id=p_id and user_id=u returning * into result;
  insert into sanie.account_mutation_receipts(user_id,request_id,operation,account_id,payload)
    values(u,p_request,'update_account',p_id,payload);
  return jsonb_build_object('account',to_jsonb(result),'replayed',false);
end $$;

create or replace function public.archive_account(
  p_id uuid, p_base_version bigint, p_request uuid, p_generation bigint
) returns jsonb language plpgsql security definer set search_path=pg_catalog,public as $$
declare
  u uuid; payload jsonb; receipt sanie.account_mutation_receipts%rowtype;
  current_row public.accounts%rowtype; result public.accounts%rowtype;
begin
  u:=public.sanie_require_generation(p_generation);
  if p_id is null or p_request is null or p_base_version is null or p_base_version<1 then
    raise exception using errcode='P0001',message='VALIDATION_ERROR';
  end if;
  payload:=jsonb_build_object('id',p_id,'base_version',p_base_version);
  select * into receipt from sanie.account_mutation_receipts
    where user_id=u and request_id=p_request;
  if found then
    if receipt.operation<>'archive_account' or receipt.account_id<>p_id or receipt.payload<>payload then
      raise exception using errcode='P0001',message='IDEMPOTENCY_MISMATCH';
    end if;
    return jsonb_build_object('account',(select to_jsonb(a) from public.accounts a where a.id=p_id and a.user_id=u),'replayed',true);
  end if;
  select * into current_row from public.accounts
    where id=p_id and user_id=u and deleted_at is null for update;
  if not found then raise exception using errcode='P0001',message='NOT_FOUND'; end if;
  if current_row.version<>p_base_version then
    raise exception using errcode='P0001',message='CONFLICT';
  end if;
  update public.accounts set is_active=false,deleted_at=now()
    where id=p_id and user_id=u returning * into result;
  insert into sanie.account_mutation_receipts(user_id,request_id,operation,account_id,payload)
    values(u,p_request,'archive_account',p_id,payload);
  return jsonb_build_object('account',to_jsonb(result),'replayed',false);
end $$;

revoke execute on function public.create_account(uuid,text,text,numeric,uuid,bigint),
  public.update_account(uuid,text,text,text,bigint,uuid,bigint),
  public.archive_account(uuid,bigint,uuid,bigint) from public,anon,authenticated;
grant execute on function public.create_account(uuid,text,text,numeric,uuid,bigint),
  public.update_account(uuid,text,text,text,bigint,uuid,bigint),
  public.archive_account(uuid,bigint,uuid,bigint) to authenticated;
