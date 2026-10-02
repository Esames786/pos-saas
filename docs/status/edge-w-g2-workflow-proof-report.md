# EDGE W-G2 — shared cashier view: browser workflow proof on BOTH runtimes (3 Oct 2026)

Team G2. Tool: `tools/edge-browser-proof/shared-pos-workflows.mjs` (Playwright on the installed Microsoft Edge, 1366×768, headless).
Online is the specification; datasets differ (Edge dev seed vs LAB seed), so this is BEHAVIOUR parity, not pixel parity.
Nothing under `app/`, `resources/`, `routes/` or `tests/` was edited. Nothing was committed. The LAB Cloud (:9701), the dev Clouds
:9702/:9703, the installed LAB appliance and production were not touched.

Instances: EDGE = dev Branch Server of this worktree, `http://127.0.0.1:8095/edge/local/pos/shared`, DB `bingoo_edge_devtest_local`
(re-seeded before the final run with `tools/edge-dev-instance/seed.sh`), operators DEVCASH1 (cashier) / DEVMGR1 (approver).
ONLINE = dev Cloud of this worktree, `http://edgehomelab.localhost:9704/pos`, disposable clone `pos_devonline_tenant_edge`,
cashier `lab.cashier@edgehomelab.test`, approver `lab.approver@edgehomelab.test` (LABMF2DE). Credentials were read from
`C:\Users\Dell\BingooEdgeLab\secrets\{cashier,approver}.pass` into environment variables; nothing was printed.

## 1. Verdict table

