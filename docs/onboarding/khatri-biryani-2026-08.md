# ✅ KHATRI BIRYANI = LIVE (2026-08-09)

- **Domain**: https://khatribiryani.bingoopos.com (valid TLS, login 200, protected pages auth-gated)
- **Application HEAD**: 44654aec22772072e7b1254205f576439b0b7ce0 (deployed; envbak md5 unchanged)
- **Tenant**: khatribiryani / plan khatri_restaurant / active subscription (exactly one each)
- **Limits**: branch 1, terminal 4 (ACTIVE cap; update-path bypass closed + tested), user 10
- **Terminals**: T1/T2 active, T3/T4 inactive (initial state; activation to 4 allowed)
- **Menu**: 8 categories / 39 products, 100% service-based (0 stock-tracked; live sale proof: 0 stock_ledgers rows)
- **Modules**: manufacturing + erp_extensions + offline_edge DISABLED (entitlement authority BLOCKED proof incl. quotations/purchase-requisitions fail-open closure)
- **Manager**: 371 perms, zero MFG/ERP/roles/billing leakage (live single-child round-trip 371→370→371)
- **Delivery charge**: live delivery sale 450+100 → GL 1110 D550 / 4150 C100 / 4120 C450 BALANCED, tb_diff 0.00, receipt line, report column
- **Report Center**: live overview orders 4 / grand 2080 / delivery 100 / cash 2080; Cash&Bank opening 1000 separate (expected formula 3080)
- **Backup**: /var/backups/khatri/khatri-biryani-post-onboarding-2026-08-09-20260809-093502.sql.gz (35,965 B, sha256 46683456e48bcbaa50b4644dd8e8e9bb3db28b62f0adcdd84cfd9e7cc01225c3) + master manifest (sha256 595b739f…) — **RESTORE-VERIFIED** into pos_test_restore_* (15/15 counts MATCH incl. 8/39/4-terminals/483 perms) then dropped
- **Owner credential**: /root/khatri-owner-credential.txt (600) — never logged
- **Owner/default report email**: `owner_kb@bingoopos.com` (set by the idempotent Khatri onboarding command)
- **Platform**: all 8 tenants tb=0/neg=0/dept=0; khatri menu isolated to its DB; demos unchanged; 0 new log errors; Edge dormant (APP_ROLE=cloud, Local Mode inactive, activation_ready=false)
- **Test data note**: 4 clearly-labeled TEST sales (customer "DEPLOY TEST") + one closed shift (variance 0) remain in the fresh tenant as deployment evidence; smoke script created sales pre-paid so SHIFT counters intentionally skipped settlement (real POS controller path is suite-proven) — no dangling open shift.
- **Client follow-ups**: confirm ⚠-flagged prices; provide owner email for report emails; native XLSX/PDF pending composer approval.

# KHATRI BIRYANI — onboarding + Sales Report Center + Permission Center (2026-08)

ONE connected production-facing implementation (user prompt 2026-08-09):
1. Tenant onboarding: **khatribiryani.bingoopos.com** — branch limit **1**, terminal limit **4**
   (**2 active** initially), ALL standard restaurant/POS modules EXCEPT **ERP Extensions** and
   **Manufacturing** (disabled at entitlement/nav/routes/permissions/direct-URL — via the EXISTING
   entitlement architecture, never a parallel system). Manager role prepared (no Manufacturing/ERP
   authority; never hardcode "Khatri" into permission code).
2. **Business-Friendly Permission Center**: presentation/grouping layer OVER the existing granular
   permissions (backend keys unchanged — no renames/merges). Module → feature → View/Add/Edit/Delete
   + SEPARATE sensitive actions (Approve/Post/Reverse/Void/Refund/Close Shift/…); expandable children
   with friendly labels; parent checked/unchecked/indeterminate; AJAX/support permissions placed under
   their consuming business feature; entitlement-aware (non-entitled modules hidden or "Not available
   on current plan"); search/select-module/clear-module; responsive. Safety tests A–F (incl. Edit
   never grants refund/void; shared lookups not accidentally revoked; existing role grants survive).
