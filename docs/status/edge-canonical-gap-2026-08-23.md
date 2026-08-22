# Offline Edge ↔ canonical gap register — 2026-08-23

Scope: re-ground the parked Offline Edge workstream against modern canonical and classify every
material canonical change. NOT a merge plan — a domain-by-domain compatibility register feeding
small EDGE-labelled compatibility batches.

## 1. Re-ground (git is authoritative)

| Key | Value |
|---|---|
| EDGE_BRANCH | `feat/edge-config-refresh-v1` |
| EDGE_HEAD / EDGE_ORIGIN_HEAD | `59de856` (local == origin) |
| CURRENT_CANONICAL | `origin/feat/14d-2-plan-upgrade-requests` @ `ec09b6a` |
| MERGE_BASE | `8799749` — "PLATFORM: record checkpoint before Edge and Catering workstream split" |
| COMMITS_EDGE_AHEAD | 12 (4 genuinely Edge-only: `ddc2a6e` config refresh + compatibility, `7e437d1` permission closure + test isolation, `2a4ac78` sync preflight, `d65d8ec` sync 1B outbox; the other 8 are platform/POS fixes that ALSO exist in canonical under different hashes) |
| COMMITS_CANONICAL_AHEAD | 140 (91 Catering/Kashif/merge/doc commits; 49 non-Catering, of which 13 are the same duplicated platform/POS fixes) |
| WORKTREE_STATUS | clean at start (no unexplained tracked changes) |
| Production | `c4fc021` Cloud-only; Local Mode inactive; `activation_ready=false`; no appliance deployed |

## 2. Edge architecture map (code-grounded)

| Concern | Where it runs | Notes |
|---|---|---|
| Runtime boundary | local | `APP_ROLE=branch_server`; route + CLI default-DENY allowlists (`config/edge.php`) |
| Local database | local MariaDB (`edge_local` resolved as `tenant`) | full tenant schema + `database/migrations/edge/*`; provisioned by `edge:local:db-init` (DESTRUCTIVE rebuild — see §4H) |
| Configuration bootstrap + refresh | central builds, local applies | v5 manifest, monotonic `config_revision`, upsert+tombstone refresh (`EdgeLocalConfigRefreshApplier`); sections = `EdgeLocalBootstrapImporter::PLAN` |
| Auth / offline authorization | local | Ed25519 enrollment assertion from Cloud → local Argon2id credential; effective authority = per-user `model_has_permissions` (role_has_permissions cleared) |
| Local POS | local | `EdgeLocalPosService`: cash-only quick/takeaway direct pay; held/Add Round/settle incl. dine-in sessions; no discounts/promo/combos/delivery |
| Catalog / prices | central-authoritative, replicated | products/variants/prices/combos/modifiers/recipes via bootstrap + refresh; tombstones preserve history |
| Inventory visibility | local PROVISIONAL only | `EdgeOperationalStockService` under an accepted device-bound baseline; official FEFO/COGS/GL stay Cloud (split-brain fence) |
| Printing | local | shared `PrintJobService` / `PrintRoutingService` / `EscPosPayloadService` produce jobs; `EdgeLocalPrintWorker` delivers to network printers (lease-safe) |
| Returns / refunds / card | NOT offline | explicitly refused (`restrictions` section; compatibility classifies `feature_unavailable_offline`) |
| Shift / cash | local | shared `ShiftService` + `SaleOperationalSettlementService` (sales_ledgers + shift counters) |
| Sync queue | local append-only outbox (1B) | immutable `edge-sale-envelope-v1` per paid sale; lease primitive; Cloud ingestion/transport (1C/1D) NOT built; ACK is the future authority |
| Reconciliation / conflicts | designed, not built | `docs/design/OFFLINE_SYNC_ENGINE_V1.md` (identity by `sale_uuid`, no `finalizePaidSale` reuse, projection mirrors) |
| Reports | NOT offline | no report surface in the Edge route allowlist |

