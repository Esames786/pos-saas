# Edge ↔ Online POS parity register

Goal (locked): **the current Online Bingoo POS is the functional specification for Edge.** Online defines WHAT the
operator sees and does; Edge defines HOW the same workflow executes safely without Internet. No "Offline Lite",
no API-only parity: a workflow counts only when the cashier can run it from the Branch Server **browser page** and
the proof executes the real route → middleware → controller → services → Blade. Every shared workflow ends as one
of **FULL_OFFLINE_PARITY / ONLINE_REQUIRED / FINANCIAL_PARITY_PENDING**. Internet-required actions are never faked.

Canonical reviewed: `origin/feat/14d-2-plan-upgrade-requests` @ `e44eb01` (7 Sep 2026) — merged into the Edge
branch with **0 shared POS commits missing** (reconciles 1–3: `ffcd390`, `958883a`, `3f6cff5`).
Cashier product: `GET /edge/local/pos` → `EdgeLocalPosController@screen` → `resources/views/edge/pos/index.blade.php`
(self-contained, renders with no Internet; every mutation → `edge.local.pos.*`, never a Cloud posting route).

## Register — executable through the Branch Server cashier page

Status is what the proof executes today (test class in the last column; all real HTTP on a branch_server-booted app).

| # | Online branch-POS workflow | Edge UI | Edge business logic | Print / report | Recovery | STATUS | Proof |
|---|---|---|---|---|---|---|---|
| 1 | Cashier login / session freshness | Edge login page | Edge credential, epoch, branch authz | — | census/restore | **FULL_OFFLINE_PARITY** | EdgeLocalAuth*, EdgeLocalPosHttp (freshness) |
| 2 | Default terminal (land on assigned) | ✓ auto-select | `default_terminal_id` | — | — | **FULL_OFFLINE_PARITY** | EdgeCashierScreenRenders |
| 3 | Terminal-switch authority (pinned operator sees only his) | ✓ | `tenant.pos.change-terminal` | — | — | **FULL_OFFLINE_PARITY** | EdgeCashierScreenRenders |
| 4 | Order-type selector (effective allowed types) | ✓ | `effectiveAllowedOrderTypes` + server refusal | — | — | **FULL_OFFLINE_PARITY** | EdgeLocalPosHttp (order-type restriction) |
| 5 | Category / Deals tabs (hierarchy, branch scope) | ✓ display | CATEGORY-BRANCH-SCOPE-1 | — | — | **FULL_OFFLINE_PARITY** | EdgeCashierScreenRenders, DineIn (payload) |
| 6 | Product tiles + search + hidden-product handling | ✓ | grid truth = Online's | — | — | **FULL_OFFLINE_PARITY** | EdgeCashierScreenRenders, DineIn |
| 7 | Takeaway cash sale | ✓ Review & Pay | `EdgeLocalPosService::completePaidSale` | receipt (row 21) | outbox | **FULL_OFFLINE_PARITY** | EdgeLocalPosHttp, Printing, ShiftAndNetworkDown |
| 8 | Quick Sale cash (vehicle + waiter rule) | ✓ prompt | same `required_if` as Online | receipt | outbox | **FULL_OFFLINE_PARITY** | EdgeLocalPosHttp |
| 9 | Dine-In: View Tables → open table (waiter, guests) | ✓ board | session lock order, frozen business_date | — | census | **FULL_OFFLINE_PARITY** | DineIn, LocalRestaurantHttp |
| 10 | Hold | ✓ | held check, one open check per session | — | census | **FULL_OFFLINE_PARITY** | DineIn |
| 11 | Draft (no KOT until held normally) | ✓ | `is_draft`, server-enforced | — | census | **FULL_OFFLINE_PARITY** | DineIn |
| 12 | Recall (never hijacks the operator's terminal) | ✓ list + board | GET /held-sales, detail | — | — | **FULL_OFFLINE_PARITY** | DineIn |
| 13 | Add Round (captured price, sent-state carry) | ✓ Save round | `reviseHeldSale` | — | — | **FULL_OFFLINE_PARITY** | DineIn, LocalRestaurantHttp |
| 14 | KOT round 1 / round 2 = only the new quantity (sent pool) | ✓ KOT | shared `PrintJobService::queueKot` + bookkeeping | KOT event + job | — | **FULL_OFFLINE_PARITY** | DineIn |
| 15 | Preview Bill (zero mutation) | ✓ modal | `previewBill` | — | — | **FULL_OFFLINE_PARITY** | EdgePreviewBill, page wiring |
| 16 | Review & Pay → cash settlement (direct + held; own shift takes the cash) | ✓ | `settleHeldSale` / `completePaidSale` | receipt | outbox | **FULL_OFFLINE_PARITY** | DineIn, LocalRestaurantHttp |
| 17 | Complete Sale permission split from discount permission | ✓ button gated | `tenant.pos.store` on POST sales / settle | — | — | **FULL_OFFLINE_PARITY** | EdgeCashierPermission (see note A) |
| 18 | Table Board actions: recall / new check / close EMPTY table (+ race) | ✓ | `closeTableSession` under row lock | — | — | **FULL_OFFLINE_PARITY** | DineIn, LocalRestaurantRace (close vs hold) |
| 19 | Reservations: reserve / details / cancel / open reserved → customer carries | ✓ board | Edge-owned authority; Cloud fence; handback | — | census + recovery | **FULL_OFFLINE_PARITY** | Reservation, TableReservation, ReservationRace, Handback, CloudFence |
| 20 | Whole-order cancel frees the table; prints at the CURRENT counter; original terminal kept | ✓ reason + manager | shared `KotCancellationService` + terminal override | cancel KOT | — | **FULL_OFFLINE_PARITY** | DineIn |
| 21 | Hidden/deactivated product already on Hold/Draft stays recallable + payable | ✓ | carried-line resolution | — | — | **FULL_OFFLINE_PARITY** | DineIn (real route) |
| 22 | Receipt: auto after payment (ensure-once), reprint, current-counter routing, network print, Print Here fallback | ✓ Recent Prints | shared `queueReceipt` + `PrintRoutingService` | Edge print authority claims stored bytes | — | **FULL_OFFLINE_PARITY** | Printing |
| 23 | KOT reprint + historical stored-copy fallback (KOT-REPRINT-BLANK-1); deal name on KOT | ✓ | shared path | canonical renderer/EscPos | — | **FULL_OFFLINE_PARITY** | Printing (line churn) |
| 24 | Recent Prints / mark printed / retry failed delivery; per-printer isolation | ✓ | Edge delivery service | — | — | **FULL_OFFLINE_PARITY** | Printing, LocalPrintRace, LocalPrintDelivery |
| 25 | Quick Report VIEW (business_date, NET SALES, Sold/Ret/Net, deal components not counted, deal identity, items-by-category, charge breakup, GRAND TOTAL, **open bills**, **branch scope**) | ✓ modal | canonical `SalesReportEngine` + `SalesReportDocumentService` — zero Edge math | canonical thermal Blade | — | **FULL_OFFLINE_PARITY** | QuickReport (engine is the oracle) |
| 26 | Quick Report THERMAL (print here) | ✓ | same | same Blade, browser print | — | **FULL_OFFLINE_PARITY** | QuickReport |
| 27 | Quick Report NETWORK | ✓ | same | `buildReport` bytes on Edge print authority | — | **FULL_OFFLINE_PARITY** | QuickReport (claimable job) |
| 28 | Shift: open, terminal lock, zero-drawer close, typed count + variance | ✓ modal | shared `ShiftService` | — | census | **FULL_OFFLINE_PARITY** | ShiftAndNetworkDown |
| 29 | Shift breakup (cash/card/bank/cancellations) + blind count (HIDE-AMOUNTS) | ✓ | shared `AmountVisibility`, figures stripped server-side | — | — | **FULL_OFFLINE_PARITY** | ShiftAndNetworkDown |
| 30 | Operating business date / business_date parity (OPERATING-DATE, shift-frozen dates) | ✓ | `TenantClock::operatingBusinessDate` | — | — | **FULL_OFFLINE_PARITY** | ShiftAndNetworkDown |
| 31 | SALE-DATE-TRUTH (payment never rewrites order time); KOT-TIME-TRUTH (shared `KotTicketTime` renderer) | ✓ | shared code paths | canonical KOT Blade/EscPos | — | **FULL_OFFLINE_PARITY** | DineIn (sale_date), Printing (renderer) |
| 32 | Network-down cash sale + business-friendly "Pending sync" (no internals on the till) | ✓ chip | outbox 1B–1E | local print | outbox | **FULL_OFFLINE_PARITY** | ShiftAndNetworkDown, LocalPosHttp (master dead) |
| 33 | Deal (combo) SELLING | tabs only | `assertNoComboSelling` refuses | — | — | **ONLINE_REQUIRED** (not inherent — next Edge build item) | — |
| 34 | Manual discount / promo on a sale | — | `assertNoDiscountOrPromo` refuses | — | — | **ONLINE_REQUIRED** (not inherent — next Edge build item) | — |
| 35 | Split bill | — | Cloud `SplitBillController` only | — | — | **ONLINE_REQUIRED** (not inherent) | — |
| 36 | Delivery orders: channel/rider, address book, ADDRESS-ATTACH-1 | — | Edge sale authority = quick_sale/takeaway/dine_in | — | — | **ONLINE_REQUIRED** | — |
| 37 | Card / provider-authorised payment | — | refused offline (never faked) | — | — | **ONLINE_REQUIRED** | EdgeLocalPos (card refused) |
| 38 | Quick Report EMAIL | truthful 422 | — | — | — | **ONLINE_REQUIRED** | QuickReport |
| 39 | Scheduled owner email report / tenant backups / agent shelf / admin | — | Cloud | — | — | **ONLINE_REQUIRED (Cloud)** | — |
| 40 | Returns / refunds / item void after payment / RETURN-MANAGER-APPROVAL | — | no offline financial event ingestion yet | — | — | **FINANCIAL_PARITY_PENDING** | see `edge-online-financial-parity-gap.md` |
| 41 | Supplier finance (SUPPLIER-FINANCE-DIRECT-1 — not yet on canonical) | — | Cloud AP/GL; artifact excludes it | — | — | **FINANCIAL_PARITY_PENDING** | see gap doc |

Note A — row 17: the Complete-Sale gate is the last enumerated behaviour landed in this tranche (server 403 on
POST sales / settle without `tenant.pos.store`, button hidden with the Online hint); its proof class is listed in the
final report for the tranche.

## Percentages — computed from this register (no estimate)

```
FULL_OFFLINE_PARITY        = 32   (rows 1–32)
ONLINE_REQUIRED            =  7   (rows 33–39; 33–35 are NOT inherent — they are the next Edge build items)
FINANCIAL_PARITY_PENDING   =  2   (rows 40–41)

NORMAL_OPERATOR_POS_PARITY_PERCENT = 32 / 39 = 82%
   (denominator = every workflow a normal branch operator runs on the Online POS: rows 1–38 + returns (40);
    excludes Cloud-only admin row 39 and supplier finance 41)
FULL_OFFLINE_PARITY_PERCENT        = 32 / 41 = 78%
   (denominator = every shared workflow in the register)
```

What moves the numbers next: deal selling (33), discounts/promo (34), split bill (35) are Edge build items, not
Internet-bound; delivery (36) needs the address/rider workflow; returns (40) and supplier finance (41) belong to the
dedicated financial-parity phase (return-event outbox reusing sync 1B–1E, Cloud-ingested, finance-gated).

## Release gates (real path only)

- `EdgeCashierScreenRendersHttpMySqlTest` — REAL HTTP GET of the cashier page on a branch_server-booted app.
- `EdgeBladeCompileGateTest` — every Edge Blade compiles and the generated PHP passes `php -l`.
- `EdgeArtifactBootTest` — the BUILT restricted artifact registers the cashier page, recall, board, receipt, print
  document, Quick Report, shift summary and sync summary routes; `EdgeArtifactTest` — the plan ships the cashier
  page, Quick Report controller, canonical report engine/thermal Blade and print documents, and keeps Cloud
  report/AP source physically out.
- `EdgeBranchServerRegistrationTest` — the route census: every branch-server URI is deliberately approved.
