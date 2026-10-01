# Sync contract

The device stores a user UUID and `data_generation` with every outbox command.
Before applying any command, the server compares it with `profiles` and rejects
stale devices using `DATA_GENERATION_MISMATCH`.

Push financial commands use RPC with `client_request_id`; retries must reuse it.
After acknowledgement, clients save the returned canonical rows, then call
`pull_changes(after_cursor, limit, generation)`. `sync_changes.sequence` is the
authoritative monotonic cursor. Realtime may later reduce latency but is never
the correctness mechanism.

Tombstones are returned as change-feed operations. Clients retain them until
the future retention/purge policy permits deletion. Canonical balance plus
pending local command effects produces the offline projected balance; clients
never upload a calculated balance.