## 3. Gap register

Legend: MUST_PORT · ALREADY_EQUIVALENT · ONLINE_ONLY · NOT_APPLICABLE_TO_EDGE · REQUIRES_EDGE_DESIGN · CONFLICT_RISK

| # | Domain | Canonical change (source) | Affected files | Class | Reason / Edge action |
|---|---|---|---|---|---|
| 1 | F Printing | KOT-ROUTING-TERMINAL-1 (`9319a15`) | tenant mig `add_terminal_to_category_printer_mappings`, `CategoryPrinterMapping`, `PrintRoutingService`, `CategoryPrinterMappingController`, views, `EdgeBootstrapService` (+`terminal_id` export), tests | **MUST_PORT** | Edge uses the shared router + replicated mappings; terminal-pinned rules must resolve identically offline; unique index now includes terminal_id |
| 2 | F Printing | PrintJob number one collision-safe authority (`1d66ab0`) | `PrintJobFactory` (new), `PrintJobNumber` (new), `PrintJobService`, `PrintAgentController`, tests | **MUST_PORT** | Edge KOT/receipt jobs go through `PrintJobService`; burst KOTs collided on `job_no` |
| 3 | F Printing | PRINT-LAYOUT-ROWS-1 (`468b9ef`) | tenant mig `add_layout_row_divider_settings`, `ReceiptLayoutSetting`, `EscPosPayloadService`, `PrintJobService` (reminder snapshot), views, tests | **MUST_PORT** + Edge addition | Edge renders ESC/POS with the shared service; the 4 new layout columns must ALSO be exported by bootstrap/refresh (canonical forgot — Edge-specific fix) |
| 4 | D Business date | REPORT-BUSINESS-DATE-1 (`dcd1ae4`, `fae23c6`) | tenant mig `add_business_date_to_returns_and_cancellations`, `SalesOrderLineCancellation`, `SalesReturn`, `KotCancellationService`, `SalesReturnService`, report engine/services, tests | **MUST_PORT** | Edge item-voids run through the shared `KotCancellationService` — a post-midnight void must carry the order's business_date; the returns part ships inert (returns offline-unsupported) |
| 5 | B POS flow | POS-DRAFT-1 (`0b5df5a`) | tenant mig `add_is_draft_to_sales_orders`, `SalesOrder`, `HeldSaleController`, `SalesOrderController`, `SalesService`, POS view, `PosDraftMySqlTest` | **MUST_PORT** + REQUIRES_EDGE_DESIGN | Edge owns held orders offline: `is_draft` persisted on hold, cleared on a normal hold and on settle; Cloud skips the KOT in browser JS — Edge POS is API-driven, so the skip must be SERVER-side (`queueKotEvents` refuses a draft) |
| 6 | B POS flow | PHASE 2b quick-sale attribution (`0d41617`) | `HeldSaleController`, `POSController`, `SalesOrderController`, POS view, `TenantFixtures::makeWaiter`, tests | **MUST_PORT** (capture) / REQUIRES_EDGE_DESIGN (hard require) | Edge already drops vehicle on takeaway; Batch 1 CAPTURES `restaurant_waiter_id` on quick sale + carries it in the sync envelope; the server-side `required_if` rule offline is a Batch-2 decision (§8) |
| 7 | A Catalog | Product-creation contract freeze (`0a74301`, `5cf34af`) | `ProductArchetypeContractMySqlTest` | ALREADY_EQUIVALENT (port the lock) | Edge `ProductController` archetype/consumption guards are byte-identical to canonical; the test is ported as a regression lock |
| 8 | A Catalog | Track-Stock ↔ consumption-method guards; service/non-stock semantics | `ProductController`, `EdgeOperationalStockService` | ALREADY_EQUIVALENT | Edge stock consumption honours `stock_item`+tracked / `recipe` / `none` (no decrement for service items) — pinned by a new Edge test |
| 9 | D Business date | `TenantClock` / shift-derived `business_date` | `app/Support/TenantClock.php` (unchanged since MB) | ALREADY_EQUIVALENT | "Karachi on every clock" (`f4e7717`) touched Catering views only |
| 10 | Platform/POS | Fix 500 finance pages; reprint buttons; receipt preview; 403 cached routes; self-signup docs; vehicle/recall/cancel-KOT; Hold delivery charge; test DB isolation (`221aba9`/`8d87db9`, `c8ac1b8`/`c6b519e`, `73e6f7a`/`653292c`, `0facf21`/`626fa82`, `74cecec`/`60e02d8`, `661cf0e`/`0a135ce`, `67efbc1`/`3eb2a1b`, `5e347df`) | various | ALREADY_EQUIVALENT | identical content already on the Edge branch under different hashes |
| 11 | E Reports | REPORT-* (`bec1d16`, `78d6a6f`, `8903742`, `719b4bb`, `24f6c29`, `786a414`, `aae508a`, `deedd7e`, `c39e335`, `703d789`) | `SalesReportEngine/Service`, `DepartmentReportService`, report center | ONLINE_ONLY | no report surface offline; NET-SALES charge-bridge semantics live in Cloud reporting which Edge sales reach via sync; local facts (frozen totals, business_date) do not contradict them |
| 12 | C Returns | PHASE 2a returns UX (`acb1ee3`), partial-return remaining-items fix (`820e76c`) | `SalesReturnController`, `SaleLookupController`, return views/tests | ONLINE_ONLY | returns are refused offline by contract; Cloud-side correctness only |
| 13 | Finance | supplier opening balance to GL (`52b5c85`); Catering finance closure in `JournalPostingService` | `JournalPostingService` | ONLINE_ONLY | Edge never posts GL (fenced) |
| 14 | UI/ops | operator scoping (`d724c79`), entitlement boundaries (`1da0bc4`, `5202c95`), product list path (`3fb2ae9`), preview parity views (`e3b2ed8`, `6d81c7e`), POS-DRAFT UI (`5fc0f60`, `8c6b4eb`), Khatri KOT data switch command (`9f1a766`) | Cloud controllers/views/commands | ONLINE_ONLY | Cloud UI / SaaS / one-off operational tooling; Edge POS is API-driven |
| 15 | Catering | CATERING-V1 … KASHIF-LEGACY-ALIGN (91 commits incl. 22 catering tenant migrations, master `register_catering_module`, `PreventDuplicateSubmission`) | `app/*Catering*`, `database/migrations/tenant/*catering*`, views, tests | NOT_APPLICABLE_TO_EDGE | Edge does not own Catering; not contractually required offline; no offline snapshot/finance/stock authority for it (§10) |
| 16 | H Schema | appliance schema UPGRADE path | `EdgeLocalDbInitCommand` (destructive rebuild only) | REQUIRES_EDGE_DESIGN | a bootstrapped appliance cannot take new tenant/edge migrations without wiping local data; needs a guarded non-destructive `edge:local:schema-upgrade` (pending-only) before any deployed appliance receives Batch-1 migrations. No appliance is deployed today |
| 17 | B POS flow | quick-sale hard REQUIRE (vehicle + waiter) offline | `EdgeLocalPosService` + ~66 Edge POS tests | REQUIRES_EDGE_DESIGN | global Cloud validation; enforcing offline risks "offline sale ability" if the roster is empty and churns every Edge POS fixture — Batch 2 with an explicit decision |
| 18 | G Sync contract | envelope v1 must carry the quick-sale waiter | `EdgeSaleEnvelopeBuilder` | REQUIRES_EDGE_DESIGN (delivered in Batch 1) | add top-level `restaurant_waiter_id`; safe now — 1C ingestion is not built, no stored production envelopes |
| 19 | B POS flow | Draft KOT skip is a browser decision on Cloud | `EdgeLocalPosService::queueKotEvents` | REQUIRES_EDGE_DESIGN (delivered in Batch 1) | Edge enforces server-side: a draft never queues a KOT; promoting (normal hold) clears the flag and the KOT then fires exactly once |
| 20 | Integration | `EdgeBootstrapService` edited on both sides (Edge: v5 manifest/revision; canonical: `terminal_id` column) | `EdgeBootstrapService` | CONFLICT_RISK (low) | one-line hunk in the section list — applied cleanly |
| 21 | Integration | `HeldSaleController` / `POSController` / `SalesOrderController` / `pos/index.blade.php` | shared Cloud POS files | CONFLICT_RISK (medium) | both branches carry the same duplicated fixes; cherry-picks `0d41617` → `0b5df5a` must land in order |
| 22 | Integration | `EscPosPayloadService` + receipt views; `SalesReportCenterController` (Edge lacks REPORT-SEND-TO-NETWORK) | printing | CONFLICT_RISK (low) | resolved by keeping Edge's report controller (online-only path) |
| 23 | Integration | future canonical merge of the 4 Edge-only commits | `EdgeBootstrapService`, tests | CONFLICT_RISK (low) | trivial overlap with #20 |

