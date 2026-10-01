-- Keep the user's day-of-month or start-date anchor through short months.
alter table public.recurring_transactions drop constraint recurring_transactions_day_of_week_check;
alter table public.recurring_transactions add constraint recurring_transactions_day_of_week_check check(day_of_week between 1 and 7);

create or replace function public.sanie_next_occurrence(r public.recurring_transactions)
returns date language plpgsql immutable set search_path=pg_catalog as $$
declare target_month date; anchor_day integer; month_end integer;
begin
 if r.next_occurrence is null then return null; end if;
 if r.frequency='daily' then return r.next_occurrence+1; end if;
 if r.frequency='weekly' then return r.next_occurrence+7; end if;
 if r.frequency='bi_weekly' then return r.next_occurrence+14; end if;
 if r.frequency='monthly' or r.frequency='quarterly' then
   target_month:=(date_trunc('month',r.next_occurrence)::date + case when r.frequency='monthly' then interval '1 month' else interval '3 months' end)::date;
   anchor_day:=r.day_of_month;
 else
   target_month:=make_date(extract(year from r.next_occurrence)::integer+1,extract(month from r.start_date)::integer,1);
   anchor_day:=extract(day from r.start_date)::integer;
 end if;
 if anchor_day is null then raise exception using errcode='P0001',message='VALIDATION_ERROR'; end if;
 month_end:=extract(day from (date_trunc('month',target_month)::date+interval '1 month - 1 day'))::integer;
 return make_date(extract(year from target_month)::integer,extract(month from target_month)::integer,least(anchor_day,month_end));
end $$;

-- The four-argument command is the safe automatic path. A five-argument call
-- with confirmed review permits a deliberate choice for an overdue backlog.
create or replace function public.process_recurring_occurrence(p_definition uuid,p_date date,p_action text,p_generation bigint,p_review_confirmed boolean)
returns jsonb language plpgsql security definer set search_path=pg_catalog,public as $$
declare u uuid; r public.recurring_transactions%rowtype; nid uuid; next_date date;
begin
 u:=public.sanie_require_generation(p_generation);
 select * into r from public.recurring_transactions where id=p_definition and user_id=u and deleted_at is null and is_active for update;
 if not found then raise exception using errcode='P0001',message='NOT_FOUND'; end if;
 if p_action not in ('generate','skip') or r.next_occurrence is distinct from p_date or p_date>current_date then raise exception using errcode='P0001',message='INVALID_STATE'; end if;
 next_date:=public.sanie_next_occurrence(r);
 if next_date<=current_date and not coalesce(p_review_confirmed,false) then raise exception using errcode='P0001',message='RECURRING_REVIEW_REQUIRED'; end if;
 if p_action='skip' then
   update public.recurring_transactions set next_occurrence=case when end_date is not null and next_date>end_date then null else next_date end where id=p_definition;
   return jsonb_build_object('status','skipped','next_occurrence',next_date);
 end if;
 if exists(select 1 from public.transactions where user_id=u and recurring_definition_id=p_definition and recurring_occurrence_date=p_date and deleted_at is null) then
   update public.recurring_transactions set next_occurrence=next_date where id=p_definition;
   return jsonb_build_object('status','already_processed');
 end if;
 nid:=gen_random_uuid();
 perform public.sanie_create_ordinary(nid,r.transaction_type,r.account_id,r.category_id,r.subcategory_id,r.amount,p_date,r.description,'recurring',nid,p_generation);
 update public.transactions set recurring_definition_id=p_definition,recurring_occurrence_date=p_date where id=nid;
 update public.recurring_transactions set next_occurrence=case when end_date is not null and next_date>end_date then null else next_date end where id=p_definition;
 return jsonb_build_object('status','generated','transaction_id',nid,'next_occurrence',next_date);
end $$;

create or replace function public.process_recurring_occurrence(p_definition uuid,p_date date,p_action text,p_generation bigint)
returns jsonb language sql security definer set search_path=pg_catalog,public as $$
 select public.process_recurring_occurrence(p_definition,p_date,p_action,p_generation,false)
$$;
revoke execute on function public.process_recurring_occurrence(uuid,date,text,bigint,boolean) from public,anon;
grant execute on function public.process_recurring_occurrence(uuid,date,text,bigint,boolean) to authenticated;
