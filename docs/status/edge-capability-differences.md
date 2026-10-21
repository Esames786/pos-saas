# Branch Server capability differences — the ONLY allowed functional differences from the Online POS

Date: 20 Oct 2026 (Team CAP, owner decisions D2 / D4 / D6 of 20 Oct 2026). Branch `feat/edge-config-refresh-v1` on top of 3d21b1c.
Source of truth in code: `App\Services\Edge\EdgePosRuntimeFactory::CAPABILITIES` (the matrix), `::LABELS` (the hints),
`App\Support\Pos\PosRuntime::CAPABILITY_KEYS` (every flag the ONE shared cashier view may ask), the Cloud factory
(`CloudPosRuntimeFactory`: every key ON). Gates: `tests/MySql/EdgeSharedPosRegressionGateMySqlTest` (both runtimes rendered —
test_c / test_c2 / test_c3) + `tests/Feature/Edge/EdgeSharedPosRegressionStaticGateTest` (the map is complete against the view
source; every OFF capability has a Blade/JS gate or is declared server-only).

Owner rule (A5, §7 of `edge-next-release-shared-pos-architecture.md`): **one shared view, no Edge-styled copy**. A capability that
is off on the Branch Server renders the SAME control in the SAME slot, `disabled` with the hint as its `title`; its route is `null`
so the page JS never calls a Cloud endpoint. These are **capability differences, never layout differences** — the pixel baseline
masks only the disabled state and the status strip.

## 1. The matrix

