# Offline Edge — cashier UI / feature-parity clarification (owner question, 20 Sep 2026)

The owner compared the P5C LAB cashier screen with the live Kashif Food POS: the Edge screen is visibly different and much
smaller. This note answers the architecture question and re-checks feature parity **from code and executable proofs, not
from buttons**. Nothing was built, merged, activated or pulled; the LAB is unchanged (STANDBY_READY, Cloud writer, cable connected).

## A / B — the intended (and built) production architecture is **B**: a separate Edge cashier UI served by the Branch Server

| | |
|---|---|
| What runs offline | `GET https://<branch-server>/edge/local/pos` → `EdgeLocalPosController@screen` → `resources/views/edge/pos/index.blade.php` (994 lines, self-contained inline CSS/JS, no Vite build, renders with no Internet). Every mutation targets the Edge JSON API `edge.local.pos.*` (`routes/edge_runtime.php`), never a Cloud posting route. |
| Why not A (point the live POS front-end at the Edge backend) | the live cashier page (`resources/views/tenant/pos/index.blade.php`, 7 060 lines) is a server-rendered Blade coupled to Cloud routes, controllers and services (`routes/tenant.php`, `POSController`, tenant session/auth, Cloud finance/inventory services). Those are **physically excluded** from the restricted Edge artifact by design (boundary gate); the Branch Server authenticates cashiers with Edge credentials, writes to the local outbox and re-validates authority/lease/shift/stock in `EdgeLocalPosService`. Re-pointing the Cloud page would mean re-implementing every endpoint it calls — the separate page IS that re-implementation, kept lean. |
| The locked product rule | "the current Online Bingoo POS is the functional specification for Edge — Online defines WHAT the operator sees and does; Edge defines HOW the same workflow executes safely without Internet; no Offline Lite" (`docs/status/edge-online-pos-parity-register.md`, `docs/design/EDGE_OPERATING_MODEL.md`). Parity was defined and measured as **workflow parity proven by browser-executable HTTP tests**, not visual identity. A smaller, differently styled screen is therefore expected and allowed; a workflow the live POS has and Edge lacks is not. |
| What was explicitly accepted as reduced | the ONLINE_REQUIRED rows of the register, reported in every accepted tranche (Q, F1, F2, F3, P4, P5): card/provider payment and card refunds, Quick Report e-mail, creating a NEW customer/address at the till, USB printers (ONLINE_REQUIRED for the pilot), Cloud admin/reports/Catering. Internet-required actions are refused truthfully, never faked. |
| What was **not** accepted — only deferred | `EdgeLocalPosController@screen` carries the note "per-tile availability / variants / modifiers land in a later milestone". Those items never got a register row, so the register's "NORMAL_BRANCH_POS_GAPS = 0" was **incomplete**. They are listed below and added to the register today. |

## C — feature-by-feature (verified in code + proofs; screen buttons not trusted)

Legend: **FULL** = implemented and supported on Edge (browser-executable proof exists) · **FULL-diffUI** = same workflow, different UI ·
**ONLINE** = supported only in Cloud/online mode (documented, accepted) · **NOT-IMPL** = not implemented on Edge · **NOT-VERIFIED** = code exists but no executable proof.

