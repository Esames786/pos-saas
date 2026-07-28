# Sale Idempotency — Design (2026-07)

> Implemented in SALE-IDEMPOTENCY-1 (commit follows). Foundation for live POS
> double-submit protection AND future Bingoo Edge offline sale sync.

## Contract
- **One logical sale = one `client_uuid`.** The POS generates it when a sale
  begins (first item), persists it (`localStorage` `pos_sale_uuid`), sends it on
  every submit, and **rotates it only after a successful/replayed sale or an
  explicit clear/cancel**. A refresh, double-click, or network timeout reuses the
  SAME uuid — never a new one per HTTP attempt.
- **Same `client_uuid` + same payload = replay:** the existing finalized sale is
  returned (`idempotent_replay: true`); no second sales_order / payment / stock
  movement / COGS / journal / print job.
- **Same `client_uuid` + different payload = conflict:** HTTP **409**
  `SALE_IDEMPOTENCY_CONFLICT`; nothing is mutated; the original sale stands.
- Cloud/manual sales that don't supply a uuid get a **server-generated** one
  (model `creating` hook) so the column is always populated — backward compatible.

## Schema (`sales_orders`)
- `client_uuid` char(36) nullable, **unique** (`sales_orders_client_uuid_unique`).
  Uniqueness is naturally tenant-scoped (per-tenant DB).
- `client_payload_hash` char(64) nullable — SHA-256 of the canonical payload.

## Canonical payload hash (`SalesOrderController::canonicalSalePayload`)
INCLUDES the customer's intent: branch/terminal/customer, order source/type,
table-session, held_sale ref, delivery channel/rider/address, discount type/value,
promo, tip; per line product/variant/kind/combo/qty/unit_price/discount/tax/
modifiers; per payment method/amount/tender. EXCLUDES CSRF, key order,
server-generated `sale_no`/ids, browser-only fields. `SaleIdempotencyService`
recursively ksorts arrays and normalizes numerics (`5`, `5.0`, `"5.00"` hash
equally) → `sha256`. A sale with a null stored hash (pre-feature) is treated as a
replay on a uuid hit, never a false conflict.

## Concurrency (race)
Pre-check alone is insufficient (two requests can both pass before either commits).
The DB **unique index** is the real guard: a losing concurrent create throws
`Illuminate\Database\UniqueConstraintViolationException`; the controller catches it,
re-fetches the finalized sale by `client_uuid`, and returns it as a replay. Any
other unique collision is rethrown. Result for two identical concurrent requests:
exactly one sale, one posting set, both callers get the same result, no 500.

## Print jobs
`store()` never enqueues receipt/KOT (the POS triggers those after the sale). On a
replay the response carries `idempotent_replay: true`; the POS **skips** auto
receipt/KOT (no duplicate kitchen ticket) and points the cashier to Recent Orders
for a manual reprint if the first attempt never printed.

## Reuses the official pipeline
Idempotency wraps — never bypasses — `SalesTotalsService`,
`SalesService::finalizePaidSale`, `InventoryService` (FEFO + negative-stock policy),
COGS and journal posting. The future Edge sync engine will reuse this exact
foundation (send canonical payload + client_uuid → replay-safe official posting).

## Held sales
A held sale is created with an auto uuid; completing it stamps the submitted uuid +
hash on the same row. A retry of the completion finds the now-finalized sale by
uuid → replay. Backward compatible: legacy/manual flows without a uuid keep working.
