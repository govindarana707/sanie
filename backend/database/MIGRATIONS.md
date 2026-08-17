# SanIE database migration policy

## Supported paths

- Fresh installations import `schema.sql`. Historical migrations are not run
  after a fresh import.
- Existing installations based on the repository's August 10, 2026 database
  dump run `php migrate.php` once per release. The runner is state-aware,
  records a checksum in `schema_migrations`, and is safe to retry after an
  interrupted reconciliation.
- Regression tests reconstruct that supported starting structure from
  `fixtures/august-2026-baseline-schema.sql`. The fixture is DDL-only and
  intentionally contains no exported user rows or password hashes.
- Phase 2's supported order is therefore:
  1. August 10, 2026 baseline (or an existing database already beyond it).
  2. `SchemaMigrator::MIGRATION_ID` through `migrate.php`.
  3. `SchemaMigrator::PHASE3_MIGRATION_ID` through the same `migrate.php`
     runner.
  4. `SchemaMigrator::PHASE4_MIGRATION_ID` through the same state-aware
     runner.
  5. `SchemaMigrator::PHASE5_MIGRATION_ID` through the same state-aware
     runner.
  6. `SchemaMigrator::PHASE8_MIGRATION_ID` through the same state-aware
     runner.
  7. `SchemaMigrator::PHASE9_MIGRATION_ID` through the same state-aware
     runner.
  8. `SchemaMigrator::PHASE10_MIGRATION_ID` through the same state-aware
     runner.
  9. `SchemaMigrator::PHASE11_MIGRATION_ID` through the same state-aware
     runner.

The runner adds missing application-required columns and relationships,
checks for orphaned foreign-key values and duplicate idempotency keys before
adding constraints, and never drops a financial table or history row.

## Legacy SQL inventory

The older SQL files document how the live schema evolved. They are retained
for provenance, but they are not an ordered migration chain and must not be
blindly replayed on a current database.

| File | Dependency / purpose | Repeat safety |
|---|---|---|
| `migration_accounts.sql` | `users`; originally created `accounts` | `CREATE TABLE IF NOT EXISTS` does not reconcile an existing table; legacy only |
| `migration_account_statement.sql` | `accounts`; added `opening_balance` | Not repeat-safe |
| `migration_categories.sql` | `users`, `categories`; added category state and subcategories | Contains seed assumptions and unsupported/legacy conditional DDL; not a supported runner |
| `migration_karobar.sql` | `users`, `accounts`; created people/Karobar | Creation-safe only; does not upgrade an existing table |
| `migration_credit_udharo.sql` | Karobar and transactions must both exist | Not repeat-safe |
| `migration_transfer_support.sql` | `transactions`, `accounts` | Not repeat-safe; performs deterministic from/to backfills |
| `migration_category_null.sql` | transactions category FK with a specific historical name | Not repeat-safe and name-sensitive |
| `migration_notifications_system.sql` | `users`; rebuilt notifications | Destructive (`DROP TABLE`); retired from supported upgrades |
| `migration_remove_unused_transaction_columns.sql` | historical transactions shape | Destructive column removal; retired from supported upgrades |
| `migration_account_savings_flag.sql` | `accounts` | State-aware; backfills the new flag for savings accounts |
| `migration_20260810_user_dump_compatibility.sql` | August 2026 users dump | Intended compatibility patch but uses server-dependent conditional DDL; superseded by the runner |
| `migration_transaction_idempotency.sql` | `transactions` | Not repeat-safe; superseded by the runner |
| `migration_transaction_version.sql` | transaction idempotency column | Not repeat-safe; superseded by the runner |
| `migration_karobar_payment_idempotency.sql` | Karobar payment fields from Phase 1 | State-aware and repeat-safe |

## Data changes made by the supported runner

- Existing rows are never deleted.
- New columns receive non-destructive defaults.
- `include_in_savings` is set to `1` for existing accounts whose type is
  `savings`.
- Existing income/expense/transfer rows with empty direction columns are
  backfilled from their existing `account_id`, matching the historical
  transfer migration.
- Duplicate scoped request IDs and orphaned relationships stop the migration
  with an actionable error; the runner never guesses which financial row to
  delete or rewrite.
- Phase 3 adds nullable `transactions.transfer_parent_id`. A fee transaction
  references its canonical transfer row through this column. A scoped unique
  index permits at most one fee per transfer, and an `ON DELETE CASCADE`
  self-reference prevents a linked fee from surviving its parent.
- Historical rows are not linked by amount, date, description, or account
  names. Such inference is ambiguous; existing unlinked rows are preserved.
- Phase 4 adds `goals.initial_amount` and `goals.version`, plus nullable
  `transactions.goal_id` with an index and `ON DELETE RESTRICT` foreign key.
  The transaction type enum gains `goal_contribution`. Existing saved goal
  amounts become the goal's initial amount, preserving data without guessing
  whether historical values came from a contribution or manual initialization.
- Historical expense transactions are never linked to goals by their amount,
  date, description, goal name, or account name. New contributions use the
  durable `goal_id` relationship and rebuild `current_amount` from
  `initial_amount + SUM(linked goal contributions)`.
- Phase 5 adds `karobar_transactions.version` and scoped unique indexes on
  both existing credit-purchase link directions. These constraints make each
  new linked expense/payable pair one-to-one. Historical records are not
  fuzzy-linked; duplicate non-null links stop the migration for explicit
  review rather than guessing which financial row is canonical.

## Phase 8 person-history protection

- `karobar_transactions.person_id -> people.id` uses `ON DELETE RESTRICT`.
- The runner discovers the installed constraint name instead of assuming the
  name generated by a particular MySQL environment.
- Before replacing a legacy cascade it verifies that the child and parent
  types match and that no orphaned person references exist. Inconsistent data
  stops the migration with an actionable error; no financial row is deleted
  or detached.
- The application hard-deletes only people with no Karobar rows. People with
  any active or settled financial history are archived using the existing
  `people.status` field.

## Phase 9 budget-classification integrity

- `budgets.category_id -> categories.id` and
  `budgets.subcategory_id -> subcategories.id` use `ON DELETE RESTRICT`.
- The runner discovers installed constraint names, verifies compatible column
  types, and refuses to replace a constraint if orphaned references exist.
- Category or subcategory deletion can no longer silently turn a scoped budget
  into an overall budget. Existing classifications and financial rows are
  preserved, and the migration remains safe to retry.

## Phase 10 report and ledger pagination

- Adds `(user_id, date, created_at)` for deterministic transaction report and
  ledger range scans.
- Adds `(user_id, transaction_date, created_at)` for account-linked Karobar
  ledger event scans.
- No report or ledger rows are rewritten. The indexes support bounded page,
  count, aggregate, and running-balance queries and are safe to add repeatedly.

## Phase 11 credential and session versioning

- Adds `users.token_version` with a non-null default of `1`. Every issued JWT
  carries the current value and authenticated middleware rejects older values.
- Adds nullable `users.password_changed_at` for credential lifecycle auditing
  without storing password material.
- Existing JWTs without a version are intentionally invalid after deployment;
  no user password hash or financial row is rewritten.
