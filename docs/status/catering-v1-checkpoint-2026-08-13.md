# Catering & Events V1 — Implementation Checkpoint (2026-08-13)

> **CATERING-GO-LIVE-READINESS-1 addendum (2026-08-14).** Finance + inventory +
> release integration closed on top of the frozen contract
> (design: `docs/audits/catering-finance-design-2026-08-14.md`):
>
> - **Platform test isolation**: Edge commit `56c06fa` cherry-picked verbatim
>   (`5e347df`); this worktree runs `EDGE_TEST_LOCAL_DB=pos_test_edge_local_cat`.
>   Proven twice: the complete 340-test suite green WHILE a concurrent 29-test
>   Edge-local trio ran on `_b` databases on the same server.
> - **Hotfix history**: finance-statement fix (`8d87db9`≡`247adb6`) and POS
>   reprint fix (`c6b519e`≡`cabc0a4`) are byte-equivalent by stable patch-id —
>   one copy each in this lineage, nothing replayed.
> - **Rollout gate (§3)**: `MasterSeeder::ROLLOUT_GATED_MODULE_KEYS=['catering']` —
>   deploying code registers the module but enables it on NO plan (enterprise
>   all-modules pull excludes gated keys; plan sync skips them both directions so
>   explicit client grants survive redeploys). Regression-tested on the real
>   deploy surface.
> - **Finance (§4–§6)**: JournalPostingService translators (advance Cr 2300,
>   settlement Cr 1300, invoice Dr 1300/Cr 4160+4130+2200 (+4200 contra),
>   advance application Dr 2300/Cr 1300, COGS Dr 5200/Cr 1400) — account-code
>   lookups only, balanced, replay-idempotent, conflicting-replay refusal,
>   Branch-Server fenced. New 4160 Catering Revenue in the idempotent CoA
>   seeder. Advances are ONE atomic operation (row + cash/bank + GL);
>   invoices post inside the issue transaction with write-once GL linkage.
> - **Stock (§7–§9)**: explicit separately-permissioned "Issue Materials" —
>   immutable `catering_material_issues(+lines)`, one per release
>   (retry-idempotent), movement ONLY via `InventoryService::postOutFefo`
>   (recipe_consumption + catering_material_issue reference, branch
>   allow-negative policy, split-brain fence inherited); COGS at ACTUAL FEFO
>   layer cost — the Material Rate Book stays quoting-only. Release/print
>   remain a pure plan; UX separates Planned/On-Hand/Shortfall/Issued/Remaining.
> - **Demo acceptance**: EV-…-0003 walked the full loop — quote cost 67,200
>   (rate book) vs FEFO COGS 57,975 (separation proven), 5 balanced journals,
>   AR & Advances net zero, cash 78,400, stock 100→38.5 / 100→40 KG, event
>   closed, zero sales_orders from Catering, ordinary POS sale still green.
> - **Gates**: complete MySQL 340/2,074 OK (no exclusions, concurrent Edge trio
>   green), fast 186 OK, caches compile, diff clean. NOT deployed.