| # | Workflow | EDGE (final run, `--rewrite-modifiers`) | ONLINE (final run) |
|---|---|---|---|
| W01 | login + POS renders; status slot; terminal/shift badge | PASS — slot `LOCAL MODE · MANUAL SWITCH`, 16 tiles, terminal `Counter 1` auto-selected, badge `No open shift` | PASS — slot `ONLINE · CLOUD`, terminal `Lab Counter 1`, badge `No open shift` |
| W02 | shift open via the SEPARATE page | PASS — `/edge/local/pos/shifts/open` → terminal checkbox + opening cash → redirect → POS `Shift open` | **FAIL (PERMISSION/DATA P2)** — `GET /shifts/open` **403** for the Lab Cashier (lacks `tenant.shifts.create`); fallback `POST /shifts/open` (`tenant.shifts.store`) redirected but no shift opened (branch fenced, P1) |
| W03 | cash walk-in sale → toast → printing panel | PASS — `POST /sales` 201 `SO-1-1-01M3Z7XGN2…`, toast `Sale complete!`, panel lists Receipt + KOT jobs `queued` to FakePrinter. **Without the workaround: 422 (CONTRACT-GAP G1)** | **FAIL (P1)** — `POST /pos` **409 BRANCH_LOCAL_EDGE_ACTIVE** |
| W03M | cash sale of a Customizable (modifier) item | PASS — 201 (modifier line accepted once the body is an array) | FAIL (P1) 409 |
| W04 | sale with a customer picked via search (takeaway) | **FAIL (CONTRACT-GAP G3)** — customer attached (chip) → `POST /sales` **422 ENVELOPE_UNSUPPORTED … customer without a canonical customer_uuid** | FAIL (P1) — chip attached `Lab Customer One`, then 409 |
| W05 | hold → Held list → recall → settle (cash) | PASS — held 201, list shows it, recall rebuilds cart, `POST /held-sales/4/settle` 200, toast `Sale complete!` | FAIL (P1) — `POST /held-sales` 409 |
| W06 | dine-in: open table → hold (KOT) → add → hold (KOT addition) → bill preview → bill requested → move → merge → settle | **FAIL (CONTRACT-GAP G2)** — open 201, round-1 hold 201 + KOT 201; **round-2 hold 422** `Reducing [Cold Drink] below its kitchen-sent quantity requires a void with a reason (qty 1)`; table bill preview OK; bill requested 200; move 200 (G1→G2); merge: **no control in the shared view** (Online has none) — endpoint probe 200 `Table sessions merged successfully.`; settle not reached (after the merge "Continue Table" left an empty cart) | FAIL (P1) — `POST /restaurant/tables/1/open` 409 |
| W07 | void a KOT-sent item → void reason → approver → cancelled | PASS in substance — recalled lines flagged `sent`; Void Reason modal (3 reasons, "Manager code required"); approver prompt = employee code + credential; verify 200 `MA-…`; line removed (2→1 rows); re-hold 200 with `void_print_jobs`. (The tool's own "line removed" check expected 0 rows — tool heuristic, not a product fault.) | FAIL (P1) 409 at open table |
| W08 | manual discount % and fixed → approver → settle; Hold with unconsumed discount | PASS — 10 % (`Discount applied: 30.00`, verify 200) → sale 201; fixed 50 → sale 201; Hold with approved-unpaid discount: toast `Manual discount removed. Apply it when taking payment.` then hold 201; **backend probe** (held-store with `discount_type/value + manager_approval_id`): 422 `Manual discounts are applied with manager approval when taking payment, not while holding an order.` | sales FAIL (P1); UI toast identical; **backend probe identical: 422 same message** → PARITY |
| W09 | Return button (iframe) → search W03 sale → return 1 line → cash refund; direct returns list | PASS — picker finds the sale, non-cash refund options disabled with the Edge hint, `POST /sales-returns` 302 → `/shared/sales-returns/1`; list shows `SR-1-1-01M3Z8B5…` Posted / Cash 100.00 | SKIP (no W03 sale — P1) |
| W10 | recent sales → reprint receipt of W03 | PASS — `POST /sales/1/receipt?reprint=1` 201, toast `Receipt re-queued` | SKIP (P1) |
| W11 | print jobs panel → retry | PASS — panel lists the job; no FAILED job → no Retry button (same rule as Online); probe `POST /print-jobs/15/retry` on a queued job → 422 `Only a terminally-failed local delivery can be retried.`; Reprint (re-queue) 201 | FAIL — no last sale (P1), toast `No recent sale to reprint` |
| W12 | quick report: view/print, network, email | PASS — view opens (`/quick-report/view`, `window.print` fired), network → 200 `Queued to Counter receipt (FakePrinter 9100)`, **email disabled with hint** (EXPECTED-DIFFERENCE E1) | PASS — view opens, network → 200 `Queued to LAB FakePrinter`, email enabled → 422 `No owner email is configured to send the report to.` (dataset) |
| W13 | reservation: reserve → details → unreserve | **FAIL (CONTRACT-GAP G4)** — `POST /restaurant/tables/10/reserve` **422 This branch is not under Branch Server authority.** | FAIL (P1) 409 |
| W14 | split bill on a held dine-in order | PASS — board `Split Bill` button present (DEVCASH1 has `tenant.sales-orders.split-bill`), split page in the modal, `POST …/split-bill` 200 → page returns to the POS; table "Held Orders" lists **2** orders | FAIL (P1) 409 at open table (and the Lab Cashier lacks `tenant.sales-orders.split-bill`, P4) |
| W15 | customer quick-add | **EXPECTED-DIFFERENCE (E3, rendering deviates)** — capability off, route null, but `Add & Attach` is **not** disabled-with-hint; pressing it shows `Could not save the customer.` instead of the hint | PASS — `POST /pos/customers/quick-store` 200, customer created + attached |
| W16 | shift close via the SEPARATE page (blind count) | FAIL (proof sequencing, not a gap) — close page renders with amounts masked `*****` (blind), no denomination grid (dataset has 0 denominations); `POST …/shifts/1/close` → refused by the shared rule `Settle all open work before closing this shift — 6 held order(s)` (ShiftService.php:268, same code on Online) | FAIL — no open shift to close (P1/P2) |
| W17 | logout → login page | PASS — `POST /edge/local/logout` 302 → `/edge/local/login`; POS redirects to login | PASS — `/logout` → `/login`; POS redirects to login |

Edge: 11 PASS, 1 EXPECTED-DIFFERENCE, 5 FAIL (G2, G3, G4, sequencing, plus W07 tool-heuristic). Online: 4 PASS, 11 FAIL, 2 SKIP — every
Online FAIL except W02's 403 is the single environmental fence P1 (see §3); Online could therefore only be proven on the
non-mutating workflows this run.

## 2. Gap list (classified)

### CONTRACT-GAP (Edge backend answers differently from Online)

**G1 — every sale / hold from the shared view is refused on Edge: `lines.*.modifiers` must be an array.**
Request (what the shared view sends — `buildInputs()` in `resources/views/tenant/pos/index.blade.php:3751`): multipart form
`POST /edge/local/pos/sales` and `POST /edge/local/pos/held-sales` with `lines[0][modifiers]="[]"` (a JSON **string**; a modifier
item sends `"[{…}]"`). Edge: **422** `{"message":"The lines.0.modifiers field must be an array. (and 1 more error)","errors":{"lines.0.modifiers":…}}`.
Online: `SalesOrderController::validateSale` (`'lines.*.modifiers' => ['nullable','string']`, decoded by `normalizeLineModifiers`) and
`HeldSaleController::store` (`'nullable|string'`) accept it. Owners: `app/Http/Controllers/Edge/EdgeLocalPosController.php` (`storeSale`,
rule at ~line 1006) and `app/Http/Controllers/Edge/EdgeLocalHeldSalesController.php` (`storeHeldSale`, rule at ~line 167).
(`quoteInput` already accepts both: `['nullable']` + json_decode, line 522 — the same normalisation belongs on the two multipart posts.
`previewBill`/`billPreviewDocument` receive JSON arrays from the view and are fine.) Evidence of the raw refusal:
`…\step7-workflows\edge-no-workaround\report.json` + `W03-cash-walk-in-sale-04-error.png` (dialog "Cannot complete sale — The
lines.0.modifiers field must be an array."). The final Edge run used the proof-tool flag `--rewrite-modifiers` (15 requests rewritten,
each tagged `rewritten` in `report.json`) ONLY so the workflows behind this gap could be exercised; it is not a fix.

**G2 — hold response `lines[]` lacks `client_line_key`, so the shared view never learns the saved line ids on Edge.**
Edge `storeHeldSale` answers `lines: [id, line_uuid, product_id, quantity, unit_price, kot_sent, kot_sent_quantity]`
(`EdgeLocalHeldSalesController.php` ~line 72). Online `HeldSaleController::store` answers per line `id, client_line_key, …, modifiers,
kot_sent, kot_sent_quantity, kitchen_note`. The view (`submitHeldSale`) matches `savedLine.client_line_key` to set `item._dbLineId`; on
Edge nothing matches, so the NEXT Hold posts the same lines without `sales_order_line_id` and Edge's reconciliation
(`EdgeLocalPosService::holdOrReviseSale`, ~line 1146) sees the kitchen-sent line as removed: **422** `Reducing [Cold Drink] below its
kitchen-sent quantity requires a void with a reason (qty 1).` Effect: the second round on a dine-in table (W06) is impossible without
reloading and recalling; the in-cart void flow (W07) only works after a recall (the held LIST does carry ids). Owner: the Edge hold
response shape (`EdgeLocalHeldSalesController::storeHeldSale`). Evidence: `edge\W06-dine-in-table-lifecycle-04-hold-round-2.png`,
report W06 http.

**G3 — a sale with a book customer is refused on Edge when the customer has no `customer_uuid`.**
Request: the W04 sale (`customer_id=1`, Ahmed Raza). Edge: **422** `ENVELOPE_UNSUPPORTED: the sale references a customer without a
canonical customer_uuid — a local id is never a cross-system identity.` (`app/Services/Edge/EdgeSaleEnvelopeBuilder.php:232`, reached
from `EdgeLocalPosController::storeSale`). Online has no such rule. Data fact that makes this real: `customer_uuid` is NULL for the
seeded customers in BOTH `bingoo_edge_devtest_local` and `pos_devonline_tenant_edge` (and therefore the LAB tenant's "Lab Customer
One/Two"); only customers created through the Online quick-add get a uuid. On the appliance a cashier cannot sell to any such customer.
Evidence: `edge\W04-sale-with-customer-search-03-pay-after.png`.

**G4 — reservations demand a stricter authority than sales on the same appliance.**
`POST /edge/local/pos/restaurant/tables/10/reserve` → **422** `This branch is not under Branch Server authority.`
(`app/Services/Edge/EdgeTableReservationService.php:135` — `BranchOperatingModeService::branchHandedToBranchServer` requires
`branches.sales_operating_mode = local_edge`), while on the same appliance state (`LOCAL MODE · MANUAL SWITCH`, bound branch row
`sales_operating_mode = cloud`) sales, holds, table open/move/merge and returns were all accepted. Online reserves on a cloud branch.
Owner: `EdgeTableReservationService::authorize` via `EdgeLocalRestaurantController::reserveTable`. Evidence: `edge\W13-…-01-reserve-form.png`.

**Observations (not gaps):** (a) the shared view has NO merge control (Online spec has none either); both runtimes expose
`…/table-sessions/{session}/merge` — Edge probe answered JSON 200 `{ok, message, session}`; Online's `merge` returns `back()` redirects
(not JSON) — only matters if a control is ever added. (b) `POST /print-jobs/{job}/retry` on a QUEUED job: Edge 422 `Only a
terminally-failed local delivery can be retried.`; the shared view only renders Retry for `print_status=failed` in both runtimes, so
cashiers never hit this. (c) Hold-with-unconsumed-discount: identical client toast and identical backend 422 message on both — parity.
(d) Edge `shift-status` JSON is a superset of Online's keys — parity. (e) After the return POST the Edge iframe answered 302 to the
detail page but the frame stayed on the create URL (the return itself posted and is listed) — same code path as Online, unverified
there (P1); re-check when Online is unfenced.

### PERMISSION/DATA

**P1 — the Online clone is fenced: every sale/hold/table/reservation mutation answers 409 `BRANCH_LOCAL_EDGE_ACTIVE` ("This branch is
operating through Bingoo Local POS. Create and manage sales from the Branch Server.").** Cause: `pos_devonline_tenant_edge.
edge_branch_authority_leases` row 1 (branch 1, holder `cloud`, edge_state `standby`, last_heartbeat 2026-10-02 20:07:05, expires
20:09:05, `fenced_at` set) — the LAB appliance heartbeats the LAB Cloud, never this clone, so `EdgeAuthorityLeaseService::blocksCloud`
treats the lease as lost. (The branch flag `sales_operating_mode` was also `local_edge` — I set it to `cloud` on the clone; the lease
still fences.) Releasing the clone's lease row was refused by the permission system for me, so it is the coordinator's one-line
action before re-running Online:
`UPDATE pos_devonline_tenant_edge.edge_branch_authority_leases SET released_at = NOW(), release_reason = 'G2 proof clone' WHERE branch_id = 1 AND released_at IS NULL;`
(disposable clone only; `pos_lab_tenant_edge` is untouched and must stay so).

**P2 — the LAB cashier cannot reach the shift pages on Online:** `GET /shifts/open` → 403 (route permission `tenant.shifts.create`;
`/shifts/{id}/close` needs `tenant.shifts.close-form`). The Lab Cashier has `tenant.shifts.store/close/index/show` only; Edge's
DEVCASH1 has `create` + `close-form`. Granting them on the clone was refused for me; the W-B report already asked Team E to add both
to `PosPermissionCatalog`. Until then the Online "Open shift" link on the POS leads to a 403 page.

**P3 — no manager PIN exists on the LAB tenant or its clone** (`manager_pins` = 0 rows in both), so Online approvals (void, discount,
return) are impossible as seeded. Precondition applied on the clone only: one `manager_pins` row for user 2 (Lab Approver) hashed from
`secrets\approver.pass` (bcrypt; never printed). The LAB tenant was not changed.

**P4 — Online Lab Cashier lacks `tenant.sales-orders.split-bill`** (has only `.store`), so the board's Split Bill button is hidden on
Online; Edge DEVCASH1 has it (W14 PASS on Edge). **P5** — Edge dev dataset has 0 `currency_denominations` (Online 10): the close page
shows no denomination grid; Edge branch `hide_amounts_from_operators=1` masks the figures (`*****`), Online branch 0. **P6** — Online
quick-report email: 422 `No owner email is configured to send the report to.` (tenant setting). **P7** — DEVCASH1 is "pinned to Counter
1" but the terminal select offers Counter 1 and Counter 2 (`tenant.pos.change-terminal` granted by the seed). **P8** — the header badge
`#ctx-terminal-name` reads "No terminal" on BOTH runtimes although `#terminal_id` = 1 (identical, so parity; worth a look in the shared view).

### EXPECTED-DIFFERENCE (owner-approved capability off)

E1 `#qr-email` disabled, hint "Email needs the Internet — use View / Print here or Send to network on the Branch Server." ✔
E2 `#branch_id` disabled, hint "This Branch Server is bound to its branch — a different branch is served by its own Branch Server." ✔
E4 returns iframe: refund methods Bank Transfer / Card / Other disabled, hint "Refunding it the same way needs the Online POS; a cash refund from this till is possible where the business allows it." ✔
**E3 (rendering deviates from A5)** — customer quick-add: capability `customerCreate` off and route null, but `#qa-save` ("Add & Attach")
is rendered ENABLED without a title; pressing it shows `Could not save the customer.` (the `POS.api` capability-off rejection has
`status 0`, so `quickSave()` falls into its generic catch) instead of the hint "No exact match in the synced customer book. Adding a new
customer needs the Online POS." Owner: shared view `index.blade.php` (customer modal / `quickSave`). Evidence: `edge\W15-…-02-quick-add-refused.png`.

### LAYOUT
None observed (no geometric difference). Console-level differences only: Edge logs `Sidebar element not found` on every page load
(35×; the Edge chrome renders no sidebar, the shared layout JS looks for one — Online 0); the split-bill page inside the modal raised 3
`pageerror`s on Edge (`Cannot read properties of null (reading 'addEventListener' / 'dataset')`) — Online not reached (P1).
Asset 404s: Edge 505, Online 315 `Failed to load resource` (asset URLs are filtered from the HTTP log by design; the dev server log has
no request lines) — most likely product images; not a runtime difference.

## 3. Disabled-with-hint census (whole DOM incl. hidden modals)
EDGE: `#branch_id` (E2), `#qr-email` (E1), returns iframe refund options (E4); plus `#bill-preview-btn` (empty-cart disable, ordinary
tooltip — both runtimes). ONLINE: only `#bill-preview-btn`. Every 4xx/5xx and every console error per workflow: `report.json → http_errors
/ console_errors` in each evidence folder (Edge http errors: the six 422s listed above; Online: `/dashboard` 403 at login, `/shifts/open`
403, the 409 fence, two 422s).

## 4. Print worker (optional, done)
`APP_ENV=edgedev php artisan edge:local:print-worker --once` (allowlisted in `config/edge.php`) ran once against the dev DB after the
proof: `job 1: delivered` (the W03 receipt) to the FakePrinter — `fake-printer.bin` 23,097 → 24,105 bytes; `print_jobs` 19 queued →
18 queued + 1 printed. The worker was not left running.

## 5. Row counts (end of run)
`pos_lab_tenant_edge.sales_orders` = **5** (LAB tenant untouched; its lease and branch rows unchanged).
`pos_devonline_tenant_edge.sales_orders` = **5** (no sale could be created: fence P1; +5 quick-add customers "Zed Proof …", 0 shifts).
`bingoo_edge_devtest_local.sales_orders` = **11** (4 paid, 1 partially_returned, 6 held) + 1 sales_return, 1 open shift.

## 6. Evidence and re-run
Evidence: `C:\Users\Dell\BingooEdgeLab\evidence\phase2\step7-workflows\edge\` (final Edge run, `--rewrite-modifiers`, report.json +
~70 PNG), `…\edge-no-workaround\` (raw G1 refusal), `…\online\` (final Online run, fenced), `…\online-run4-fenced\`.
Re-run (Git Bash, from `tools/edge-browser-proof`; `node` = `D:/laragon2/bin/nodejs/node-v20.20.1-win-x64/node.exe`):
```
# Edge — reproduce G1 (no flag) / prove the rest (with the flag); re-seed first for a clean DB: bash tools/edge-dev-instance/seed.sh
MSYS_NO_PATHCONV=1 POS_SHOT_PASS=CashierPass1 POS_MGR_PASS=MgrPass1 node shared-pos-workflows.mjs --mode edge --base-url http://127.0.0.1:8095 --user DEVCASH1 --manager-user DEVMGR1 [--rewrite-modifiers]
# Online — after the coordinator releases the clone's lease (P1); secrets from the files, never typed
MSYS_NO_PATHCONV=1 POS_SHOT_PASS="$(tr -d '\r\n' < /c/Users/Dell/BingooEdgeLab/secrets/cashier.pass)" POS_MGR_PASS="$(tr -d '\r\n' < /c/Users/Dell/BingooEdgeLab/secrets/approver.pass)" node shared-pos-workflows.mjs --mode online --base-url http://edgehomelab.localhost:9704 --user lab.cashier@edgehomelab.test
```
Options: `--only W03,W05`, `--out <dir>`, `--headed`, `--settle <ms>`, `--customer-query <text>`. README section added in
`tools/edge-browser-proof/README.md`.
