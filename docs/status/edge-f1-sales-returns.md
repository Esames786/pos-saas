# OFFLINE EDGE — F1: SALES RETURNS + CASH REFUNDS, EXACTLY ONCE

Status: **built and proven in executable tests** (appliance authority, two-database Cloud sync, independent-process
race, encrypted backup recovery, real cashier HTTP, artifact boot). Card / provider refunds stay ONLINE_REQUIRED.
Supplier finance is F2 (FINANCIAL_PARITY_PENDING). No physical pilot; production Local Mode not activated.

## The rules F1 implements

- PRE-SETTLEMENT VOID (held / draft / unsettled check) = the existing local operational cancel. No financial event.
- POST-SETTLEMENT VOID = SALES RETURN through the F1 authority. There is no "void a settled sale" shortcut.
- The return arithmetic is the ONLINE one: `SalesReturnService::computeReturn` (extracted from `processReturn`, which
  now uses it too): cap at remaining, line discount + order discount spread by gross, tax per line, remainder on the
  final quantity, delivery charge refunded only when the return leaves nothing of the order, refund must equal the
  computed total. Combo components restore stock in proportion to the returned header.
- Cloud remains the official financial truth. The Cloud posts the OFFICIAL return exactly once at ingestion through the
  existing `processReturn` (FEFO stock in, sales ledger, shift buckets, GL reversal with inventory restock / COGS
  reversal, cash-bank refund movement), verified finance-complete or refused atomically.
- Original tender policy: offline the till refunds CASH only (`edge.returns.offline_refund_methods`); card / bank /
  other refunds answer "needs the Online POS". A cash refund of a card sale is allowed where the business decides
  (Online lets the cashier override the default). Nothing is ever converted silently.
- Manager approval where the branch requires it (`branches.sales_return_approval_mode`): same action type
  (`sales_return`), same payload binding (sale, branch, method, amount), same single-use / expiry / requester
  semantics, verified locally against provisioned credentials; the approval audit travels inside the immutable event and
  the Cloud stores it with the registry row (and re-checks the approver exists and the bound amount).

## RETURNABLE-SALE WARM CACHE (an Online sale is returnable offline)

- The Cloud advertises `returnable_watermark` / `returnable_as_of` on every heartbeat (content hash over the branch's
  returnable sales, their line quantities / returned quantities and posted returns within `edge.returns.cache_window_days`,
  default 14). When it moves, the standby worker pulls `POST /api/edge/returnable/refresh` (device-authenticated).
- The appliance holds the projection as SHADOW rows in the canonical sales tables: `sales_orders.status = cloud_mirror`
  (never a report population status, never synced, never sold from), lines with the Cloud identities in
  `edge_returnable_sale_lines`, the tenders (default refund method), and the Cloud's posted returns mirrored as
  `sales_returns.status = cloud_mirror` so remaining discount/tax allocation is exact. It is a RETURN VALIDATION CACHE,
  not a second sales accounting authority.
