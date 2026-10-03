-- Normalize existing rows before enforcing one active default per user.
update public.accounts set is_default=false
 where is_default and (not is_active or deleted_at is not null);
with extras as (
 select id, row_number() over (partition by user_id order by created_at,id) as rank
 from public.accounts where is_default and is_active and deleted_at is null
)
update public.accounts a set is_default=false from extras e
 where a.id=e.id and e.rank>1;
with candidates as (
 select id, row_number() over (partition by user_id order by created_at,id) as rank
 from public.accounts a
 where a.is_active and a.deleted_at is null
   and not exists (
     select 1 from public.accounts d where d.user_id=a.user_id
       and d.is_default and d.is_active and d.deleted_at is null
   )
)
update public.accounts a set is_default=true from candidates c
 where a.id=c.id and c.rank=1;

create unique index accounts_one_active_default_per_user
 on public.accounts(user_id)
 where is_default and is_active and deleted_at is null;

-- The first usable account becomes default. Archiving or deactivating the
-- default hands it to the oldest remaining active account. RPC switches clear
-- the old default before setting the new one, so the unique index stays valid.
create or replace function public.sanie_account_default_insert()
returns trigger language plpgsql security definer set search_path=pg_catalog,public as $$
begin
 if not new.is_active or new.deleted_at is not null then
   new.is_default:=false;
 elsif not exists (
   select 1 from public.accounts a where a.user_id=new.user_id
     and a.is_default and a.is_active and a.deleted_at is null
 ) then new.is_default:=true; end if;
 return new;
end $$;
create trigger sanie_account_default_insert before insert on public.accounts
 for each row execute function public.sanie_account_default_insert();

create or replace function public.sanie_account_default_handoff()
returns trigger language plpgsql security definer set search_path=pg_catalog,public as $$
declare replacement uuid;
begin
 if old.is_default and old.is_active and old.deleted_at is null
    and (not new.is_active or new.deleted_at is not null) then
   if new.is_default then
     update public.accounts set is_default=false where id=new.id;
   end if;
   select a.id into replacement from public.accounts a
    where a.user_id=new.user_id and a.id<>new.id and a.is_active
      and a.deleted_at is null
    order by a.created_at,a.id limit 1;
   if replacement is not null and not exists (
     select 1 from public.accounts a where a.user_id=new.user_id
       and a.is_default and a.is_active and a.deleted_at is null
   ) then
     update public.accounts set is_default=true where id=replacement;
   end if;
 end if;
 return null;
end $$;
create trigger sanie_account_default_handoff after update of is_active,deleted_at
 on public.accounts for each row execute function public.sanie_account_default_handoff();
revoke execute on function public.sanie_account_default_insert(),
 public.sanie_account_default_handoff() from public,anon,authenticated;

-- Force settings changes through the guarded RPC. Existing metadata columns
-- retain their prior table privileges; balance remains protected.
revoke update on public.accounts from authenticated;
grant update (name,account_type,account_number,currency,color,icon,deleted_at)
 on public.accounts to authenticated;

alter table sanie.account_mutation_receipts
 drop constraint account_mutation_receipts_operation_check;
alter table sanie.account_mutation_receipts
 add constraint account_mutation_receipts_operation_check
 check (operation in ('create_account','update_account','archive_account','update_account_settings'));

create or replace function public.update_account_settings(
 p_id uuid, p_is_default boolean, p_include_in_net_balance boolean,
 p_include_in_savings boolean, p_is_active boolean,
 p_base_version bigint, p_request uuid, p_generation bigint
) returns jsonb language plpgsql security definer set search_path=pg_catalog,public as $$
declare
 u uuid; payload jsonb; receipt sanie.account_mutation_receipts%rowtype;
 current_row public.accounts%rowtype; result public.accounts%rowtype;
 replacement uuid;
begin
 u:=public.sanie_require_generation(p_generation);
 if p_id is null or p_request is null or p_base_version is null or p_base_version<1
    or p_is_default is null or p_include_in_net_balance is null
    or p_include_in_savings is null or p_is_active is null
    or (p_is_default and not p_is_active) then
   raise exception using errcode='P0001',message='VALIDATION_ERROR';
 end if;
 payload:=jsonb_build_object('id',p_id,'is_default',p_is_default,
   'include_in_net_balance',p_include_in_net_balance,
   'include_in_savings',p_include_in_savings,'is_active',p_is_active,
   'base_version',p_base_version);
 select * into receipt from sanie.account_mutation_receipts
  where user_id=u and request_id=p_request;
 if found then
   if receipt.operation<>'update_account_settings' or receipt.account_id<>p_id
      or receipt.payload<>payload then
     raise exception using errcode='P0001',message='IDEMPOTENCY_MISMATCH';
   end if;
   return jsonb_build_object('account',
     (select to_jsonb(a) from public.accounts a where a.id=p_id and a.user_id=u),
     'replayed',true);
 end if;
 select * into current_row from public.accounts
  where id=p_id and user_id=u and deleted_at is null for update;
 if not found then raise exception using errcode='P0001',message='NOT_FOUND'; end if;
 if current_row.version<>p_base_version then
   raise exception using errcode='P0001',message='CONFLICT';
 end if;
 if current_row.is_default and not p_is_default and p_is_active then
   select a.id into replacement from public.accounts a
    where a.user_id=u and a.id<>p_id and a.is_active and a.deleted_at is null
    order by a.created_at,a.id limit 1;
   if replacement is null then
     raise exception using errcode='P0001',message='INVALID_STATE';
   end if;
 end if;
 if p_is_active and not p_is_default and not current_row.is_default
    and not exists (
      select 1 from public.accounts a where a.user_id=u and a.id<>p_id
        and a.is_default and a.is_active and a.deleted_at is null
    ) then
   raise exception using errcode='P0001',message='INVALID_STATE';
 end if;
 if p_is_default then
   update public.accounts set is_default=false
    where user_id=u and id<>p_id and is_default and deleted_at is null;
 end if;
 update public.accounts set is_default=p_is_default,
   include_in_net_balance=p_include_in_net_balance,
   include_in_savings=p_include_in_savings,is_active=p_is_active
  where id=p_id and user_id=u returning * into result;
 if replacement is not null then
   update public.accounts set is_default=true where id=replacement;
 end if;
 insert into sanie.account_mutation_receipts(user_id,request_id,operation,account_id,payload)
  values(u,p_request,'update_account_settings',p_id,payload);
 return jsonb_build_object('account',to_jsonb(result),'replayed',false);
end $$;
revoke execute on function public.update_account_settings(uuid,boolean,boolean,boolean,boolean,bigint,uuid,bigint)
 from public,anon,authenticated;
grant execute on function public.update_account_settings(uuid,boolean,boolean,boolean,boolean,bigint,uuid,bigint)
 to authenticated;
