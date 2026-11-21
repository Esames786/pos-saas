# Work Log — All Projects and Parallel Sessions

**7 August – 7 September 2026** · compiled 7 Sep 2026 from git history + session memory of every worktree/project on this machine.

| | |
|---|---|
| **Primary product** | Bingoo POS SaaS (`pos-saas`, repo `Esames786/pos-saas`) — 4 live restaurant tenants |
| **Canonical/prod branch** | `feat/14d-2-plan-upgrade-requests` — **462 commits** dated in this window (incl. merges); HEAD `21f734f` (7 Sep) |
| **Offline Edge branch** | `feat/edge-config-refresh-v1` — **53 Edge-only commits**; HEAD `ae7e6ad` (pushed); +15,759 / −52 lines over canonical in 132 files |
| **Other pos-saas sessions** | Catering (Kashif Kitchen), Cloud Billing, Tawakal + The Kashif Foods onboarding |
| **Other projects touched** | DMS (`dms_main`, 55 commits), LMS backend/frontend (16), Seera ERP (12), Washinton / Hello Transport / CrazyRays (rounds 3, 5–9), SLGTrax (2), Shopify Sonic (1), central-gateway (1), plumber_repo (1) |

> Finer-grained logs already in the repo (canonical branch): `docs/status/work-log-month-2026-08-05-to-09-05.md`, `work-log-2026-08-05-to-09-05.md` (by worktree), `work-log-2026-08-25-to-09-01.md`, `work-log-2026-08-11-to-08-24.md`, and (Edge branch) `docs/status/edge-work-summary-2026-08-09-to-23.md`. This document is the cross-project consolidation; it adds 5–7 September and the non-POS projects.

---

## 0. Executive summary

**Bingoo POS (pos-saas)**

1. **Four tenants live and trading.** Khatri Biryani (live 11 Aug, ~6,150 orders by 5 Sep), Kashif Food (live 24 Aug, ~2,309 orders), Kashif Kitchen (catering; production data reset + go-live prep 5 Sep), Tawakal + The Kashif Foods (live 6 Sep — the **first two-branch, two-domain tenant**).
2. **Catering V1 shipped** — bookings, quotations with cost blocks, production releases, kitchen sheets, store issues, advances/refunds/final invoices, keyboard "punch" screen modelled on the client's 20-year-old software, client catalogue rebuilt from their own database (909 items, original IDs).
3. **Reporting stopped lying** — totals reconcile to NET SALES; returns dated by business day; deal components no longer counted as sales; deals report under their own name/head; six legacy reports rejoined the population; open bills now visible on the Quick Report; Quick Report scoped to the operator's branch.
4. **Printing became predictable** — 72 mm thermal fit, three-level hierarchy on the roll and the preview, deal identity on KOT, KOT reprint can no longer be blank, per-printer isolation, printer health (ping/reset/reboot), print-here local fallback, cancellation prints at the cancelling counter.
5. **Shifts, cash and time** — shift follows the drawer, business date follows the order; KOT/receipt/GL now carry the order's time, not the print time; dashboard and shift list "today" = the open shift's business date; cash/card/bank/cancellation breakups; blind count (`*****`) for operators.
6. **Access control** — permission editor, per-branch manager PIN for cancellation and returns, terminal-pinned cashiers, Complete Sale split from discount permission, report access removed from operator roles.
7. **Platform self-care** — per-tenant scheduled backups, scheduled owner reports, collision-safe print-job numbering, full-schema reset guard.
8. **Offline Edge** — went from a frozen dormant runtime to a **complete offline sale loop** (immutable outbox → authenticated sync → Cloud exactly-once ingestion → verified ACK → reconciliation → baseline cutover), plus productization (encrypted backup/restore, restricted artifact, Windows supervision, signed updater), reservation authority, two clean reconciliations against the live POS, and **milestone 1 of the real browser cashier POS** (route + page + real-HTTP render guard + Blade gate).
9. **Cloud billing / self-signup** built and E2E-tested (14–15 Aug) — **not deployed**, awaiting a payment account.
10. **Three production outages, all owned** (403 storm 12 Aug, POS 500 on 31 Aug, Close Branch 500 on 1 Sep) — each produced a permanent discipline rule.

**Other projects**

- **DMS** — Promotion module P1–P11 built in one day (20 Aug) replacing the discount module, five rounds of client-driven mockups, then the tentative **Orders-to-Cash Load Form & Settlement** module (5 Sep) live on growic.
- **LMS** (Django backend + frontend) — portal KPI/shipment APIs with JWT, CN shipment journey, L1/L2 import with ledger sign conversion, TO-7522 type-8 financing realise/settlement + B20 tiers.
- **Seera Construction ERP** — Phases 3–7 foundations (HR/Payroll, Accounting, Projects & Site Expenses, Inventory & Warehouse, Equipment & Vehicles), org-chart login accounts, production bootstrap seeder, and the **CR-01…CR-13** client review round (7 Sep).
- **Washinton / Hello Transport / CrazyRays** — client rounds 3, 5, 6, 7, 8, 9 (7–28 Aug): payment/alt-pay fixes, HR document gates, Check Price Open/Enclosed (phase 1 + gateway phase 2), 29-blade panel-chain sweep, Access Guide page, a signup outage caused by a NUL byte, production log triage.
- **SLGTrax** (favicon + Open Graph card), **Shopify Sonic** (logging failure no longer kills bookings), **central-gateway** (pricing engine honours `requested_mode`), **plumber_repo** (bilingual EN/FR platform guide).

---

## 1. pos-saas — the parallel sessions (worktrees) and their state