> **CATERING-V1-CLOSURE-1 addendum (same day).** Client-readiness closure on top
> of the frozen V1 architecture — nothing below was redesigned:
>
> - **Costing readiness fails closed (§2).** `CateringRecipeCostingService::readiness()`
>   verdicts every estimate; SEND/CONFIRM refuse on missing conversions, invalid
>   yields, unpriceable ingredients, or (preferred contract) any material without
>   an effective Catering Material Rate. `default_purchase_price` is a labelled
>   DRAFT-ONLY fallback, never the commercial rate. Free-text lines = visible
>   soft warnings.
> - **Agreed visibility (§3).** Rate Impact shows a read-only Agreed/Confirmed
>   group (old cost vs new cost, margin delta, date, customer, version) with
>   Keep-Agreed-Price (no mutation) or Create-Revision; automatic repricing
>   stays draft-only.
> - **Advance contract (§4).** No customer-credit authority in V1 ⇒ cumulative
>   advances may reach but never exceed the outstanding balance; refused at the
>   model layer; unpriced estimates take no advances.
> - **Final invoice + closure (§5).** `catering_final_invoices` — immutable
>   snapshot (unique per event, `CI-Ymd-####`), A4 EN/UR/bilingual document,
>   idempotent email; event completes on issue and closes only at zero balance.
>   **FINANCE STOP:** GL posting for advances/settlement needs a customer-advance
>   liability + catering revenue account design via JournalPostingService
>   translator methods — deliberately not built (no journal writes anywhere).
> - **Production printing (§6/§7).** `CateringProductionPrintService` queues one
>   idempotent job per mapped printer through the existing print_jobs/LAN-agent
>   transport as document type `catering_production` (never a POS KOT); durable
>   logical keys, controlled reprint copies, price-free frozen payloads; POS
>   routing untouched byte-for-byte. English thermal only — Urdu thermal is NOT
>   claimed (raster pipeline + physical certification pending); Urdu remains on
>   A4.
> - **Multi-tenant reminders (§8)** proven across two real tenant DBs
>   (entitlement-gated, isolated, deactivate-in-finally, idempotent rerun).
> - **Friendly permissions (§9).** `PermissionCatalogService::CATERING_FEATURES`
>   groups the 36 route permissions into 11 business actions — presentation
>   only; enforcement unchanged.
> - **Platform non-regression (§10)** proven: with zero catering rows,
>   Product/Customer/Supplier CRUD, inventory posting (FEFO + moving average),
>   paid-sale GL posting, and POS KOT routing behave identically.
> - Demo tenant closed the full loop: EV-20260813-0001 → CI-20260813-0001
>   (68,500; advance 30,000; settlement 38,500; closed), 2 station print jobs
>   queued idempotently, overpayment refused live.

Branch `feat/catering-events-v1` (BINGOO-CATERING-PREFLIGHT-1 + slices 1–3).
Architecture record: `docs/audits/catering-preflight-2026-08-13.md`. NOT deployed.

## What shipped

**Entitlement.** New `catering` module (master migration `2026_08_13_000001` +
`MasterSeeder` + central admin summary). Routes `tenant.catering.*` (31) live inside the
`tenant.subscription.access` + `route.permission` + `prevent.demo.mutation` stack —
non-entitled tenants fail closed (403 module-disabled), sidebar section gated by
`$hasModule('catering')` + `@can`. Permissions seeded to the Owner role by tenant
migration `2026_08_13_100003` (permission name == route name).

**Domain (all ULID-carrying, never `sales_orders`).** `catering_events` →
`catering_estimates` (versioned, `unique(event, version_no)`) → `catering_estimate_lines`
(name/unit snapshots, en+ur). Status machines: event
`inquiry→draft→quoted→confirmed→production_ready→released→completed/closed/cancelled`;
estimate `draft→sent→accepted/superseded/cancelled`. Sent+ estimates are commercially
immutable — model-layer guards throw on any commercial column/line mutation; repricing
clones to version N+1 (`CateringEstimateService::revise`). Event/release numbers use the
locked `lockForUpdate` date-sequence pattern (`EV-YYYYMMDD-0001`, `PR-YYYYMMDD-0001`).

**Localization.** Reuses the platform translation-table architecture:
`product_translations` (existing) + new `customer_translations`/`supplier_translations`.
English is always the fallback; Urdu values optional and hand-entered.
`SetLocale`/locale-switch whitelists gained `ur`; layouts treat `ur` as RTL.

**Catering product profiles.** `catering_product_profiles` (1:1 with products —
zero columns added to the shared Product): pricing mode, default rate, quote unit,
production station, min qty, production labels (en/ur), instructions.

**Costing (pure).** `CateringRecipeCostingService`: ingredient qty × (quote qty ÷ recipe
yield) with `UnitConversionService`, priced from the effective-dated
`catering_material_rates` Rate Book (fallback `default_purchase_price`). Missing
conversions become warnings, never silent numbers. Zero inventory/GL interaction, ever.
Snapshots (`catering_cost_snapshots`) freeze the basis per estimate version; drafts show
estimated cost + margin (internal only — never printed).

**Rate Impact Center.** On a material rate change: affected DRAFT quotations with
old/new cost, Δ, and margin; actions new-only / update-selected / update-all / skip.
Sent+ documents never appear and can never be repriced.

