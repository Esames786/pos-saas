# OFFLINE EDGE — F3: PURCHASE RETURN PARITY

Status: **built and proven in executable tests** (appliance authority, two-database Cloud sync over the real device-authenticated
transport, independent-process race, encrypted backup recovery, real operator HTTP page, artifact boot). Cloud remains the
stock-valuation / AP / GL authority; the appliance never posts a purchase-return document, stock ledger, subledger or journal
itself. F3 is narrow: no purchase ordering, no GRN creation, no supplier advance, no arbitrary purchasing adjustments.

## The Online contract F3 mirrors (re-grounded from current code, canonical 1ce55c7)

| Fact | Where it lives on the Cloud |
|---|---|
| Authority | `PurchaseReturnService::createDraft(header, lines, userId)` then `post(return, userId)`: header lock, `calculateTotals`, `validateReturnableQuantities` (per source goods-receipt line: `quantity_received − Σ posted return lines sourced from it` — `returnableForGrnLine`; per product: official branch `stock_balances` on hand must cover the return), official stock OUT per line by FEFO (`InventoryService::postOutFefo`, movement `purchase_return`), supplier subledger CREDIT `purchase_return` for the grand total (`PurchasingService::postSupplierLedger`), status `posted`, then the production-fixed GL `JournalPostingService::postPurchaseReturn` (Dr `2100` Accounts Payable / Cr `1400` Inventory Asset = grand total, entry on `return_date`) linked by `purchase_returns.journal_entry_id` |
| Source of a return | the GOODS RECEIPT (`goods_receipts` / `goods_receipt_lines`: received quantity, unit cost, batch, expiry). A bill is optional context (`purchase_bills.goods_receipt_id`). Online also allows a return WITHOUT a source receipt (validated against official stock only) |
| Valuation | the receipt line's `unit_cost` (canonical default when none is given); line total = qty × unit cost (− discount + tax, none offline) |
| Business date | `return_date` → GL `entry_date` |
| Cash / bank | none — a purchase return credits Accounts Payable only |
| Supplier position | `suppliers.current_balance` DOWN by the grand total; the canonical advance guard does NOT apply to purchase returns |
| Reason | required on the header or on every line (`PurchaseReturn::REASON_CODES`) |
| Permissions | `tenant.purchase-returns.store` (draft) and `tenant.purchase-returns.post` (post) — route name = permission |
| Printing / reporting | no thermal document; the show page and `PurchaseReportService` (Cloud) |

Offline answers: PURCHASE_RETURN_OFFLINE_PARITY = FULL_OFFLINE_PARITY for GRN-sourced returns; a return without a source receipt =
ONLINE_REQUIRED (it needs the Cloud's official stock check); LOCAL_STOCK_RETURN_TO_SUPPLIER = operational stock OUT exactly once.

## Warm purchase-return projection (read-only, device-authenticated)

`EdgePurchaseReturnProjectionService` (Cloud) packages the branch's POSTED goods receipts within `edge.purchase_returns.grn_window_days`
(90): supplier identity / status, optional linked bill, lines with `quantity_received`, the OFFICIAL `cloud_returned_quantity` (posted
return lines sourced from the line — the canonical arithmetic), `unit_cost`, product / variant / unit / batch / expiry; the reason codes,
the permission names, and the Edge events the Cloud has APPLIED (45 days). Watermark `pr:` + sha256 fingerprint (receipts, lines,
posted sourced return lines, suppliers, applied registry) is advertised on every heartbeat (`EdgeStandbyAdvertiser`); the standby pulls
`POST /api/edge/purchase-returns/refresh` when it moves (`EdgeStandbyFreshnessService::refreshPurchaseReturnIfBehind`). Appliance
tables `edge_purchase_return_grns` / `_grn_lines` / `_applied_events` are replaced wholesale; the binding carries
`purchase_return_cache_watermark / as_of / refreshed_at`, `standby_purchase_return_watermark_seen / as_of_seen`, `purchase_return_rules`.
Freshness = the same rule as every warm cache; the takeover proof records `purchase_return_cache_current`. Stale → fail closed.

## LOCAL RETURNABLE (no double return, no over-return)

`returnable(line) = quantity_received − cloud_returned_quantity − Σ local return lines whose event is NOT in the applied set`. An ACK
before the next refresh keeps subtracting; the refresh that lists the event as applied stops subtracting it exactly when the Cloud's
returned quantity includes it. The projected receipt and its lines are locked `FOR UPDATE` as the FIRST statements of the transaction and
the pending aggregate is a locking read — two terminals serialise (§11 race test: 7 + 7 of 10 → exactly one accepted).

## The immutable PURCHASE_RETURN event

`edge-purchase-return-envelope-v1` (`event_type=purchase_return`): event_uuid, tenant / branch / device / epoch / config revision,
supplier identity, goods receipt identity (+ linked bill), return / business date, reason, notes, lines (line_uuid, cloud_grn_line_id,
product / variant, quantity, unit cost, line total, reason), totals, operator / terminal, projection watermark, created_at,
`content_hash = sha256(canonicalJson(envelope − hash))`. Same outbox (`createForFinanceEvent`, `sale_uuid` = event_uuid), same lease /
verified-ACK / reconciliation; the sender routes the schema to `edge.sync.purchase_returns_url`.

Cloud `POST /api/edge/sync/purchase-returns` → `EdgeInboundPurchaseReturnIngestionService` → registry
`edge_inbound_purchase_return_ingestions` (same uuid + hash → already_applied; different hash → conflict). Inside ONE transaction with the
registry claim, under `EdgeIngestionAuthority`: resolve the receipt (branch + supplier match), each line (belongs to the receipt, same
product / variant), the operator (must hold BOTH Online permissions), then `PurchaseReturnService::createDraft` + `post`;
`EdgeFinancePostingVerifier::verifyPostedPurchaseReturn` proves the posted document, the linked balanced Dr 2100 / Cr 1400 journal for the
total, exactly one subledger credit, and official stock OUT equal to the returned quantities — or everything rolls back and the event is
never APPLIED. The canonical authority's refusal (more than the receipt line still holds, official stock no longer covers it) is
`PURCHASE_RETURN_REFUSED` — terminal (`refused` 422 with the envelope identity → `failed_permanent`, a supervisor matter). The reconcile
endpoint unions the registry. The F2 supplier projection's applied set also lists applied purchase returns, so the provisional
payable effect (Cr subledger) recorded through the F2 position stops being subtracted exactly when the Cloud payable includes it.

## Local effect

`EdgeOperationalStockService::purchaseReturnOut`: quantity-only movement (`purchase_return`, `out`) bound to event + line, idempotent, never
below what the branch holds (canonical wording "Insufficient branch stock to return …"). Local event + lines in
`edge_local_purchase_return_events` / `_lines`; the supplier effect as a payable delta in the F2 effects table; sync state derived
from the outbox + applied set (PENDING SYNC → POSTED AT CLOUD → OFFICIAL / REFUSED). No local GL / AP / stock ledger, ever.

## Operator surface

`Purchase Returns` in the cashier header (permission-gated) → `/edge/local/pos/purchase-returns`: goods receipts (supplier filter, GRN
search, returnable total) → the receipt's Received Lines (Product, Variant, Batch, Received, Already Returned (+ pending badge),
Returnable, Return Qty, Unit Cost, Line Total, Reason), header Return Date / Reason / Notes, Return Total, "Post Return (pending sync)",
recorded returns with sync state. Server-side: view needs `store` or `post`; posting needs BOTH (offline the draft and the post are
one step because drafts are Cloud documents).

