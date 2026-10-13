# Prepared redemption: atomic transaction model (UI-03D.A)

Status: **pure PHP model, NOT wired, NOT a migration.** The plugin ships the
definitions (`EVC_Prepared_Redemption_Service`, `EVC_Prep_Redemption_Store`,
`EVC_Prep_Catalogue_Authority`, `EVC_Prep_Snapshot`), but nothing constructs
them. The packager rejects any store implementation, any catalogue authority
implementation and any `new EVC_Prepared_Redemption_Service` in shipped code. Only
the test fakes in `tests/support/class-evc-in-memory-prepared-redemption-store.php`
exist.

- No SQL is written or executed, and no migration exists. Migration 003 (the
  payment-identity design) remains unapproved and is NOT used for this.
- The staff flag stays off and production redemption stays HTTP 503.

## 1. The contract

One verified, eligible coffee redemption produces **exactly three records**:

| # | record | key facts |
|---|--------|-----------|
| 1 | ledger row | member (internal id), benefit, Europe/Athens business date, coffee code, catalogue version, `prep_hash`, `snapshot_hash`, request id, fingerprint v2 and its version, staff actor, membership snapshot, `redeemed_at_utc` |
| 2 | preparation snapshot | `evc-prep-snapshot-v1`: codes and the labels shown at the counter, `prep_hash` (over the codes), `snapshot_hash` (over everything, labels included) |
| 3 | success audit event | `redemption` / `redeemed`, linked to the new ledger id; details hold codes and hashes only, never labels or free text |

All three are written together or not at all. The store receives them as one
`EVC_Prepared_Redemption_Write`, which refuses to exist unless the three agree:

- the ledger hashes equal the snapshot hashes;
- the same coffee and catalogue version;
- the same request, member, actor and instant in the audit event;
- the snapshot verifies.

## 2. Decision order and states

```text
request ──► SHAPE ──► PARSE ──► FINGERPRINT ──► REQUEST-ID LOOKUP
             │          │                         │
   invalid_request  invalid_preparation           ├─ found, other fingerprint ─► idempotency_key_reused
                    (free text, duplicates)       ├─ found, same fingerprint ──► VERIFY SNAPSHOT
                                                  │        ├─ intact ──────────► replayed (stored record)
                                                  │        └─ missing/corrupt/conflicting ─► record_integrity_failure
                                                  └─ none
                                                       ▼
                       CATALOGUE AUTHORISED? ── no ─► catalogue_not_authorised
                                 ▼
                       VERSION CURRENT? ─────── no ─► catalogue_version_stale
                                 ▼
                       SEMANTIC VALIDATION ── fail ─► invalid_preparation / invalid_coffee
                                 ▼
                       MEMBER (server lookup) ─ no ─► member_not_found
                                 ▼
                       ONE CLOCK READ + ELIGIBILITY ─ no ─► membership_inactive
                                 ▼
                       ATOMIC COMMIT (ledger + snapshot + audit)
                          ├─ ok ──────────────► redeemed
                          ├─ duplicate key ───► (rolled back) fresh reads:
                          │                      same request id ─► replay / conflict / integrity
                          │                      same member-day ─► benefit_already_redeemed
                          │                      neither visible ─► server_error
                          ├─ retryable ───────► retry whole transaction (max 3), then server_error
                          ├─ commit unknown ──► outcome_unknown
                          └─ other ───────────► server_error (rolled back)
```

**Superseded by UI-03D.C** (`docs/fulfillment-contract.md`): `may_serve()` no longer
exists. Only a FRESH commit instructs `prepare`. A replay reports
`previously_recorded` plus the stored handoff status, and instructs
`verify_handoff`, `do_not_prepare` or `do_not_prepare_for_today`, never `prepare`.
A replayed commit proves the entitlement was consumed, not that a coffee was
handed over.

**Unresolved outcomes** are `outcome_unknown` and `server_error`. Both carry
`retry_request_id()`, the original request id. The only safe recovery is to
repeat the request with that same id:

- if the first attempt committed, the repeat replays it;
- if nothing committed, the repeat performs the first and only redemption.

A new request id is never a valid recovery. On the same day the unique key
would refuse it anyway, but **after midnight it would be a second coffee for
the same visit**. An unknown commit is never retried inside the call.

