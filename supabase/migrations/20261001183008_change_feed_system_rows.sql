-- System categories are globally readable but are not user-owned sync records.
-- They must not enter a user-scoped feed with a NULL owner.
create or replace function public.sanie_record_change()
returns trigger language plpgsql security definer set search_path=pg_catalog,public as $$
declare gen bigint; op text;
begin
 if new.user_id is null then return new; end if;
 select data_generation into gen from public.profiles where id=new.user_id;
 op:=case when new.deleted_at is not null then 'tombstone' when tg_op='INSERT' then 'insert' else 'update' end;
 insert into public.sync_changes(user_id,entity_type,entity_id,operation,entity_version,data_generation)
 values(new.user_id,tg_table_name,new.id,op,new.version,gen);
 return new;
end $$;