All folders under `D:\laragon2\www\` are git worktrees of one repo. Removing a folder never deletes a branch or commit; only uncommitted work is at risk.

| Folder | Branch | Session purpose | State on 7 Sep |
|---|---|---|---|
| `pos-saas` | `feat/14d-2-plan-upgrade-requests` | **Production branch** — merge + deploy only | HEAD `21f734f`; last recorded prod deploy `9d5da75` (7 Sep) |
| `pos-saas-hideamounts` | `feat/items-by-category` | Day-to-day POS/report work (name is historical) | 0 ahead of prod — all merged |
| **`pos-saas-edge`** | **`feat/edge-config-refresh-v1`** | **Offline Edge / branch server** | **53 ahead**; HEAD `ae7e6ad` pushed; prod runs Cloud mode, `APP_ROLE`/`EDGE_FEATURE_ENABLED` unset |
| `pos-saas-cloud` | `feat/cloud-billing-onboarding-v1` | Cloud billing + self-signup | 8 ahead; built + tested; **not deployable without a payment account** |
| `pos-saas-catering` | `feat/catering-product-ux-v1` | Catering ongoing work | 23 ahead (Aug 17–21 costing/rate work, most already merged by content) |
| `pos-saas-tawakal` | `feat/tawakal_kashif` | Tawakal + The Kashif Foods onboarding + branch-scoped categories | 0 ahead — merged and **live 6 Sep** |
| `pos-saas-catering-codex*`, `-parity`, `-catalogue` | audit/data branches | One-off August audits / catalogue prep | Finished; safe to remove |
| ⚠️ `pos-saas-catering-codex-cert-v2` | detached HEAD | Rate-impact certification | **Uncommitted work**: `CateringFinalPermissionHttpMySqlTest`, `CateringRecertificationRedTeamMySqlTest`, `catering_rate_race_worker.php` — commit before removing |

Feature branches merged into canonical during the window (each 0 ahead now): `feat/hide-amounts`, `feat/dashboard-7day`, `feat/legacy-reports-population`, `feat/deal-heads`, `feat/rider-returns`, `feat/kot-sent-pool`, `feat/items-by-category`, `feat/tawakal_kashif`, plus the Catering fix branches (`fix/catering-client-feedback-20260827`, `-history-urdu-ux-20260829`, `-quoted-rate-persistence-20260828`).

---

## 2. pos-saas — Canonical / Production session (`feat/14d-2-plan-upgrade-requests`)

### 2.1 7–10 August — the week before Khatri's go-live

- POS bug triage from a code-grounded audit: mouse-wheel corrupting number inputs, split-bill quantity cap, Table Board "Close (Paid)" made an action, held-order quantities, receipt reprint persistence.
- Print routing: "All categories" wildcard, reminder mappings restricted to reminder-capable printers, seeded default layouts, null-safe live preview.
- **Shift / timezone / business date** in fourteen parts: `TenantClock` as the single authority; a shift freezes business date + timezone at open; every POS operation requires an open shift; reports key on business date; live server clock + business-date badge. Business timezone (branch) separated from display timezone (user).
- Reports/permissions/money: shared sales report engine with reconciliation proof; Report Center with exports/print/Email Now/schedules; permission editor in business language; customer-facing delivery charge through the whole money path.
- Khatri onboarding: tenant contract, custom plan, menu seed, network KOT printers with order-type-aware routing, named terminals, delivery counter user. `deploy/handover-2026-08-08` — third-party department handover of sales to its owner.

### 2.2 11–24 August — live trading and a caterer's whole business

- **11 Aug go-live day (43 commits):** thermal reports fit 72 mm and read as columns; every total reconciles to NET SALES; printers kept awake; print jobs no longer lost to a busy printer; POS helper-scope bug (two script blocks) fixed; sales-return accounting prorates discount/tax; customer mandatory on delivery; manual discounts with per-branch approval.
- **12 Aug — the 403 storm (`e17d6b5`)**: Spatie permission cache on the shared `database` store produced a wrong-guard Gate across every tenant. Moved to per-request `array`; bare `permission:cache-reset` forbidden forever. Also `db9194f` tenant identification before session (closing `/login` 500s), `267d512` `markPrinted()` separated from `cancelObsolete()`, `b795405` shift open/close limited to the cashier's own terminal, `6192092` dine-in counter gets its own role.
- **13 Aug — platform checkpoint `8799749`** recorded before the Edge / Catering workstream split; `c4fc021` EDGE-SPLITBRAIN-STOCK-1 fences official stock authority during Local Mode (this became production `c4fc021`).
- **13–14 Aug — Catering V1 closure and go-live readiness** (`0e85e02` → `482e208`): costing readiness fails closed on send/confirm; agreed-event visibility, advance contract, final invoice + closure; production releases ride the PrintJob transport; multi-tenant reminder proof; rollout gate "deploying code never broadens access"; release notes with migration review/rollback/deploy procedure (`release/catering-go-live-1`, `-2`).
- **13–19 Aug — catering foundations:** booking statements + settlement, audited refunds, customer credit + financial position, named cost blocks, recipe-or-blocks costing, commercial material rates + rate impact, quoted-rate override with Cost Details, customer-supplied materials, reset that knows catering documents. `1d66ab0` one collision-safe authority for print job numbers. `52b5c85` supplier opening balance posted to GL (Dr 3300 / Cr 2100).
- **18–21 Aug — printing overhaul + report truth:** terminal-keyed KOT routing; preview/print parity; Report Center Print/Z/Export no longer narrowing to one terminal; Sales Report streamed to thermal through the agent. Category/department order counts made DISTINCT; filters honoured in order-level sections; returns/item-voids keyed on business day (`dcd1ae4`); delivery-charge bridge closes BY ORDER TYPE and global sections to NET SALES (`719b4bb`, `24f6c29`).
- **20–22 Aug — catering serialisation + operator workspace:** every draft mutation, rate impact and costing basis serialised with send (draft-writer send races exposed by `55ad8d5`); operations driven from quotation snapshots; store issues reconciled without inventing allocations; calendar dashboard + event search; Making adjustments; in-workspace create/edit; client's 888-item menu imported without inventing commercial truth; 55-label kitchen vocabulary.
- **22 Aug — POS-DRAFT-1** (`0b5df5a` + UI): park a held sale as a DRAFT (saved like Hold, KOT not sent), DRAFT badge in recall list.
- **22–24 Aug — KASHIF-ORDER-PUNCH** (14 commits `193c012` → `ffd8f7f`): the client's keyboard flow on the existing event screen — type code → Qty → Party/Own → linked materials → Enter, row lands below, cursor returns. Three real bugs named honestly: a DOM sweep that deleted saved rows (`82a6359`), PARTY→OWN keeping the customer's share (`c259607`), a CSS selector eating a column (`ffd8f7f`). Auto-save removed on owner's instruction.
- **23–24 Aug — POS:** `804e7af` Print-Here local/USB fallback when network print fails; `0896a39` per-printer isolation; `e9ebe23` recall must not switch the operator's terminal; Report button in POS; Recent Prints modal enlarged; `f191680` combo shows name-only in cart/receipt/preview; `bb5964e` hidden/service combo parts must not read as out-of-stock; thermal readability (`a82bbd6`, `a32d32d`).
- **24 Aug — KASHIF-LEGACY-REBUILD-1** (`616f793`, run on prod): catalogue rebuilt from the client's own workbook — 909 items keyed on legacy `OrderItemId`, 18 categories, cost blocks derived from their rates (`OrderRate = MeatRate + ServiceRate` exact on all 909), 4,848 customers, GL/stock fingerprinted at zero movement. **KASHIF-FOOD-ONBOARD** (`b3617c8`): new restaurant tenant — 199 products, 41 combos, 4 terminals, 3-station routing. **Kashif Food live 24 Aug.**

### 2.3 25 August – 1 September

- **26 Aug:** PRINTER-HEALTH-1 (`13a741a`, `54c603f`, `89a49d4`) — browser-triggered ping/reset/reboot, versioned agent shelf 2.5.0, defer/requeue, per-printer lock, fair fetch, 90-second lease, honest reboot label. REPORT-SCHEDULE (`0b80549`) previous-day A4 PDF to multiple recipients. TENANT-AUTO-BACKUP-1 (`c49e883`) per-tenant scheduled DB backups (up to 3 PKT times, 7-day retention).
- **27 Aug:** QUICK-REPORT-SEND-1 (`2454d7c` → `6994ac7`) — POS "Quick Report" modal: email / print / network, filters cascade over the whole report, parent/child categories, saved selection. Catering client feedback fixes.
- **28 Aug:** Preview Bill inside Review & Pay (`5483d0b`) with full running bill (discount+tax+service charge+tip); Payment modal widened; TABLE-RESERVATION-1/2/2b (`b5c243a`, `d47fa80`, `3d241ce`) — reserve a table (colour, who/when/note, attach customer), on the standalone board, and carry the reserved customer onto the order on Open Table; `c2590f2` deploy.sh builds caches as www-data (fixed a root-owned compiled-view 500); print-agent delete; KOT divider full width + tighter tail feed.
- **29 Aug:** POS header — View Tables beside the title (`15afd50`), two-line product tile; Report/Return windows hide app chrome; RETURN-UX unit-aware stepper. Catering: Urdu names carried to kitchen (`a539b11`), kitchen sheet says what each dish takes (`98e2efe`), events-list Actions menu.
- **30 Aug (the terminal chain):** POS-DEFAULT-TERMINAL-1 (`7c50fe7`) cashier lands on the assigned terminal; RECALL-REPRINT-TERMINAL-1/2 (`940b1ce`, `ed977c2`) reprints/cancellations follow the current operator's terminal; POS-TERMINAL-PIN-1 (`92dd4af`) read other terminals, sell only on your own; POS-COMBO-CATEGORY-1/1b/1c (`a2d26eb`, `056e9bd`, `8e35a9d`) deal tabs, hierarchical Deals tree, child filter; COMBO-KOT-DEAL-NAME-1 (`cf096ad`); SHIFT-OPEN-UX-1 (`2bdaa94`) lock terminals with an open shift; POS-PERM Complete Sale gated on `tenant.pos.store` (`f12f1fc`); aggregator channels customer optional (`fd1b447`); Kashif Food floors/tables docs.
- **31 Aug:** POS-CANCEL-TERMINAL-1 (`22ad93e`), POS-SHIFT-ATTRIBUTION-1 (`4bdda60`) shift follows the drawer / business date follows the order, POS-30AUG-FIXES (`cba7e09`) cancel frees the table + reminders carry the right terminal + open bills survive a hidden product; **HOTFIX `1385876`** POS payload called a non-existent relation → **`202907c` a guard that actually loads the POS screen** (`PosScreenRendersHttpMySqlTest`); DASHBOARD-DETAILS-1 + DASHBOARD-OPEN-BILLS-1; REPORT-DEAL-COMPONENTS-1 (`fc5414a`) deal parts stop counting as separate sales; KOT-REPRINT-BLANK-1 (`007e07f`) reprint falls back to the stored copy; SHIFT-CANCELLATIONS-1 (`d3b1d2d`, `f7f0246`) tender breakup + cancellations on Shift Report and Close Branch.
- **1 Sep:** **HOTFIX `d746abe`** Close Branch 500 (`voided@endif`); RETURN-MANAGER-APPROVAL-1 (`613c250`) branch-level manager PIN before a return posts; TABLE-CLOSE-EMPTY-1 (`67cde05`) free a table opened by mistake, decided under a lock; REPORT-DEAL-IDENTITY-1 (`03f0d99`) a deal reports under its own name (money moved 0.00); POS-HELD-DELIVERY-META-1 rider/channel on held list; CATEGORY-BRANCH-SCOPE-1 + Tawakal/Kashif Foods onboarding command (`efe2894`); HIDE-AMOUNTS-1 (`5408ba6`) a branch can make its counter count blind.

### 2.4 2–5 September

- DASHBOARD-7DAY-POPULATION-1 (`718562b`) one day, one answer; LEGACY-REPORTS-POPULATION-1 (`d5b8a92`) six reports rejoin the population; DEAL-CATEGORY-1 (`5aab512`) a deal reports under its own head and is counted once; RIDER-RETURNS-1 (`193da7b`) delivery reports show what came back; KOT-SENT-POOL-2 (`d167164`) a second helping on a running bill reaches the kitchen.
- ITEMS-BY-CATEGORY-1 (five commits) the item report under category heads as its own section, closed with the NET SALES bridge, fenced entries, visible heads. BRIDGE-DEALS-1 (`11fbe7f`) the bridge stops calling deal money "charges" (Kashif 95,859 → real 4,369). REPORT-CATEGORY-ORDER-1 shop's own menu order. THERMAL-ITEM-LAYOUT-1/2 (`63b3e76`, `d1bd19a`) the Z report fits the paper and reads as a hierarchy on roll and preview alike. REPORT-GRAND-TOTAL-WORDING (`63dee02`) `KUL` → `GRAND TOTAL`.
- HIDE-AMOUNTS-2 (`cffbf76`) the shift page joins the blind count. CHARGE-BREAKUP-1 (`d89f32a`) every charge on the bridge names whose money it is.
- **Kashif Kitchen go-live prep** (`e8bcee1` research, `56c9838` execution): reset guard widened to the whole schema (it only saw `catering_` tables); customer phone fusion fixed at the source (165 rows with two concatenated numbers); 236 suppliers imported with zero opening balances (14 carrying ~6.49M credit withheld pending owner confirmation); production reset run — 14 tables, 190 rows, master data intact.
- Kashif Food business-date misdating: plan written 4 Sep; 3 Sep correction applied as data (63 bills + 16 cancellations + 63 sessions + shift).
- ZERO-DRAWER-1 + SHIFT-RECONCILE-1 (`27b677e`) an empty drawer closes, list shows the count; SHIFT-RECONCILE-2 (`29044da`) reconciliation in a collapsible panel; SHIFT-DATE-FILTER-1 (`e2d14bc`) date filter + Today/Yesterday.

### 2.5 5–7 September (not yet in any earlier log)

| Commit | What |
|---|---|
| `5080e4a` OPERATING-DATE-1 | Dashboard "today" = the open shift's business date |
| `5972f96` OPERATING-DATE-2 | Shifts list "Today" also on the open shift's day |
| `fad960b` KOT-TIME-TRUTH-1 | KOT prints the order's time, not the print time (every reprint used to show today's date) |
| `417436e` SALE-DATE-TRUTH-1 | Order time is not rewritten at payment |
| `6e3e67c` GL-BUSINESS-DATE-1 | The ledger sits on the same day the report sits on |
| `193b5c4` HOTFIX | Layout live preview 500 after KOT-TIME-TRUTH-1 |
| `87cd76f` / `012e8c5` | CATEGORY-BRANCH-SCOPE-1 + **Tawakal / The Kashif Foods onboarding merged → LIVE 6 Sep** |
| `56bdf13` ADDRESS-ATTACH-1 | Saving an address attaches it to the order immediately (found on Tawakal) |
| `fdad50a` → `9887b1c` → `8ced875` QUICK-REPORT-OPEN-BILLS-1 | Open (held/draft) bills on the Quick Report. Plan changed twice with the owner: first a separate EXPECTED figure, then the ESC/POS path (missed from the first commit — "guard worked, `git add` didn't"), finally **one switch**: `include_open` on `salesBase()` used only by Quick Report, so all seven line-based sections include open bills with no renderer change; Report Center's eleven sections proven byte-identical |
| `6c22fc7` QUICK-REPORT-BRANCH-SCOPE-1 | Quick Report scoped by `UserDataScope::branchIds()` + branch picker in the modal. The "deliberately unscoped" decision of 27 Aug became wrong on the first multi-branch tenant; requested foreign branches are intersected, not 422'd. Live: counter_tb 34/12,300 + counter_kf 51/43,130 = owner 85/55,430 exactly |
| `21f734f` FONT-FS-COLLISION-1 | Theme CSS redefined bootstrap's `fs-1..fs-6` as 1–6 **pixels**; Close Shift amounts unreadable. Fixed once in `a11y-custom.css` (loaded last); CSS only |

### 2.6 Outages and the rules they bought

1. **12 Aug 403 storm** → never bare `permission:cache-reset`; per-request permission cache.
2. **31 Aug POS 500** (`SalesOrderLine::salesOrder()` did not exist; 480 green tests, none loaded the POS screen) → **a guard must run the real path** (`PosScreenRendersHttpMySqlTest`).
3. **1 Sep Close Branch 500** (`voided@endif` never compiled) → **compile every Blade change and lint the generated PHP**.
4. Reset guard narrower than its contract (`catering_` filter) → a guard narrower than its contract is silent about exactly the untested thing.
5. Preview and printer are two code paths → both get their own guard (THERMAL-ITEM-LAYOUT-2, QUICK-REPORT-OPEN-BILLS-1 2/2).
6. Never run `php artisan migrate` while the MySQL suite runs (347 phantom errors once).
7. Fix the source, not the rows (Kashif phone extractor).
8. In one PHP process, switching tenants and asking `$user->can()` lies (per-request array cache pins to the first tenant) — one process per tenant.

### 2.7 Tenant by tenant

| Tenant | State | This window |
|---|---|---|
| **Khatri Biryani** (#212) | LIVE since 11 Aug | ~6,150 orders by 5 Sep. Printing overhaul, terminal-keyed KOT routing, report/finance corrections, nightly owner report 00:30 PKT (now Overview + Categories only), backups 14:30/19:30/02:30 PKT. Data-only: new categories/products (Raitas, Sauce, Boxes, drinks, Extra Boti). |
| **Kashif Food** (#348) | LIVE since 24 Aug | ~2,309 orders. 199 products, 41 combos in four deal groups, 3-station routing, return manager PIN ON (1 Sep), report access revoked from operator roles, nightly report 02:30 PKT to two recipients. Data-only: full KOT routing, Raita/BBQ Sauce/Singaporean Sauce routing, 3 Sep date correction. ⚠️ six users share one manager PIN. |
| **Kashif Kitchen** (catering) | Prepped for go-live 5 Sep | Catalogue rebuilt from the client's database; production reset run; phones repaired; 236 suppliers imported (balances withheld). Open: 61 duplicate customers (owner's call), 9 malformed phones, 14 supplier opening balances, only one user/role. |
| **Tawakal + The Kashif Foods** (`tawakalkashif`) | LIVE 6 Sep | First two-branch tenant (thekashiffoods / tawakkalbiryani subdomains). 12 categories, 74 products, 7 combos (branch 1), 0 routing rules (each counter prints its own), layouts copied from `kashiffood` minus footer, `hide_amounts_from_operators` ON both branches (needed an Owner permission grant first). Printer IPs still empty. Quick Report branch scope found and fixed here (7 Sep). |

---

## 3. pos-saas — Offline Edge session (`feat/edge-config-refresh-v1`) — for the Edge team

### 3.1 Where it stands (7 Sep)

| | |
|---|---|
| HEAD / origin | `ae7e6ad` (pushed) — "EDGE: serve the canonical cashier experience from the branch server" |
| Edge-only delta over canonical | 53 commits · 132 files · +15,759 / −52 |
| Inventory | 49 services in `app/Services/Edge`, 48 `tests/MySql/Edge*` test files + 11 `tests/Feature/Edge`, 12 Edge migrations, 8 console commands, 5 design docs + 6 status docs |
| Last full authoritative gate | after `958883a`: **1,251 tests / 5,985 assertions**, 2 red = the known Dompdf/A4-PDF library debt (byte-identical to canonical), **NEW_EDGE_REGRESSIONS = 0**. After `ae7e6ad` the focused Edge suite (cashier render, Blade gate, POS HTTP, artifact, artifact boot) is green; the full suite has not been re-run since. |
| Production | **PRODUCTION_MUTATED = no · KHATRI/KASHIF_MUTATED = no · LOCAL_MODE_ACTIVATED = no · DEPLOYED = no · activation_ready = false.** Prod runs Cloud mode; the whole Edge chain is dormant there. |
| Canonical for parity | `origin/feat/14d-2-plan-upgrade-requests` (not `main`, which is frozen at 20 Jul). Compare by content — shared fixes were often rebased under different hashes. |
| Rollback tags | `edge-pre-reconcile-8f98d06` (reconcile 1), `edge-pre-reconcile2-97612ff` (reconcile 2) |

**Locked product rule:** *current Online Bingoo POS is the functional specification for Edge.* Online defines what the operator sees and does; Edge defines how it executes safely without Internet. No "Offline Lite", no second workflow. A feature is operator parity only when the cashier can use it from the Branch Server browser. Each shared workflow ends as exactly one of `FULL_OFFLINE_PARITY` / `ONLINE_REQUIRED` / `FINANCIAL_PARITY_PENDING`. Card authorisation and email are truthful `ONLINE_REQUIRED`, never faked. Reports reuse the canonical `SalesReportEngine` — never Edge math. Parity percentages are **FROZEN** until the full browser workflow matrix is executable (the old ~90 % / ~72 % are not to be reused).

### 3.2 Timeline by commit

**13–14 Aug — config refresh + sync preflight (on top of the frozen dormant runtime)**
- `ddc2a6e` EDGE-CONFIG-REFRESH-1 + EDGE-COMPATIBILITY-CONTRACT-1: revisioned, non-destructive config refresh. `56c06fa` env-driven Edge-local test DB isolation (`EDGE_TEST_LOCAL_DB`). `7e437d1` permission authority + test isolation closed.
- `2a4ac78` OFFLINE-SYNC-ENGINE-1A preflight — identity matrix, double-apply verdict, wire contract. `d65d8ec` **1B** — immutable Edge sale outbox + envelope (`edge_sync_outbox`, append-only lease state machine pending → leased → acknowledged / failed_permanent, SKIP LOCKED lease).

**23–25 Aug — canonical gap batch 1–2, sync 1B closure**
- `6f8a63f` printing routing/job numbering/layout rows aligned with canonical; `2646765` business date on returns and item voids; `6493071` draft + quick-sale attribution offline; `71147ac` product archetype contract locked (identity = PK_EQUALITY_ENFORCED); `70be516` gap register.
- `a595fd5` upgrade an appliance without rebuilding its local DB (schema upgrader); `6096c60` cross-system sale envelope identity closed; `227175f` outbox + config-revision races proven, deadlock-free lease; `0d972e2` current product + quick-sale contract; `6f321cb` closure doc.

**26 Aug — sync 1C + 1D**
- `e9abc72` **1C** Cloud ingestion of one offline sale through Cloud authority (exactly-once registry `edge_inbound_sale_ingestions`, official FEFO/COGS/GL); `6ab9af8` ingestion fails closed on missing finance postings (`EdgeFinancePostingVerifier`); `d0ce873` baseline cutover protocol doc; `e092a83` true refresh × sale overlap certified.
- `9639733` **1D** authenticated HTTP transport for the sale outbox with verified ACK (`EdgeSyncSender`, device id/secret).

**28 Aug — sync 1E + productization (backup, artifact, supervision)**
- `7e7ea2e` reconcile local outbox against Cloud ingestion truth; `840af51` operational stock baseline cutover without split brain; `468a12e` sync health + exceptions (`EdgeSyncStatusService`) → **1E complete, 440 tests green**.
- Gate 0: `7952fc1` `/api/edge/sync/reconcile` + client; `e9f43b7` `/api/edge/sync/baseline` issuance + transport + full round trip.
- Backup/restore: `b0170eb` back up and restore without losing unsynced sales; `09d4cff` recover encrypted backups without the dead appliance's APP_KEY (per-backup random DEK aes-256-gcm wrapped by a provisioned recovery key via `EdgeBackupKeyProvider`, key_id versioning + retired keys); `EdgeRestoreService` reference-integrity precheck, atomic apply, mid-restore rollback, single-writer lock; `edge:local:backup` / `edge:local:restore`.
- Restricted artifact: `18038fd` manifest (min_db, capabilities), reproducible-build proof, command allowlist; `42cab7d` **physical exclusion of Cloud subsystems** from the branch-server artifact; `32b2f08` full operational census restored onto a genuinely fresh branch server (`EdgeFreshDbRecoveryMySqlTest`, `EDGE_BACKUP_STATE_CENSUS.md`); `936e7ec` / `066da95` test isolation.
- `d04851c` Windows supervision — `EdgeSupervisionPlan` (branch-only, allowlisted-only, least privilege), `EdgeWorkerBootstrap` bounded DB wait, `Install-EdgeSyncSenderTask.ps1` / `Install-EdgeBackupTask.ps1`. `5f5bde4` boot the **actual built artifact** (`EdgeArtifactBootTest`) + cross-DB A→B lost-ACK recovery.

**29 Aug — signed updater, reservations, reconcile 1**
- `6ffd588` **signed appliance updates**: Ed25519 (`EdgeEnrollmentCrypto`), `EdgeUpdatePackageService` binds the artifact manifest hash, `EdgeUpdateVerifier` fail-closed before mutation, `EdgeUpdateInstaller` pre-update backup → staged versioned dir → atomic `current` pointer switch → forward schema upgrade → rollback; `edge:local:update`; `edge_local_updates` audit.
- `bb1bc86` table reservations carried through local dine-in — Edge-owned `edge_local_table_reservations` (survives config refresh + recovery), `EdgeTableReservationService` reserve/activeFor/cancel/seatOnOpen, customer carry-over on Open Table; `e7c24b9` parity register + financial parity gap docs; `8f98d06` reservation concurrency certified with real OS-process races (`EdgeReservationRaceTest`).
- `ffcd390` **reconcile 1**: merged canonical @ `15afd50` (207 commits, 5 conflicts resolved in favour of canonical's newer POS/print parity); `fdb74f6` artifact kept restricted after the merge — `config/edge.php` exclude expanded (basename globs `Catering*`, `Manufacturing*`, `Purchase*`, `Supplier*`, `Subscription*`… + dir prefixes) → **0 Cloud business-logic files** in the built artifact, boot proof green; 74 boundary tests.
- `fa0f258` Cloud reservation mutation **fenced** during Local Mode (`RestaurantTableController` reserve/unreserve call `BranchOperatingModeService::assertSaleMutationAllowed`).

**30 Aug – 1 Sep — handback, preview bill, reconcile 2, cashier milestone 1**
- `fff5ebe` reservation **handback** without split brain — `EdgeReservationHandbackService` projects active Edge reservations into canonical `restaurant_tables.reserved_*`, customer_uuid → Cloud id, fail-closed on occupied/conflict/unknown uuid, idempotent. Reservation authority parity = concurrency + Cloud fence + recovery + handback all green.
- `97612ff` **Preview Bill** offline with zero mutation (`EdgeLocalPosService::previewBill`, `edge.local.pos.preview.bill`).
- `958883a` **reconcile 2**: merged canonical @ `03f0d99` (45 commits, 30 Aug–1 Sep — the whole terminal chain, deal tabs, KOT deal name, Complete Sale permission, open-shift guard, aggregator customer, 30-Aug fixes, KOT reprint fallback, REPORT-DEAL-COMPONENTS + IDENTITY, shift breakup, TABLE-CLOSE-EMPTY, RETURN-MANAGER-APPROVAL, `PosScreenRendersHttpMySqlTest`) — **zero conflicts**. Full gate 1,251 / 5,985, only the Dompdf debt red.
- `ae7e6ad` **cashier UI milestone 1** (see 3.4).

### 3.3 Architecture inventory (what exists on the branch)

- **Runtime boundary:** `routes/web.php` on `APP_ROLE=branch_server` loads only `routes/edge_runtime.php`; explicit route + CLI allowlists in `config/edge.php`; `EdgeRuntime`, `EdgeBranchContext` (immutable branch binding), `EdgeLocalReadiness`.
- **Auth:** `EdgeLocalAuthService`, `EdgeUserAuthz` (default branch OR active assignment), `EdgeEnrollmentIssuer/Consumer/Crypto`, `EdgePairingService`, `EdgeActivationEpochService`; `edge.auth` enforces session freshness (disabled user / revoked branch / disabled credential / superseded epoch → logged out).
- **POS authority:** `EdgeLocalPosService` (quick_sale/takeaway cash sale, held/draft, KOT events, table sessions, settle/cancel, manager approval, preview bill), `EdgeOperationalStockService` + `EdgeOperationalBaselineService` (Edge stock is separate from Cloud official stock), `EdgeTableReservationService`, `EdgeReservationHandbackService`, `BranchOperatingModeService` (Cloud fence), `OfflineEdgeEntitlementService`.
- **Sync:** `EdgeSaleEnvelopeBuilder`, `EdgeSyncOutboxService`, `EdgeSyncSender`, `EdgeSyncFailureClassifier`, `EdgeSyncReconciliationClient/Service`, `EdgeSyncStatusService`, `EdgeBaselineClient`, `EdgeBaselineIssuanceService`, `EdgeBaselineCutoverService`; Cloud side `EdgeInboundSaleIngestionService`, `EdgeIngestionAuthority`, `EdgeFinancePostingVerifier`, `IngestionRefusal`.
- **Config/bootstrap:** `EdgeBootstrapService`, `EdgeLocalBootstrapImporter`, `EdgeConfigRevisionService`, `EdgeLocalConfigRefreshApplier`, `EdgeCompatibilityService`, `EdgeLocalSchemaUpgrader`, `EdgeBuildInfoService`.
- **Productization:** `EdgeBackupService` / `EdgeRestoreService` / `EdgeBackupKeyProvider` / `ConfigEdgeBackupKeyProvider`, `EdgeArtifactBuilder`, `EdgeUpdatePackageService` / `EdgeUpdateVerifier` / `EdgeUpdateInstaller`, `EdgeSupervisionPlan`, `EdgeWorkerBootstrap`, `EdgeLocalPrintWorkerSupervisor`, `EdgeLocalPrintDeliveryService`, `EdgeNetworkPrinterTransport`.
- **Test harness:** `tests/MySql/Support/edge_pos_sale_worker.php` — independent OS processes with a spin barrier for genuine races (sale, reserve, cancel_reservation modes); `EdgeLocalRuntimeFixture`.

### 3.4 Cashier UI — milestone 1 (`ae7e6ad`)

| | |
|---|---|
| Route | `GET /edge/local/pos` → `edge.local.pos.screen` (edge.auth + edge.branch, allowlisted) |
| Controller | `EdgeLocalPosController@screen` — Edge analogue of `Tenant\POSController@index`, bound branch only: default terminal + terminal-switch authority (a pinned operator without `tenant.pos.change-terminal` sees only his terminal), effective order types, grid products (active/sellable/pos-visible), deal tabs (display-only), cash payment methods, waiters, operational-stock readiness |
| View | `resources/views/edge/pos/index.blade.php` — self-contained (inline CSS/JS, no Vite) so it renders on the appliance with no Internet; every mutation calls `edge.local.pos.*`, never a Cloud posting/finance/inventory route |
| Wired | terminal select, shift open/close, order-type tabs, category + Deals pills, product grid + search, cart, **Preview Bill** (zero mutation), **Review & Pay** cash settlement (Quick Sale vehicle + waiter enforced), Hold / Draft, read-only Table Board |
| Shown honestly as pending | deal selling, Recall, Quick Report, Recent Prints (no Edge endpoint yet — not faked) |
| Gates | `EdgeCashierScreenRendersHttpMySqlTest` — **real HTTP GET** on a branch_server-booted app: 200 with the real surface + view-model, default terminal, pinned-terminal policy, unauthenticated → local login. `EdgeBladeCompileGateTest` — every Edge Blade compiles and the generated PHP passes `php -l`. Artifact restriction unchanged. |

### 3.5 Parity register (executable state, 7 Sep)

- **Proven through the browser page:** cashier auth, real HTTP render, default terminal, terminal-switch authority, Blade compile + lint.
- **Wired, executable via Edge endpoints:** Takeaway / Quick Sale cash (incl. aggregator rule server-side), Preview Bill zero mutation, Hold / Draft, shift lifecycle, Table Board read.
- **Backend authority proven, UI pending:** dine-in open/rounds/KOT/settle/close, reservations (create/view/cancel/open + customer carry-over), empty-table close + race, whole-cancel frees table, KOT/receipt print + Recent Prints + KOT reprint fallback, hidden-product-on-open-bill through the real route.
- **Not built:** Quick Report UI (must reuse canonical `SalesReportEngine`; email = ONLINE_REQUIRED), deal selling, network-down UX proof, actual-artifact cashier boot proof.
- `RETURN_MANAGER_APPROVAL_STATUS = FINANCIAL_PARITY_PENDING` (returns/refunds/void not built offline; the future implementation must include the manager-approval contract). Card = ONLINE_REQUIRED.
- `NORMAL_OPERATOR_POS_PARITY_PERCENT = FROZEN`, `FULL_OFFLINE_PARITY_PERCENT = FROZEN`.

### 3.6 What is next (in order)

1. Cashier milestone 2 — Dine-In + Recall (held-list endpoint + page wiring), Table Board actions.
2. Milestone 3 — reservations in the UI + carry-over proof, empty-table close + race through the page, hidden-product-on-open-bill via the real route.
3. Milestone 4 — Review & Pay/printing parity: KOT deal identity, receipt, Recent Prints, print-here fallback, KOT reprint fallback, cancellation prints at the current counter.
4. Milestone 5 — Quick Report UI reusing canonical engine (view / thermal / network; email = INTERNET REQUIRED).
5. Proofs — network-down cashier sale, actual restricted-artifact cashier boot; full suite; recompute percentages from the executable register.
6. Then **financial parity** (return-event outbox reusing 1B–1E, Cloud return ingestion finance-gated, fenced local reversal, card = ONLINE_REQUIRED), then P entitlement lease → Q health → R print → installer → physical Windows/unplug certification → pilot.

### 3.7 Open caveats

`PHYSICAL_WINDOWS_CERTIFIED = no`, `PHYSICAL_WINDOWS_UPDATE_CERTIFIED = no`, `REAL_SIGNING_KEY_REQUIRED = yes` (update signing), `REAL_RECOVERY_KEY_PROVIDER_REQUIRED = yes` (backup KMS/vault). The unattended `apt` upgrade that restarted MySQL on prod (1 Sep, 06:14 UTC) is a physical-productization requirement for Edge: no uncontrolled DB package upgrade/restart during branch service. Known Dompdf/A4 PDF debt stays classified separately; no new Edge error may hide behind it.

### 3.8 How to run (Edge worktree)

```bash
export PATH="/d/laragon2/bin/php/php-8.3.16-Win32-vs16-x64:$PATH"
./test-mysql.sh --filter EdgeCashierScreenRendersHttpMySqlTest   # untracked harness; isolated DBs pos_test_*_edge
./test-mysql.sh                                                   # full authoritative suite (~25–30 min, one process)
vendor/bin/phpunit tests/Feature/Edge/EdgeBladeCompileGateTest.php
```
Never use Khatri/Kashif as an Edge test tenant; never touch Catering code from this worktree; other sessions run suites on the same MySQL — DB names are env-isolated.

---

## 4. pos-saas — Catering session (Kashif Kitchen)

- **Worktrees:** `pos-saas-catering` (`feat/catering-product-ux-v1`, kept), plus finished audit/prep worktrees (see §1). Dev DB `pos_saas_master_cat`, demo tenant `cateringdemo`.
- **13–14 Aug:** V1 closure + go-live readiness 1/2 (release branches), final-invoice fix, checkpoint doc.
- **16–22 Aug:** statements/settlement, refunds, customer credit; cost blocks (named, material vs charge, rate basis), recipe-or-blocks costing, commercial material rates + rate impact + house rate, quoted-rate override, customer-supplied materials, cost-block-first UAT seed, Store Issue many-bookings + searchable materials, store requirements reconciled, operations from quotation snapshots, every draft mutation serialised with send, calendar dashboard + event search, Making adjustments, in-workspace create/edit, client 888-item menu + 55-label vocabulary, legacy-screen habits honoured.
- **22–24 Aug:** KASHIF-ORDER-PUNCH (14 commits), EVENT-FORM-1/2, EVENT-HISTORY-1/2 (append-only revisions, restore/revert through authorities), PARTIAL-SUPPLY-1, COSTPANEL-SIMPLE-1, LEGACY-ALIGN-4/5/6, LEGACY-IMPORT-2 + **LEGACY-REBUILD-1 run on prod**.
- **27–29 Aug:** client feedback fixes (time/item entry UX, estimate workflow gaps, punched rates persisted), Urdu carried to kitchen, events-list Actions menu, kitchen sheet per-line materials snapshot + prod backfill.
- **5 Sep:** go-live research + execution (reset guard widened, phones repaired, suppliers imported, production reset).
- **Open:** duplicate customers, malformed phones, supplier opening balances, operator role/users, UTC numbering counter vs Karachi business day, punch-screen polish items (unit label, hide material rate, CAT vs Party naming), uncommitted cert-v2 tests.

## 5. pos-saas — Cloud Billing session (`feat/cloud-billing-onboarding-v1`)

14–15 Aug, 8 unique commits: CLOUD-BILLING-1A manual payment directory + proof UX, 1A-HARDEN payment-method lifecycle + HTTP authorization matrix, 1B trial-safe automatic first invoice + deterministic activation, 2 monthly/yearly billing period end to end, 3A transactional billing email foundation, E2E real monthly + yearly signup lifecycle on throwaway tenants. Six plans. **Built and tested, not deployed — blocked on a payment account.**

## 6. pos-saas — Tawakal + The Kashif Foods onboarding session

1 Sep spec + research docs (`docs/plans/tawakkal-kashif-onboarding-2026-09-01.md`, 708 lines) → `onboard:tawakal-kashif` idempotent command + CATEGORY-BRANCH-SCOPE-1 (`efe2894`) → merged `87cd76f` → **live 6 Sep**. 6 Sep issues found on Tawakal: ADDRESS-ATTACH-1 (fixed), refund case (documented). 7 Sep: Quick Report branch scope (fixed, verified live). Remaining: printer LAN IPs, receipt footer numbers, one shared manager PIN across both counters (command supports two).

---

## 7. Other projects

### 7.1 DMS (`dms_main`) — 55 commits
- **9–18 Aug — Promotion mockups v2 → v5** (client wants Salesflo parity, better UX): 4-tab structure (Project Code / Promotion Group / Promotion / Trading Terms), master code removed, Option A criteria-once slabs, priority-wise FOC SKUs with fallback, row-wise slab table, product hierarchy levels, Carton/Box/Unit bases, Normal-then-Special cascade, And/Or rules, full English; node + DOM-shim tests.
- **20 Aug — Promotion module P1–P11 in one day:** schema + models, Promotion Groups CRUD, searchable picker, ProjectCodeResolver + audience materialisation, Project Code screens, Promotions screens + engine, promotions drive invoicing, old Discount module retired, documentation.
- **21–28 Aug — UAT fixes:** on-screen guides/tooltips, distributor-type single pick, free-goods SKU named, fixes from client UAT + real-browser testing, UAT walkthrough page, sidebar collapsed/expands on hover, hide manage after expiry, type-to-search pickers, filer-status mapping, row colours, "why not applied" explanation, store search preview, single-select modifications.
- **23 Aug – 3 Sep — mobile secondary API:** master-data / visits / non-productive, visit status + image, secondary order submit, dist code on visit screen, stats in today-list API, image required for order visits, API history only for online state, double-invoice menu fix.
- **5 Sep — Orders to Cash (Load Form & Settlement)**, tentative prototype live on growic: Invoicing → Build Load Sheet, settlement workbench (live pool per SKU, adjust = cancel + regenerate, redeliver vs cancel), close with RTG + finance postings; phase 2 same evening — add-SKU in adjust, partial-reason setting, SalesFlo print receipt, 5-tab Reports, 4-tab Finance (cash book, receivables, DM ledger, cheque realise/bounce), revert until close. Open: knock-off allocation, credit notes, tolerance, mobile capture, PHPUnit coverage.

### 7.2 LMS backend (11) + frontend (5)
Portal shipment APIs + JWT (10 Aug), Admin Shipment Journey page for CN timeline from Recon (11 Aug), portal KPI cards + migration loader aligned with Finova (12 Aug), one-product-per-borrower constraint (21 Aug), L1/L2 import with ledger sign conversion + Postgres sequence reset (22 Aug), TO-7522 type-8 realise/settlement + B20 tier thresholds + CSV audit tooling, Django Admin polish + `/admin` on staging (27 Aug).

### 7.3 Seera Construction ERP (12)
Phase 3 HR/Payroll + Phase 4 Accounting foundation (17 Aug), Phase 5 Projects & Site Expenses reference package (17 Aug), Phase 6 Inventory & Warehouse (18 Aug), Phase 7 Equipment & Vehicle Tracking (18 Aug); phases 1–3 completion, org-chart login accounts with forced first-login password change, production bootstrap sequence test, `seera:org-domain` command (22 Aug); **client review 5 Sep → CR-01…CR-13 implemented, client-review walkthrough under `public/client-review`, `ProductionBootstrapSeeder`, refreshed `public/build.zip`** (7 Sep).

### 7.4 Washinton / Hello Transport / CrazyRays portals (rounds 3, 5–9)
- **Round 3 (7 Aug, 10 points):** Hello shift attendance rules, W-9 404, brand-gated HR forms (CNIC vs State ID), washinton_latest card payments to Confirmation Pending, florida chrome gating, official Hello T&C partial.
- **Round 5 (13 Aug):** ShipA1 alt-pay validation, CR login gate, report-modal radio leak, verify/OTP chrome, HR document lock/verified gates, **Check Price Open/Enclosed phase 1** on all 12 quote blades.
- **Round 6 (14 Aug):** booking-form stray line, checked-out agent CRM gate, CR approval email portal URL, Hello agents admin-only guard, two production passwords rotated. Flagged: hardcoded login OTP `123456` (needs SMTP + client decision).
- **Round 7 (26 Aug):** print_report `$val` crash (both portals), **panel-chain sweep — 29 blades + logout controller**, signup letters-only/zip rules, campaign vs subcontractors badge counts. Tooling lesson: never use perl for multi-line PHP edits on this mixed-CRLF repo.
- **Round 8 (26 Aug):** `/access-guide` page (~120 permission codes with definitions, admin + manager), **Check Price phase 2** in central-gateway (`PricingEngine` computes only `requested_mode`, halving CentralDispatch calls).
- **Round 9 (28 Aug):** hello signup outage root-caused to a NUL byte from the perl incident; 7-day prod log triage (RingCentral cleanup job + voicemail log class created, webhook CSRF exemption, checkNewChat guard, NDA mime, price sanitising, phone regex).

### 7.5 Small
- **SLGTrax** (4 Sep): favicon set + link tags; 1200×630 Open Graph card.
- **Shopify Sonic** (22 Aug): a logging failure no longer kills bookings; Sonic calls hardened.
- **central-gateway** (26 Aug): pricing engine honours `requested_mode`.
- **plumber_repo** (12 Aug): bilingual EN/FR end-to-end platform guide + third-party guide.

---

## 8. Open items and risks (cross-project)

| Item | Where | Why it matters |
|---|---|---|
| ⚠️ Wildcard cert `*.bingoopos.com` expires **17 Sep 2026**, cannot auto-renew (manual DNS-01) | pos-saas prod | Every tenant goes dark |
| ⚠️ Short-cash draft expenses ≈ 4.6M (Kashif 2.10M, Khatri 2.49M) | pos-saas | Likely cash pickups not recorded |
| Shift can run into the next date unchecked | pos-saas | 172,930 landed on the wrong day once |
| Kashif Food: one manager PIN for six users; `verifyPin()` has no role check | pos-saas | Approval audit is meaningless |
| Khatri schedule #4 failing every 15 min (recipients empty → relay 550) | pos-saas | Delete / disable / add email |
| Prod `unattended-upgrades` restarted MySQL mid-service | pos-saas prod / Edge requirement | Move window or hold `mysql-server` |
| `REPORT-SHIFT-BREAKUP-1` P1 built + green but stashed, not committed | pos-saas | Work at risk |
| Deal money falls into component category (Fix B) — owner decision pending | pos-saas reports | Category rows only; grand total unchanged |
| `pos-saas-catering-codex-cert-v2` has uncommitted tests | pos-saas | Lost if folder removed |
| Cloud billing deploy | pos-saas | Needs payment account |
| Kashif Kitchen: duplicate customers, supplier balances, operator roles | Catering | Owner decisions |
| Edge cashier milestones 2–5, then financial parity | Edge | Percentages frozen until executable |
| Edge real signing key + recovery-key provider; physical Windows certification | Edge | Before any pilot |
| Washinton hardcoded OTP `123456` | Washinton | Undermines password rotations; needs SMTP verification |
| DMS Load Form phase-2 gaps, no PHPUnit coverage | DMS | Client sign-off pending |

## 9. Numbers that changed hands this month (pos-saas)

| | |
|---|---|
| Canonical commits (7 Aug–7 Sep, incl. merges) | 462 |
| Edge-only commits | 53 |
| Live tenants | 4 (Khatri 11 Aug · Kashif Food 24 Aug · Kashif Kitchen prep 5 Sep · Tawakal 6 Sep) |
| Test suite (canonical, 5 Sep) | 1,175 tests / 5,531 assertions green |
| Test suite (Edge branch, after reconcile 2) | 1,251 tests / 5,985 assertions, 2 known Dompdf red, 0 Edge regressions |
| Production outages | 3 (12 Aug, 31 Aug, 1 Sep) — each with a permanent rule |
