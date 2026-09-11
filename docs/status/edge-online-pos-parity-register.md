# Edge ↔ Online POS parity register

Goal (locked): **the current Online Bingoo POS is the functional specification for Edge.** Online defines WHAT the
operator sees and does; Edge defines HOW the same workflow executes safely without Internet. No "Offline Lite",
no API-only parity: a workflow counts only when the cashier can run it from the Branch Server **browser page** and
the proof executes the real route → middleware → controller → services → Blade. Every shared workflow ends as one
of **FULL_OFFLINE_PARITY / ONLINE_REQUIRED / FINANCIAL_PARITY_PENDING**. Internet-required actions are never faked.

Canonical reviewed: `origin/feat/14d-2-plan-upgrade-requests` @ `ebaf8b2` (8 Sep 2026) — merged into the Edge
branch with **0 shared POS commits missing** (reconciles 1–4: `ffcd390`, `958883a`, `3f6cff5`, `8aeecfe`).
Cashier product: `GET /edge/local/pos` → `EdgeLocalPosController@screen` → `resources/views/edge/pos/index.blade.php`
(self-contained, renders with no Internet; every mutation → `edge.local.pos.*`, never a Cloud posting route).

## Phase C — browser-executable matrix (8 Sep 2026)

Status is what the proof executes today; all proofs are real HTTP on a branch_server-booted app (test class in the
last column). `NORMAL_BRANCH_POS_GAPS = 0` except the explicitly classified ONLINE_REQUIRED / FINANCIAL rows.