3. **Sales Report Center** (Reports → Sales Report Center): ONE shared filter/query engine —
   Overview KPIs, Category→Child→Item, Item/Variant, Waiter (+Unassigned bucket), Order Type,
   Detailed (server-side pagination; exports use FULL filtered set), and a SEPARATE **Cash & Bank
   Movement** report (opening float NEVER revenue; expenses never negative sales; Expected Cash
   reconciliation; bank side). Filters: date presets + branch/terminal/shift/cashier/waiter/order
   type/category/child/item/payment method (tenant timezone). Rendering: THERMAL (58/80mm Z-style) +
   STANDARD A4 from the same view-model; XLSX workbook + PDF + CSV(detailed); **Email Now** (tenant
   default email; controlled error if missing); **Schedules** (daily/weekly/monthly, email-only,
   tenant tz, idempotent per schedule+period, last/next-run tracking; Cloud scheduler/queue — cron
   configured manually; NOT Edge). **Z Report / End of Day preset** (Overall + Order Types +
   Categories + Waiters + Payment Summary + Cash & Bank for the day).
   LOCKED PRINCIPLE: Sales reports answer "what did we sell?"; Cash & Bank answers "where did money
   come from/go?" — never merged into one misleading grand total.
   Reconciliation fixture must prove Overall = Category = Item = Waiter(+Unassigned) = OrderType =
   Detailed net for one population; opening float excluded from sales.

Deploy gate: full inspection report first (16 points), then implement in the prompt's AG order,
**checkpoint report → user review → ONLY then deploy**. Edge work untouched (Local Mode inactive,
activation_ready=false).

## AG-1 INSPECTION REPORT (2026-08-09) — 16 points, grounded

1. **Entitlement**: master `plans`/`plan_modules`(is_enabled)/`plan_features`(numeric limits)/`modules`
   (16 keys; `route_module_keys` JSON) → route `module_key` via `PermissionSyncService::moduleKey()`
   (first 2 segments + explicit overrides) → `EnsureTenantSubscriptionAccess` (FAIL-OPEN on unmapped
   keys — known bug class). `tenant_module_overrides` DOES NOT EXIST (Phase-2 doc only) → per-tenant
   module set = own plan row. Nav gating via `$hasModule()` in sidebar; **"ERP Extensions" is NOT a
   module** — a sidebar group hardcoded to plan codes {enterprise, standard, finance_erp}; its pages:
   bank-reconciliation→`tenant.finance` key, quotations + purchase-requisitions **unmapped ⇒
   entitlement fail-open** (permission-gated only; all three are "Soon" stubs).
2. **Branch limit**: `plan_features.branch_limit` enforced at BranchController::store only (active-count).
3. **Terminal limit**: `plan_features.terminal_limit` enforced at TerminalController::store; counts
   ACTIVE terminals only; update() has no re-check → "4 total / 2 active" = 4 rows (2 inactive), used
   2/4 — correct under the active-count semantics. Provisioner seeds NO terminal.
4. **Role/permission storage**: spatie on tenant DB, guard `tenant`; **permission KEY = exact route
   name** (≈519 tenant.* names); enforced by `EnsureRoutePermission` ($user->can(routeName)); keys
   originate from `system:routes-sync` → route_catalogs → syncTenantPermissions + provisioner array +
   deploy.sh Owner-grant loop.
5. **Key origins**: route_catalogs (published) — three sync sources must stay aligned (routes-sync,
   provisioner array, deploy loop). No RouteModulePermissionCatalog class exists.
6. **Permission↔route relationship**: 1:1 by name; verb-suffix taxonomy is machine-derivable
   (.index/.show/... CRUD vs .approve/.post/.reverse/.void/.close/... actions — full list captured).
7. **AJAX deps**: 8 `tenant.ajax.*` routes are authenticated-only (NEITHER permission- NOR
   entitlement-gated today — rows exist but are never checked); `tenant.ajax.products` is shared by
   ~15 screens across purchasing/inventory/manufacturing; UI must place them under consuming features
   and must NOT silently revoke shared ones. Several `tenant.api.pos|catalog|kitchen-display|
   print-agent` + `tenant.printing.documents|jobs` prefixes are permission-BYPASSED by design.
8. **Report architecture**: thin controllers → `app/Services/Reports/*`; conventions to reuse:
   `businessDayExpr` = COALESCE(business_date, DATE(sale_date)) (currently DUPLICATED in 2 services —
   new engine becomes the single home), ResolvesBranchIds trait, TenantClock currentBusinessDay,
   filters partial, CsvStreamer (BOM + tenant header).
