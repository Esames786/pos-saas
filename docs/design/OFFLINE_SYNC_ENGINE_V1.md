# OFFLINE-SYNC-ENGINE-1A — authoritative preflight + wire contract

Status: **PRE-FLIGHT / design checkpoint** (no sync engine implemented). Grounded at
`feat/edge-config-refresh-v1` after EDGE-CONFIG-REFRESH-1 + EDGE-COMPATIBILITY-CONTRACT-1 (frozen).
Production: `c4fc021`, Cloud-only, Local Mode inactive, `activation_ready=false`.

---

## 1. Identity matrix (code-grounded)

Everything below EXISTS today (EDGE-IDENTITY-1: `App\Support\Edge\EdgeIdentity` registry +
`HasCanonicalIdentity`; proven end-to-end by `EdgeIdentityFlowMySqlTest` / `EdgeIdentityMySqlTest`
including two-independent-database resolution). **No new identity column is required for V1.**

| Entity | Canonical cross-system identity | Local numeric PK | Business display number | Idempotency key |
|---|---|---|---|---|
| Sale | `sales_orders.sale_uuid` (ULID; durable across held→pay/Add Round — row updated, never recreated) | `sales_orders.id` (LOCAL ONLY) | `sale_no` (`SO-{branch}-{terminal}-{ULID}` on Edge — traceable to sale_uuid, human label only) | `client_uuid` + `client_payload_hash` (dedups ONE POS submit; NOT the object identity) |
| Sale line | `sales_order_lines.line_uuid` (ULID; identifies the CURRENT row — held re-saves churn lines; final settled rows are stable) | `id` | — | — |
| Payment | `sale_payments.payment_uuid` (ULID; same churn caveat, final rows stable) | `id` | — | — |
| Shift | `shifts.shift_uuid` (ULID, REUSED registry; minted by `ShiftService::open`) | `id` | — | — |
| Restaurant table session | `restaurant_table_sessions.session_uuid` (ULID) | `id` | `session_no` (display) | — |
| KOT batch/event | `kot_batches.event_uuid` (UUID v4 — documented format exception; print `logical_key` derives from it) + `sequence_no`/`copy_no` (per-sale ordering, display) | `id` | KOT `#N` | print `logical_key` (print-idempotency, not object identity) |
| KOT line | `kot_batch_lines.kot_line_uuid` + `source_line_uuid` (IMMUTABLE snapshot of the sale line's canonical id — survives line churn) | `id` | — | — |
| Operational stock movement | `edge_operational_stock_ledgers` rows carry `sale_uuid` + `line_uuid` (evidence links); baseline: `baseline_uuid` (ULID) + `content_hash` + `active_binding_key` | `id`, `balance_key` (local composite) | — | baseline accept is idempotent by (baseline_uuid, hash) |
| Terminal | **Config-replicated: Cloud numeric id IS preserved on Edge** (bootstrap imports preserve IDs) + `code`, `device_identifier` | same id both sides | `code` | — |
| User | Config-replicated preserved id + `employee_code` (login identity) | same id both sides | `employee_code` | — |
| Branch | Config-replicated preserved id (binding-verified per package) + `code` | same id both sides | `code` | — |
| Device | `edge_devices.public_uuid` (master) = `edge_local_meta.device_uuid` (appliance) | master `id` | `device_name` | device secret hash = auth, not identity |
| Activation epoch | `edge_branch_activations.generation` (monotonic per tenant+branch); sale rows already stamp `sales_orders.edge_activation_epoch` | — | — | — |
| Config revision | `edge_branch_config_revisions.revision` (monotonic per tenant+branch); appliance: `edge_local_meta.last_applied_config_revision` | — | — | — |

Classification rules (locked by EDGE-IDENTITY-1 and unchanged here):
- **Numeric Edge PKs are never Cloud identity** — envelopes reference parents by canonical UUID only.
- **Config entities** (terminal/user/branch/product/…) are the one exception where numeric ids are
  cross-system stable, because bootstrap/refresh PRESERVES Cloud ids on the appliance. Envelopes still
  carry them as *references into replicated config*, validated against the branch binding.
- Display numbers are labels; idempotency keys dedup one write; neither is object identity.

**One genuine gap found:** the sale row records `edge_activation_epoch` but NOT the config revision
it was created under (`edge_local_meta.last_applied_config_revision` is mutable state — a later
refresh overwrites it). V1 closes this via the **outbox row written in the same transaction as the
sale**, which snapshots `config_revision` at sale time (see §6). No new column on `sales_orders`.

---

## 2. V1 sync scope

IN (V1): cash/manual offline **paid** sales — quick sale, takeaway, dine-in; held/Add-Round **final
settlement** (the settled sale, not intermediate held states); sale lines + modifiers; payments
(cash only, per the offline restrictions section); the referenced shift identity (`shift_uuid` +
open/close facts Cloud history needs); referenced table-session / KOT identities **as identity
snapshots inside the envelope only** (no independent KOT/session sync stream in V1); provisional
operational-stock evidence (baseline_uuid + per-line consumed quantities) for validation/audit.

OUT (V1): returns, refunds, credit sales, card/provider payments, purchasing, official inventory
from Edge, manufacturing, Catering. These are already refused offline (`restrictions` section;
`EdgeCompatibilityService` classifies them `feature_unavailable_offline`).

---

## 3. Cloud ingest authority analysis — effect/double-apply matrix

Traced from the real Cloud paid-sale execution path (`SalesService::finalizePaidSale`,
`SaleOperationalSettlementService`, `JournalPostingService`, `DepartmentConsumptionService`) vs.
what `EdgeLocalPosService` already performed locally on the appliance at sale time.

**Cloud `finalizePaidSale` effect chain** (SalesService.php):
inside ONE tenant transaction — official FEFO out per stock-tracked line (`InventoryService::postOutFefo`,
guarded by the per-sale `inventory_posted` flag) / recipe consumption (`RecipeConsumptionService`) /
`consume_stock` modifier stock; COGS written onto lines (`unit_cost`/`cost_total` from FEFO ledger
costs); sale row → `status='paid'` + `inventory_posted=true`; shared operational settlement
(`SaleOperationalSettlementService::settle` = `sales_ledgers` subledger rows via firstOrCreate +
ATOMIC shift-counter INCREMENTS: total_sales/total_discount/total_tax/expected_cash/total_cash/…);
dine-in session close. AFTER the transaction — GL (`JournalPostingService::postPaidSale`,
idempotent), cash-bank movement (`postSalesCashBankMovement`, idempotent), department custody
(`DepartmentConsumptionService::processSaleOrder`, custody sub-ledger only, idempotent per ledger).
`finalizePaidSale` is fenced: it throws on a Branch Server (H9), and printing/KOT are NOT part of it.

**Edge already performed locally at sale time** (`EdgeLocalPosService` sale transaction):
sale/line/payment rows with canonical identities, `edge_sync_state='pending'`,
`inventory_posted=FALSE`, `edge_operational_stock_posted=TRUE`; the SAME shared
`SaleOperationalSettlementService::settle` (sales_ledgers + shift counters on the EDGE shift row);
PROVISIONAL operational stock only (`EdgeOperationalStockService` — official FEFO is fenced);
`unit_cost=0`/`cost_total=0` placeholders (no COGS); NO GL, NO cash-bank, NO department custody;
KOT/printing done physically at the branch; dine-in session closed locally on settle.

| Effect | Cloud finalize does | Edge already did | If ingestion called `finalizePaidSale` | V1 ingest rule |
|---|---|---|---|---|
| A. Row persistence | sale→paid, inventory_posted=true | rows exist on EDGE db (pending) | n/a — Cloud has no row yet; ingestion must CREATE Cloud rows first | ingest creates Cloud rows from envelope (new Cloud PKs, same canonical UUIDs), `edge_sync_state='synced'` |
| B. Shift settlement (sales_ledgers + shift counters) | `settle()` INSIDE txn | **YES — already settled on the EDGE shift row (appliance DB)** | **DOUBLE-APPLY** (if row written non-paid first) or **SILENTLY SKIPPED** (paid early-return). `settle()` is explicitly NOT re-entrant (its own docblock) and silently no-ops on a non-open shift | NEVER call `settle()`. Ingest runs a dedicated **mirror projection**: sales_ledgers rows idempotent per Cloud sale id + mirror-shift counter increments applied exactly once per sale_uuid (safe — the Cloud mirror starts at zero; the Edge increments live only in the appliance DB; exactly-once is guaranteed by the registry claim in the same txn; NO status-open gate). Counters are required on the mirror because `SalesReportEngine` aggregates SHIFT columns (`SUM(s.expected_cash)`, SalesReportEngine.php:542). A future shift-close-summary envelope VERIFIES totals against the projection — it never adds on top |
| C. Official FEFO stock | postOutFefo + recipe + modifier stock, `inventory_posted` guard | NO — provisional operational stock only (fenced) | **SKIPPED ENTIRELY** by the `status==='paid'` early-return — the envelope arrives paid | Cloud-authoritative at ingest: run FEFO/recipe/modifier consumption under the ingestion authority scope, exactly once per sale_uuid |
| D. COGS | from FEFO ledger costs | NO (zeros) | skipped with C | Cloud-authoritative at ingest (never trust Edge zeros) |
| E. GL / finance | postPaidSale AFTER txn | NO | **WOULD POST — with WRONG (zero) COGS** because C/D were skipped | Cloud-authoritative at ingest, AFTER official stock/COGS, inside the ONE ingest transaction boundary |
| F. Cash-bank | postSalesCashBankMovement | NO | would post (amount correct, but ordering unguarded) | Cloud-authoritative at ingest |
| G. Department custody | processSaleOrder (mirrors official out-movements) | NO | would deduct custody with NO official movement behind it | Cloud-authoritative at ingest, after C |
| H. Printing / KOT | not in finalize (controllers) | **YES — physically printed at the branch** | n/a | NEVER re-print; store KOT identity snapshots only |
| Session close | closeRestaurantTableSession inside txn | **YES — closed locally** | would mutate Cloud table occupancy for a branch the appliance owns | mirror session created already-closed; Cloud table rows untouched |

**Verdict (the critical question answered): Cloud ingestion must NOT call
`SalesService::finalizePaidSale`.** The early-return on `status==='paid'` makes the naive call
maximally dangerous — it would SKIP official stock + COGS entirely while STILL posting GL (from
zero-COGS lines), cash-bank, and department custody. And writing the row non-paid first to dodge
the early-return would instead DOUBLE-run the non-re-entrant shift settlement. A dedicated
`EdgeInboundSaleIngestionService` composes the authoritative sub-services directly (C→D→E→F→G in
one boundary) and never runs B/H/session-close.

---

## 4. Cloud authoritative ingest contract — `EdgeInboundSaleIngestionService`

One repository-native Cloud service (master+tenant, runs ONLY on the Cloud instance), keyed by
canonical sale identity. It must NOT call `SalesService`'s normal POS entrypoints; it re-uses the
*authoritative* sub-services directly with the frozen envelope as input:

Authoritative on Cloud at ingestion:
- **Official stock / FEFO** — post the official outbound movement for each stock-tracked line
  (recipe-aware), exactly once per sale_uuid.
- **COGS** — computed by Cloud FEFO costing at ingestion time (the Edge `unit_cost=0` placeholders
  are never trusted).
- **GL / finance posting** — journal entries from the envelope's frozen totals.
- **Cash-bank posting** — from the envelope's frozen payments.
- **Cloud reporting projection** — the ingested `sales_orders` row (+lines/payments) with
  `edge_sync_state='synced'`, `business_date`, and original occurred-at timestamps.

Respected (NEVER re-run) from the Edge envelope:
- Sale/line/payment row *content* — totals, prices, discounts (none in V1), tax: frozen at sale time.
- The APPLIANCE's operational settlement — the Edge shift row's counters live only in the appliance
  DB and are never resubmitted; the envelope's frozen totals are the AUTHORITY the Cloud mirror
  projection is rebuilt from (below), never an instruction to re-run business settlement.
- Printing/KOT side effects — already happened physically at the branch; Cloud stores identity
  snapshots only, and never enqueues print jobs for an ingested sale.

Mirrored-parent reconciliation (by canonical identity, upsert-once semantics):
- `shift` — resolve `shift_uuid` on Cloud; if absent, create a MIRROR row (same shift_uuid,
  branch/terminal/user references, business_date, open facts from the envelope's shift snapshot)
  flagged as edge-origin. The mirror's counters start at zero and are rebuilt as a **projection**:
  incremented exactly once per ingested sale inside the exactly-once ingest transaction (required
  because `SalesReportEngine` aggregates shift columns directly). This is reconstruction, not
  double-settlement — the Edge-side increments never leave the appliance DB, and idempotency is
  carried by the sale_uuid registry claim, not by counter arithmetic.
- `restaurant_table_session` — resolve `session_uuid`; create an identity-level mirror already in
  its final (closed) state for dine-in history. Cloud restaurant_table rows are never touched.

---

## 5. Immutable sale envelope (wire schema v1)

`edge-sale-envelope-v1` — canonical JSON (same canonicalJson rules as the bootstrap wire contract),
immutable once written; `content_hash = sha256(canonicalJson(envelope minus content_hash))`.

```jsonc
{
  "envelope_schema_version": "edge-sale-envelope-v1",
  "tenant_id": 42, "tenant_code": "…",
  "branch_id": 3,                        // config-replicated stable id, binding-verified
  "device_public_uuid": "…",
  "activation_epoch": 2,
  "config_revision": 7,                  // revision the sale was CREATED under (outbox snapshot)
  "config_schema_version": "edge-config-v1",

  "sale_uuid": "01J…",                   // THE identity
  "sale_no": "SO-3-9-01J…",              // display label
  "client_uuid": "…",                    // original POS idempotency token (audit)
  "business_date": "2026-08-13",
  "occurred_at": "2026-08-13T14:02:11+05:00",   // sale finalized (appliance clock, TZ-explicit)
  "created_at": "…", "settled_at": "…",

  "order_type": "dine_in",               // quick_sale | takeaway | dine_in
  "terminal_id": 9, "terminal_code": "TERM1",
  "user_id": 5, "employee_code": "USR-C",
  "shift": { "shift_uuid": "01J…", "business_date": "…", "opened_at": "…",
             "terminal_id": 9, "opened_by_user_id": 5 },
  "table_session": { "session_uuid": "01J…", "restaurant_table_id": 4,
                     "restaurant_waiter_id": 2 },          // dine-in only, identity snapshot
  "kot_events": [ { "event_uuid": "…", "sequence_no": 1, "event_type": "new" } ], // identity snapshots
  "customer": null,                      // V1: snapshot/reference only if the sale carried one

  "totals": { "sub_total": 1120.0, "discount_amount": 0, "tax_amount": 60.0,
              "service_charge_amount": 0, "grand_total": 1180.0, "paid_amount": 1180.0,
              "change_amount": 0, "currency_code": "PKR" },

  "lines": [ {
      "line_uuid": "01J…", "product_id": 17, "product_variant_id": null,
      "combo_id": null, "product_name": "Burger",          // frozen snapshot fields
      "quantity": 2, "unit_price": 500.0, "line_total": 1000.0,
      "discount_amount": 0, "tax_amount": 0,
      "modifiers": [ { "modifier_id": 3, "name": "Extra Cheese", "price_delta": 50.0 } ],
      "operational_stock": { "consumed": true, "baseline_uuid": "01J…" }   // provisional evidence
  } ],

  "payments": [ { "payment_uuid": "01J…", "payment_method_id": 1, "method_type": "cash",
                  "amount": 1180.0, "paid_at": "…" } ],

  "local_state": { "edge_sync_state": "pending", "edge_operational_stock_posted": true },

  "content_hash": "sha256-hex"
}
```

Rules: parents referenced by canonical UUID; config references (product/terminal/user/branch/
payment-method ids) are replicated-config ids validated against the binding + historical config
acceptance (§9); numeric Edge PKs of sale/line/payment rows never appear.

---

## 6. Local outbox contract

`sales_orders.edge_sync_state` (`'pending'`, + `edge_activation_epoch`,
`edge_operational_stock_posted`) is **discovery state, not a sync authority** — it cannot carry an
immutable envelope, a monotonic sequence, per-attempt state, or an ack audit. V1 adds an
**append-only Edge outbox** (edge-only migration, `database/migrations/edge/`):

`edge_sync_outbox`
- `id` (monotonic local sequence — InnoDB auto-increment)
- `sale_uuid` (unique)
- `envelope_schema_version`, `config_revision`, `activation_epoch`
- `envelope` (LONGTEXT canonical JSON, IMMUTABLE after insert)
- `content_hash` (sha256 of the envelope)
- `state`: `pending → leased → acknowledged` (+ `failed_permanent` for hard conflicts only)
- `lease_owner`, `lease_expires_at` (crash-safe re-lease; mirrors the print-worker lease pattern)
- `attempts`, `last_error`, `first_sent_at`, `acknowledged_at`
- `ack_ingestion_uuid`, `ack_payload` (durable ACK audit)

Written **in the same DB transaction that finalizes the paid sale** (this is also what snapshots
`config_revision` at sale time). The envelope is therefore exactly-once-created, and a sale without
an outbox row cannot exist (and vice versa).

State machine: `pending` →(lease)→ `leased` →(Cloud ACK verified)→ `acknowledged`; lease expiry →
back to `pending`; same-hash conflict NEVER occurs from the appliance's own outbox (immutable
envelope); a Cloud `CONFLICT` verdict → `failed_permanent` + operator surface (never silent).
Acknowledged rows are RETAINED (durable audit; pruning is a far-future policy decision, never
"delete on ack"). `sales_orders.edge_sync_state` flips `pending → synced` only when the outbox row
reaches `acknowledged` — HTTP success is NEVER the authority; only a verified ACK body (sale_uuid +
matching content_hash + Cloud ingestion identity) is. Crash between Cloud commit and local ack: the
row re-leases and the retry replays the SAME immutable envelope; Cloud replies `already_applied`
with the original ACK (§7) and the outbox completes.

---

## 7. Cloud idempotency + conflict policy

Cloud keys ingestion by **`sale_uuid`** with a durable per-envelope registry:

`edge_inbound_sale_ingestions` (tenant DB): `sale_uuid` (unique), `content_hash`,
`ingestion_uuid` (Cloud-minted ULID), `device_public_uuid`, `activation_epoch`, `config_revision`,
`status` (`applied` | `conflict` | `refused_*`), `ack_payload`, `ingested_sales_order_id`,
timestamps. Row inserted (uniquely claimed) in the SAME transaction as the authoritative posting.

| Case | Behaviour | HTTP |
|---|---|---|
| First envelope | apply once (one Cloud transaction), persist registry row, ACK | 201 `applied` |
| Exact replay (same sale_uuid + same hash, registry `applied`) | ZERO mutation; return the stored ACK | 200 `already_applied` |
| Concurrent same-envelope requests | unique claim on `sale_uuid` inside the ingest transaction; loser waits/retries the registry read → exactly one official posting | 201 / 200 |
| Same sale_uuid + DIFFERENT hash | hard conflict; registry `conflict`; never partially applied | 409 `ENVELOPE_CONFLICT` |
| Wrong tenant/branch/device binding | refuse before any write | 403 `BINDING_MISMATCH` |
| Stale activation epoch (< current generation) | refuse (a replaced appliance may not post) | 409 `STALE_ACTIVATION` |
| Future/unknown config revision (> Cloud current for the branch) | refuse `retryable=false` until Cloud knows the revision (compatibility surface) | 422 `UNKNOWN_CONFIG_REVISION` |
| Unsupported payment/order feature | refuse; explicit verdict (never silent partial) | 422 `FEATURE_UNSUPPORTED` |
| Malformed totals (lines ⊄ totals, paid < grand_total, negative qty) | refuse | 422 `ENVELOPE_INVALID` |
| Hash of body ≠ `content_hash` | refuse | 400 `HASH_MISMATCH` |

Every request (accepted or refused) writes a durable ingestion audit row + structured log
(`[edge-sync-audit]`) with device identity.

---

## 8. ACK contract

ACK is returned **only after the ONE Cloud ingest transaction has committed** the full authoritative
posting (official stock + COGS + GL + cash-bank + registry row). ACK body:

```jsonc
{ "status": "applied" | "already_applied",
  "sale_uuid": "…", "content_hash": "…",           // echo — the appliance verifies both
  "ingestion_uuid": "…",                            // Cloud ingestion identity
  "activation_epoch": 2, "config_revision": 7,      // accepted context
  "ingested_at": "…" }
```

No partial-state ACK exists: if any authoritative posting step fails, the transaction rolls back,
the registry row is not committed, and the appliance retries the identical envelope later.

---

## 9. Config-revision semantics (historical acceptance)

- A sale is stamped with the `config_revision` it was CREATED under (outbox snapshot, §6).
- Cloud accepts any **historical** revision `≤` its current revision for the branch **whose
  identities resolve**: envelope config references (product/terminal/user/payment-method) must exist
  on Cloud (tombstoned/deleted-upstream is FINE — tombstones keep rows resolvable by design of
  EDGE-CONFIG-REFRESH-1; an id Cloud has never seen is `ENVELOPE_INVALID`).
- **Price snapshot authority: the envelope.** Cloud validates internal consistency (lines sum to
  totals, tax arithmetic) but NEVER reprices the customer's agreed sale with today's catalog.
- Tombstoned product/user at ingest time: accepted (historical validity); Cloud reporting reads the
  frozen name/price snapshots on the lines.
- Minimum supported config schema: `edge-config-v1`; envelope schema `edge-sale-envelope-v1`;
  anything else → `software_update_required` classification via the compatibility contract.
- A refresh to N+1 on the appliance NEVER invalidates a pending outbox envelope created under N.

---

## 10. Ordering / dependency contract

**Decision: Cloud ingestion reconciles by canonical identity — it does NOT depend on HTTP arrival
order.** Every envelope is self-contained: it carries the full shift/session identity snapshots it
needs, so a sale can be ingested even if a "shift stream" never exists.

- The appliance sender still drains the outbox in **monotonic local sequence** (id ASC) as a
  courtesy ordering — it makes mirrors appear in natural order — but correctness never rests on it.
- shift-open before sale: NOT required — the sale envelope's shift snapshot creates/resolves the
  mirror shift idempotently by `shift_uuid`.
- table-session before dine-in sale: NOT required — same rule via `session_uuid`.
- sale before shift-close: NOT required — V1 has no shift-close stream; the mirror shift stays open
  on Cloud and its counter projection grows per ingested sale (order-independent: increments
  commute); a future V2 shift-summary envelope closes the mirror and VERIFIES the projection
  against the appliance's frozen close totals (never adds on top).
- Cross-sale ordering: independent sales commute (distinct sale_uuids, per-sale official postings).

---

## 11. Split-brain proof (fence stays closed)

`BranchOperatingModeService` (frozen, proven by `EdgeSplitBrainStockMySqlTest` /
`EdgeBranchServerFencingMySqlTest`):
- Branch Server: official stock ALWAYS fail-closed (`CODE_BRANCH_SERVER_OFFICIAL_STOCK`).
- Cloud + branch handed to server (active/closing/suspended): ordinary Cloud sale mutation AND
  official stock mutation both fail closed.

Sync ingestion needs Cloud-side official authority for EXACTLY such branches. Design (V1 slice):
an explicit **ingestion authority scope** — `EdgeIngestionAuthority::run(Branch $b, Closure $fn)` —
a container-scoped, non-request-settable context that only `EdgeInboundSaleIngestionService` enters.
`assertOfficialStockMutationAllowed` gains ONE additional pass condition: cloud instance AND branch
handed to server AND the ingestion scope is active for THIS branch. Ordinary Cloud requests (no
scope) keep today's exact behaviour — the fence is never weakened for them, and a Branch Server can
never enter the scope (cloud-instance check first). Sale-side `assertSaleMutationAllowed` is NOT
relaxed at all: ingestion does not go through POS controllers/SalesService entrypoints.

---

## 12. Security

Reuse the existing device trust — **no second credential system**:
- Transport: the central `api/edge` route group; `edge.device.auth` middleware
  (X-Edge-Device-ID + bearer secret, constant-time hash compare, revocation-aware).
- Binding: tenant/branch come from the authenticated `EdgeDevice` row ONLY; envelope
  tenant/branch/device fields must MATCH the device row or `BINDING_MISMATCH`.
- Replay resistance: idempotent-by-design (sale_uuid + content_hash); a replayed request cannot
  double-post; epoch fencing rejects replaced appliances.
- Body/hash binding: `content_hash` covers the canonical envelope; Cloud recomputes before any write.
- Limits: payload cap (1 envelope per request in V1, size-limited ~1 MB), per-device throttle on the
  route group; audit identity = device_public_uuid + ingestion_uuid in every log/registry row.
- No Cloud password/PIN anywhere in the envelope (same SECRET_FIELDS discipline as bootstrap).

---

## 13. Failure / recovery matrix (executable test plan)

| # | Failure | Expected (exact) |
|---|---|---|
| 1 | Network drop before Cloud receives | outbox row stays `pending`/re-leases; no Cloud row; retry sends same envelope; `applied` once |
| 2 | Network drop during request (unknown outcome) | lease expires → retry same envelope; Cloud either `applied` (first time) or `already_applied` (commit had happened); no double posting |
| 3 | Cloud commits, response lost | retry → `already_applied` + original ACK; outbox `acknowledged`; zero second mutation (registry row proves) |
| 4 | Edge crashes before local ack write | outbox still `leased`/expired → re-lease → replay → `already_applied`; state converges to `acknowledged` |
| 5 | Duplicate retry (sequential) | second gets `already_applied`, ACK identical, Cloud row count unchanged |
| 6 | Two CONCURRENT retries | unique sale_uuid claim inside ingest txn → exactly one `applied`; other `already_applied`/brief conflict-wait; one official posting (row-count proof) |
| 7 | Cloud DB rollback after official stock but before GL | ONE transaction ⇒ stock AND registry roll back together; nothing persisted; retry re-applies cleanly (assert stock ledger empty after forced late failure) |
| 8 | Malformed envelope (bad totals/negative qty/missing section) | 4xx refusal, durable refusal audit, ZERO tenant-data writes; outbox marks attempt + surfaces error (not acknowledged) |
| 9 | Stale activation epoch | 409 STALE_ACTIVATION; refused before writes; outbox `failed_permanent` + operator surface |
| 10 | Same sale_uuid, different hash | 409 ENVELOPE_CONFLICT; registry `conflict`; no mutation; operator surface |
| 11 | Unsupported feature (card payment, return) | 422 FEATURE_UNSUPPORTED; refused pre-write |
| 12 | Cloud temporarily unavailable (5xx/timeout) | outbox retries with backoff; envelope immutable; eventual `applied` exactly once |

---

## 14. Implementation slices (after this preflight is approved)

1. **SYNC-1B — Edge outbox**: `edge_sync_outbox` migration + envelope builder (same-transaction
   write in `EdgeLocalPosService` finalize/settle paths) + immutability/hash tests.
2. **SYNC-1C — Cloud ingest registry + `EdgeInboundSaleIngestionService`**: validation → refusals →
   the ONE ingest transaction (mirror shift/session by identity, sale/line/payment rows,
   official FEFO + COGS + GL + cash-bank via existing authoritative services) + `EdgeIngestionAuthority`
   scope + the full idempotency/conflict/rollback MySQL proofs (failure matrix rows 5–11).
3. **SYNC-1D — transport**: device-authed `POST /api/edge/sync/sales` route + ACK verification +
   outbox sender loop (lease/backoff) + end-to-end two-database proof (matrix rows 1–4, 12).
4. **SYNC-1E — operator surface**: outbox/ingestion status in `edge:local:status` + readiness
   (`sync` state), Cloud-side ingestion audit listing; compatibility capability `sale_sync_v1`.

Each slice lands with its MySQL proofs; Local Mode activation remains OUT of all four slices.