| Workflow | Edge UI | Edge business logic | Print / report | Sync | STATUS | Proof |
|---|---|---|---|---|---|---|
| Takeaway | ✓ | `completePaidSale` | receipt | outbox | **FULL_OFFLINE_PARITY** | LocalPosHttp, Printing, ShiftAndNetworkDown |
| Quick Sale (vehicle + waiter rule) | ✓ | same `required_if` as Online | receipt | outbox | **FULL_OFFLINE_PARITY** | LocalPosHttp |
| Dine-In (open table → rounds → settle → close) | ✓ | session lock order, frozen dates | KOT/receipt | outbox | **FULL_OFFLINE_PARITY** | DineIn, LocalRestaurantHttp |
| Deals (sell a deal; components; KOT identity; receipt name-only; report identity; stock) | ✓ tiles + cart rows | server-side expansion of the synced combo book | KOT deal name; receipt name-only; engine counts once | outbox (deal rows) | **FULL_OFFLINE_PARITY** | DealsDiscounts (deal + dine-in deal) |
| Combos (= deals; add round scales components, sent state carried) | ✓ | `reviseHeldSale` | KOT deltas | — | **FULL_OFFLINE_PARITY** | DealsDiscounts |
| Discounts (manual fixed/percent; branch approval mode; manager consumes approval) | ✓ Review & Pay panel + manager prompt | shared `SalesTotalsService` + `ManagerApprovalService::consume` | receipt/report carry discount | envelope carries type/value | **FULL_OFFLINE_PARITY** | DealsDiscounts, LocalPos (refusal) |
| Promotions (synced codes) | ✓ promo field | shared `PromotionService` on synced promotions | report/receipt | envelope carries promo | **FULL_OFFLINE_PARITY** | DealsDiscounts |
| Split Bill | ✓ modal | `splitHeldSale` (canonical math, sent-state carry) | no re-KOT | one outbox row per settled check | **FULL_OFFLINE_PARITY** | DealsDiscounts |
| Delivery (channel, aggregator rule, rider, charge lock) | ✓ Review & Pay delivery fields | `resolveDeliveryAttribution` | receipt; rider reports (Cloud after sync) | envelope delivery block | **FULL_OFFLINE_PARITY** | DealsDiscounts |
| Customer (attach from the synced book) | ✓ search picker + chip | synced `customers` | — | envelope customer identity | **FULL_OFFLINE_PARITY** | DealsDiscounts, Reservation (carry-over) |
| Customer Address (ADDRESS-ATTACH: pick saved address → on the order) | ✓ saved-address pick / typed | synced `customer_addresses`; `delivery_address` on the sale | receipt | envelope | **FULL_OFFLINE_PARITY** | DealsDiscounts (address on sale + envelope) |
| New customer / new address CREATED offline | page says "needs the Online POS" | no Cloud ingestion contract for till-created customers yet | — | — | **ONLINE_REQUIRED** (not inherent — next Edge build item) | — |
| Tables (board, open, new check, close empty + race) | ✓ | row-locked close | — | — | **FULL_OFFLINE_PARITY** | DineIn, LocalRestaurantRace |
| Reservations (reserve/details/cancel/open → customer carries) | ✓ | Edge-owned authority; Cloud fence; handback | — | handback | **FULL_OFFLINE_PARITY** | Reservation, ReservationRace, Handback, CloudFence |
| Hold / Draft / Recall (no terminal hijack) | ✓ | held/draft, list/detail | — | — | **FULL_OFFLINE_PARITY** | DineIn |
| Add Round | ✓ | captured price + sent-state carry | KOT deltas | — | **FULL_OFFLINE_PARITY** | DineIn |
| KOT (round 1 / round 2 = new quantity only; deal identity; reprint + stored-copy fallback) | ✓ | shared `PrintJobService` | Edge print authority | — | **FULL_OFFLINE_PARITY** | DineIn, Printing, DealsDiscounts |
| Review & Pay / Preview Bill (zero mutation; totals incl. discount, promo, delivery charge) | ✓ | `previewBill` | — | — | **FULL_OFFLINE_PARITY** | PreviewBill, DealsDiscounts |
| Cash payment; Complete Sale permission split | ✓ button gated | `tenant.pos.store` server 403 | receipt | outbox | **FULL_OFFLINE_PARITY** | Permission, LocalPosHttp |
| Card / provider payment | — | refused offline, never faked | — | — | **ONLINE_REQUIRED** | LocalPos |
| Printing (receipt ensure-once/reprint at current counter, network, Print Here, cancellation at current counter) | ✓ | shared routing + Edge transport | canonical documents | — | **FULL_OFFLINE_PARITY** | Printing, DineIn |
| Recent Prints (list, printed, retry) | ✓ | Edge delivery service | — | — | **FULL_OFFLINE_PARITY** | Printing |
| Quick Report (view / thermal / network; open bills; branch scope; deal identity) | ✓ modal | canonical engine — zero Edge math | canonical thermal Blade / bytes | — | **FULL_OFFLINE_PARITY** | QuickReport, DealsDiscounts (deal on the report) |
| Quick Report EMAIL | truthful 422 | — | — | — | **ONLINE_REQUIRED** | QuickReport |
| Shift workflow (open, lock, zero drawer, count, breakup, blind count, operating date) | ✓ modal | shared `ShiftService` / `AmountVisibility` | — | — | **FULL_OFFLINE_PARITY** | ShiftAndNetworkDown |
| Network-down cash sale + Pending sync | ✓ chip | outbox 1B–1E | local | outbox | **FULL_OFFLINE_PARITY** | ShiftAndNetworkDown |
| Returns / refunds / post-settlement void (+ RETURN-MANAGER-APPROVAL) — F1 | ✓ Returns entry, unit-aware stepper, partial/full, refund breakdown, approval prompt | canonical `SalesReturnService::computeReturn`; Online sales returnable from the fresh warm cache; cash refund out of the till once; immutable return event → Cloud OFFICIAL return exactly once | return view (Online has no thermal return document) | `edge-return-envelope-v1` in the sale outbox | **FULL_OFFLINE_PARITY** (cash refund; sales inside the cache window) · card/bank/provider refund = ONLINE_REQUIRED | ReturnAuthority, ReturnSyncHttp, ReturnRace, ReturnBackupRecovery, CashierReturnHttp |
| Supplier finance — F2 (SUPPLIER-FINANCE-DIRECT-1 on canonical: direct supplier payment WITHOUT a purchase bill · Supplier Ledger → Record Payment · supplier-aware General Journal / AP dimension · cash/bank REQUIRED · Purchase Bill optional) | ✓ Suppliers → Supplier Ledger → Record Payment page; General Journal page (AP line → Supplier enabled + required); header entry points permission-gated | fresh warm projection (suppliers + authoritative payable, open bills, cash/bank + COA mapping, chart + AP family, official ledger window, applied-event set); LOCAL AVAILABLE PAYABLE = Cloud payable + pending local deltas (no double application); boundary: no supplier advance (fail closed, row-locked); immutable `supplier_payment` / `supplier_ap_journal_adjustment` events → Cloud OFFICIAL posting through `SupplierPayableService::recordPayment` / `ManualJournalService::post` exactly once, finance-complete-or-refuse | Supplier Ledger with PENDING SYNC → OFFICIAL (one visible transaction per event) | `edge-supplier-payment-envelope-v1`, `edge-supplier-ap-journal-envelope-v1` in the sale outbox; Cloud registry `edge_inbound_supplier_finance_ingestions` | **FULL_OFFLINE_PARITY** (cash / bank transfer / cheque / other; card to a supplier = ONLINE_REQUIRED; stale projection → refused offline) · PURCHASE_RETURN_OFFLINE_PARITY = FINANCIAL_PARITY_PENDING | SupplierFinanceAuthority, SupplierFinanceSyncHttp, SupplierFinanceRace, SupplierFinanceBackupRecovery, SupplierFinanceHttp |
| Purchase returns — F3 (return received goods to the supplier against the source goods receipt: Received / Already Returned / Returnable / Return Qty / Unit Cost / Reason; Cloud posts FEFO stock OUT, supplier subledger credit, Dr 2100 / Cr 1400) | ✓ Purchase Returns page (Source GRN → Received Lines → Post, pending sync); header entry point permission-gated | fresh warm projection of the branch's goods receipts; LOCAL RETURNABLE = received − Cloud returned − pending local lines (no double / over-return, row-locked across terminals); local operational stock OUT once, never below what the branch holds; provisional supplier effect via the F2 position; immutable `purchase_return` event → Cloud OFFICIAL return through `PurchaseReturnService` exactly once, finance-complete-or-refuse | — (Online has no thermal document) | `edge-purchase-return-envelope-v1` in the sale outbox; Cloud registry `edge_inbound_purchase_return_ingestions` | **FULL_OFFLINE_PARITY** (GRN-sourced returns inside the 90-day window) · return without a source receipt = ONLINE_REQUIRED | PurchaseReturnAuthority, PurchaseReturnSyncHttp, PurchaseReturnRace, PurchaseReturnBackupRecovery, PurchaseReturnHttp |
| Catering (customer-credit worklist, overpayment, refund within / beyond credit, split refund posting, explicit negative-payment path) | — | Cloud Catering module — NOT part of the normal Branch POS Edge product scope; physically excluded from the restricted artifact (`config/edge.php` exclude: `Catering*`, `app/Http/Controllers/Tenant/Catering`, `app/Services/Catering`) | — | — | **OUT OF EDGE SCOPE** (Cloud-only unless the owner requests Offline Catering) | EdgeArtifactTest |
| Cloud admin: scheduled email reports, tenant backups, agent shelf | — | Cloud | — | — | **ONLINE_REQUIRED (Cloud)** | — |