9. **Monetary fields**: sales_orders subtotal/discount_amount/tax_amount/service_charge_amount/
   tip_amount/grand_total/paid_amount/balance_due/status(draft|held|paid|cancelled|
   partially_returned|returned)/order_type enum/business_date; lines returned_quantity; **NO
   delivery_charge column** (delivery economics = channel commission_percent, computed, no snapshot).
   ⚠ Existing "net_sales" = SUM(grand_total) of status='paid' ONLY — includes tax/service/tips and
   makes partially_returned orders VANISH from reports.
10. **Return authority**: sales_returns + sales_return_lines + lines.returned_quantity;
    grand_total(=subtotal+prorated tax) is the money authority (drives cash-bank movement); returns
    never recover discount/service/tip; own return_date (no business_date).
11. **Waiter/order-type**: restaurant_waiters + sales_orders.restaurant_waiter_id;
    `User::ORDER_TYPES` = canonical (dine_in/takeaway/quick_sale/delivery); RestaurantReportService
    is single-branch-only today (upgrade in new engine).
12. **Shift/cash**: shifts opening_cash/expected_cash/counted/variance/total_* buckets (+per-method
    refund buckets); ⚠ wallet/other method types never reach shift buckets; **NO manual cash-in/out
    feature exists**; daily_closings; denominations polymorphic.
13. **Bank/finance movement**: `cash_bank_account_transactions` (direction/amount/balance_after/
    transaction_type) — COMPLETE writer set captured (sales_payment, sales_return_refund,
    opening_balance(+void), expense_payment(+void), supplier_payment, customer_payment,
    manual_journal(+reversal), dept_handover_payout(+reversal)); ⚠ model TYPES const is stale;
    ⚠ transaction_date = sale_date (NOT business_date) — cross-midnight divergence must be surfaced;
    unmapped payment methods silently skipped.
14. **Export/PDF stack**: composer has NO excel/pdf packages; existing = CsvStreamer CSV + printable
    HTML (`printing/documents/receipt.blade.php` 58/80mm pattern + @media print) — thermal/A4 print
    views + CSV exports are the repository-supported path; native XLSX/PDF needs new composer deps
    (flagged for approval, exporter seam left ready).