| Capability (`PosRuntime` key) | Cloud | Branch Server | Slot / control the view gates | Hint shown on the Branch Server (`EdgePosRuntimeFactory::LABELS`) | Why | Decision |
|---|---|---|---|---|---|---|
| `reports` | ON | **OFF** | `#pos-report-btn` (Reports Centre iframe) | Reports run on the Online POS. | Reports Centre is Cloud reporting scope; the Quick Report covers the counter | §7 (A5), W4 |
| `quickReport` | ON | ON | `#pos-quick-report-btn` | — | canonical engine runs on the appliance | W4 |
| `quickReportEmail` | ON | **OFF** | `#qr-email` | Email needs the Internet — use View / Print here or Send to network on the Branch Server. | no Internet path from the appliance; never fakes "sent" | W4 (R5.5) |
| `quickReportNetwork` | ON | ON | `#qr-network` | — | Edge print authority | W4 |
| `manageFloorsTables` | ON | **OFF** | `button[data-management-url]` (Manage Floors / Tables iframes) | Floors, tables and waiters are Cloud configuration — manage them on the Online POS; changes reach this Branch Server with the next configuration refresh. | Cloud configuration CRUD; reaches the appliance by config refresh | §7 (A5) |
| `branchSelect` | ON | **OFF** | `select#branch_id` | This Branch Server is bound to its branch — a different branch is served by its own Branch Server. | one bound branch per appliance | §7 (A5) |
| `nonCashTender` | ON | **OFF** | `select#payment_method_id option[data-type!="cash"]` (cash stays enabled) | Cash is taken on the Branch Server. Card / provider payments run on the Online POS; bank transfer, cheque and other manual methods await the owner decision. | card/provider = ONLINE_REQUIRED; manual methods pending owner decision | §7, owner pending |
| `tipOnPaidSale` | ON | **OFF** | `.tip-btn` on the paid-sale path (tips are still QUOTED on held/preview) | A tip cannot be recorded on a Branch Server sale until the Cloud sync contract carries tips — choose No Tip to complete this sale. | the sale envelope does not carry a tip yet | §7 |
| `customerCreate` | ON | **ON while the Cloud advertised `customer_create`** (baseline OFF) | `#qa-save` (quick-add modal) | No exact match in the synced customer book. Adding a new customer here needs the Online service to confirm offline customer support; until then add the customer on the Online POS. | a new customer must be able to reach the Cloud as a `new_customer` envelope (v3) | Phase 4 §8 |
| `customerAddressCreate` | ON | same advertisement | `#new-addr-save` | Saving another address for this customer here needs the Online service to confirm offline customer support; the order can still go to a saved address. | same envelope kind | Phase 4 §8 |
| `changeRider` | ON | **OFF** (JS-gated, route `salesOrderShow` null) | the sales-order detail link | Changing the rider of a completed delivery needs the Online POS. | no Edge sales-order page | §7 (§14 gap) |
| `splitBill`, `salesReturn`, `shiftPages`, `promotions`, `tips`, `tableMerge`, `reservations`, `deadSessionRecovery`, `printHere` | ON | ON | — | — | local twins exist | W-B/W4/F1 |
| `reminderPause` | ON | **ON** (since D2, 20 Oct 2026) | `#pos-reminder-toggle` | — (no hint: the capability is on; a runtime with it off renders the toggle disabled with the hint, as before) | the pause is a stamp on the appliance's OPEN shift row through the shared `ReminderPauseService`; the shared print plumbing honours it; no Cloud call; reconciliation = the shift (see `edge-d2-d3-reminder-pause-and-capabilities.md` §2) | **D2** |
| `websiteOrders` | ON (plan with Online Ordering) | **OFF** | `#web-orders-btn` bell + the `website-orders` partial (`#webOrdersModal`) — the SAME component in both renders when the plan carries Online Ordering; routes `websiteOrders` / `websiteOrderHold` / `websiteOrderVerify` null on Edge, the partial's JS stops without `POS.route('websiteOrders')` | Website orders arrive on the Online POS — a website order needs the Cloud, so this Branch Server does not take them. | a website order is received by the Cloud; the appliance has no inbox source | **D4** (CLOUD_ONLY) |
| `partnerShare` | ON | **OFF** (server-side) | **none on either runtime** — the quick-report modal has no Partner Share box (Online `PosQuickReportController::SECTIONS` excludes `partner_share`; the section lives in the Reports Centre = `reports`); the Edge quick report strips the section by this flag (`EdgeQuickReportController::CAPABILITY_SECTIONS`, `sectionsAllowed()`) and never computes it | Partner share is a Cloud report section — this Branch Server's Quick Report does not compute it; open the Online Reports Centre. (server refusal hint only) | `share_partners` / `share_rates` are CLOUD_ONLY tables (manifest D7) and are NOT shipped in this release; bootstrap stays **v8** | **D6** |
| `returnWithoutBill` | ON | **OFF** (server-side) | **none on the cashier page** — the site is the SHARED `sales-returns/create` screen: the "Without bill" tab + partial render only when the Cloud feature `return_no_bill` is on AND this capability is on; the Cloud `SalesReturnController` no-bill store route has no Edge twin | A return without a bill is recorded on the Online POS — it needs the Cloud (today's price, the batch it goes back to); this Branch Server returns against a bill only. | RETURN-NO-BILL-1 (canonical 025728d8): no sale to read from — today's price, batch pick, Master switch; the appliance returns against a bill only (F1) | **Owner 22 Oct 2026** (CANONICAL_RELEASE_FREEZE integration: Cloud-only capability boundary) |

Edge-only *additive* labels that are not capabilities: `nonCashTenderShort`, `reservedOnline`, `refundOnlineRequired`, `shiftBranchWide`,
`shortageVoucher`, `modeChip`, `connection` (status slot wording — the shared slot, business words only).

## 2. The three decisions of 20 Oct 2026 in one line each

- **D2 reminder pause — LOCAL.** Same control, same modal-less toggle, same visible badge state, same permission
  (`tenant.pos.pause-reminders`), same rule engine (`ReminderPauseService` → the LOCAL `shifts.reminders_paused_at/_by_user_id`),
  same consumers (`PrintJobService` KOT reminder plan / cancellation reminder / `DirectPayPrintOrchestrator`). Twin:
  `POST /edge/local/pos/reminder-pause` (`edge.local.pos.reminder.pause`, allowlisted). STANDBY refuses with
  `422 {ok:false, code: STANDBY}`; reads (the badge) keep working. Nothing crosses a boundary: the pause ends with the shift, and a
  handback is blocked while any appliance shift is open (`OPEN_SHIFTS`).
- **D4 website orders — CLOUD_ONLY capability.** Same component; disabled + hint; zero geometry delta; no Cloud endpoint
  reachable from the Edge render (gate test_c3 + test_e). Documented here as a capability difference, not a layout difference.
- **D6 partner share — CLOUD_ONLY, server-side capability.** `partnerShare` in `PosRuntime::CAPABILITY_KEYS`, Cloud ON, Edge OFF;
  the interim `EdgeQuickReportController::CLOUD_ONLY_SECTIONS` guard is replaced by the capability; no control exists to disable
  (adding one would change Online's modal), so the gates carry a `SERVER_ONLY_CAPABILITIES` class that asserts: Cloud ON, Edge OFF,
  no view site, the server rule. Bootstrap stays v8 — no `share_partners` / `share_rates` sections.

## 2a. Owner decision §4-2 (21 Oct 2026) — Medical Counter and Purchasing-V2 are NOT built offline in this release

- **Medical counter (MEDICAL-COUNTER-1/-KEYS/-UX/-RX-POPUP, PHARMACY-PACKS-1): capability OFF on every Branch Server.** `PosPageData`
  key `medicalCounter` is `false` on the Edge runtime, so none of the medical chrome, pack/batch/Rx fields or shortcut handlers render or
  run in Local Mode — no silent medical / batch / prescription behaviour at the till. The schema it needs
  (`product_pack_units`, `sales_order_lines.inventory_batch_id/batch_note`, `sales_returns.shift_id/terminal_id`) IS on the appliance
  (EDGE_REQUIRED, D7), so a later twin needs no new migration. Bootstrap stays v8, the sale envelope stays v3 — no version bump for this.
- **Purchasing-V2 (batch-picked purchase return, purchase discounts, GRN void by own batch): Cloud-only screens, excluded from the
  artifact.** The Cloud-specific workflow is never presented as supported offline. The current F3 Edge purchase-return twin
  (`purchase_returns_grn`: return against a mirrored goods receipt, posted pending sync) keeps the behaviour already tested; the `products`
  discount columns reach the appliance through the proven upgrade + the next refresh.
- Both become later Edge capability phases after the current Restaurant / General POS release is certified.
## 3. How to add a capability (so the gates stay green)

1. `PosRuntime::CAPABILITY_KEYS` += key; `EdgePosRuntimeFactory::CAPABILITIES[key]` = the Branch Server verdict (Cloud factory fills every key ON).
2. If OFF on Edge: either gate a control in the shared view (`@disabled(! $posRuntime->can('key'))` + `title="{{ $posRuntime->capabilityHint('key') }}"`
   → add it to `EdgeSharedPosRegressionGateMySqlTest::CAPABILITY_CONTROLS`, or `POS.can('key')` in the JS → `JS_GATED_CAPABILITIES`), or —
   when no control exists on EITHER runtime — declare it in `SERVER_ONLY_CAPABILITIES` and enforce it in the Edge endpoint. Always add the hint to `LABELS`.
3. Its route(s): `null` on Edge while off; a real allowlisted `edge.local.*` path when on (`config/edge.php` route_allowlist + the URI census tests).
4. Add a row to §1 above with the owner decision reference.