| Area | Live Kashif Food POS | Edge cashier (evidence) | Status |
|---|---|---|---|
| Order types | quick_sale, takeaway, dine_in, delivery (tabs) | same four, validated `in:quick_sale,takeaway,dine_in,delivery`, per-user allow-list; select box instead of tabs | **FULL-diffUI** |
| Tables — board, open, new check, close (incl. empty close race), reserve/unreserve | Table Workspace modal with cards | `/restaurant/board`, `tables/{t}/open`, `table-sessions/{s}/close`, reserve/unreserve; proofs `EdgeCashierDineInHttpMySqlTest`, `EdgeLocalRestaurantRaceTest`, `EdgeCashierReservationHttpMySqlTest` | **FULL-diffUI** |
| Tables — **Move** a check to another table | `POST /restaurant/table-sessions/{s}/move` (`RestaurantTableSessionController@move`) | no route, no service method | **NOT-IMPL** |
| Tables — per-table Bill Preview document (rounds + previously paid, 14 Sep) | yes | cart-level `previewBill` totals only (registered UX drift 19 Sep) | **FULL-diffUI** (presentation drift) |
| Deals / combos (sell, components, add-round scaling, KOT deal identity, receipt name-only, report identity, stock) | yes | server-side expansion of the synced combo book; `EdgeCashierDealsDiscountsHttpMySqlTest` | **FULL** |
| **Modifiers** (modifier groups per product, price deltas, linked-product stock consumption — live for the steak/side setup, STEAK-SIDE-MODIFIER-1) | modifier entry modal (`#modifierEntryModal`) | bootstrap SYNCS `modifier_groups`/`modifiers`; `storeSale`/`held-sales` accept `lines.*.modifiers` array and persist it; **the Edge screen has no modifier picker (0 references)** and no executable proof drives a modifier line | **NOT-IMPL (UI)** / server path NOT-VERIFIED |
| **Product variants** (sizes) | variant selection in the modal; barcodes per variant | server resolves `product_variant_id` (`InventoryService::resolveVariant`) and carries `variant_name`; **screen shows one tile per product, no variant picker (0 references)** | **NOT-IMPL (UI)** |
| Barcode scan | search matches SKU/barcodes (`product_barcodes`) | search box says "or scan barcode…" but filters **by name only** (`items.filter(i => i.name…includes(q))`); `product_barcodes` are synced but unused | **NOT-IMPL** (placeholder text misleading) |
| Customer — attach from the synced book, saved address on delivery | yes | search picker + chip, `customer_addresses` synced, address on sale + envelope | **FULL-diffUI** |
| Customer — create NEW customer/address at the till | yes | page says "needs the Online POS"; no Cloud ingestion contract yet | **ONLINE** (accepted; "next Edge build item") |
| Shifts — open, lock, zero drawer, count, breakup, blind count (hide amounts), operating date | yes | shared `ShiftService` / `AmountVisibility`; `EdgeCashierShiftAndNetworkDownHttpMySqlTest` | **FULL-diffUI** |
| Hold / Draft / Recall / held list | yes | `held-sales` store/list/show/settle/cancel; DineIn proof | **FULL** |
| Add Round (captured price, sent-state carry) | yes | `reviseHeldSale`; KOT deltas | **FULL** |
| KOT (round 1 / round 2 = new quantity only, deal identity, reprint, stored-copy fallback, cancel-KOT at the current counter) | yes | shared `PrintJobService` + Edge print authority; `EdgeCashierPrintingHttpMySqlTest` | **FULL** |
| **KOT Reminder** document (Kashif Food: reminder always to the punching counter) | `document_type=reminder`, `print_role=reminder` routing | no reminder request path; only a comment mentions it | **NOT-IMPL** |
| Per-line kitchen notes / instructions | yes | `kitchen_note` is carried on held lines server-side; **not enterable on the Edge screen (0 references)** | **NOT-IMPL (UI)** |
| Waiter / vehicle rules (Quick Sale) | yes | same `required_if` rules; Quick Sale + Dine-In waiter selection | **FULL** |
| Discounts (manual fixed/percent, branch approval mode, manager consumes approval) | yes | shared `SalesTotalsService` + `ManagerApprovalService::consume`; manager prompt | **FULL-diffUI** |
| Promotions (synced codes) | yes | shared `PromotionService` | **FULL** |
| Split Bill | yes | `splitHeldSale`, canonical math, sent-state carry, one outbox row per check | **FULL-diffUI** |
| Delivery (channel/aggregator rule, rider, charge lock) | yes | `resolveDeliveryAttribution`; Review & Pay delivery fields | **FULL-diffUI** |
| Service charge / tax on totals | yes | shared totals; displayed | **FULL** |
| **Tip** | `tip_amount` accepted, tip row | `storeSale` has no `tip_amount`; no tip input | **NOT-IMPL** |
| Cash payment; Complete Sale permission split | yes | `tenant.pos.store` server 403; `EdgeCashierPermissionHttpMySqlTest` | **FULL** |
| Card / provider payment and card refunds | yes (online) | refused offline, never faked | **ONLINE** (accepted) |
| Returns / refunds / post-settlement void + RETURN-MANAGER-APPROVAL | yes | F1: Returns entry, unit-aware stepper, partial/full, refund breakdown, approval prompt; `EdgeCashierReturnHttpMySqlTest`; sales older than the warm cache window = ONLINE | **FULL-diffUI** |
| Manager approvals (discount, cancel/void KOT items, returns) | PIN/approval flow | `/manager-approvals/verify`; `KotCancellationService` for voids; approval consumed server-side | **FULL-diffUI** — **but** Edge still runs the PRE-fix `KotCancellationService` (MANAGER-APPROVAL-COMBO-VOID-1, live on Cloud since 19 Sep): cancelling ONE line of a combo with approval is refused on Edge until the reconcile |
| Printing — receipt ensure-once/reprint at the current counter, network printers (edge-direct TCP 9100), Print Here, Recent Prints retry | yes | shared routing + Edge transport; USB = ONLINE_REQUIRED for the pilot | **FULL** (+ USB **ONLINE**) |
| Quick Report (view / thermal / network / open bills / branch scope) | yes | canonical engine, zero Edge math; e-mail = truthful 422 | **FULL** (+ e-mail **ONLINE**) |
| Reports Center, dashboards, admin, Catering | Cloud pages | not on the Branch Server by design | **ONLINE** (accepted, Cloud-only) |

