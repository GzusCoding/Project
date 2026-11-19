# Marketplace migration history and Workspace status

The migration source sequence is v1 through v6:

Migration scripts are stored in `migrations/`; run the commands below from the
project root.

| Version | Migration | Source status |
| --- | --- | --- |
| 1 | Products | `migrations/migrate_products.php` |
| 2 | Offers | `migrations/migrate_offers.php` |
| 3 | Exchange transactions, product reservations, and chat rooms | `migrations/migrate_exchange_transactions.php` |
| 4 | Chat messages | `migrations/migrate_chat_messages.php` |
| 5 | Payment evidence and transaction disputes | `migrations/migrate_payment_evidence.php` |
| 6 | Handover confirmations | `migrations/migrate_handover_confirmations.php` |

These are separate facts and must not be conflated:

- **Migration source exists:** the v6 script is present in the project.
- **Migration is recorded:** the audited Workspace Database has one record
  each for v1 through v5 and no record for v6.
- **Migration is verified as applied:** the read-only Workspace audit found
  the v5 schema but no `handover_confirmations` table, its v6 index, or its
  handover triggers. Therefore v6 is **not applied to the audited Workspace
  Database**. A migration script's presence alone does not establish that it
  ran.

The audited target was the default Workspace file
`hybrid_marketplace.sqlite` in the project directory; `DB_PATH` can override
that default in another runtime. These findings do not establish the state of
any other database. Production migration status is separate and unknown; no
Production Database was accessed.

The source order is v1 → v2 → v3 → v4 → v5 → v6. The v6 script requires
migration version 5 to be recorded exactly once. Reconcile and inspect the
intended Workspace target and complete the migration's preflight checks before
any separately authorized v6 execution. Do not start v7 or v8 before v6 has
been applied and verified on the intended target.

The proposed work remains separated as:

- **Proposed v7 — Lifecycle and Payment/Dispute Retirement**
- **Proposed v8 — Report System**

These labels and scopes are planning references, not finalized migration
numbers or authorization to create or apply either migration. Confirm the
intended target's migration records and schema, and verify the sequence after
v6, before finalizing deployable v7/v8 numbers.

## Schema

The `offers` table contains:

| Column | Meaning |
| --- | --- |
| `id` | Integer primary key |
| `creator_id`, `recipient_id` | Offer participants, both referencing `users.id` |
| `cash_payer_id` | Explicit payer reference; constrained to equal `creator_id` |
| `offered_product_id`, `target_product_id` | Product references |
| `cash_amount_minor` | Non-negative integer amount in minor units |
| `cash_currency`, `currency_exponent` | ISO 4217 code and its exponent for positive amounts |
| `status` | `pending`, `accepted`, `rejected`, `cancelled`, `superseded`, or `expired` |
| `created_at`, `updated_at` | Timestamps defaulting to the current time |
| `responded_at` | Nullable response timestamp |

All five foreign keys use `ON DELETE RESTRICT`. Creator and recipient must
differ, as must offered and target products. The schema deliberately has no
uniqueness rule for product pairs, so multiple pending offers for the same
ordered pair are allowed.

Indexes cover `(creator_id, status)`, `(recipient_id, status)`,
`(offered_product_id, status)`, `(target_product_id, status)`, and `created_at`.

### Currency rules

Amounts use SQLite `INTEGER` storage and never `REAL`/`FLOAT`. For a positive
amount, both currency fields are required and the code/exponent pair must match
the migration's controlled allowlist:

| Exponent | Supported codes |
| --- | --- |
| 0 | JPY, KRW, VND |
| 2 | AUD, CAD, CHF, CNY, EUR, GBP, HKD, INR, NZD, SGD, USD |
| 3 | BHD, KWD |

For a zero amount, both `cash_currency` and `currency_exponent` must be `NULL`.
No conversion or payment collection is performed. The creator remains the
recorded payer even when the cash amount is zero. Expanding the currency list
requires an explicit schema migration and coordinated validation updates.

## Running and safety

The migration is CLI-only, supports SQLite only, and resolves `DB_PATH` to the
`hybrid_marketplace.sqlite` file beside the script (the default). It checks the
existing users/products schema, enables and verifies SQLite foreign keys, asks
for the exact confirmation phrase `APPLY OFFERS MIGRATION 2`, and creates and
verifies an online backup before applying DDL. Backups are retained in the
private `.database-backups/` directory. The DDL and version record are applied
in one immediate transaction. Users and products are snapshotted before and
after; integrity and foreign-key checks run before success. A verified version
2 rerun is a no-op.

From the project directory, after reviewing the selected database and taking
the application offline:

```sh
DB_DRIVER=sqlite DB_PATH="$PWD/hybrid_marketplace.sqlite" php migrations/migrate_offers.php
```

Only run this against the intended database after checking the printed path and
typing the confirmation phrase. This procedure is documented, but this task's
validation uses only disposable temporary databases.

## Backup, rollback, and dependencies

The migration does not rewrite user or product rows. If rollback is required,
stop application access and restore the verified pre-migration SQLite backup
using an operationally approved restore procedure; restoring replaces the
whole database file and also discards any writes made after the backup.
Dropping `offers` and removing version 2 is an alternative only before any
offer data or dependent application code exists, and should be done from a
separately reviewed rollback procedure rather than ad hoc SQL.

Offer acceptance now records agreement and reserves both products; it does not
transfer ownership or deactivate products. Payment collection, handover
confirmation, final ownership transfer, and currency conversion remain out of
scope.

## Exchange transaction migration (version 3)

The CLI-only `migrations/migrate_exchange_transactions.php` adds `exchange_transactions`,
`product_reservations`, and `chat_rooms`. Transactions snapshot the accepted
offer's participants, products, creator-as-payer, and cash terms. Positive cash
starts at `awaiting_method`; zero cash starts at `not_required`. New transactions
start `in_progress`. The migration does not create records for historical
accepted offers.

