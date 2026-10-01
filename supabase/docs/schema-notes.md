# SanIE Supabase schema notes

Flutter will create UUIDv7 values offline. PostgreSQL uses `pgcrypto` only for
server-originated fallback UUIDv4 values because `uuid_generate_v7()` is not
universally available in Supabase PostgreSQL installations. UUID version is not
used as an authorization signal.

`accounts.balance` and `goals.current_amount` are server-maintained caches.
They are rebuilt from active ledger rows by internal functions; no RLS policy
allows clients to write transactions or Karobar rows directly.

Migration metadata is held in the non-exposed `sanie` schema. It contains no
legacy production data in this phase.