`record_integrity_failure` is definitive and is audited:

- nothing is served, written or repaired;
- the damaged member-day still blocks another coffee;
- a manager must investigate.

### Trust boundaries

| input | source | how the client is excluded |
|-------|--------|----------------------------|
| member public id, benefit | URL (QR resolution) | format checked, then resolved by the server to the internal id and WP user |
| staff actor (+ session ref) | authenticated server session (`EVC_Staff_Actor`) | not a body field |
| time, business date | injected clock, read once | body keys rejected |
| eligibility | server-side membership adapter at that instant | body keys rejected |
| catalogue + approval | `EVC_Prep_Catalogue_Authority` (server) | body only *names* a version, which must equal the active one |
| preparation | body: typed modifiers and quick notes only | any non-null `note` → `free_text_not_supported`; unknown keys → invalid |

**Catalogue authorisation.** A catalogue document whose own status says
`approved` is not enough. The server must also hold an owner approval record
for that version whose digest equals `EVC_Prep_Catalogue_Digest::of(document)`.
The digest is a sha256 over the document with keys sorted recursively and list
order kept, so any edit after approval invalidates it.

The illustrative fixture works only with the explicit test opt-in, and that
opt-in never authorises a document claiming `approved`.

Replays never consult the catalogue. They keep working while the active
catalogue is unauthorised, retired or relabelled.

## 3. Failure model (all covered by tests with the in-memory store)

| situation | result | records |
|-----------|--------|---------|
| failure before or after the ledger insert, after the snapshot, after the audit event, or at COMMIT (definite) | `server_error` + same-id retry | none |
| deadlock or lock-wait (1213/1205) | whole-transaction retry; after 3 attempts `server_error` | exactly one triple, or none |
| COMMIT outcome unknown, committed | `outcome_unknown`; same-id retry → `replayed` (also after midnight, expiry or a catalogue change); a new id the same day → `benefit_already_redeemed` | one triple |
| COMMIT outcome unknown, not committed | `outcome_unknown`; same-id retry → `redeemed` | one triple after recovery |
| two tills, same member, different request ids (serial, or B commits between A's lookup or eligibility check and A's commit) | first `redeemed`, other `benefit_already_redeemed` | one triple |
| duplicate submit (same id, same preparation) | first `redeemed`, other `replayed` (the same redemption) | one triple |
| same id, different preparation, member, actor, coffee or catalogue version | `idempotency_key_reused`; nothing of the original is revealed | unchanged |
| three tills racing | one `redeemed`, two `benefit_already_redeemed` | one triple |
| snapshot missing, duplicated, corrupt (shape, extra `note`, label, code, order, hash) or conflicting (a self-consistent rewrite that disagrees with the ledger hashes, or ledger fields altered) | `record_integrity_failure` | unchanged |
| a v1-engine row on the same member-day, or under the same request id | `benefit_already_redeemed`, or `idempotency_key_reused` | unchanged |

## 4. How the future SQL transaction must satisfy the same contract (NOT implemented)

The implementation needs a future migration. **Its number and timing need
separate owner authorisation.** It must not be folded into the unapproved
migration 003.

### Schema additions

- **`evc_redemptions`**: new columns `fingerprint_version` (ascii), `catalogue_version`
  (INT UNSIGNED NULL), `prep_hash` and `snapshot_hash` (CHAR(64) ascii NULL).
  - They are NULL only for v1 rows.
  - A CHECK requires all four to be set when `fingerprint_version = 'evc-redeem-v2'`.
  - The existing `uq_redemptions_one_per_day` and `uq_redemptions_request` stay
    the ONLY uniqueness authorities.
- **`evc_redemption_preparations`** (new table):
  - Primary key `redemption_id`, which is also a FK to `evc_redemptions(id)`
    `ON DELETE RESTRICT ON UPDATE RESTRICT`. The primary key means at most one
    snapshot per redemption.
  - Columns: `schema_version`, `prep_version`, `catalogue_version`,
    `coffee_code`, `coffee_label`, `modifiers_json` and `quick_notes_json`
    (or normalised child rows), `prep_hash`, `snapshot_hash`.
  - There is no free-text column of any kind.