The migration supports SQLite only, checks that version 2 is applied and that
the selected path is exactly the project database, and requires the exact
phrase `APPLY EXCHANGE TRANSACTION MIGRATION 3`. It creates and verifies a
private online backup, applies DDL and the version record atomically, and runs
integrity and foreign-key checks. A verified version 3 rerun is a no-op. Never
run it from a web request or against production as part of tests.

Review the selected path and take the application offline before an approved
deployment. The documented CLI form is:

```sh
DB_DRIVER=sqlite DB_PATH="$PWD/hybrid_marketplace.sqlite" php migrations/migrate_exchange_transactions.php
```

Tests execute a copied script against disposable SQLite databases only.

## Disposable tests

Run `php tests/offers_migration_test.php`. The test creates a temporary
directory, copies the CLI migration there, and uses a fixture database named
`hybrid_marketplace.sqlite`, so the migration's normal path guard is exercised
without opening or changing the project database.

## Chat messages migration (version 4)

`migrations/migrate_chat_messages.php` adds the SQLite-only `chat_messages` table,
`idx_chat_messages_room_created_id` on `(room_id, created_at, id)`, and database
triggers that reject inserts or sender/room changes unless the sender is one of
the room participants. Message bodies are constrained to 1–2000 characters.
Room and sender foreign keys both use `ON DELETE RESTRICT`. Migration version 3
must be present. The migration is CLI-only, checks that the resolved database
path is exactly `hybrid_marketplace.sqlite` beside the script, requires the
exact confirmation `APPLY CHAT MESSAGES MIGRATION 4`, verifies a private SQLite
online backup, and applies the schema and version record atomically. It checks
the final schema, integrity, and foreign keys; a verified version 4 rerun is a
no-op.

The deployment command is documented here but must only be run against the
intended database after an approved backup and maintenance window:

```sh
DB_DRIVER=sqlite DB_PATH="$PWD/hybrid_marketplace.sqlite" php migrations/migrate_chat_messages.php
```

The chat UI in `exchange_transaction.php` uses `chat_messages.php` for
participant-only JSON retrieval and sending. Polling requests only messages
after the latest message ID, every request rechecks transaction and room
membership, and message content is inserted as text in the browser. Sending is
permitted for `in_progress` and `disputed` transactions; completed and cancelled
transactions retain history but are read-only. Requests are CSRF-protected and
limited to 10 messages per sender per rolling minute.

Validate version 4 using `php tests/chat_system_test.php`; it copies the
migration into a temporary directory and executes it only against a disposable
SQLite database.

## Payment evidence and dispute migration (version 5)

`migrations/migrate_payment_evidence.php` is a CLI-only SQLite migration that requires
version 4. It rebuilds `exchange_transactions` in one immediate transaction to
add `disputed` to the transaction status CHECK and replace the old payment
status set with exactly `not_required`, `awaiting_method`, `awaiting_payment`,
`submitted`, `verified`, and `rejected`. The rebuild preserves transaction
values, the AUTOINCREMENT sequence, explicit indexes/triggers, reservations,
chat rooms, and chat messages. It adds `payment_evidence` and
`transaction_disputes`, restrictive foreign keys, indexes, and database guards
for participant attribution, matching transaction terms, review transitions,
and dispute resolution.

The migration does not map legacy values such as `pending`, `failed`, or
`refunded`, because doing so would change stored data. If any transaction uses
an unsupported or inconsistent legacy payment value, the preflight aborts
without changing the database; resolve that data through an explicitly
reviewed migration before retrying. Historical accepted offers do not gain
synthetic transactions or evidence.

The migration resolves and verifies the database path as
`hybrid_marketplace.sqlite` beside the script, checks SQLite foreign keys,
creates and verifies a private online backup, and requires the exact phrase
`APPLY PAYMENT EVIDENCE MIGRATION 5`. DDL and the version-5 record are atomic.
It checks indexes, triggers, integrity, foreign keys, and snapshots before and
after. A verified version-5 rerun is a no-op. Do not execute migrations against
production as part of tests; tests copy every required migration into a
disposable directory.

For an approved deployment, take the application offline, verify the selected
path and backup policy, and use:

```sh
DB_DRIVER=sqlite DB_PATH="$PWD/hybrid_marketplace.sqlite" php migrations/migrate_payment_evidence.php
```

### Payment and evidence rules

Only the transaction creator (the payer) can select `promptpay`,
`bank_transfer`, or `cash_on_handover`. The recipient alone can verify or reject
submitted evidence; rejection requires a reason. Payment amount, currency, and
exponent are matched to the transaction in the application and database.
Every submission creates a separate history row; reviewed records cannot be
deleted, and rejected records and their review notes remain when the creator
resubmits.

Digital slips use the submitted file's detected MIME type and are limited to
JPEG, PNG, or PDF up to 5 MB. Files are stored using a random 64-character
opaque key, outside the public web root, with private directory/file
permissions; a configurable `PAYMENT_EVIDENCE_DIR` must also resolve outside
the public root. Downloads are served only after participant authorization.
Cash submissions create an evidence row with a `NULL` storage key. The creator
can report cash paid, but only the recipient can confirm or deny receipt.
Marking cash paid does not set the transaction to verified.

Either participant may report a dispute while the transaction is
`in_progress`. The report freezes payment actions but leaves participant chat
and existing evidence intact. Only an Admin can resolve an open dispute to
`in_progress` or `cancelled`; the reporter, Admin identity, decision, reason,
and timestamps remain in the audit table. Resolution does not release product
reservations or change ownership. No handover, completion, payment gateway,
bank API verification, or ownership transfer is implemented in this step.

Validate migration preservation and rollback with
`php tests/payment_evidence_migration_test.php`, and exercise uploads,
authorization, verification/rejection/resubmission, cash receipt confirmation,
disputes, Admin audit, and chat with
`php tests/payment_workflow_test.php`. These tests use disposable SQLite
databases and local test HTTP servers only.

## Handover confirmations migration (version 6)

