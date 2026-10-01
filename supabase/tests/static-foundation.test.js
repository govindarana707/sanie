// Static contract checks are intentionally dependency-free. Integration tests
// should be run with `supabase db test` once a local Supabase runtime exists.
const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '..');
const read = name => fs.readFileSync(path.join(root, 'migrations', name), 'utf8');
const schema = read('20261001183002_core_domain_schema.sql');
const constraints = read('20261001183003_constraints_indexes_and_triggers.sql');
const rls = read('20261001183004_rls_policies.sql');
const rpc = read('20261001183005_financial_rpcs.sql');
const sync = read('20261001183006_change_feed_and_sync.sql');
const hardening = read('20261001183007_column_privileges_and_hardening.sql');
const phase1c = read('20261001183010_phase1c_financial_contracts.sql');
const privileges = read('20261001183011_function_execute_hardening.sql');
const derived = read('20261001183012_derived_insert_protection.sql');
const recurring = read('20261001183013_recurring_parity.sql');
const karobar = read('20261001183014_karobar_integrity.sql');
const replay = read('20261001183015_financial_replay_integrity.sql');
const detailedUpdate = read('20261001183016_transaction_update_details.sql');

for (const table of ['profiles','accounts','categories','subcategories','transactions','budgets','goals','people','karobar_transactions','recurring_transactions','tasks','notifications','attachments']) {
  if (!schema.includes(`public.${table}`)) throw new Error(`missing ${table}`);
}
for (const token of ['numeric(15,2)', 'client_request_id uuid', 'transfer_parent_id uuid', 'recurring_occurrence_date date']) {
  if (!schema.includes(token)) throw new Error(`missing financial schema contract: ${token}`);
}
if (!constraints.includes('exclude using gist') || !constraints.includes('daterange(start_date,end_date')) throw new Error('budget range overlap protection missing');
if (!constraints.includes('transactions_request_idx') || !constraints.includes('transactions_occurrence_idx')) throw new Error('idempotency constraints missing');
for (const table of ['accounts','transactions','karobar_transactions']) {
  if (!rls.includes(`alter table public.${table} enable row level security`)) throw new Error(`RLS missing for ${table}`);
}
if (!rls.includes('create policy transactions_read') || rls.includes('transactions_write')) throw new Error('financial direct-write boundary invalid');
for (const token of ['revoke update on public.accounts', 'revoke update on public.goals', 'revoke all on public.sync_changes']) {
  if (!hardening.includes(token)) throw new Error(`derived-field hardening missing: ${token}`);
}
for (const fn of ['create_income','create_expense','create_transfer','update_transaction','delete_transaction','create_goal_contribution','update_goal_contribution','delete_goal_contribution','create_karobar_transaction','update_karobar_transaction','delete_karobar_transaction','process_recurring_occurrence','update_account_opening_balance','fresh_start']) {
  if (!rpc.includes(`function public.${fn}`)) throw new Error(`missing public RPC ${fn}`);
}
if (!rpc.includes('auth.uid()') || !rpc.includes('DATA_GENERATION_MISMATCH') || !rpc.includes('set search_path')) throw new Error('RPC hardening contract incomplete');
for (const fn of ['create_credit_purchase','update_credit_purchase','delete_credit_purchase','create_budget']) {
  if (!phase1c.includes(`function public.${fn}`)) throw new Error(`missing Phase 1C RPC ${fn}`);
}
if (!phase1c.includes('BUDGET_SCOPE_OVERLAP') || !phase1c.includes('IDEMPOTENCY_MISMATCH')) throw new Error('stable Phase 1C error contract missing');
if (!privileges.includes('revoke execute on all functions in schema public from public, anon, authenticated')) throw new Error('function execute hardening missing');
if (!derived.includes('sanie_initialize_account_balance') || !derived.includes('sanie_initialize_goal_amount')) throw new Error('derived insert protection missing');
if (!recurring.includes('RECURRING_REVIEW_REQUIRED') || !recurring.includes('least(anchor_day,month_end)')) throw new Error('recurrence parity contract missing');
if (!karobar.includes("p_type in ('returned','repaid')") || !karobar.includes('IDEMPOTENCY_MISMATCH')) throw new Error('Karobar integrity contract missing');
if (!replay.includes('existing.description is not distinct from p_description')) throw new Error('full financial replay comparison missing');
if (!detailedUpdate.includes('perform public.sanie_rebuild_account(old.account_id,u)')) throw new Error('detailed transaction rebuild missing');
for (const token of ['sync_changes','generated always as identity','pull_changes','sequence>greatest']) {
  if (!sync.includes(token)) throw new Error(`sync contract missing ${token}`);
}
console.log('PASS: SanIE Supabase foundation static contract');