**Production.** `CateringProductionRelease` (+lines): immutable snapshot document —
NOT a POS KOT (no `kot_batches`, no `print_jobs`, no stock, no prices). Uses production
labels/stations from profiles and embeds consolidated raw-material requirements
(`CateringRequirementService` — shared Raw Chicken across recipes = one line, read-only
compare vs `stock_balances`).

**Documents (A4/browser).** Client estimate + kitchen/service sheet as standalone Blade
+ `window.print()` (the platform's A4 architecture), each in EN / UR / bilingual with
`lang="ur" dir="rtl"` + Urdu font stack (Jameel Noori Nastaleeq / Urdu Typesetting /
Noto Nastaliq Urdu). Kitchen sheet carries no commercial prices. Thermal Urdu is NOT
claimed — the ESC/POS path has no codepage/raster support (future raster pipeline +
physical printer proof required).

**Email (V1 = email only).** `CateringCustomerMail` (quotation sent/revised, booking
confirmed, advance received, event reminder) branded with the tenant business name;
`CateringMailService` claim-before-send idempotency on `catering_email_logs`
(report-schedule pattern). No hardcoded recipients; missing customer email skips
gracefully.

**Reminders.** `catering:dispatch-event-reminders` every 15 min (Cloud-safe gate,
`withoutOverlapping`), per-tenant loop gated on entitlement + schema; D-7/D-3/D-1/
same-day offsets configurable in `catering_settings`; idempotent claims in
`catering_event_reminders`. Events dashboard buckets: Today / Tomorrow / Next-7 /
Unconfirmed.

**Printer routing.** `catering_printer_mappings` (branch/category/station → printer) as
an independent authority + one-way “Copy From POS KOT Mappings”
(`CateringPrinterRoutingService`); POS `category_printer_mappings` is never written.

**Advances.** Operational records only (event balance display + email); HARD zero
GL/cash-bank/shift in V1 — future posting goes through `JournalPostingService`.

## Finance/inventory safety (verified by tests)

Quote lifecycle, costing, repricing, advances, and production release write ZERO rows to
`stock_ledgers`, `stock_balances`, `journal_entries`, `journal_lines`, `sales_orders`,
`kot_batches`, `print_jobs`, `cash_bank_account_transactions`. No Catering code imports
`InventoryService` mutators, `JournalService`, or the settlement services.

## Tests

`tests/MySql/Catering{EstimateLifecycle,Costing,Entitlement}MySqlTest.php` — 19 tests /
117 assertions: entitlement fail-closed + pass, permissions exist, zero-mutation
invariants, sent immutability (estimate + lines + snapshot), revision cloning, unit
conversion + GM→KG pricing, rate-book effective dating, purchase-price fallback,
missing-conversion warning, selective draft-only repricing, consolidation, advance
finance-zero, release immutability, email + reminder idempotency, English fallback /
Urdu optional. Fast suite: 186 tests green.

## Local demo

Tenant `cateringdemo` (DB `pos_tenant_cateringdemo`, enterprise plan,
`cateringdemo.pos-saas.test`, owner `owner@cateringdemo.test` / `CateringDemo123!`).
Walima demo: EV-20260813-0001, 300 PAX, Biryani 100 KG + Qorma 50 KG + naan counter;
grand 68,500; material cost 40,800 @ chicken 720/KG → 44,000 @ 800/KG (Rate Impact
shows +3,200 / margin change); advance 100,000; release PR-20260813-0001 with ONE
consolidated Raw Chicken 40 KG line; quotation/confirmation/advance emails in
`storage/logs/laravel.log` (log mailer).

## Edge-ready classification (no offline implementation)

CONFIG-REPLICATED: profiles, translations, material rates, printer mappings, settings.
OFFLINE-TRANSACTIONAL (future generic Edge sync): events, estimates+lines, advances,
production releases, final invoice. All carry ULIDs from day one.

## Deferred (deliberate)

Final invoice/closure + advance GL posting (translator methods on
`JournalPostingService`), thermal/station printing of catering documents (needs the
raster Urdu pipeline), WhatsApp/SMS, purchasing automation from requirements,
amount-in-words on the estimate (no repo helper exists), public signed customer links
(no platform precedent), Edge offline execution.