15. **Mail/scheduler/queue**: 2 Mailables exist (pattern captured); MAIL log-only; scheduler =
    routes/console.php inside `EdgeRuntime::isCloudSafe()` with config-gate + withoutOverlapping
    convention; queue=database on MASTER connection (tenant jobs must re-resolve context — avoided in
    v1 by sending inline from the scheduler command, tenants:backup-style iteration); NO tenant-level
    timezone (branch is the anchor — schedules resolve tz via the tenant's first active branch).
16. **Tenant default email**: `tenants.owner_email` (master, nullable) — THE default recipient;
    missing → controlled config error. No new storage.

## DECISION LOG (locked for implementation)
- **D1 Khatri plan**: NEW non-public custom plan `khatri_restaurant` (is_public=false, is_custom=true)
  with EXACTLY restaurant_pro's module set (= all 16 minus manufacturing minus offline_edge) +
  plan_features branch_limit=1, terminal_limit=4, user_limit=10, product_limit=null. Plan code ∉
  {enterprise,standard,finance_erp} ⇒ ERP Extensions sidebar group hidden automatically. Custom plan
  rows are NOT touched by MasterSeeder re-runs (syncPlanModules only iterates seeder-defined plans).
- **D2 ERP fail-open closure**: new `erp_extensions` module row (route_module_keys: tenant.quotations,
  tenant.purchase-requisitions) enabled ONLY where the sidebar group shows today ⇒ closes the
  unmapped-key fail-open for those routes; bank-reconciliation stays under finance (stub; residual
  documented).
- **D3 Terminals**: seed 4 (T1..T4), T1/T2 active, T3/T4 inactive.
- **D4 Products**: all service-based (is_stock_tracked=0, consumption 'none', product_type simple).
- **D5 Report engine population**: paid + partially_returned + returned (fixes vanishing-order bug in
  NEW screens only; existing reports untouched). Formulas: Gross=SUM(subtotal);
  Net Sales=SUM(grand_total) − period returns (by return_date); returned qty/amount always separate
  columns. Existing report numbers unchanged.
- **D6 Exports v1**: CSV (CsvStreamer, multi-section) + thermal/A4 print HTML (browser print = PDF
  path); Email attachments = CSV via Attachment::fromData; native XLSX/PDF behind a driver seam
  pending composer-dependency approval.
- **D7 Schedules**: tenant-DB `report_schedules` + `report_schedule_runs` (unique schedule+period_key
  = idempotency); Cloud command `reports:dispatch-scheduled` in the isCloudSafe block iterating
  tenants (tenants:backup pattern), sending inline; recipient = tenants.owner_email.
- **D8 Permission Center**: metadata catalog service (module→feature→action via the captured suffix
  taxonomy + explicit overrides; sensitive suffixes NEVER classified as CRUD); zero key
  renames/deletes; entitlement-aware editor; ajax/support perms shown under consuming feature with
  shared-dependency guard.

## SCOPE ADDITIONS (user, 2026-08-09 mid-implementation)
- **Delivery charges input** (DELIVERY-CHARGE-1): sales_orders.delivery_charge_amount (additive, default 0); POS input for delivery orders; totals/grand_total; GL credit 4150 Delivery Charges Income (revenue formula minus delivery); receipts (HTML + ESC/POS); canonical payload key; Edge REFUSES non-zero value offline (delivery not offered; hash stability); Report Center column.
- **Cash & Bank must show department handover payouts**: include dept_handover_payout / dept_handover_payout_reversal transaction types as labeled money-out rows (“paid from cash/bank to third-party department owners”).
- **Departments section in the overall sales report**: new Departments tab in the Report Center using the existing department-sales authority (is_third_party depts incl.).

## Menu / rate list (extracted from client screenshots)
AUTHORITY ORDER: Z-report item prices (actual POS data 02-Aug-2026) > new "Kashif Kitchen" menu >
old printed menu. ⚠ VERIFY WITH CLIENT flags noted. Branding on materials says "KASHIF KITCHEN /
KASHIF FOODS BLOCK 13/D" while the tenant is named Khatri Biryani — signage vs tenant name to
confirm at go-live (not blocking).
**ALL products SERVICE-BASED: `is_stock_tracked=0`, `inventory_consumption_method='none'` — no
inventory deduction anywhere.**

| Category | Product | Price (PKR) | Source |
|---|---|---|---|
| Beef Khatri Biryani | Beef Khatri Biryani (1/2 kg) | 450 | Z |
| Beef Khatri Biryani | Beef Khatri Biryani (1 kg) | 900 | Z |
| Beef Khatri Biryani | Beef Khatri Biryani Special (1/2 kg) | 600 | Z |
| Beef Khatri Biryani | Beef Khatri Biryani Special (1 kg) | 1200 | Z |
| Beef Khatri Biryani | Saadi Khatri Biryani (1/2 kg) | 250 | Z |
| Beef Khatri Biryani | Saadi Khatri Biryani (1 kg) | 500 | Z |
| Beef Khatri Biryani | Saadi Biryani (1/2 kg) | 200 | Z (distinct from Saadi Khatri) |
| Beef Khatri Biryani | Matka Biryani Beef | 4000 | Z |
| Beef Changezi Pulao | Beef Changezi Pulao (1/2 kg) | 450 | Z |
| Beef Changezi Pulao | Beef Changezi Pulao (1 kg) | 900 | Z |
| Beef Changezi Pulao | Beef Changezi Pulao Special (1/2 kg) | 600 | Z |
| Beef Changezi Pulao | Saada Beef Changezi Pulao (1/2 kg) | 250 | Z |
| Chicken Biryani | Chicken Biryani (1/2 kg) | 330 | Z (menu2 said 250 ⚠) |
| Chicken Biryani | Chicken Biryani (1 kg) | 650 | Z |
| Chicken Biryani | Chicken Biryani Family Pack | 1600 | menu2 ⚠ |
| Chicken Biryani | Chicken Extra Piece | 150 | Z (menu2 said 110 ⚠) |
| Singaporean Rice | Singaporean Rice (Small) | 550 | Z |
| Singaporean Rice | Singaporean Rice (Large) | 1000 | menu1 ⚠ (menu2 880) |
| Singaporean Rice | Singaporean Rice Family Pack (Small) | 2500 | menu1 ⚠ (menu2 2250) |
| Singaporean Rice | Singaporean Rice Family Pack (Large) | 3500 | menu1 ⚠ (menu2 3150) |
| Singaporean Rice | Extra Sauce | 130 | Z |
| Haleem | Haleem (Plate) | 300 | menu1 |
| Haleem | Haleem (1/2 kg) | 400 | menu1 |
| Haleem | Haleem (1 kg) | 800 | menu1 |
| Desserts | Cream Cocktail (Cup) | 120 | Z (menu2 160 ⚠) |
| Desserts | Cream Cocktail (Half Pack) | 600 | Z |
| Desserts | Cream Cocktail (Full Pack) | 1000 | menu2 |
| Desserts | Mango Delight (Cup) | 130 | Z |
| Desserts | Mango Delight (Half) | 650 | Z |
| Desserts | Mango Delight (Full) | 1300 | ⚠ inferred (menu lists it; price unreadable) |
| Beverages | Mineral Water (Small) | 60 | Z |
| Beverages | Mineral Water (Large) | 120 | Z |
| Beverages | Cola Next 300 ml | 90 | Z |
| Beverages | Cola Next 500 ml | 110 | Z |
| Beverages | Cola Next 1.5 Ltr | 180 | Z |
| Beverages | Coldrink Jumbo | 240 | Z |
| Beverages | 1 Ltr Coldrink | 160 | Z |
| Beverages | Pakola 300 ml | 90 | Z |
| Extras | Raita | 70 | Z (menu2 50 ⚠) |

Notes: "Delivery Charges" on the legacy Z-report is a CHARGE, not a product — maps to Bingoo's
delivery-charge field (delivery order type + rider/channel support already exist). Legacy system
shows returns as negative qty lines — Bingoo's return authority handles this properly (report center
must show returned qty separately, per spec). Order types observed on legacy Z: DELIVERY / DINE IN /
TAKEAWAY / TAKEAWAY WAITER / POS — map to Bingoo's delivery/dine_in/takeaway/quick_sale (TAKEAWAY
WAITER ≈ waiter-attributed takeaway; handled by waiter attribution, not a new order type).