`migrations/migrate_handover_confirmations.php` is a CLI-only SQLite migration that
requires migration version 5 to be recorded exactly once. It adds
`handover_confirmations` with a unique constraint on
`(transaction_id, confirmation_type)`, restrictive foreign keys to
`exchange_transactions` and `users`, and the
`idx_handover_confirmations_confirmer_time` index on
`(confirmer_id, confirmed_at)`. The allowed confirmation types are
`creator_handed_over_offered_item`, `recipient_received_offered_item`,
`recipient_handed_over_requested_item`, and
`creator_received_requested_item`.

The insert guard requires an `in_progress` transaction and the participant
authorized for that confirmation type. Update and delete guards make
confirmation records immutable. This migration does not add transaction
completion guards.

### Verified Workspace v5 baseline

The verified target is
`/workspaces/codespaces-blank/hybrid_marketplace.sqlite`. In the audit
environment, `DB_PATH` and `DB_DRIVER` were unset; [db.php](../includes/db.php) therefore
resolves SQLite to the file beside the application. The ledger contained
versions 1–5 exactly once, in order; `PRAGMA user_version` was 0. Read-only
checks returned `integrity_check = ok` and no rows from `foreign_key_check`.

The pinned legacy schema-manifest SHA-256 is
`7e54f337342555b7d7199db78a51a248b245436428125b7a7f09f7fe751e3774`.
It was calculated from the exact target above, over rows
`type,name,tbl_name,sql` in `sqlite_master` where `name NOT LIKE 'sqlite_%'`,
ordered by `type,name`, JSON-encoded with compact separators and UTF-8, then
SHA-256 hashed. It is a schema fingerprint, not a database-content hash. The
preflight below verifies this pinned value; do not silently replace it with a
new value.

The complete v5 object inventory observed read-only is:

**Tables (11):**
`chat_messages`, `chat_rooms`, `exchange_transactions`, `login_attempts`,
`offers`, `payment_evidence`, `product_reservations`, `products`,
`schema_migrations`, `transaction_disputes`, `users`.

**Explicit indexes (16):**
`idx_chat_messages_room_created_id`,
`idx_chat_rooms_creator`, `idx_chat_rooms_recipient`,
`idx_exchange_transactions_created_at`,
`idx_exchange_transactions_creator_status`,
`idx_exchange_transactions_recipient_status`,
`idx_offers_created_at`, `idx_offers_creator_status`,
`idx_offers_offered_product_status`, `idx_offers_recipient_status`,
`idx_offers_target_product_status`,
`idx_payment_evidence_submitted_by`,
`idx_payment_evidence_transaction_status`,
`idx_product_reservations_transaction`,
`idx_transaction_disputes_open`,
`idx_transaction_disputes_transaction`.

**Triggers (10):**
`chat_messages_sender_insert`, `chat_messages_sender_update`,
`exchange_transactions_dispute_guard`,
`exchange_transactions_payment_guard`,
`payment_evidence_insert_guard`, `payment_evidence_no_delete`,
`payment_evidence_update_guard`, `transaction_disputes_insert_guard`,
`transaction_disputes_no_delete`, `transaction_disputes_update_guard`.

**Views:** none.

`PRAGMA index_list(table_name)` reports the following complete index inventory.
`origin=c` means an explicitly created index, `origin=u` means an index
created for a `UNIQUE` constraint, and `origin=pk` means an index created for
a primary-key constraint. `unique` and `partial` are the SQLite pragma values.
The key columns shown are verified from `PRAGMA index_xinfo(index_name)` rows
whose `key` value is 1:

| Table | Index | Unique | Origin | Partial | Key columns |
|---|---|---:|---|---:|---|
| `chat_messages` | `idx_chat_messages_room_created_id` | 0 | c | 0 | `room_id, created_at, id` |
| `chat_rooms` | `idx_chat_rooms_creator` | 0 | c | 0 | `creator_id` |
| `chat_rooms` | `idx_chat_rooms_recipient` | 0 | c | 0 | `recipient_id` |
| `chat_rooms` | `sqlite_autoindex_chat_rooms_1` | 1 | u | 0 | `exchange_transaction_id` |
| `exchange_transactions` | `idx_exchange_transactions_created_at` | 0 | c | 0 | `created_at` |
| `exchange_transactions` | `idx_exchange_transactions_creator_status` | 0 | c | 0 | `creator_id, status` |
| `exchange_transactions` | `idx_exchange_transactions_recipient_status` | 0 | c | 0 | `recipient_id, status` |
| `exchange_transactions` | `sqlite_autoindex_exchange_transactions_1` | 1 | u | 0 | `offer_id` |
| `login_attempts` | `sqlite_autoindex_login_attempts_1` | 1 | pk | 0 | `attempt_key` |
| `offers` | `idx_offers_created_at` | 0 | c | 0 | `created_at` |
| `offers` | `idx_offers_creator_status` | 0 | c | 0 | `creator_id, status` |
| `offers` | `idx_offers_offered_product_status` | 0 | c | 0 | `offered_product_id, status` |
| `offers` | `idx_offers_recipient_status` | 0 | c | 0 | `recipient_id, status` |
| `offers` | `idx_offers_target_product_status` | 0 | c | 0 | `target_product_id, status` |
| `payment_evidence` | `idx_payment_evidence_submitted_by` | 0 | c | 0 | `submitted_by, submitted_at` |
| `payment_evidence` | `idx_payment_evidence_transaction_status` | 0 | c | 0 | `transaction_id, status, id` |
| `product_reservations` | `idx_product_reservations_transaction` | 0 | c | 0 | `transaction_id` |
| `transaction_disputes` | `idx_transaction_disputes_open` | 1 | c | 1 | `transaction_id` |
| `transaction_disputes` | `idx_transaction_disputes_transaction` | 0 | c | 0 | `transaction_id, reported_at` |
| `users` | `sqlite_autoindex_users_1` | 1 | u | 0 | `email` |

The four `sqlite_autoindex_*` entries are not user-created explicit indexes:
SQLite creates them to enforce `chat_rooms.exchange_transaction_id UNIQUE`,
`exchange_transactions.offer_id UNIQUE`, `login_attempts.attempt_key PRIMARY
KEY`, and `users.email UNIQUE`, respectively. Their `sqlite_master.sql` is
NULL; their constraint origin and indexed columns come from `PRAGMA index_list`
and `PRAGMA index_xinfo`. `INTEGER PRIMARY KEY` columns in rowid tables do not
need separate primary-key indexes. The v5 table SQL and `PRAGMA table_xinfo`
are also captured so these primary-key and unique-constraint declarations are
preserved and checked, including tables with no index entries.

