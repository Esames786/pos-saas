# BINGOO-CATERING-PREFLIGHT-1 — Architecture Preflight (2026-08-13)

Preflight for the **Bingoo Catering & Events** Cloud-first vertical on branch
`feat/catering-events-v1` (checkpoint `8799749`, application ancestor `c4fc021`).
Grounded by direct inspection of the platform. Every decision below names the
authority it reuses or the reason it builds separately.

## 1. Ground truth (what exists today)

| Area | Authority | Facts that shape Catering |
|---|---|---|
| Products | `app/Models/Tenant/Product.php`, `products` | int PK, no UUID; `product_translations` (`product_id`, `language_code`, `name`, `description`) already exists; `activeRecipe()` = `is_active` + latest |
| Categories | `categories` + `category_translations` | same translation pattern; adjacency-list tree |
| Units | `units` + `unit_conversions` | conversion is pairwise via `App\Services\Kitchen\UnitConversionService::convert()` (single-hop, throws on missing pair); `base_factor` exists but is dead for math |
| Recipes | `recipes` (`yield_quantity`, `yield_unit_id`), `recipe_ingredients` (`quantity`, `unit_id`, `line_section`, `applicable_order_types`) | requirement math = `ingredient.qty × (outputQty / yield_qty)` + unit conversion — currently duplicated in `RecipeConsumptionService`, `EdgeOperationalStockService::consumeRecipe`, `POSController::recipeAvailability` |
| Inventory | `App\Services\Inventory\InventoryService` | THE only sanctioned stock mutator (`postIn`/`postOutFefo`/`transfer`); fenced by `BranchOperatingModeService`; costs live in `stock_balances.average_cost` + `inventory_batches.unit_cost` |
| Finance | `JournalPostingService` (only approved GL entry point) over `JournalService` | idempotent per `(source_type, source_id)`; business code never writes journal tables or calls `JournalService::post` directly |
| Payments | `sale_payments`, `payment_methods` (+`cash_bank_account_id`), `customer_payments` | shifts tie via `sales_orders.shift_id`; settlement contract `SaleOperationalSettlementService::settle` is NOT re-entrant — Catering never touches it |
| Sales | `sales_orders` status enum `draft/held/paid/cancelled/…` | NO quotation concept exists (`/quotations` is a Coming-Soon stub) — extending the enum would ripple through ~15 status-filtered queries. Catering gets its own tables |
| Customers | `customers` (`customer_uuid` canonical ULID) | no translations table, has `customer_addresses` |
| Suppliers | `suppliers` (SoftDeletes, no UUID) | no translations table |
| Printing | `PrintJobService` → `print_jobs` (frozen `raw_payload` at queue time) → polling LAN agent → raw TCP 9100 | KOT routing = `category_printer_mappings` resolved by `PrintRoutingService`; NO codepage / raster / Unicode support in the ESC/POS path; NO PDF library — “A4” = standalone Blade + `window.print()` |
| Modules | master `modules.route_module_keys` JSON + `plan_modules` | gate = `EnsureTenantSubscriptionAccess` → `TenantSubscriptionAccessService::check`; fail-open ONLY for unmapped route keys, so registering `tenant.catering` in `modules` makes it fail closed; sidebar gate = `$hasModule('catering')` in `partials/sidebar.blade.php` |
| Permissions | spatie, permission name == route name, enforced by `EnsureRoutePermission` | registered via `route_catalogs` publish + `PermissionSyncService`; role UI groups via `PermissionCatalogService` (entitlement-aware) |
| Mail | `app/Mail/*` synchronous `Mail::to(...)->send()` | branding = `app('tenant')->business_name` scalar; idempotency archetype = `report_schedule_runs` claim-before-send (`insertOrIgnore` on a unique key); NO signed-URL precedent exists |
| Scheduler | `routes/console.php`, `EdgeRuntime::isCloudSafe()`-gated | archetype = `DispatchScheduledReportsCommand` (per-tenant loop, `hasTable` guard, every 15 min, `withoutOverlapping`) |
| i18n | `resources/lang/en/*` only; `SetLocale` whitelists `en,ar` while `config/saas.php` says `en,ur`; layouts set `dir=rtl` for `ar` only | per-entity translation tables are the working localization architecture |
| Identity | `App\Models\Concerns\HasCanonicalIdentity` (standalone trait; ULID default) | 3-stage migration template `2026_08_08_000010_add_canonical_cross_system_identities.php`; `EdgeIdentity::REGISTRY` is Edge-sync scope and is NOT modified by Catering |
| Numbering | best pattern = `JournalService::nextEntryNo()` (`JE-Ymd-0001`, `lockForUpdate`) | `GeneratesSequentialCode` trait is unlocked (race-prone); timestamp+random style unsuitable for customer-facing numbers |
| Tests | `tests/MySql/MySqlTenantTestCase` + `TenantFixtures` (no factories) | isolated DBs `pos_test_master_cat` / `pos_test_tenant_cat` via untracked `./test-mysql.sh` |

