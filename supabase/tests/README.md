# Supabase test strategy

`static-foundation.test.js` guards the reviewable migration contract.

`run-local-integration.ps1` executes the real SQL suites inside the local
Supabase PostgreSQL container. The suites cover RLS identities, cross-user
references, protected derived fields, financial RPCs, rebuilds, Karobar,
recurrence calendars and backlog review, budget ranges, and change-feed
pagination and isolation.

`budget-concurrency.test.js` opens two local PostgreSQL sessions and proves
that the database exclusion constraint permits at most one overlapping budget.
All fixtures are synthetic and local.