The following command is a read-only comparison against the live v5 schema
inventory. It must print `V5 inventory matches` and the existing pinned
fingerprint. Any mismatch is a STOP condition; do not proceed:

```bash
cd /workspaces/codespaces-blank
python3 - /workspaces/codespaces-blank/hybrid_marketplace.sqlite <<'PY'
import hashlib, json, os, sqlite3, sys

target = sys.argv[1]
expected_path = "/workspaces/codespaces-blank/hybrid_marketplace.sqlite"
expected_fingerprint = "7e54f337342555b7d7199db78a51a248b245436428125b7a7f09f7fe751e3774"
expected_tables = {
    "chat_messages", "chat_rooms", "exchange_transactions", "login_attempts",
    "offers", "payment_evidence", "product_reservations", "products",
    "schema_migrations", "transaction_disputes", "users",
}
expected_explicit_indexes = {
    "idx_chat_messages_room_created_id", "idx_chat_rooms_creator",
    "idx_chat_rooms_recipient", "idx_exchange_transactions_created_at",
    "idx_exchange_transactions_creator_status",
    "idx_exchange_transactions_recipient_status", "idx_offers_created_at",
    "idx_offers_creator_status", "idx_offers_offered_product_status",
    "idx_offers_recipient_status", "idx_offers_target_product_status",
    "idx_payment_evidence_submitted_by",
    "idx_payment_evidence_transaction_status",
    "idx_product_reservations_transaction", "idx_transaction_disputes_open",
    "idx_transaction_disputes_transaction",
}
expected_triggers = {
    "chat_messages_sender_insert", "chat_messages_sender_update",
    "exchange_transactions_dispute_guard", "exchange_transactions_payment_guard",
    "payment_evidence_insert_guard", "payment_evidence_no_delete",
    "payment_evidence_update_guard", "transaction_disputes_insert_guard",
    "transaction_disputes_no_delete", "transaction_disputes_update_guard",
}
expected_indexes = {
    "chat_messages": {
        "idx_chat_messages_room_created_id": (0, "c", 0, ("room_id", "created_at", "id")),
    },
    "chat_rooms": {
        "idx_chat_rooms_creator": (0, "c", 0, ("creator_id",)),
        "idx_chat_rooms_recipient": (0, "c", 0, ("recipient_id",)),
        "sqlite_autoindex_chat_rooms_1": (1, "u", 0, ("exchange_transaction_id",)),
    },
    "exchange_transactions": {
        "idx_exchange_transactions_created_at": (0, "c", 0, ("created_at",)),
        "idx_exchange_transactions_creator_status": (0, "c", 0, ("creator_id", "status")),
        "idx_exchange_transactions_recipient_status": (0, "c", 0, ("recipient_id", "status")),
        "sqlite_autoindex_exchange_transactions_1": (1, "u", 0, ("offer_id",)),
    },
    "login_attempts": {
        "sqlite_autoindex_login_attempts_1": (1, "pk", 0, ("attempt_key",)),
    },
    "offers": {
        "idx_offers_created_at": (0, "c", 0, ("created_at",)),
        "idx_offers_creator_status": (0, "c", 0, ("creator_id", "status")),
        "idx_offers_offered_product_status": (0, "c", 0, ("offered_product_id", "status")),
        "idx_offers_recipient_status": (0, "c", 0, ("recipient_id", "status")),
        "idx_offers_target_product_status": (0, "c", 0, ("target_product_id", "status")),
    },
    "payment_evidence": {
        "idx_payment_evidence_submitted_by": (0, "c", 0, ("submitted_by", "submitted_at")),
        "idx_payment_evidence_transaction_status": (0, "c", 0, ("transaction_id", "status", "id")),
    },
    "product_reservations": {
        "idx_product_reservations_transaction": (0, "c", 0, ("transaction_id",)),
    },
    "products": {},
    "schema_migrations": {},
    "transaction_disputes": {
        "idx_transaction_disputes_open": (1, "c", 1, ("transaction_id",)),
        "idx_transaction_disputes_transaction": (0, "c", 0, ("transaction_id", "reported_at")),
    },
    "users": {
        "sqlite_autoindex_users_1": (1, "u", 0, ("email",)),
    },
}

if os.path.realpath(target) != expected_path:
    raise SystemExit(f"STOP: wrong target: {os.path.realpath(target)}")
db = sqlite3.connect("file:" + target + "?mode=ro", uri=True)
db.row_factory = sqlite3.Row
versions = [r[0] for r in db.execute(
    "SELECT version FROM schema_migrations ORDER BY version"
)]
if versions != [1, 2, 3, 4, 5]:
    raise SystemExit(f"STOP: unexpected migration ledger: {versions}")
user_version = db.execute("PRAGMA user_version").fetchone()[0]
if user_version != 0:
    raise SystemExit(f"STOP: unexpected PRAGMA user_version: {user_version}")
if db.execute("PRAGMA integrity_check").fetchone()[0] != "ok":
    raise SystemExit("STOP: integrity_check failed")
if db.execute("PRAGMA foreign_key_check").fetchall():
    raise SystemExit("STOP: foreign_key_check returned rows")
objects = [dict(r) for r in db.execute("""
    SELECT type, name, tbl_name, sql FROM sqlite_master
     WHERE name NOT LIKE 'sqlite_%'
     ORDER BY type, name
""")]
by_type = {}
for obj in objects:
    by_type.setdefault(obj["type"], set()).add(obj["name"])
if by_type.get("table", set()) != expected_tables:
    raise SystemExit(f"STOP: table inventory differs: {by_type.get('table', set())}")
if by_type.get("index", set()) != expected_explicit_indexes:
    raise SystemExit(f"STOP: explicit index inventory differs: {by_type.get('index', set())}")
if by_type.get("trigger", set()) != expected_triggers:
    raise SystemExit(f"STOP: trigger inventory differs: {by_type.get('trigger', set())}")
if by_type.get("view", set()) != set():
    raise SystemExit(f"STOP: unexpected views: {by_type.get('view', set())}")

actual_indexes = {}
for table in sorted(expected_tables):
    entries = {}
    quoted = '"' + table.replace('"', '""') + '"'
    for row in db.execute(f"PRAGMA index_list({quoted})"):
        xquoted = '"' + row["name"].replace('"', '""') + '"'
        xinfo = list(db.execute(f"PRAGMA index_xinfo({xquoted})"))
        key_columns = tuple(r["name"] for r in xinfo if r["key"] == 1)
        entries[row["name"]] = (
            row["unique"], row["origin"], row["partial"], key_columns
        )
    actual_indexes[table] = entries
if actual_indexes != expected_indexes:
    raise SystemExit(f"STOP: index_list/index_xinfo inventory differs: {actual_indexes}")

legacy_rows = [tuple(r) for r in db.execute("""
    SELECT type, name, tbl_name, sql FROM sqlite_master
     WHERE name NOT LIKE 'sqlite_%'
     ORDER BY type, name
""")]
fingerprint = hashlib.sha256(
    json.dumps(legacy_rows, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
).hexdigest()
db.close()
if fingerprint != expected_fingerprint:
    raise SystemExit(f"STOP: pinned schema fingerprint mismatch: {fingerprint}")
print("V5 inventory matches")
print("schema_fingerprint=" + fingerprint)
print(f"ledger=1,2,3,4,5; user_version={user_version}; integrity_check=ok; foreign_key_check=empty")
PY
```