## 2. Safe reuse seams (decisions)

1. **Module entitlement** — new `catering` module key, `route_module_keys: ['tenant.catering']`,
   registered in `MasterSeeder` (fresh installs) + a master data migration (existing installs,
   pattern `2026_08_10_000001_add_ajax_customers_to_pos_module_keys.php`). All Catering routes
   named `tenant.catering.*` inside the `['tenant.subscription.access','route.permission','prevent.demo.mutation']`
   group → direct URLs fail closed for non-entitled tenants. Sidebar wrapped in
   `@if($hasModule('catering'))` + `@can`. Not attached to existing commercial plans by default
   (same rollout posture as `offline_edge`); enabled per-plan via central admin / demo plan.
2. **Localization** — reuse the existing per-entity translation architecture:
   `product_translations` / `category_translations` as-is for Urdu display names, and add
   `customer_translations` + `supplier_translations` mirroring the exact shape
   (`{entity_id, language_code}` unique). No columns added to stable tables. A read helper
   (`CateringLocalizationService`) resolves `name(locale)` with English/default fallback.
   Urdu values are optional, hand-entered — never machine-translated.
   Locale plumbing: add `ur` to the `SetLocale`/`AuthController` whitelist (config already
   declares `en,ur`) and extend the layout RTL condition to `ar|ur`. UI chrome stays English;
   this only unlocks entity values + print profiles.
3. **Catering product profile** — new `catering_product_profiles` (unique `product_id`,
   cascade), NOT columns on `products`: `profile_uuid` ULID, `catering_enabled`,
   `default_quote_unit_id`, `pricing_mode` (`per_pax|fixed`), `default_catering_rate`,
   `production_station`, `minimum_qty`, `production_label` / `production_label_ur`
   (catering production labels are catering metadata, not catalog truth), `instructions`.
4. **Separate Catering domain tables** (all with ULID canonical identities via
   `HasCanonicalIdentity`; `sales_orders` is never touched):
   - `catering_events` — customer + venue + PAX + dates + status machine
     `inquiry → draft → quoted → confirmed → production_ready → released → completed → closed / cancelled`
   - `catering_estimates` — versioned commercial documents, `unique(catering_event_id, version_no)`,
     status `draft → sent → accepted / superseded / cancelled`; sent+ are IMMUTABLE
     (model-level guard throws on commercial mutation; repricing creates revision v2)
   - `catering_estimate_lines` — product ref + name snapshots (en/ur) + qty/unit/rate/amount +
     instructions + estimated cost fields
   - `catering_material_rates` — the Material Rate Book: `(product_id, rate, per unit_id,
     effective_from)`, versioned/effective-dated; NEVER writes `average_cost`, batch costs,
     or POS selling prices
   - `catering_cost_snapshots` — per-estimate frozen costing breakdown (JSON) with computed_at
   - `catering_advances` — operational advance records (V1: zero GL; future finance posting
     will reuse `JournalPostingService` via a translator method)
   - `catering_production_releases` + `_lines` — immutable production snapshot document
     (own doc type; NOT a POS KOT; no `kot_batches` involvement; no prices on the document)
   - `catering_event_reminders` — D-7/D-3/D-1/same-day schedule rows with claim-before-send
   - `catering_email_logs` — unique `(catering_event_id, email_type, dedupe_key)` idempotency claims
   - `catering_printer_mappings` — independent routing authority mirroring
     `category_printer_mappings` shape + “Copy from POS KOT mappings” convenience
     (reads POS rows, inserts catering rows; POS table untouched thereafter)
   - `catering_settings` — per-tenant singleton (reminder recipient, service-charge default,
     print language profile `en|ur|both`)
5. **Pure recipe costing** — new `App\Services\Catering\CateringRecipeCostingService`:
   requirement = `ingredient.qty × (quoteQty / recipe.yield_qty)` converted via the existing
   `UnitConversionService`, priced from the Material Rate Book effective at pricing date
   (fallback: `products.default_purchase_price`). Read-only by construction: it never touches
   `InventoryService`, stock tables, GL, shifts, or KOT. (Extracting a shared pure primitive
   out of `RecipeConsumptionService` is deliberately deferred — refactoring a shared platform
   authority is platform work, not Catering work; the catering service is new code with the
   same math and unit-conversion authority.)
