# Financial RPC contract

All public commands authenticate with `auth.uid()`, require the client data
generation, derive ownership server-side, and return stable application errors
through PostgreSQL exception message identifiers: `UNAUTHENTICATED`,
`VALIDATION_ERROR`, `FORBIDDEN_REFERENCE`, `NOT_FOUND`, `INSUFFICIENT_FUNDS`,
`CONFLICT`, `IDEMPOTENCY_MISMATCH`, `DATA_GENERATION_MISMATCH`,
`BUDGET_SCOPE_OVERLAP`, `RECURRING_REVIEW_REQUIRED`, and `INVALID_STATE`.

`create_income` and `create_expense` accept a client UUID record ID, account,
category, optional subcategory, amount, date, text fields, request UUID, and
generation. They return canonical transaction/account data and replay safely.

`create_transfer` accepts source/destination accounts, amount, optional fee and
fee category, date, description, request UUID, and generation. It atomically
creates one transfer plus at most one fee.

Goal contribution and ordinary transaction update/delete commands require a
base version. Financial conflicts never use last-write-wins.

Karobar commands preserve their distinct lending/payable model. Origin and
settlement commands enforce cash availability, outstanding limits, request
replay matching, version conflicts, and tombstone reversal. Credit purchases
use `create_credit_purchase`, `update_credit_purchase`, and
`delete_credit_purchase`: the payable and linked expense are atomic and do not
reduce a cash account at purchase time.

`process_recurring_occurrence` supports Generate/Skip for the current due
cursor. When another occurrence is already overdue it returns
`RECURRING_REVIEW_REQUIRED`; the reviewed overload accepts an explicit boolean
confirmation. Month and year calculations retain their original day anchor,
including February and leap-year clamping. Generated occurrence identity uses
a generated transaction UUID and database uniqueness on `(user, definition,
occurrence date)`.

Budget creation uses `create_budget`, which converts the PostgreSQL exclusion
violation into `BUDGET_SCOPE_OVERLAP`.

`fresh_start` currently creates the generation boundary only. It deliberately
does not enable irreversible data deletion until reset scope is reviewed.