Counts: GAP_TOTAL=23 · MUST_PORT=6 · ALREADY_EQUIVALENT=4 · ONLINE_ONLY=4 · NOT_APPLICABLE=1 · REQUIRES_EDGE_DESIGN=4 · CONFLICT_RISK=4

## 4. Priority domain audits

**A. Product / Catalog** — `product_kind`, `is_stock_tracked`, `inventory_consumption_method`, visibility and price authority are unchanged since MB in every shared file Edge uses; canonical's only product changes are the Catering materials context (N/A) and the archetype contract *test*. Edge stock consumption already skips `none` (service) items, decrements `stock_item`+tracked, expands `recipe`. GAP: none behavioural; lock ported.

**B. POS sale flow** — Held/recall/Add Round/settle equivalent; Hold delivery charge + vehicle recall already on Edge. GAPS: `is_draft` (#5/#19), quick-sale waiter capture (#6), hard require (#17). Discounts/delivery/other charges remain refused offline (unchanged contract).

**C. Returns / voids** — returns offline-unsupported (unchanged). Item voids: shared `KotCancellationService` must stamp `business_date` (#4). Partial-return fix is Cloud UI (#12).

**D. Business date** — shift-derived on both sides; midnight behaviour identical; returns/voids anchoring ported (#4).

**E. Reports** — ONLINE_ONLY; Edge frozen totals/business_date feed Cloud reports after sync. No local fact contradicts canonical.

**F. Printing** — #1 terminal routing, #2 job numbering, #3 layout rows + Edge export of the 4 columns. Offline queue/lease/worker unchanged.

**G. Permissions / configuration** — canonical permission changes are Catering vocabulary + SaaS entitlement (N/A / online). Config refresh handles new payload columns generically (terminal_id, layout columns) once exported. Timezone/business-date unchanged.

**H. Schema** — migrations since MB affecting Edge-used tables: `category_printer_mappings` (+terminal_id, unique index rebuilt) PORT; `receipt_layout_settings` (+4) PORT; `sales_orders` (+is_draft) PORT; `sales_order_line_cancellations` (+business_date) PORT; `sales_returns` (+business_date) PORT (inert offline); Catering tables (22) NOT APPLICABLE; master `register_catering_module` NOT APPLICABLE. Upgrade path for existing appliances: DESIGN REQUIRED (#16).

## 5. Special modern canonical changes — checklist

| Item | Edge verdict |
|---|---|
| partial-return remaining-item fix | ONLINE_ONLY (returns refused offline) |
| business_date on returns/voids | MUST_PORT — Batch 1 (#4) |
| NET SALES charge-bridge | ONLINE_ONLY reporting; Edge envelopes carry frozen totals; no local contradiction |
| POS Save as Draft (no KOT until promoted) | MUST_PORT + server-side skip — Batch 1 (#5/#19) |
| DRAFT badge / recall | Edge recall payloads expose `is_draft` — Batch 1 |
| PrintJob unique-number reliability | MUST_PORT — Batch 1 (#2) |
| product stock/non-stock consumption guards | ALREADY_EQUIVALENT (+ Edge pin test) |
| Track Stock ↔ Consumption Method consistency | ALREADY_EQUIVALENT (controller identical; contract lock ported) |
| Karachi / current-business-date | ALREADY_EQUIVALENT (`TenantClock` unchanged; shift-derived business_date) |
| Catering V1 | NOT_APPLICABLE_TO_EDGE |
| Cloud billing/onboarding | NOT_APPLICABLE_TO_EDGE |

## 6. Data-compatibility risk (Edge-used tables)

| Table | Delta | Risk | Disposition |
|---|---|---|---|
| `category_printer_mappings` | +`terminal_id` nullable; unique index now includes terminal_id | central rows with terminal_id rejected locally if the column is missing; refresh upsert needs the column | PORT migration + export column (Batch 1) |
| `receipt_layout_settings` | +`item_font_size`, `time_font_size` (null = current), `show_column_dividers` (0), `show_category_header` (1) | silent default mismatch → appliance prints the old layout | PORT migration + export (Batch 1) |
| `sales_orders` | +`is_draft` default false | local drafts must never sync (envelope is paid-only; settle clears the flag) | PORT (Batch 1) |
| `sales_order_line_cancellations` | +`business_date` nullable | Cloud reports would misallocate Edge voids by calendar date | PORT (Batch 1) |
| `sales_returns` | +`business_date` | none offline | PORT inert |
| `print_jobs.job_no` | no schema change; generator widened + constraint-backed retry | duplicate-key failures on KOT bursts | PORT (Batch 1) |
| Catering tables | new | none (Edge never references them; Catering migrations are NOT ported) | n/a |

## 7. Sync contract audit

CENTRAL → EDGE (bootstrap/refresh sections): `category_printer_mappings.terminal_id` — exported after Batch 1; `receipt_layout_settings` 4 columns — exported after Batch 1 (Edge-specific; canonical's export list lacks them); `sales_orders.is_draft` — operational, not config (n/a). All other section columns unchanged. Version fields: `SCHEMA_VERSION=edge-bootstrap-v5`, `CONFIG_SCHEMA_VERSION=edge-config-v1` — adding columns to sections does NOT change the wire envelope; the column set is carried per row and the refresh applier updates whatever the payload carries. Decision: keep `edge-config-v1` (additive columns with safe defaults); bump only on a semantic change.

EDGE → CENTRAL (`edge-sale-envelope-v1`): add `restaurant_waiter_id` (quick-sale attribution); `is_draft` intentionally omitted (a paid sale is never a draft — the builder refuses non-paid); `delivery_charge_amount` intentionally omitted (delivery refused offline); `business_date` already carried; voids/returns not synced in V1 (explicitly unsupported).

## 8. Batch plan

**Batch 1 (this session, high confidence):**
1. `EDGE: align printing routing, job numbering and layout rows with canonical` — #1 #2 #3 (+ export of the layout columns).
2. `EDGE: carry business-date on returns and item voids` — #4.
3. `EDGE: carry draft and quick-sale attribution offline` — #5 #6 (capture) #18 #19.
4. `EDGE: lock product archetype contract` — #7 (+ Edge non-stock consumption pin).

**Batch 2 (next):** #16 non-destructive appliance schema upgrade; #17 quick-sale hard-require decision + fixture alignment; Edge HTTP controller payloads for draft/waiter (`EdgeLocalPosController`) if the local UI needs them; re-run of the full authoritative MySQL suite after the canonical merge of the Edge-only commits.

Not in any batch: Catering, Cloud reports / returns UI / finance / SaaS entitlement.