### Stop gates and backup procedure

Migration v6 is not authorized by this runbook. Before any separately
authorized execution, confirm the application and all workers/scheduled tasks
that can access this database are stopped. Use the operating system's process
inspection to confirm no process has the database open; if `lsof` is not
available or the check is inconclusive, STOP. Then inspect all three sidecars:

```bash
TARGET=/workspaces/codespaces-blank/hybrid_marketplace.sqlite
for sidecar in "$TARGET-wal" "$TARGET-shm" "$TARGET-journal"; do
    if [ -e "$sidecar" ]; then
        printf 'STOP: investigate SQLite sidecar: %s\n' "$sidecar" >&2
        exit 1
    fi
done
if ! command -v lsof >/dev/null 2>&1; then
    printf 'STOP: lsof unavailable; cannot verify open database handles\n' >&2
    exit 1
fi
if lsof_output=$(lsof -nP "$TARGET" 2>&1); then
    printf '%s\n' "$lsof_output" >&2
    printf 'STOP: a process has the database open\n' >&2
    exit 1
else
    status=$?
    if [ "$status" -ne 1 ] || [ -n "$lsof_output" ]; then
        printf 'STOP: lsof result is inconclusive (status %s): %s\n' \
            "$status" "$lsof_output" >&2
        exit "$status"
    fi
fi
```

The operator must separately attest that application processes are inactive;
an empty `lsof` result alone is not proof that no service will reopen the file.
If any sidecar exists, do not delete, rename, ignore, or back up around it.
Investigate the process/database state and stop until resolved. Recheck
inactivity, open database handles, and sidecars immediately before backup and
again immediately before migration. Repeat the check after migration and before
opening the database for post-migration inspection.

After the path, ledger, schema inventory, and sidecar checks pass, create the
additional pre-v6 backup below. It uses SQLite's backup API; a raw `cp`, `dd`,
or filesystem copy is not a substitute. The destination is exclusively
created, so an existing filename is never overwritten. The source connection
uses SQLite URI `mode=ro`; only the new destination is opened writable. This
procedure does not write to the Workspace database:

```bash
set -eu
TARGET=/workspaces/codespaces-blank/hybrid_marketplace.sqlite
BACKUP_DIR=/workspaces/codespaces-blank/.database-backups
mkdir -p -- "$BACKUP_DIR"
chmod 700 -- "$BACKUP_DIR"
BACKUP="$BACKUP_DIR/hybrid_marketplace.sqlite.pre-v6-$(date -u +%Y%m%dT%H%M%SZ)-$$.sqlite"
python3 - "$TARGET" "$BACKUP" <<'PY'
import os, sqlite3, sys

target, backup = sys.argv[1:]
expected = "/workspaces/codespaces-blank/hybrid_marketplace.sqlite"
if os.path.realpath(target) != expected:
    raise SystemExit(f"STOP: wrong source database: {os.path.realpath(target)}")
for suffix in ("-wal", "-shm", "-journal"):
    if os.path.exists(target + suffix):
        raise SystemExit(f"STOP: investigate sidecar {target + suffix}")
if os.path.realpath(os.path.dirname(backup)) != "/workspaces/codespaces-blank/.database-backups":
    raise SystemExit("STOP: backup destination is not the Workspace backup directory")
fd = os.open(backup, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
os.close(fd)
source = destination = None
try:
    source = sqlite3.connect("file:" + target + "?mode=ro", uri=True)
    destination = sqlite3.connect(backup)
    source.backup(destination)
finally:
    if destination is not None:
        destination.close()
    if source is not None:
        source.close()
print(backup)
PY
sqlite3 -readonly "$BACKUP" 'PRAGMA integrity_check; PRAGMA foreign_key_check;'
set -o pipefail
SOURCE_DUMP_HASH=$(sqlite3 -readonly "$TARGET" ".dump" | sha256sum | cut -d' ' -f1)
BACKUP_DUMP_HASH=$(sqlite3 -readonly "$BACKUP" ".dump" | sha256sum | cut -d' ' -f1)
printf 'source=%s\nbackup=%s\n' "$SOURCE_DUMP_HASH" "$BACKUP_DUMP_HASH"
test "$SOURCE_DUMP_HASH" = "$BACKUP_DUMP_HASH"
```

Expected: backup integrity prints `ok`, foreign-key check prints no rows, and
the dump hashes match. Any command error or mismatch is a STOP condition.
Keep this verified backup, both schema manifests described below, and the
migration's own separately created `backup-v6-*` file together in the private
backup directory. Preserve the migration output in a uniquely named audit log;
never redirect over an existing file.