6. **Consolidated requirements** — `CateringRequirementService` recipe-explodes an event and
   consolidates by ingredient product in the ingredient product's stock unit (one Raw Chicken
   line across Biryani/Qorma/Karahi), compared read-only against `stock_balances`. No
   purchasing/stock mutation.
7. **Rate Impact Center** — on a material (or profile rate) change, scan DRAFT estimates whose
   lines' recipes consume the product; show current vs new cost + margin impact; actions:
   new-quotes-only / update selected drafts / update all drafts / skip. Sent+ documents are
   never touched (immutability guard enforces this at the model layer too).
8. **Documents** — A4 estimate + A4 kitchen/service sheet as standalone Blade documents
   (`window.print()`), the platform's existing "A4" architecture. Urdu/bilingual via
   `lang="ur" dir="rtl"` + Urdu font stack (`Jameel Noori Nastaleeq`, `Urdu Typesetting`,
   `Noto Nastaliq Urdu`). Thermal Urdu is NOT claimed: the ESC/POS path has no codepage or
   raster support; physical Urdu thermal needs a raster pipeline + real printer proof (future).
   Kitchen sheet carries zero commercial prices.
9. **Email** — reuse `Mail::to(...)->send()` + tenant `business_name` branding, `MAIL_MAILER=log`
   locally; idempotency via `catering_email_logs` claim-before-send (report-schedule pattern).
   No hardcoded recipients. No public signed customer links in V1 (no platform precedent —
   building one would be new security surface; revisit with WhatsApp/SMS work).
10. **Reminders** — `catering:dispatch-event-reminders` command scheduled in
    `routes/console.php` (Cloud-safe gate, every 15 min, `withoutOverlapping`), per-tenant
    loop with `hasTable` + entitlement guard; plus dashboard buckets (Today / Tomorrow /
    Next-7 / Unconfirmed / Advance pending / Production not released).
11. **Numbering** — `CateringNumberService` using the locked `JE-Ymd-0001` pattern:
    `EV-YYYYMMDD-####` (events), `PR-YYYYMMDD-####` (production releases); estimates display
    `{event_no} / Q{version_no}`.
12. **Edge-ready classification** (no offline implementation now; no separate sync engine):
    - CONFIG-REPLICATED: `catering_product_profiles`, `customer_translations`,
      `supplier_translations`, `catering_material_rates`, `catering_printer_mappings`,
      `catering_settings`.
    - OFFLINE-TRANSACTIONAL (later, via generic Edge Config Refresh / Compatibility / Sync):
      `catering_events`, `catering_estimates(+lines)`, `catering_advances`,
      `catering_production_releases`, final invoice.
    All rows carry ULIDs from day one so future sync reconciles by canonical identity.

## 3. Hard safety rules encoded

- Draft/quote/estimate/repricing: **zero** stock, GL, shift, payment, KOT mutation.
  Enforced structurally (no Catering code path imports `InventoryService` mutators,
  `JournalService`, or `SaleOperationalSettlementService`) and proven by MySQL tests
  asserting empty `stock_ledgers` / `journal_entries` / `sales_orders` deltas.
- Catering-disabled tenant: no sidebar, no routes (403 module-disabled), no permissions in
  the role editor (`PermissionCatalogService` entitlement-aware build), POS byte-identical.
- POS KOT mappings never mutated by Catering routing (copy is one-way INSERT).
- Sent/accepted/confirmed estimates never silently repriced (model guard + revision flow).
- No `sales_orders` rows for catering quotes/events, ever.

## 4. Slice plan

- SLICE 1 — module/entitlement/permissions, translations tables, locale `ur`, product
  profiles, events + estimates + lines + numbering + status machine, event/estimate UI.
- SLICE 2 — Material Rate Book, pure recipe costing + cost snapshots, Rate Impact Center,
  draft selective repricing, quote revisioning (v2), immutability guards.
- SLICE 3 — A4 estimate + kitchen sheet (en/ur/bilingual), email events + idempotency,
  reminders + dashboard, production release document, catering printer routing + copy-from-POS.

Tests ride inside each slice; MySQL gates run via `./test-mysql.sh` before each checkpoint.
No production deploy from this branch until review.
