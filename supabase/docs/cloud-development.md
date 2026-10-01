# Cloud development verification

Use only a dedicated non-production project. Link it locally with the Supabase
CLI; the resulting local runtime state is ignored and must never be committed.
SanIE cloud work uses the dedicated `sanie-dev` project only; it is never a
production deployment workflow.

Cloud projects may not provide the local development table grants implicitly.
Migration `20261001183017_cloud_authenticated_table_privileges.sql` makes the
RLS-authorized read and ordinary metadata operations explicit for
`authenticated`. It does not grant transaction or Karobar writes, budget direct
writes, derived financial fields, internal-table access, `anon`, or `PUBLIC`.
Cloud default grants can also include non-DML privileges such as `TRUNCATE`,
`TRIGGER`, `REFERENCES`, and `MAINTAIN`. Migration
`20261001183018_cloud_exact_table_privileges.sql` removes all inherited client
table privileges and rebuilds the exact RLS-governed table/column surface.

PostgREST RPC calls use named SQL parameters. In particular, `create_income`
and `create_expense` require `p_client_request_id`; sending `p_request` returns
PostgREST `PGRST202`. This is a client invocation error, not a schema-cache or
database portability issue.

The sync contract uses `insert`, `update`, and `tombstone` operations. Clients
must retain tombstones until a future retention policy permits their removal;
they must not expect a `delete` change-feed operation.

Verify a cloud project with disposable Auth users, authenticated Data API calls,
RPC replay/error checks, cursor pagination, and a privilege audit. Storage
binary policy is intentionally later scope: attachment metadata is protected,
but no public Storage bucket is created here. Only publishable/anon client
configuration may ever reach a client; service-role keys, access tokens,
database passwords, JWT secrets, and private connection strings remain server
or local-test secrets.

Keep access tokens, service-role keys, database passwords, and private URLs out
of Git. Disposable test identities and data must be removed without resetting a
remote project.