### Pre-migration schema manifest

The manifest command below is read-only against SQLite. It writes only a new
JSON audit file in `.database-backups`, using exclusive creation. It captures
all user table definitions and `table_xinfo`, every explicit index, trigger,
and view definition, and for **each** user table the full `index_list` metadata
plus `index_xinfo` rows. The latter records SQLite-generated autoindexes and
their origin (`pk`/`u`), uniqueness, partial status, key columns, collation,
sort order, and rowid auxiliary entries. The command checks the exact target,
v5 ledger, integrity, foreign keys, and pinned schema fingerprint, and records
the complete object/index inventory. The inventory preflight immediately
above checks the expected object names and per-table index properties. Any
mismatch stops the procedure.

Run this exact command before migration, after the verified backup:

```bash
python3 - /workspaces/codespaces-blank/hybrid_marketplace.sqlite \
    /workspaces/codespaces-blank/.database-backups pre-v6 <<'PY'
import hashlib, json, os, secrets, sqlite3, sys, time

target, audit_dir, phase = sys.argv[1:]
expected_path = "/workspaces/codespaces-blank/hybrid_marketplace.sqlite"
expected_fingerprint = "7e54f337342555b7d7199db78a51a248b245436428125b7a7f09f7fe751e3774"
expected_versions = [1, 2, 3, 4, 5] if phase == "pre-v6" else [1, 2, 3, 4, 5, 6]
if phase not in ("pre-v6", "post-v6") or os.path.realpath(target) != expected_path:
    raise SystemExit("STOP: invalid phase or target path")
for suffix in ("-wal", "-shm", "-journal"):
    if os.path.exists(target + suffix):
        raise SystemExit(f"STOP: investigate SQLite sidecar: {target + suffix}")
if os.path.realpath(audit_dir) != "/workspaces/codespaces-blank/.database-backups":
    raise SystemExit("STOP: audit destination differs from the verified Workspace directory")
db = sqlite3.connect("file:" + target + "?mode=ro", uri=True)
db.row_factory = sqlite3.Row
versions = [r[0] for r in db.execute(
    "SELECT version FROM schema_migrations ORDER BY version"
)]
if versions != expected_versions:
    raise SystemExit(f"STOP: unexpected migration ledger: {versions}")
if db.execute("PRAGMA integrity_check").fetchone()[0] != "ok":
    raise SystemExit("STOP: integrity_check failed")
if db.execute("PRAGMA foreign_key_check").fetchall():
    raise SystemExit("STOP: foreign_key_check returned rows")
objects = [dict(r) for r in db.execute("""
    SELECT type, name, tbl_name, sql FROM sqlite_master
     WHERE name NOT LIKE 'sqlite_%'
     ORDER BY type, name
""")]
tables = {}
for obj in objects:
    if obj["type"] != "table":
        continue
    table = obj["name"]
    quoted = '"' + table.replace('"', '""') + '"'
    indexes = []
    for idx in db.execute(f"PRAGMA index_list({quoted})"):
        index_name = idx["name"]
        xquoted = '"' + index_name.replace('"', '""') + '"'
        indexes.append({
            "name": index_name,
            "unique": idx["unique"],
            "origin": idx["origin"],
            "partial": idx["partial"],
            "xinfo": [dict(row) for row in db.execute(
                f"PRAGMA index_xinfo({xquoted})"
            )],
        })
    tables[table] = {
        "columns": [dict(row) for row in db.execute(f"PRAGMA table_xinfo({quoted})")],
        "indexes": sorted(indexes, key=lambda item: item["name"]),
    }
legacy_rows = [tuple(r) for r in db.execute("""
    SELECT type, name, tbl_name, sql FROM sqlite_master
     WHERE name NOT LIKE 'sqlite_%'
     ORDER BY type, name
""")]
fingerprint = hashlib.sha256(
    json.dumps(legacy_rows, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
).hexdigest()
db.close()
if phase == "pre-v6" and fingerprint != expected_fingerprint:
    raise SystemExit(f"STOP: pinned fingerprint mismatch: {fingerprint}")
manifest = {
    "phase": phase,
    "target": expected_path,
    "sqlite_version": sqlite3.sqlite_version,
    "pinned_v5_schema_fingerprint": expected_fingerprint,
    "observed_legacy_schema_fingerprint": fingerprint,
    "objects": objects,
    "tables": tables,
}
name = f"handover-v6-{phase}-{time.strftime('%Y%m%dT%H%M%SZ', time.gmtime())}-{secrets.token_hex(6)}.json"
path = os.path.join(audit_dir, name)
fd = os.open(path, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
with os.fdopen(fd, "w", encoding="utf-8") as output:
    json.dump(manifest, output, ensure_ascii=False, sort_keys=True, indent=2)
    output.write("\n")
print(path)
PY
```

Record the printed pre-manifest path and its SHA-256 alongside the verified
backup path and migration log. For example, after assigning the exact printed
path to `PRE_MANIFEST`, display the artifact hashes with:

```bash
sha256sum "$BACKUP" "$PRE_MANIFEST"
```

Store these audit artifacts together; do not overwrite or regenerate the
pre-manifest during execution.

### Migration execution

Only after separate explicit authorization and every preflight gate passes,
run from the project directory so the path and script are unambiguous:

```bash
DB_DRIVER=sqlite \
DB_PATH="$PWD/hybrid_marketplace.sqlite" \
php migrations/migrate_handover_confirmations.php
```

For an authorized run, preserve the complete interactive migration output in
a new audit log. This creates the log exclusively (and fails rather than
overwriting an existing path); standard input remains attached to the
terminal:

```bash
AUDIT_DIR="$PWD/.database-backups"
LOG="$AUDIT_DIR/handover-v6-migration-$(date -u +%Y%m%dT%H%M%SZ)-$$.log"
( set -C; : > "$LOG" )
chmod 600 -- "$LOG"
set -o pipefail
DB_DRIVER=sqlite \
DB_PATH="$PWD/hybrid_marketplace.sqlite" \
php migrations/migrate_handover_confirmations.php 2>&1 \
    | tee -a "$LOG"
```