- **Immutability:**
  - the runtime DB user gets INSERT and SELECT only on the ledger, snapshot and
    audit tables (no UPDATE or DELETE);
  - optionally, BEFORE UPDATE/DELETE triggers that SIGNAL.

### Transaction (one `EVC_Club_Db::transactional()` call, READ COMMITTED)

```sql
-- 1. ledger row: the unique keys decide one-per-member-day and one-per-request
INSERT INTO evc_redemptions (..., fingerprint_version, catalogue_version, prep_hash, snapshot_hash) VALUES (...);
-- 2. snapshot, keyed by the new id
INSERT INTO evc_redemption_preparations (redemption_id, ...) VALUES (LAST_INSERT_ID(), ...);
-- 3. success audit event, keyed by the new id
INSERT INTO evc_audit_events (event_type, outcome, member_id, redemption_id, request_id, ...) VALUES (...);
COMMIT;
```

How each store result maps to the database:

- **1062:** rollback first. Then fresh statements on the same connection:
  `find_by_request_id` (joined with the snapshot), then `find_for_day`.
  This is exactly the v1 engine's proven recovery.
- **1213 / 1205:** rollback, then retry the whole transaction. Do not retry a
  single statement. The bound is 3.
- **A failed COMMIT:** `EVC_Db_Commit_Unknown_Exception` becomes
  `outcome_unknown`. Never retry inside the call.
- **Reads for replay:** must return EVERY snapshot row joined to the ledger
  row, so the model's missing and conflicting checks keep meaning. LEFT JOIN
  returns zero rows when one is missing.

### What the database cannot enforce alone (the model covers it)

- **"At least one snapshot per ledger row."** MySQL/MariaDB have no deferred
  constraints. The guarantee is the single transaction, plus fail-closed
  verification on every read (`record_integrity_failure`), plus a periodic
  reconciliation query (ledger LEFT JOIN snapshot WHERE snapshot IS NULL, and
  hash mismatches). The reconciliation query is a future task.
- **Corruption of label copies.** `snapshot_hash` in both tables detects it
  on read.
- **The v1 and v2 engines must share ONE ledger table and its unique keys**, so
  the one-coffee-per-day invariant holds across engine versions. The v1 engine
  should be retired when the v2 path is enabled.

## 5. Gaps that require the SQL implementation

1. A migration authorisation (number, timing, rollback plan) and the schema
   above. It is not authorised.
2. A real `EVC_Prep_Redemption_Store` on `EVC_Club_Db`. Its integration tests
   must run on MySQL 8.0, MySQL 8.4 and MariaDB 10.11, covering:
   - real concurrent workers (like the existing concurrency suite);
   - real deadlocks;
   - COMMIT-loss simulation;
   - the reconciliation query.
3. A real `EVC_Prep_Catalogue_Authority`: where the active catalogue document
   and the owner approval digests are stored (read-only for the runtime user),
   and how an approval is recorded and audited.
4. REST wiring: the redeem endpoint passes only URL values, the session actor
   and the decoded body. It maps outcomes to HTTP:
   - `outcome_unknown` and `server_error` must tell the till to retry with the
     same request id;
   - `record_integrity_failure` must not suggest a retry.
5. Client (till) persistence of the in-flight request id, so it survives a
   reload. Until a definitive answer arrives, the till offers only "retry"
   (same id), never "new order".
6. The immutability privileges or triggers, and retention/erasure handling for
   member-linked preparation history (owner decision D9).

## 6. Dependencies

**Owner:**

- **D3:** the real coffee catalogue and canonical codes.
- **An approval process:** who approves, how the digest is recorded, and how
  a version is retired.
- **D-PRICING:** still undecided. An approved catalogue must not be read as
  "free extras".
- **D9:** retention and access for member-linked preparation history (GDPR).
- **Migration authorisation**, separate from migration 003.
- **When the v1 engine is retired.**

**Website technician:**

- the separate Club database and its MySQL/MariaDB version;
- a runtime DB user with INSERT/SELECT-only grants on these tables;
- READ COMMITTED and InnoDB;
- backups and restore drills, covering the snapshot table;
- a staging environment to run the integration suite before any activation.