## Handback, backup, restart

Blockers `PURCHASE_RETURN_PENDING`, `PURCHASE_RETURN_PERMANENT_FAILURE`, `PURCHASE_RETURN_DIVERGENCE` join the existing set. Backup census
adds the projection tables and the local event tables (exact bytes / hash survive restore; the operational movement once). Sync state is
derived, so a restart changes nothing.

## Executable proof

| Class | Proves |
|---|---|
| `EdgePurchaseReturnAuthorityMySqlTest` (6) | return against the source receipt: local stock 10 → 3 once, provisional supplier effect, PENDING SYNC, immutable envelope with a self-consistent hash, no local official effects; returnable accounts for pending local returns and never over-returns; refusals leave nothing behind (no source receipt → Online POS, unknown receipt / line, no quantity, missing / invalid reason, invalid date, insufficient LOCAL stock rolls back, inactive supplier); stale projection fails closed; a cashier and a store-only user cannot post; handback findings (pending / failed / divergent) |
| `EdgePurchaseReturnSyncHttpMySqlTest` (3, two databases) | network-down end to end A–F: projection pulled on the heartbeat, PURCHASE_RETURN_CACHE_CURRENT at takeover, offline return of 7/10 (local stock once, F2 position 50,000 → 47,900 provisional), handback blocked while pending, reconnect with the appliance still the writer, the Cloud posts the OFFICIAL return exactly once (document, FEFO stock OUT 7, subledger credit 2,100, Dr 2100 / Cr 1400 2,100, linked journal, payable 47,900, registry), the ACK re-applies nothing, LOST ACK recovered once, replay → already_applied, tampered → conflict, controlled handback, convergence (returnable 3 = 10 − 7, event OFFICIAL, F2 position 47,900 with nothing pending). Stale projection: an Online return the appliance could not pull → takeover records `purchase_return_cache_current=false`, the offline return is refused, nothing recorded. Cloud refusal: official stock dropped after the last heartbeat → `PURCHASE_RETURN_REFUSED`, terminal, nothing saved; finance-incomplete seam → rolled back, next tick applies once; permanent failure blocks the handback |
| `EdgePurchaseReturnRaceTest` (1, independent processes) | received 10; two terminals return 7 at a barrier: exactly one accepted, the loser meets the canonical returnable message, local stock moves for the accepted one only, one event, the remaining 3 still returnable, one more refused |
| `EdgePurchaseReturnBackupRecoveryMySqlTest` (1) | the pending event, its lines, the supplier effect, ONE operational movement and the projection survive encrypted backup → loss → restore with the same bytes / hash |
| `EdgePurchaseReturnHttpMySqlTest` (3, real HTTP) | the page carries the Online UX; the operator returns goods through the real routes (reason required, 201 pending, returnable 3, over-return 422, event view); a cashier gets 403 everywhere and no header entry point; a store-only user may look but gets 403 on post |
| gates | `EdgeArtifactBootTest` (F3 routes register; Cloud purchase-return authority / projection / ingestion absent; appliance runtime ships), `EdgeArtifactTest`, `EdgeBranchServerRegistrationTest` census, `EdgeBladeCompileGateTest` (page compiles, lints, parses as JavaScript) |

## Classification

- PURCHASE_RETURN_OFFLINE_PARITY: **FULL_OFFLINE_PARITY** for returns against a source goods receipt within the projection window
  (90 days), from a fresh projection. Return without a source receipt: ONLINE_REQUIRED. Receipts older than the window: ONLINE_REQUIRED.
- Not implemented (out of F3 by design): purchase ordering, GRN creation, supplier advance, arbitrary purchasing adjustments.
