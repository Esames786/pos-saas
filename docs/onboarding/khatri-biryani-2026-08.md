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
- **REPORT EMAIL = NOT CONFIGURED** (Email Now/Schedules give the controlled error until owner_email is set)
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