---

## Post-go-live UX/print hardening (2026-08-10)

Driven by first-real-sales-day feedback (screenshots + printed slips):

- **CHANGE-AMOUNT-1** (`c592657`): sale-level change now = per-payment tendered − applied
  (was always 0); receipts print tendered cash + real change; ESC/POS auto-cut (GS V) on all
  documents; **Bingoo branding** layout toggle (default ON receipts only). 9 historical Khatri
  sales backfilled (display-only; GL untouched).
- **PRINT-FORMAT-PARITY-1** (`09a9cf6`): ESC/POS receipt/KOT honour the SAME
  receipt_layout_settings as the browser preview (toggles previously applied to HTML only);
  ORDER TYPE leads every document; single-line items with clean quantities ("2" not "2.000")
  across receipt/KOT/reminder in both renderers. POS tiles overlap fix + category wrap.
- **CUSTOMER-UX-1**: POS "Add / Search Customer" modal (server-side name/phone search via new
  `tenant.ajax.customers`, mapped into the pos module for entitlement + Manager sync);
  per-customer **address book** (`customer_addresses`, one default; legacy customers.address
  as fallback); attached-customer **chip** near the order-type tabs; branch
  **default_delivery_charge + delivery_charge_locked** (lock enforced SERVER-side in both the
  quote and the paid-sale path — client input ignored when locked). The render-every-customer
  dropdown is gone (page weight + 1000-customer hang).
- Offline/Edge: unaffected by design — delivery (and therefore the charge/address flow) stays
  Cloud-only (Edge refuses `delivery_charge_amount`); vehicle_number retains offline parity.

## Day-2 evening sprint (2026-08-10, prod @ `74d9415`)

- **KHATRI-MENU-2** (`071c983`): child categories per the client's handwritten note — Beef
  Khatri Biryani → Non-Saada / Saada / Matka; Beef Changezi Pulao → Non-Saada / Saada
  (Saada = plain/no meat; items named Saada/Saadi matched there). New items: Beef Changezi
  Pulao Special (1 kg) @1200, 750 ML Box @30, 1500 ML Box @50. products.sort_order drives
  small→large ordering everywhere. KOT routing splits by PARENT, children inherit
  (52 mappings). 13 categories / 42 products.