```
NORMAL_BRANCH_POS_GAPS      = 0     (every normal branch-POS workflow is FULL, or explicitly ONLINE_REQUIRED / FINANCIAL_PARITY_PENDING)
FULL_OFFLINE_PARITY         = 26 rows        ONLINE_REQUIRED = 4 (new-customer creation offline*, card payment/refund, QR email, Cloud admin)
FINANCIAL_PARITY_PENDING    = 0 rows
F1 sales returns / cash refunds: FULL (11 Sep 2026) · F2 supplier finance: FULL (12 Sep 2026) · F3 purchase returns: FULL (12 Sep 2026)
* not inherent — next Edge build item (till-created customers need a Cloud ingestion contract)

NORMAL_OPERATOR_POS_PARITY_PERCENT = 26 / 29 = 90%   (all rows a branch operator runs: 26 FULL + card + QR email + new-customer; Cloud admin excluded)
FULL_OFFLINE_PARITY_PERCENT        = 26 / 30 = 87%   (every row in the matrix)
```

## Release gates (real path only)

- `EdgeCashierScreenRendersHttpMySqlTest` — REAL HTTP GET of the cashier page on a branch_server-booted app.
- `EdgeBladeCompileGateTest` — every Edge Blade compiles, the generated PHP passes `php -l`, and the cashier page
  script passes `node --check` (when a Node runtime exists).
- `EdgeArtifactBootTest` / `EdgeArtifactTest` — the BUILT restricted artifact registers the cashier surfaces; the plan
  ships the cashier page, Quick Report controller, canonical report engine/thermal Blade and print documents; Cloud
  report/AP source stays physically out.
- `EdgeBranchServerRegistrationTest` — the route census: every branch-server URI is deliberately approved.

## Previous state (7 Sep) for the record
32 FULL / 7 ONLINE_REQUIRED / 2 FINANCIAL at workflow granularity → this matrix regroups to the owner's list; the four
former "not inherent" ONLINE_REQUIRED rows (deal selling, discounts/promo, split bill, delivery/address) are now FULL.