## Is UI parity a P5/P6 launch requirement?

- **Visual/identical UI parity: no.** The locked rule makes the Online POS the functional spec (WHAT) and lets Edge choose HOW; the
  register measures browser-executable workflows. The Edge page is a deliberately lean, self-contained surface (no Vite, no
  Cloud chrome); its size is not a defect.
- **Workflow parity for everything a branch operator does offline: yes** — that is the "no Offline Lite" rule. The accepted
  reductions are only the documented ONLINE_REQUIRED rows above.
- **Therefore the launch-blocking findings of this check are the workflows the live POS has that Edge lacks and that were never
  accepted as ONLINE_REQUIRED:**

| # | Gap | Why it blocks P6 | Evidence |
|---|---|---|---|
| 1 | **Modifiers** not selectable on Edge | STEAK-SIDE-MODIFIER-1 shows modifiers are LIVE for a real client menu; an offline cashier could not sell a steak with its side (or would sell it without the modifier → wrong KOT, wrong stock consumption of the linked side product) | Edge screen 0 refs; controller note "later milestone"; register has no Modifiers row |
| 2 | **Variants** not selectable on Edge | any size/variant product cannot be sold offline as the right variant (wrong price/stock) | one tile per product; no picker |
| 3 | **KOT Reminder** not implemented | Kashif Food's kitchen workflow uses the reminder print to the punching counter (kashif-reminders-all-categories-2026-08-30, kashif-kot-routing-requests-2026-09-03) | no request path on Edge |
| 4 | **Tip** not accepted offline | a restaurant taking tips would lose them or block the sale | `storeSale` has no `tip_amount` |
| 5 | **Table Move** not implemented | live workflow on the Table Workspace card; offline the cashier would have to cancel and re-punch | no route/service |
| 6 | Per-line **kitchen notes** not enterable | kitchen loses instructions offline (server carries them, UI cannot enter them) | UI 0 refs |
| 7 | **Barcode** search not implemented | label says "scan barcode" but only names match; low impact for a restaurant, misleading UI | JS filter on `name` only |
| 8 | **Combo single-line void** with approval refused (pre-fix `KotCancellationService`) | the live POS was fixed 19 Sep; Edge executes the same service and still has the bug | canonical 243e01d not reconciled |

Items 1, 3 and 8 are certain blockers for a Kashif Food pilot; 2, 4, 5, 6 block if the pilot branch uses them (check the branch's
menu/settings before P6 — the LAB tenant here is synthetic); 7 is a P2 wording/feature fix. None of these existed in the
register, so `NORMAL_BRANCH_POS_GAPS` is now **8 rows (5 NOT-IMPL UI/route, 2 NOT-IMPL feature, 1 pending reconcile)** until a
"Cashier Parity Milestone 6" builds them **with browser-executable proofs** (modifier lines, variant lines, reminder print,
tip, table move, kitchen notes, barcode) and the canonical void fix is reconciled. That milestone is NOT started here.

What this does **not** change: the P5C connectivity work (install, standby, TLS, cashier access, WAN-down/up) tests the appliance
shell and network contract and remains valid; the WAN-down connectivity test can proceed independently of the UI gaps because it
creates no sales. The gaps gate **P6 (business pilot)**, not the P5C connectivity proof.

```
CASHIER_ARCHITECTURE=B (separate self-contained Edge cashier page served by the Branch Server; live POS page is Cloud-only by design)
UI_VISUAL_PARITY_REQUIRED=no (Online = WHAT, Edge = HOW)   WORKFLOW_PARITY_REQUIRED=yes (no Offline Lite)
ACCEPTED_ONLINE_REQUIRED=card payment/refund, QR e-mail, till-created customers, USB printers (pilot), Cloud admin/reports/Catering
UNREGISTERED_GAPS_FOUND=8 (modifiers, variants, KOT reminder, tip, table move, kitchen notes, barcode search, combo-void fix reconcile)
LAUNCH_BLOCKING_FOR_P6=yes (1, 3, 8 certain; 2, 4, 5, 6 menu/workflow dependent)   P5C_CONNECTIVITY_TEST_BLOCKED=no
CODE_CHANGED=no   LOCAL_MODE_ACTIVATED=no   SALES_CREATED=no   CABLE=connected   PRODUCTION_MUTATED=no
```
