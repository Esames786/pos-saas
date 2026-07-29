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
replay the response carries `idempotent_replay: true`. **HARDEN-1:** the POS no
longer *skips* printing on a replay — it re-runs `maybePrintReceipt` + the KOT delta,
because the earlier attempt may have died (network timeout) *before* printing. Both
print paths are idempotent so this recovers a missed copy without ever duplicating
one: the receipt endpoint is **ensure-once** (`PrintJobService::queueReceipt(..., ensureOnce: true)`
reuses a live queued/printed receipt job for the sale; a failed job is *not* reused,
so a genuine miss is still recoverable), and KOT already prints only the un-sent line
delta (`kot_sent_quantity`). An explicit reprint (`?reprint=1`) forces a fresh job.

## HARDEN-1 — proving races and recovering prints
- **Real concurrency (not sequential):** 12 genuinely-parallel HTTP `POST /pos` with
  one shared `client_uuid` (barrier-synchronised curl against `php artisan serve`).
  Result: all 12 → HTTP 200, all resolved to the same `sale_id`, exactly one fresh
  post (`idempotent_replay:false`), 11 replays, and exactly **one** `sales_orders`
  row. No 500. The loser of the DB `client_uuid` unique-index race is caught as
  `UniqueConstraintViolationException` → `resolveFinalizedWithRetry()` (bounded
  re-fetch) → replay; if the winner isn't yet visible it returns a retryable **503
  `SALE_IDEMPOTENCY_PENDING`** (never a 500).
- **Null-hash safety:** a stored sale with no `client_payload_hash` is never silently
  replayed. `payloadMatches()` is strict (`hasVerifiableHash` + `hash_equals`); an
  existing sale under the same uuid with no verifiable hash returns **409
  `SALE_IDEMPOTENCY_UNVERIFIABLE`**. Same uuid + different verified payload → **409
  `SALE_IDEMPOTENCY_CONFLICT`** (both proven over real HTTP; conflict created no row).
- **UUID isolation (POS):** the mid-sale uuid moved from a single global
  `localStorage` key to **`sessionStorage`** (per-tab) under a key scoped to
  `origin:branch:terminal`. Two registers in two tabs never share a uuid; two
  branches/terminals never collide; a refresh in the *same* tab still reuses its uuid.
- **Canonical payload line order is immaterial:** `canonicalSalePayload()` sorts lines
  by stable identity (`client_line_key|product_id|product_variant_id|line_kind`) and
  payments by `payment_method_id|amount`, so harmless browser reordering never
  triggers a false conflict.

## Reuses the official pipeline
Idempotency wraps — never bypasses — `SalesTotalsService`,
`SalesService::finalizePaidSale`, `InventoryService` (FEFO + negative-stock policy),
COGS and journal posting. The future Edge sync engine will reuse this exact
foundation (send canonical payload + client_uuid → replay-safe official posting).

## Held sales
A held sale is created with an auto uuid; completing it stamps the submitted uuid +
hash on the same row. A retry of the completion finds the now-finalized sale by
uuid → replay. Backward compatible: legacy/manual flows without a uuid keep working.