At the interactive prompt, type exactly:

```text
APPLY HANDOVER CONFIRMATIONS MIGRATION 6
```

The script rejects a non-SQLite driver and any resolved path other than
`hybrid_marketplace.sqlite` beside the script. It reserves a new private backup
with exclusive creation, uses SQLite's online backup API, and verifies that
backup's data snapshot, `integrity_check`, and `foreign_key_check` before
starting `BEGIN IMMEDIATE`. The DDL, triggers, and v6 ledger insert are in that
transaction; exceptions before commit roll it back. A post-commit verification
error cannot roll back the committed transaction: preserve the output and
database files and STOP for review. The script's trigger snapshot compares
triggers with the state immediately before its transaction; it is **not** by
itself proof that the whole v5 schema matches the pinned baseline. That proof
comes from the external pre/post manifest comparison below.

### Post-migration schema and data verification

After a successful migration, repeat the application-inactivity, `lsof`, and
three-sidecar checks before opening the database for read-only inspection.
Capture a `post-v6` manifest using the **same manifest command**, changing only
the phase argument from `pre-v6` to `post-v6`. It requires ledger v1–v6 and
still stops on sidecars, integrity, or foreign-key failures. Record its path
and SHA-256 alongside the pre-manifest, verified backups, and complete
migration output. After assigning the exact printed paths, record their hashes:

```bash
sha256sum "$BACKUP" "$PRE_MANIFEST" "$POST_MANIFEST" "$LOG"
```

Compare the two manifests using the following read-only comparator. Supply the
exact paths printed by the capture commands. It proves all old table/index/
trigger/view SQL and per-table column/index metadata remain byte-for-byte
unchanged; it allows exactly the v6 table, explicit index, and three expected
triggers; it verifies the v6 table's unique-constraint autoindex; and it checks
the exact SQL definitions of the v6 objects. Any difference exits nonzero and
is a STOP condition. Preserve both manifests and both database files if it
fails.

```bash
python3 - /workspaces/codespaces-blank/.database-backups/PRE-MANIFEST.json \
    /workspaces/codespaces-blank/.database-backups/POST-MANIFEST.json <<'PY'
import json, sys

pre_path, post_path = sys.argv[1:]
with open(pre_path, encoding="utf-8") as f:
    pre = json.load(f)
with open(post_path, encoding="utf-8") as f:
    post = json.load(f)
if pre["phase"] != "pre-v6" or post["phase"] != "post-v6":
    raise SystemExit("STOP: wrong manifest phases")
if pre["target"] != post["target"] or pre["target"] != "/workspaces/codespaces-blank/hybrid_marketplace.sqlite":
    raise SystemExit("STOP: manifests refer to different targets")

def object_map(manifest):
    return {(o["type"], o["name"]): o for o in manifest["objects"]}

before, after = object_map(pre), object_map(post)
expected_added = {
    ("table", "handover_confirmations"),
    ("index", "idx_handover_confirmations_confirmer_time"),
    ("trigger", "handover_confirmations_insert_guard"),
    ("trigger", "handover_confirmations_update_guard"),
    ("trigger", "handover_confirmations_no_delete"),
}
if set(before) - set(after):
    raise SystemExit(f"STOP: old schema objects removed: {set(before) - set(after)}")
if set(after) - set(before) != expected_added:
    raise SystemExit(f"STOP: unexpected schema additions: {set(after) - set(before)}")
for key, value in before.items():
    if after[key] != value:
        raise SystemExit(f"STOP: old schema object altered: {key}")

v5_trigger_names = {
    o["name"] for o in pre["objects"] if o["type"] == "trigger"
}
post_trigger_names = {
    o["name"] for o in post["objects"] if o["type"] == "trigger"
}
v6_trigger_names = {
    "handover_confirmations_insert_guard",
    "handover_confirmations_update_guard",
    "handover_confirmations_no_delete",
}
if post_trigger_names != v5_trigger_names | v6_trigger_names:
    raise SystemExit("STOP: complete trigger inventory differs from pinned v5 plus v6")

def normalize_sql(sql):
    out, quote, pending, i = [], None, False, 0
    while i < len(sql):
        ch = sql[i]
        if quote is not None:
            out.append(ch)
            if ch == quote:
                if quote != "]" and i + 1 < len(sql) and sql[i + 1] == quote:
                    i += 1
                    out.append(sql[i])
                else:
                    quote = None
        elif ch.isspace():
            pending = bool(out)
        else:
            if pending:
                out.append(" ")
                pending = False
            out.append(ch)
            if ch in ("'", '"', "`"):
                quote = ch
            elif ch == "[":
                quote = "]"
        i += 1
    return "".join(out)