- RETURNABLE = sold − Cloud returned − LOCAL returns the Cloud has not yet reflected: the shadow's `returned_quantity` is
  maintained as exactly that sum (recomputed on every refresh from the local return events' ACK state), so the canonical
  formula is the balance and two terminals serialize on the locked rows.
- Freshness gate for returning a Cloud sale: cache watermark = last advertised, or refreshed strictly after the last
  acknowledged heartbeat; otherwise fail closed with a business message ("not current on this branch server — complete
  this return on the Online POS or ask a supervisor"). Never a synchronous Cloud call after the WAN failed. Takeover
  records `return_cache_watermark` / `return_cache_fresh` in the freshness proof (RETURN_CACHE_WATERMARK).
- Sales older than the window are ONLINE_REQUIRED (documented product constraint).

## The immutable return event and its transport

- `edge-return-envelope-v1`: return_uuid (ULID), tenant/branch/device/epoch/config revision, original sale identity
  (`cloud` id or Edge `sale_uuid`), lines (product/variant, quantity, unit_code, price, discount, tax, total, Cloud line id
  or `line_uuid`), totals incl. delivery refund, refund method/amount, business_date, approval audit, actor/terminal/
  shift, freshness watermark, created_at, `content_hash = sha256(canonicalJson(envelope − hash))`.
- Queued in the SAME append-only outbox as sales (`edge_sync_outbox`, `sale_uuid` = return_uuid, schema tells the
  sender to route to `edge.sync.returns_url`), same lease / hash / verified-ACK / terminal-vs-retryable classification /
  lost-ACK reconciliation (the Cloud reconcile endpoint unions the return registry). `ORIGINAL_SALE_NOT_INGESTED` is
  retryable (the sale's own envelope may still be in flight).
- Cloud: `POST /api/edge/sync/returns` → `EdgeInboundReturnIngestionService` → registry `edge_inbound_return_ingestions`
  (return_uuid unique): same uuid + same hash → already_applied; same uuid + different hash → conflict, no mutation;
  `EdgeFinancePostingVerifier::verifyPostedReturn` (document, ledger, balanced GL, cash/bank refund movement for cash /
  bank refunds — a mappable account is REQUIRED —, FEFO stock reversal per stock-tracked product) or exception + rollback.

## Local effects (LOCAL_OPERATIONAL_RETURN vs CLOUD_OFFICIAL_RETURN)

- One transaction: the local `sales_returns` / `sales_return_lines` document (canonical, so reports and the return view
  work), `returned_quantity` increments, `EdgeOperationalStockService::returnIn` (quantity-only movement bound to
  return_uuid + line, idempotent, direction `in`; never applied twice — the Cloud's official reversal is separate and the
  standby refresh after handback re-bases the appliance on the Cloud position), the till (`total_refunds`,
  `total_cash_refunds`, `expected_cash` — the sale's own local open shift as Online, else the current terminal's open
  shift, because that is the drawer that physically pays), and the outbox event.
- The ACK and the lost-ACK recovery never touch the till or the operational stock again.
- Reports: the canonical `SalesReportEngine` sees the local return documents → Ret / Net / returned_qty on the operator's
  Quick Report at once, by the order's business day (Online rule). After Cloud ACK the Online report tells the same story.
- Backup census: `sales_returns`, `sales_return_lines`, `edge_returnable_sales`, `edge_returnable_sale_lines` join the
  encrypted backup; the pending event survives restore with the same hash.

## Cashier UI (Online return UX on the actual Branch Server page)

"Returns" in the header → find the sale (local or Online, badge) → per line: sold, returned, returnable, unit-aware −/+
stepper (whole = 1, weight/volume/length = 0.001) clamped to the remainder → live refund breakdown (items − discount +
tax + delivery charge only when the whole order comes back) → refund method (non-cash options disabled "needs the Online
POS") → reason → manager approval prompt where configured → posted view (return number, refund, sync state). Online has
no thermal return document, so there is no return print to mirror (RETURN_PRINT = parity: none).

## Executable proof

| Class | Proves |
|---|---|
| `EdgeReturnAuthorityMySqlTest` (4) | Online arithmetic on a local sale (partial then full: 90/90 lines, delivery only on completion), unit steps, till once, over-return capped with the refund-figure guard, return after complete refused, canonical Ret/Net; tender policy (card default → needs Online; cash allowed); pre-settlement void local, settled sale not voidable; manager approval required / unauthorized approver / amount binding / single use / audit in the event; an Online sale from the cache: stale → refused, fresh → accepted, RETURNABLE = sold − Cloud − local pending, refresh keeps the pending subtraction, capped second return, reports |
| `EdgeReturnSyncHttpMySqlTest` (2, two databases) | network-down end to end: cache pulled while online; RETURN_CACHE_WATERMARK at takeover; offline return of the Online sale (local stock once, till once, event pending, returnable down at once); reconnect while the appliance stays writer; the Cloud posts the OFFICIAL return exactly once (document, FEFO stock in, balanced GL, cash/bank out, ledger, order status); the ACK re-applies nothing locally; LOST ACK → recovered once, one of everything on both sides; replay → already_applied; different hash → conflict with no mutation; controlled handback blocked by the open shift then clean; the cache then equals the Cloud (no double count). Also: a return arriving before its Edge sale is retryable; finance-incomplete rolls back atomically and the next tick applies once |
| `EdgeReturnRaceTest` (1, independent processes) | two terminals returning the same remaining quantity at a barrier: never more than sold, stock and tills move only for what was accepted, one event per accepted return; a request for the original quantity against a pending return is capped |
| `EdgeReturnBackupRecoveryMySqlTest` (1) | the pending event, document, quantities, one operational return and the till effect survive encrypted backup → loss → restore, same hash |
| `EdgeCashierReturnHttpMySqlTest` (3) | the real page carries the Return UX; real routes search / view / post / show; card refund offline → business message; shift summary reflects the refund; no permission → 403 |
| `EdgeArtifactBootTest`, `EdgeBranchServerRegistrationTest` | the return routes register from the built restricted artifact; the appliance route census extended deliberately |

## Classification

- RETURNS_PARITY: FULL_OFFLINE_PARITY for local and window-fresh Online sales, cash refund. Returns of sales older than
  the cache window: ONLINE_REQUIRED.
- REFUNDS_PARITY: cash FULL; card / bank / provider refunds ONLINE_REQUIRED (no provider offline contract).
- CARD_SALE_OFFLINE / CARD_REFUND_OFFLINE: ONLINE_REQUIRED.
- Customer credit: normal Branch POS has no generic customer-credit settlement flow in the canonical POS; Catering's
  customer-credit / advance / refund module stays out of the Edge artifact — classified separately for a later event.
- SUPPLIER_FINANCE_OFFLINE_PARITY: FINANCIAL_PARITY_PENDING (F2 uses this proven financial-event pattern).

## Gates at the F1 head (11 Sep 2026)

- Focused F1 + adjacent sync / backup / Q suites: 56 tests, 605 assertions, green.
- Full MySQL suite at a39c90e (run alone): 1556 tests, 1551 passed, 5 red = the known canonical Dompdf/A4-PDF debt
  (PosQuickReport email, ReportSchedule, three CateringDocumentPdf tests). New Edge regressions: 0.
- Fast Feature+Unit: 226 tests; the two stale canonical SQLite unit tests red as before. Feature Edge gates (route census,
  Blade compile + generated PHP lint + JS syntax, runtime boundary, artifact build/boot incl. the return routes): green.
- `git diff --check`: clean. Canonical at the F1 gate: af6755d (unchanged since the Q re-ground; nothing to reconcile).