- **KHATRI-UX-2** (`d5fc95e`): one-row POS context bar (branch/terminal → summary + Change
  modal), duplicate delivery-address input removed (customer modal + chip carry it),
  vehicle inline.
- **REPORT-CENTER-3** (`6331060`): order-type combination reports + cancellations report
  (post-KOT voids/decreases w/ reasons); checkbox section selection for Print Selected
  (Thermal/A4) + Export Selected CSV; Print All renamed; thermal = A4 column parity; TOTAL
  rows everywhere. Per-section permissions `tenant.reports.center.sections.*` (9 keys,
  back-granted to Report-Center roles; enforced across tabs/print/export/email).
  `tenant.dashboard` = baseline permission on every role (fixes "dashboard forbidden +
  logout error" for custom roles like `cash`).
- **TENANT-RESET-1** (`74d9415`): `tenant:reset-transactions {code} --yes --confirm={code}` —
  wipes transactions, keeps ALL master data; triple-guarded + integrity-verified.
  **NOT yet run on Khatri** — planned after the client demo (backup first).

### CASH-SHORTAGE-1 (2026-08-10)

Closing a drawer **short** now shows the difference live on both close screens ("Short by X" /
"Over by X" per terminal + branch total) and automatically raises a **DRAFT expense voucher**:

- New CoA account **6930 Cash Short / Over** (synced to every tenant by deploy.sh).
- Auto-created system expense category **"Daily Closing — Short Cash"** (`DAILY-CLOSING-SHORT`),
  created on the first shortage only.
- One draft per source (`EXP-SHORT-<date>-S<shiftId>` / `-D<dailyClosingId>`) — idempotent, so a
  repeated close never duplicates it. Branch-total mode raises ONE branch voucher; per-terminal
  mode raises one per short drawer.
- **Draft only**: nothing posts to the GL or the cash-bank ledger until finance posts the voucher
  in Finance → Expenses. Over/exact counts raise nothing.

## GO-LIVE configuration (2026-08-10 final client decision)

**Printers — BlackCopper BC97AC + XPrinter**, both using 80mm rolls / 72mm effective print width
and the safe 42-character layout (auto-cut is carried by the ESC/POS payload):
- `PRINTER-1` "BlackCopper BC97AC - Delivery Receipt + KOT" — LAN (agent prints to `ip:9100`) + USB (POS "print here" preview).
  Default printer; prints the **receipt/bill** and the **KOT for every category**.
- `PRINTER-2` "XPrinter - Beverages / Desserts / Extras KOT" — network only; KOT for
  **Beverages, Desserts, Extras** (including their child categories).
- Seeded IPs are placeholders (`192.168.1.50` / `.51`) — set the real ones on site.
- **No reminder printer** for this restaurant; earlier trial printers are retired (inactive, routes removed).

**Terminals** — `Delivery`, `Takeaway`, `Dine In`, all active, **auto KOT + auto receipt ON**.
Only the Delivery terminal is bound to a printer today (receipt + default KOT → PRINTER-1).

**Shifts** — the seeder opens none; the owner opens the shift on site.

**Delivery counter user** — `delivery_kb@bingoopos.com`, role `Delivery` (134 permissions):
- POS end-to-end (customers + address book, delivery charge, hold/recall, bill preview, pay, print),
  shifts open/close, held sales, sales orders/returns/ledger, restaurant floors/tables/split bill,
  customers, delivery channels, payment methods, catalog **view/add/edit**.
- **Zero delete permissions anywhere**; branches/terminals/users/roles/billing/settings/finance/
  stock/purchasing/departments/system-reset all denied.
- **Data lock (`UserDataScope`)**: Sales Orders, Sales Ledger and the whole Report Center are forced
  to **his terminal + delivery order type** — enforced in the query and on single-record access, so a
  hand-edited URL cannot widen it. Owner/manager stay unscoped.
- **Report Center**: both A4 and Thermal print; sections allowed = Categories, Items, Waiters,
  Order Types, By Order Type, Cancellations. **Overview (overall restaurant sales), Details and
  Cash & Bank are denied** — the KPI cards disappear with the Overview section.

### Print fixes from the live counter (2026-08-11)

- **One KOT per category.** KOT routes group by printer **and category**, and the print job's
  logical key carries the category — without that, two categories on the SAME printer deduped into
  one ticket and the second category's lines were lost. Each ticket names its station,
  parent-qualified ("Beef Khatri Biryani / Non-Saada") since both biryani parents have a
  "Non-Saada" child. All tickets of a round share one KOT sequence number.
- **Layout preview 500 fixed** — the preview builder didn't carry `show_bingoo_branding` into its
  temporary layout object; added, and the template made defensive.
- **Piece units suppressed** ("2 EA" → "2") on KOT/receipt/reminder, printed and on-screen.
  Real measures (KG/LTR) still print.
- **Delivery charge is visible** on the POS payment screen, the bill preview and the printed bill.
- **Customer name + phone print on the bill**, ON by default for receipts (migration for existing
  tenants + provisioner default), including typed walk-in details.
- **Bill preview = the real bill.** The POS "Bill / Preview" was hand-built HTML in JavaScript and
  drifted from the receipt. It now POSTs the cart to `/api/pos/bill-preview`, which builds a
  TRANSIENT (never saved) sale and renders the SAME receipt template with the SAME saved layout,
  headed "BILL PREVIEW / NOT A TAX RECEIPT".

### Thermal printer profile and report polish (2026-08-11, `7138700`)

- Production deployed commit `7138700` on `feat/14d-2-plan-upgrade-requests`.
- Live printer profiles now identify the installed hardware:
  - `PRINTER-1` / id `5`: **BlackCopper BC97AC - Delivery Receipt + KOT**, `80mm`, 42 characters,
    `192.168.100.206:9100`, default role `both`.
  - `PRINTER-2` / id `6`: **XPrinter - Beverages / Desserts / Extras KOT**, `80mm`, 42 characters,
    `192.168.100.69:9100`, role `kot`.
- The live metadata update changed only the two printer names. IDs, onsite IPs, ports, routing
  roles, defaults, active flags, paper settings, and transaction tables were preserved.
- Print Agent `KHATRI BIRYANI DELIVERY COUNTER-2` was online at verification time with a current
  heartbeat, no error, zero queued jobs, and zero failed jobs.
- Thermal Sales Report cancellations now use a matching four-column Item/Reason/Events/-Qty
  layout instead of the unrelated Sold/Return/Net heading.
- Daily Closings is explicitly labelled as a frozen drawer snapshot: a later return belongs to
  Sales Report Centre on its return date and does not rewrite the historical counted drawer.
- Verification: fast suite `161` tests / `31,463` assertions; MySQL Sales Report suite `5` tests /
  `66` assertions; Blade cache and both tenant `/up` + `/login` checks passed.
- Safety: no Khatri onboarding command, tenant reset, transaction reset, or sales/payment/return/
  stock/journal mutation was run. The normal deploy reported no migrations to apply.

### Printer layout and report-scope hardening (2026-08-11)

- Claude's `847f4fa` connects the saved KOT/reminder font setting to raw ESC/POS output. The
  configured size selects normal, tall, double, or triple text; long kitchen text wraps at the
  corresponding 42/21/14-character budget and every scaled block resets to normal afterward.
- The browser KOT/reminder previews use the same character budget. Normal seeded values (receipt
  12, KOT 14) remain normal-size, so existing tenants do not change until a layout value is raised.
- Both live Khatri printers remain `80mm` / 42 characters. This is conservative for the
  BlackCopper BC97AC's 72mm effective print width and is also used by the XPrinter route.
- Print Agent 2.3 hardens Claude's keep-awake work: heartbeat now replaces stale printer
  destinations, and keep-awake probes cannot overlap each other or a real print poll. The rebuilt,
  version-stamped installer is served uncached; installing it is an explicit onsite action.
- Report Centre first load selects an assigned user's valid default branch and terminal. An
  explicit All selection still means only that user's assigned scope, and hand-edited IDs cannot
  widen it. Terminal options follow the selected/allowed branch set.
- The legacy Sales Summary return deduction now follows the selected terminal, order type, and
  cashier, matching the sale side of the same filter instead of subtracting unrelated returns.
- Return posting locks the order and its lines before calculating refundable quantity, preventing
  two simultaneous submissions from returning the same quantity. Order return status is based on
  customer-facing lines, not internal component/modifier rows.
- Verification: 29 focused unit tests / 100 assertions, 10 MySQL integration tests / 51
  assertions, PHP syntax, Blade cache, KOT/addition/cancel/reminder payloads, printer configuration,
  and installer version checks passed. No live Khatri transactions were read or changed.