expected_v6_sql = {
    ("table", "handover_confirmations"): """CREATE TABLE handover_confirmations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        transaction_id INTEGER NOT NULL,
        confirmation_type TEXT NOT NULL CHECK (confirmation_type IN (
            'creator_handed_over_offered_item',
            'recipient_received_offered_item',
            'recipient_handed_over_requested_item',
            'creator_received_requested_item'
        )),
        confirmer_id INTEGER NOT NULL,
        confirmed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (transaction_id, confirmation_type),
        FOREIGN KEY (transaction_id) REFERENCES exchange_transactions(id) ON DELETE RESTRICT,
        FOREIGN KEY (confirmer_id) REFERENCES users(id) ON DELETE RESTRICT
    )""",
    ("index", "idx_handover_confirmations_confirmer_time"): """CREATE INDEX idx_handover_confirmations_confirmer_time
        ON handover_confirmations (confirmer_id, confirmed_at)""",
    ("trigger", "handover_confirmations_insert_guard"): """CREATE TRIGGER handover_confirmations_insert_guard
            BEFORE INSERT ON handover_confirmations
            FOR EACH ROW
            WHEN NOT EXISTS (
                SELECT 1 FROM exchange_transactions
                WHERE id = NEW.transaction_id
                  AND status = 'in_progress'
                  AND (
                      (NEW.confirmation_type = 'creator_handed_over_offered_item'
                       AND creator_id = NEW.confirmer_id)
                      OR
                      (NEW.confirmation_type = 'recipient_received_offered_item'
                       AND recipient_id = NEW.confirmer_id)
                      OR
                      (NEW.confirmation_type = 'recipient_handed_over_requested_item'
                       AND recipient_id = NEW.confirmer_id)
                      OR
                      (NEW.confirmation_type = 'creator_received_requested_item'
                       AND creator_id = NEW.confirmer_id)
                  )
            )
            BEGIN
                SELECT RAISE(ABORT, 'handover confirmer is not an eligible participant');
            END""",
    ("trigger", "handover_confirmations_update_guard"): """CREATE TRIGGER handover_confirmations_update_guard
            BEFORE UPDATE ON handover_confirmations
            FOR EACH ROW
            BEGIN
                SELECT RAISE(ABORT, 'handover confirmations are immutable');
            END""",
    ("trigger", "handover_confirmations_no_delete"): """CREATE TRIGGER handover_confirmations_no_delete
            BEFORE DELETE ON handover_confirmations
            FOR EACH ROW
            BEGIN
                SELECT RAISE(ABORT, 'handover confirmations are retained for audit');
            END""",
}
for key, sql in expected_v6_sql.items():
    actual = after[key]["sql"]
    if actual is None or normalize_sql(actual) != normalize_sql(sql):
        raise SystemExit(f"STOP: v6 definition differs from expected SQL: {key}")

old_tables = set(pre["tables"])
if set(post["tables"]) != old_tables | {"handover_confirmations"}:
    raise SystemExit("STOP: table inventory is not exactly v5 plus the v6 table")
for table in old_tables:
    if post["tables"][table] != pre["tables"][table]:
        raise SystemExit(f"STOP: columns or index_list/index_xinfo changed: {table}")
v6_indexes = post["tables"]["handover_confirmations"]["indexes"]
index_summary = {
    row["name"]: (
        row["unique"], row["origin"], row["partial"],
        tuple(x["name"] for x in row["xinfo"] if x["key"] == 1),
    )
    for row in v6_indexes
}
expected_v6_indexes = {
    "idx_handover_confirmations_confirmer_time":
        (0, "c", 0, ("confirmer_id", "confirmed_at")),
    "sqlite_autoindex_handover_confirmations_1":
        (1, "u", 0, ("transaction_id", "confirmation_type")),
}
if index_summary != expected_v6_indexes:
    raise SystemExit(f"STOP: unexpected v6 index/constraint inventory: {index_summary}")
if pre["pinned_v5_schema_fingerprint"] != "7e54f337342555b7d7199db78a51a248b245436428125b7a7f09f7fe751e3774":
    raise SystemExit("STOP: pre-manifest does not carry the pinned v5 fingerprint")
print("PASS: v5 schema preserved; only expected v6 schema objects added")
print("PASS: full trigger inventory is pinned v5 triggers plus the three verified v6 triggers")
PY
```

In addition to schema comparison, run these read-only data checks:

```bash
sqlite3 -readonly /workspaces/codespaces-blank/hybrid_marketplace.sqlite <<'SQL'
SELECT group_concat(version, ',')
  FROM (SELECT version FROM schema_migrations ORDER BY version);
SELECT COUNT(*) FROM schema_migrations WHERE version = 6;
SELECT COUNT(*) FROM handover_confirmations;
PRAGMA user_version;
PRAGMA integrity_check;
PRAGMA foreign_key_check;
SQL
```

Expected: ledger `1,2,3,4,5,6`; v6 count `1`; handover row count `0`;
`user_version` remains `0`; integrity returns `ok`; foreign-key check returns no
rows. The manifest comparison is the **schema-preservation** check. Separately,
the migration's data snapshot checks preservation of users, products (including
ownership columns), offers, exchange transactions, reservations, chat rooms,
chat messages, payment evidence, and disputes. No handover confirmations are
backfilled.

### Functional verification

These are disposable-database tests only; do not set or export an override
that changes their explicit fixture paths:

```bash
cd /workspaces/codespaces-blank
env -u DB_PATH -u DB_DRIVER php tests/handover_confirmations_migration_test.php
env -u DB_PATH -u DB_DRIVER php tests/handover_workflow_test.php
```

Both tests create unique temporary roots and databases under the system
temporary directory and pass those database paths explicitly to child
processes. They copy the migration/application files into the fixture app
directory; they do not execute against the Workspace database.

### Rollback and stop conditions

Restoration is not authorized by this runbook and requires separate explicit
approval. If restoration is separately approved: stop the application and all
writers; stop immediately if any target WAL/SHM/journal sidecar exists; preserve
the current database under a new exclusive quarantine filename; verify the
pre-v6 backup with `integrity_check`, `foreign_key_check`, and a comparison to
the saved pre-v6 manifest; only then stage and atomically replace the target.
Never delete or ignore a sidecar, and never restore while application writes
are active. After restoration, repeat the v5 path, ledger, inventory,
fingerprint, integrity, and foreign-key checks. A restore loses all writes
made after the backup, which is why the current database must first be
preserved and reviewed.

STOP if the resolved path differs; the ledger is not exactly v1–v5 before
migration; any v5 table, index, trigger, view, table constraint, or
`index_list`/`index_xinfo` entry differs; an unexpected object is present; a
sidecar exists or an active connection cannot be ruled out; the backup or any
verification fails; the migration errors; or pre/post schema or data checks
fail. On post-check failure, preserve both manifests, both database files, and
the complete migration output. Do not retry or restore without review and
separate authorization.

The migration command, exact confirmation phrase, backup behavior, and
transaction boundaries above match
[migrations/migrate_handover_confirmations.php](../migrations/migrate_handover_confirmations.php).
The functional test sources are
[tests/handover_confirmations_migration_test.php](../tests/handover_confirmations_migration_test.php)
and [tests/handover_workflow_test.php](../tests/handover_workflow_test.php).
This runbook update is documentation only; it does not apply or authorize v6.
