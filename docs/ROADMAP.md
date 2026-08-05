# Bingoo POS — Roadmap & System Gap Register

> Maintained working document. Update after every completed sprint.
> Last updated: **2026-08-03** (POS-WORKSPACE-UX-1 built locally; LOCAL-PRINT-LAN-CERT-1 physically blocked; production not deployed) · branch `feat/14d-2-plan-upgrade-requests`

---

## ✅ Completed (major tracks)

| Track | Status |
|---|---|
| Core POS, inventory, purchasing, restaurant, printing, KDS | ✅ live |
| Finance (FIN-1..12: CoA, journals, TB/P&L/BS, receivables/payables) | ✅ live |
| SaaS platform (plans/modules/limits, billing+proof, upgrade requests, self-signup, 6 public demos) | ✅ live |
| Manufacturing foundation (MANUF-1..10, planning/tracking only) | ✅ live |
| Manufacturing finance (A-D + G: settings/infrastructure, consumption, FG receipt, WIP close/variance) | ✅ built; audit-hardened locally, production deployment pending |
| Manufacturing plan entitlement (ENTITLEMENT-IMPLEMENT-1, 2026-07): central `manufacturing` module + route/sidebar gating — allowed: standard/enterprise/finance_erp; blocked: retail_starter/inventory_store/restaurant_starter/restaurant_pro (Phase 2 = paid add-on, design doc `docs/audits/manufacturing-entitlement-design-2026-07.md`) | ✅ built locally; prod deploy pending |
| Department module (DEPT-1..5: mapping, custody stock, shadow consumption, counts/reconciliation, dashboard) | ✅ live |
| Purchasing UX + portal-wide UX hardening (AJAX pickers, batch dropdowns, shortcuts, Swal, POS images/colors) | ✅ live |
| Tenant ops (backup/restore/sync/reset from central panel) + provisioner config-cache fix | ✅ live |

---

## 🔜 TRACK A — Production readiness for real clients (DO FIRST)

| # | Item | Why | Status |
|---|---|---|---|
| A1 | **PRD-6 hardening**: login throttle, payment-proof upload validation (mime/size), dev-artifact cleanup, RELEASE_CHECKLIST.md | brute-force + upload abuse exposure | ✅ **PROD-READINESS-1** (2026-07-11): email+IP+guard throttle 5/2min on the shared login; proof mimes+mimetypes+5MB+help text; RELEASE_CHECKLIST.md; artifact audit run |
| A2 | **SMTP**: prod `MAIL_MAILER=log` — signup/password-reset emails are silently never sent | real clients can't reset passwords | 🟡 code+docs ready (`mail:test` command, docs/ops/SMTP_SETUP.md) — **manual: put real SMTP creds in prod .env + config:cache + verify** |
| A3 | **Merge → main + tag `v0.9.0-pilot`** | release discipline / rollback point | ✅ 2026-07-11: main fast-forwarded 173 commits (23e1738→e7fce39, zero divergence), annotated tag `v0.9.0-pilot` pushed — rollback point established |
| A4 | **deploy.sh hardening** | every deploy hits gotchas by hand | ✅ deploy.sh now: MasterSeeder + `system:clear-tenant-permission-cache` (new command) + queue:restart + chown/chmod |
| A5 | Ops: nightly demo:reset-all green check; SSL auto-renew (cert ~Sep 2026); rotate root+MySQL passwords; re-register lost client tenant | | 🟡 manual ops — documented in RELEASE_CHECKLIST/DESTRUCTIVE_COMMANDS docs |

## 🔜 TRACK F — Offline POS / Branch Edge (NOW the primary direction, 2026-07)

Design: `docs/audits/offline-pos-architecture-2026-07.md` + `docs/ops/OFFLINE_POS_ROLLOUT_RUNBOOK.md`. **Branch Edge / Local POS Mode**: one on-prem Branch Server per Local-Mode branch (existing Laravel + local MySQL, single-tenant); terminals are browsers on the LAN URL. Cloud stays the only official accounting authority; local sales sync idempotently via `SalesService::finalizePaidSale`.

| # | Item | Status |
|---|---|---|
| F1 | **BRANCH-OPERATING-MODE-1** + **HARDEN-1** — 5-state lifecycle, `APP_ROLE`/`EDGE_*` (env-only), guard on 9 sale paths + shift open/close; code-enforced transition matrix; EDGE_TENANT_CODE+EDGE_BRANCH_ID both enforced; setup UI+actions behind `EDGE_FEATURE_ENABLED` (default OFF). Cloud lock on active/closing/suspended; pending keeps cloud sales | ✅ built locally; **prod deploy deferred until pairing/bootstrap ready** |
| F2 | ✅ **SALE-IDEMPOTENCY-1** — `sales_orders.client_uuid` (unique) + `client_payload_hash`; one-logical-sale uuid in POS JS (persist/retry/rotate); same-uuid+same-payload = replay, same-uuid+different-payload = 409; DB-unique race guard; replay never double-posts sale/stock/COGS/journal/print. Design `docs/audits/sale-idempotency-design-2026-07.md`. QA 16/16 | ✅ built locally; deploy deferred |
| F2 | ✅ **SALE-IDEMPOTENCY-HARDEN-1** — proved the race with **real 12-way parallel HTTP** (all 200, one fresh, one row, no 500); loser → bounded re-fetch replay or retryable **503 PENDING**; **null stored hash never silently replayed** (→ 409 `UNVERIFIABLE`); POS uuid moved to **`sessionStorage` scoped `origin:branch:terminal`** (tab/branch/terminal isolation); replay now **ensure-prints** (receipt `ensureOnce` + KOT delta) so a timeout-before-print still recovers, no dup; canonical line/payment order made immaterial. QA 21/21 + replay/conflict over HTTP; 7/7 tenants tb=0/neg=0/dept=0; `EDGE_FEATURE_ENABLED=false` | ✅ built locally; deploy deferred |
| F3 | ✅ **EDGE-EDITION-ARCHITECTURE-1** (design) — sellable `offline_edge` module + self-service `BingooEdgeSetup.exe`; restricted **allowlist** Laravel Edge artifact + local MariaDB/MySQL (one Branch Server per branch, browser terminals on LAN URL); **`EdgeSaleCaptureService`** (local operational capture, NO official GL) + cloud-only `finalizePaidSale` replay by `client_uuid`; boundary manifest (INCLUDE POS/restaurant/printing; EXCLUDE finance/COGS/GL/purchasing/stock-authority/mfg/billing/tenancy); offline signed entitlement lease/grace (brief net loss never stops selling); Print Agent reuse (re-point to local URL). Honest timeline ~4–5 months to pilot. Docs: `docs/audits/edge-edition-architecture-2026-07.md`, `docs/audits/edge-edition-boundary-manifest-2026-07.md`, `docs/ops/EDGE_INSTALLER_PRODUCT_RUNBOOK.md` | ✅ design only; deploy deferred |
| F3 | ✅ **OFFLINE-EDGE-ENTITLEMENT-1** — sellable `offline_edge` module in the existing plan/module framework (attached to NO plan by default); `OfflineEdgeEntitlementService` with **three independent gates** (entitlement / `EDGE_FEATURE_ENABLED` rollout / installer availability); `/settings/offline-edge` landing + `/download` gate (self-renders **403 `EDGE_FEATURE_DISABLED`** / **503 `EDGE_INSTALLER_NOT_AVAILABLE`**, never a fake EXE, never 500); 2 perms + sidebar entry (hidden unless entitled+rolled-out). No installer/pairing/sync/device-licensing built. QA 20/20 + real-HTTP matrix; 7/7 tenants tb=0/neg=0/dept=0, manufacturing regression green, no tenant auto-entitled. Docs: `docs/audits/offline-edge-entitlement-design-2026-07.md` | ✅ built locally; deploy deferred |
| F3 | ✅ **OFFLINE-EDGE-ENTITLEMENT-HARDEN-1** — entitlement now flows through the canonical `TenantSubscriptionAccessService::check()` (fixes lapsed-subscription-with-module bug; fail-closed on invalid route mapping; structured `EDGE_NOT_ENTITLED` instead of bare abort); existing-tenant perm propagation = **reuse `deploy.sh` step 5** (grants `tenant.offline-edge.*` to every Owner idempotently, Owner-only; `is_published=0` doesn't block it — proven 21/21 ×2); `installerIsAvailable()` rejects dir/zero-byte/unreadable; `.env.example` installer keys added. QA 19/19 service + 6-scenario real-HTTP matrix; 7/7 smoke green | ✅ built locally; deploy deferred |
| F3 | ✅ **BRANCH-DEVICE-PAIRING-1** — master-DB `edge_devices` + `edge_pairing_codes` (one active device/code per branch via unique active_slot); central `POST /api/edge/pair` (tenant+branch from code, never request input) + `GET /api/edge/device/me` (device-auth middleware); **client-generated device secret** (cloud stores sha256 only) + **response-loss-safe idempotent retry**; 6-digit HMAC code (15min/single-use/5-attempt/throttled); entitlement+rollout re-checked at exchange; revoke exempt from entitlement/flag (security); pairing → `pending_bootstrap` only, branch stays **pending** (never active, cloud sales unaffected); device limit `max_active_edge_devices` (fail-closed default 1). QA 27/27 service + real-HTTP (pair/auth/revoke/idempotent/4-parallel→1 device) + propagation 21/21 ×2; 7/7 smoke green. Docs: `docs/audits/branch-device-pairing-design-2026-07.md` | ✅ built locally; deploy deferred |
| F3 | ✅ **BRANCH-DEVICE-PAIRING-HARDEN-1** — durable/race-safe/recoverable pairing: failure-state (attempts/burn) now COMMITS via outcome pattern (was rolled back); DB `unique(code_hash,active_slot)` + `unique(installation_uuid,active_slot)` (re-pair after revoke); tenant-row-lock serializes device cap across branches; encrypted one-time code (no plaintext in session); always-reachable `/settings/offline-edge/security` (revoke/cancel without entitlement/flag); cross-DB **saga+compensation** (idempotent branch reconcile, reconciliation-required not 500); `EDGE_DEVICE_LIMIT_REACHED`=409. **Real 2-process concurrency cert (ports 8899+8900): A–D all pass, 0×500.** Honesty: max_attempts bounds only resolved-code failures (unknown-code guessing = IP throttle). QA 14/14 + 27/27 + concurrency + session + security-HTTP; 7/7 smoke green | ✅ built locally; deploy deferred |
| F3 | ✅ **BRANCH-DEVICE-PAIRING-HARDEN-2** — generation no longer silently replaces a live code (concurrent generate → **409 `PAIRING_CODE_ALREADY_ACTIVE`**, cancel-first UX; expired codes still replaceable); one-time code consumed via `session()->pull()` + `no-store`/`no-cache` headers; migration `down()` refuses to destroy revoked-device history (safe/irreversible); durable `edge_reconciliation_markers` (persist on post-mutation reconcile failure, resolve on retry, never 500). QA 11/11 + base 27/27 + HARDEN-1 14/14 + 2-process generate (one 302/one 409) + headers + marker persist/resolve; 7/7 smoke green | ✅ built locally; deploy deferred |
| F3 | ✅ **BRANCH-BOOTSTRAP-SNAPSHOT-1** — device-authed central API (`POST /api/edge/bootstrap/snapshots` + manifest/section/acknowledge) delivering a deterministic, immutable, **branch-scoped** snapshot (tenant+branch from device only, spoof ignored; explicit column allowlists → no cost/finance/secrets/other-branch; users = min identity, no password/tokens); per-section + manifest SHA-256, gzip-invariant; ownership-checked by public UUID; controlled codes (NOT_ALLOWED/DEVICE_REVOKED/BRANCH_NOT_PENDING/NOT_FOUND/EXPIRED/HASH_MISMATCH/SCHEMA_UNSUPPORTED), never 500; ack moves **device** pending_bootstrap→ready (idempotent) — **branch stays pending, no activation**. Preflight: migration down() order + concurrent-generate flash race (direct-render + no-store). QA 22/22 service + HTTP flow + 2-process converge + regression 27/14/11 + POS/PrintAgent 200; 7/7 smoke green. Docs: `docs/audits/branch-bootstrap-snapshot-design-2026-07.md` | ✅ built locally; deploy deferred |
| F3 | ✅ **BRANCH-BOOTSTRAP-SNAPSHOT-HARDEN-1** — secure acknowledgment + complete delivery + consistency: ONE shared contract re-checked at create AND ack (entitlement/sub/flag/current-device/branch-pending → all block ack, device never wrongly `ready`); **fresh locked-model** re-validation in create+ack (real 2-process revoke-vs-ack / revoke-vs-create → no resurrection, 0×500); per-section download receipts → ack needs the **complete verified section-hash map** (INCOMPLETE 409 / SECTION_HASH_MISMATCH 422); guaranteed `finally` tenancy cleanup; **REPEATABLE-READ** tenant read boundary + SOURCE_CHANGED retry; complete source_revision (+terminal_printer_settings, +model_has_roles); build-lifecycle codes (201/200/202/503/409); tightened data policy (active+sellable+POS products, no orphans, **cash-only** payments, **own-only** delivery, restrictions section); gzip hash-invariant (28/28) + corruption detected. QA 34/34 service + HTTP + 2-proc races + regression 27/14/11 + POS/PrintAgent 200; 7/7 smoke green | ✅ built locally; deploy deferred |
| F3 | ✅ **BRANCH-BOOTSTRAP-SNAPSHOT-HARDEN-2** — final activation-gate closure: contract now also enforces **active subscription** + **tenant device limit** (409 `DEVICE_LIMIT_REACHED`) at create AND ack; final contract re-check **under the lock** before device-ready/publish; nullable locked-model (no TypeError); **true** source-change detection via fresh post-build live revision (claim==txn==live else `SOURCE_CHANGED`); role revision hashes `model_id:role_id` pairs (swap-safe); full reference coherence (variant/combo/modifier constrained, incoherent combo dropped whole); **server verifies stored payload bytes** before delivery (503 `SECTION_CORRUPTED`, ack impossible on corruption even if client echoes manifest); atomic receipt increment. HTTP-certified lifecycle 201/200/202/503 + partial-ack 409 + **ordered revoke-vs-ack both ways** (never `ready` after revoke, 0×500). QA 16/16 + 34/34 + regression 27/14/11 + POS/PrintAgent 200; smoke green | ✅ built locally; deploy deferred |
| F3 | ✅ **EDGE-LOCAL-RUNTIME-PREFLIGHT-1** (audit, **superseded by HARDEN-1**) — first code-grounded map of turning the Edge foundation into a disconnected Branch POS. Useful draft, but challenged as a code review and NOT implementation-approved: several "verified" statements were assumptions (bootstrap version, shift model, cross-system identities, invoice numbering) and one recommendation was security-regressing (exporting cloud password/PIN hashes). Doc: `docs/audits/edge-local-runtime-preflight-2026-08.md` | ✅ design only (superseded) |
| F3 | ✅ **EDGE-LOCAL-RUNTIME-PREFLIGHT-HARDEN-1** (audit) — re-grounded every contested fact against code and corrected 16 PREFLIGHT-1 statements: bootstrap is **`edge-bootstrap-v3`** (not v1); shifts are **per-terminal** (not one/branch); `nextSaleNo` is **timestamp+random, not gapless** → offline numbering must be designed before offline sales; **no UUIDs** on lines/payments/kot-lines/shifts/customers → cross-system identity additions required before sync; split-brain guard covers **sales only** → all branch-stock mutation paths must be blocked in Local Mode; `allow_negative_stock=false` must **hard-block locally** (not warn); outbox needs an **ingestion boundary** before `finalizePaidSale` (no re-print/re-number); local auth = **Edge-specific verifier** (never ship cloud hashes) + device-local `EDGE_LOCAL_APP_KEY`; recovery needs **binlog/journal + RPO/RTO** (outbox can't self-restore); entitlement lease + restricted-artifact-first added to roadmap. **Governance: audit = zero prod mutations; deploy needs separate approval + green tests.** Revised 12-sprint roadmap; first safe step = **EDGE-RUNTIME-BOUNDARY-1** + §8/§9/§10 design closure (no selling code until signed off). Doc: same file | ✅ design only |
| F3 | ✅ **EDGE-LOCAL-RUNTIME-PREFLIGHT-HARDEN-2** (audit) — closes the deeper runtime-level contracts HARDEN-1 left as architecture claims. Code-grounded: **recipe/modifier/combo consumption** (`inventory_consumption_method` recipe→ingredient decrement w/ unit-conversion-that-blocks-the-sale; `consume_stock` modifiers→linked product; combos→components) makes product-level offline stock wrong → **phase-1a BLOCK recipe/consume-stock products** (only bootstrap ships modifiers/combos, NOT recipes/recipe_ingredients/unit_conversions — verified absent) or phase-1b export+replicate; **bootstrap atomicity** = revision-pointer (single-row DML flip, no DDL — MySQL DDL auto-commits so HARDEN-1's "one-txn flip" wasn't real); **first-credential** = cloud-signed one-time enrollment assertion (breaks circular login); **APP_KEY recovery** = DPAPI machine-bound → escrowed wrapped data-recovery key, re-enroll creds on new box; **atomic activation fence** (freeze→baseline@R→import+ack→verify→active→unfence-on-fail); **trusted sync bypass** = device+ingestion-service-scoped only; **complete identity set** (add line/payment/kot-line/shift/session/approval/offline-customer UUIDs; session_no/approval_no stay labels); **sale_no = ULID** (clock-skew/restore-safe, printed==stored); **immutable sale ENVELOPE** atomic ingest, not-done-until-officially_posted; **config provenance** (cloud verifies revision issued to device — anti price-tamper); **print = at-least-once + dedup** (exactly-once impossible on ESC/POS); **durability** = binlog on separate volume + off-box upload (not a 2nd InnoDB table) + RPO≤s/RTO≤min; **lease clock-rollback defense**; **software-update channel** + **terminal authorization** + **observability** + **PII policy** added. 5 sequence diagrams (activation/sale/sync/refresh/recovery). Doc: `docs/audits/edge-local-runtime-preflight-2026-08.md` (Part II) | ✅ design only |
| F3 | ✅ **EDGE-LOCAL-RUNTIME-CONTRACT-CLOSURE-1** (audit, code-trace) — closes the remaining runtime contracts A–M with code grounding. **Config atomicity (A):** transactional full-set DML swap (shadow `*_incoming` → one InnoDB txn DELETE+INSERT; MVCC = atomic cutover, no DDL, no revision predicate; models unchanged) — enabled by verified per-line price capture (held lines store own unit_price/product_name, `SalesTotalsService` only totals). **Cloud config archive (B):** persist every issued revision immutably to validate historic price/tax/permission at sync. **Activation epoch (C):** monotonic epoch on lease/bootstrap/revision/envelope/sync; replacement increments; cloud rejects old epochs (stops cloned/stolen/old server double-send). **Operational recipe stock (D):** `EdgeOperationalStockService` reuses exact quantity rules (recipe yield+unit-conversion-that-blocks, consume_stock modifier linked-product, combo=component lines w/ combo_header skipped) minus COGS/FEFO/GL; `allow_negative_stock=false`→hard-block; bootstrap adds recipes/recipe_ingredients/unit_conversions (verified absent); 9 worked examples. **Baseline+cursor (E):** availability = baseline@R − unacked local events; refresh never overwrites stock. **sale_no (F):** `SO-{branch}-{terminal}-{ULID}` (no code parses format — only LIKE search; verified safe); separate `occurred_at`+drift as business time. **Print history (G):** non-printing audit rows (printer_id/logical_key/status) so cancellation keeps historical Reminder destinations w/o physical jobs. **Held/Add-Round (H):** per-line captured price (code-verified) — NOT whole-check freeze; Edge mirrors. **Key split (I):** app-key vs data-recovery-key; escrow tradeoff stated. **LAN TLS (J):** branch-local CA, hostname-bound cert, offline renewal. **Tamper (K):** append-only hash-chained HMAC envelopes; threat boundary stated. **MySQL tests (L):** phpunit runs SQLite `:memory:` (verified) → add MySQL suite for Edge-critical tests; FIRST sprint. Doc: Part III. | ✅ design only |
| F3 | (next, APPROVED ORDER — coding gated) **1. MYSQL-TEST-FOUNDATION-1** (contract L, deterministic MySQL Edge test DB) → **2. EDGE-RUNTIME-BOUNDARY-1** (restricted build/route-allowlist/appliance skeleton, with contract J TLS + §H2.14 update channel locked; no DB/auth/selling) → **3. EDGE-LOCAL-RUNTIME-1** (local MariaDB + transactional-swap import (A) + branch binding + `EnsureEdgeBranchBound` + activation epoch (C)) → **4. EDGE-LOCAL-AUTH-1** (Edge verifier + enrollment assertion + key split (I)) → EDGE-IDENTITY-1 → EDGE-LOCAL-POS-1 (stock_item then recipe phase-1b) → EDGE-LOCAL-PRINT-1 → EDGE-SPLITBRAIN-STOCK-1 → EDGE-CONFIG-REFRESH-1 → OFFLINE-SYNC-ENGINE-1 → OFFLINE-RECOVERY-HARDEN-1 → lease/update/observability → EDGE-ACTIVATION-1. **Sales/sync remain later. Awaiting user product decisions: B retention window, I escrow-vs-passphrase, J confirm.** | **ready pending go-ahead** |
| F4 | **PRINT-ROUTING-FOUNDATION-1** - order-type-aware KOT category routes; multiple physical printers per category/order/document; exact duplicate-rule validation including global mappings; Reminder-ready printer/layout/job schema; automatic KOT logical keys with DB uniqueness; manual copies isolated by source revision and destination. Existing KOT prompt/auto/default/browser/agent behavior and Receipt flow preserved. MySQL two-process race converged to one job; all-tenant finance/inventory smoke green. Reminder output and Edge activation remain deferred. | built locally; deploy deferred |
| F4 | **REMINDER-PRINT-1** - complete-order non-fiscal Reminder on accepted KOT rounds; order-type/category multi-printer routing; same physical KOT+Reminder support; immutable revision and `(R)` delta snapshots; Ask-on-addition with bound confirmation token; isolated per-printer manual duplicates; approved partial/whole cancellation correction Reminder with historical destination union. Existing KOT/Receipt and Print Agent retry contract preserved. Edge bootstrap v3 exports configuration only; Local Mode/sync remain off. | built locally; software QA green; deploy deferred |
| F4 | **LOCAL-PRINT-LAN-CERT-1** - repository/test/cache preflight, explicit non-fiscal rendering, historical destination, tenant invariants, and Edge-off proofs passed on `a0452e7`. Real paper matrix blocked: only loopback fake printers were configured, no Reminder-capable printer/mappings existed, and agent status was stale. | **BLOCKED** pending real LAN printers/agent; no production deploy |
| F4 | **DIRECT-PAY-PRINT-ORCHESTRATION-1** - Review & Pay resolves KOT Print/Skip before finalization; paid sale stores durable KOT/Receipt state; server-side orchestration reuses delta KOT, Reminder Auto/Ask and Receipt ensure-once; failures stay paid + retryable; cart clears only after stable result; retry is not a manual duplicate. Cloud canonical contract only, Local Mode/sync unchanged. | built locally; deploy deferred |
| F4 | **POS-WORKSPACE-UX-1** - compact one-screen Restaurant POS with internal product/cart scrolling; same-page Table Workspace for Open/Continue/Held/Move/Split and permission-controlled setup; live-cart and server-session previews retain separate authority; Move locking hardened. Merge backend locking/paid-history semantics are staged but its UI is deliberately hidden pending deterministic two-process certification. Add Round and Direct Pay/KOT/Reminder/Receipt unchanged. Audit: `docs/audits/pos-workspace-ux-2026-08-03.md`. | built locally; browser/merge certification + deploy deferred |
| F3 | BRANCH-BOOTSTRAP-SNAPSHOT-1 → EDGE-SALE-CAPTURE-1 → OFFLINE-SYNC-ENGINE-1 → EDGE-BUILD-STRIPPER-1 → EDGE-BUILD-PACKAGING-1 → LOCAL-PRINT-LAN-1 → SYNC-EXCEPTION-DASHBOARD-1 → MODE-RECONCILIATION-1 → pilot | queued |

## 🔜 TRACK B — Manufacturing Finance Posting (moved to extreme end — after Offline POS)

Design: `docs/MANUFACTURING_FINANCE_POSTING_DESIGN.md` · backlog: `docs/MANUFACTURING_FINANCE_BACKLOG.md`. All gated behind per-tenant `manufacturing_posting_settings` (disabled by default). **Deferred: remaining MFG-FIN phases (F/E/H) handled only when needed, after the Offline POS track.**

| # | Item | Posting |
|---|---|---|
| B1 | ✅ **MFG-FIN-C** Consumption posting (built `92859f5`; **variant-null bugfix 2026-07** — stock lives under the default variant, so posting failed "Insufficient stock" on ALL normally-stocked materials until fixed; 15/15 QA rollback-clean incl. allow_negative_stock isolation) | Dr WIP 1420 / Cr Raw Material 1410 + `manufacturing_material_issue` stock out; strict settings-gate, idempotent, reversible |
| B2 | ✅ **MFG-FIN-D + G** FG receipt + WIP closing/variance (built in `9dd35fb`; concurrency, immutability, and state guards hardened by REPO-AUDIT-UX-GAP-1) | Dr FG 1430 / Cr WIP; residual WIP to variance 5300; stock receipt/reversal |
| B3 | MFG-FIN-F Manufactured FG COGS on sale | Dr COGS 5310 / Cr FG 1430 at captured FG cost |
| B4 | MFG-FIN-E Scrap / Rejection / Rework posting | 6900 / 6910 / relevant inventory or WIP account |
| B5 | MFG-FIN-H WIP/FG valuation + variance reports | read-only |

## 🔜 TRACK C — Department module optional phases (v1 complete)

| # | Item |
|---|---|
| C1 | DEPT-3B strict mode — optional flag: block sale when department custody short (today: exception-only, never blocks) |
| C2 | Wastage shadow — `wastage_shadow_consumption_out` supported by service, wastage flow not wired to call it |
| C3 | Department count barcode scan + "add zero-custody product" button (service `addLine()` exists, no UI) |
| C4 | Optional approved branch adjustment from approved dept count (behind safe flag — deliberately skipped in DEPT-4) |

## 🔜 TRACK E — Client-requested features (queued 2026-07-10)

| # | Item | Design notes | Size |
|---|---|---|---|
| E1 | ✅ **DELIVERY-CHANNELS-1** — delivery channel + rider attribution | Tenant tables `delivery_channels` + `delivery_riders`; `sales_orders.delivery_channel_id/delivery_rider_id`; POS delivery channel picker with own-delivery rider requirement; held-sale recall support; admin screens under Sales; sales-by-channel and rider deliveries reports; receipt/KOT payload visibility; permissions/routes/module keys wired; demo seeders populate channels, riders, and sample delivery sales. **Production deployed 2026-07-13** (`4a1423f`, deploy blocker fix `6a5c6df`): 7/7 tenant schemas and permissions green; Own Delivery rider validation, aggregator no-rider flow, held recall, reports/CSV, browser receipt and ESC-POS payload verified; all tenants `tb_diff=0`, official/dept negatives `0`. Later phase: channel-specific pricing/menus + aggregator settlement reconciliation | ✅ prod verified |
| E2 | ✅ **NEGATIVE-STOCK-SETTING-1B (2026-07)** — `branches.allow_negative_stock` (default OFF everywhere); opt-in `allowNegative` param on `postOutFefo`/`postMovement` (design doc `docs/audits/negative-stock-setting-design-2026-07.md` §7); ONLY sale family passes it (POS/manual sale + recipe + modifier consumption) — wastage/adjustment/count/transfer/purchase-return/manufacturing still block; batch-less negative leg with 5-step cost-fallback chain (no silent zero COGS); POS amber Backorder badge + toast + checkout Swal confirm; Negative Stock report (`/reports/inventory/negative-stock`: current negatives + balance_after<0 crossing audit + CSV); RELEASE_CHECKLIST smoke redefined branch-aware. QA 15/15 pass with rollback | done |

## 🔜 TRACK D — Catalog polish (quick wins)

| # | Item |
|---|---|
| D1 | Professional category tree seed + tree-view UI (schema `parent_id` ready) — Raw Materials / Packing / Semi-Finished / Finished / Consumables / Scrap |
| D2 | Unit `base_factor` tooltip/help text on units form |
| D3 | Variant manager UI (storage complete; every product has one default variant) — LOW until a client needs size/color |

---

## ⚠️ SYSTEM GAP REGISTER (found in gap analysis 2026-07-10)

### G1 — Functional gaps
| Gap | Detail | Priority |
|---|---|---|
| Purchase Returns | ✅ **PURCHASE-RETURNS-1 (e98928b, 2026-07-12)**: full document flow (draft→post immutable), GRN-sourced returnable tracking + standalone mode, stock out via InventoryService (FEFO), supplier ledger credit + GL Dr 2100/Cr 1400 (bill mirror), report with by-supplier/by-reason. v1 scope notes: per-bill paid/balance untouched; line batch informational (FEFO out) | ✅ was HIGH |
| No credit notes / store credit | Sales-return refund is cash-out only; no credit-note issuance for later use | MED |
| No period/fiscal closing lock | Anyone can post backdated documents forever; no month/year close | MED |
| Unit conversion not applied on consumption/purchasing | Wastage/PO record a unit but qty is never converted (recipe cost report converts; live stock ops don't) | MED |
| No loyalty / gift cards | Customers exist; no points program | LOW |
| No auto-reorder suggestions | Low-stock report exists; no suggested-PO generation | LOW |
| Multi-currency partial | Currencies table exists; reports/documents assume single currency | LOW |
| "FBR-ready" marketing vs reality | No actual FBR e-invoicing/fiscal integration built | MED (before PK compliance clients) |

### G2 — Security gaps
| Gap | Detail | Priority |
|---|---|---|
| No login throttling (A1) | Central + tenant logins unprotected | **HIGH** |
| ~~POS tile XSS~~ | product name/SKU rendered unescaped into innerHTML — **FIXED 2026-07-10** (escapeHtml) | fixed |
| No 2FA | Especially central superadmin | MED |
| No admin activity/audit log | Central panel actions (plan edits, resets, restores) unlogged | MED |
| Static demo print-agent token in repo | Rotate pattern for prod agents | LOW |

### G3 — Data-safety / ops gaps
| Gap | Detail | Priority |
|---|---|---|
| Scheduled backups + offsite | Nightly 02:00 `tenants:backup --prune` schedule now registered behind `BACKUP_SCHEDULE_ENABLED` (✅ code, 2026-07-11) — **manual: enable flag on prod + set up offsite sync per docs/ops/BACKUP_AND_RESTORE_RUNBOOK.md** | 🟡 was HIGH |
| Queue worker missing | Supervisor config + runbook at docs/ops/QUEUE_WORKER_SETUP.md; deploy.sh runs `queue:restart \|\| true` (✅ docs, 2026-07-11) — **manual: install supervisor on prod** | 🟡 was HIGH |
| No error monitoring / alerting | No Sentry/uptime checks; failures found by users | MED |
| Single 1vCPU/2GB droplet | No capacity plan for real multi-tenant load; MySQL+PHP same box | MED |
| Backup retention policy | tenant_backups grow unbounded | LOW |

### G4 — Engineering gaps
| Gap | Detail | Priority |
|---|---|---|
| **Minimal automated tests** | only framework health/unit placeholders exist; posting flows rely on rollback-clean integration harnesses | HIGH (long-term) |
| POS payload scales poorly | ALL products+variants+barcodes+branch-prices+modifiers serialized into the page — fine at 78 SKUs, will crawl at 5-10k SKU marts; needs pagination/caching/AJAX | MED |
| No POS offline mode | Connectivity loss stops billing — common requirement for marts | MED (big) |
| Localization incomplete | languages/ar RTL scaffolding exists; blades hardcoded English | LOW |
| Tenant offboarding/export | No data-export or archival flow for leaving clients | LOW |
| No central "login as tenant" support impersonation | Support has to ask for credentials | LOW |

---

## Recommended execution order
1. **PROD-READINESS-1** = A1+A2+A4 + queue worker (G3) + scheduled offsite backups (G3) — one hardening sprint
2. **A3** merge → main + tag
3. **PURCHASE-RETURNS-1** (G1 high gap — completes the purchasing cycle)
4. **DELIVERY-CHANNELS-1 (E1)** + **NEGATIVE-STOCK-SETTING-1 (E2)** — client-requested, restaurant/mart operations
5. **Manufacturing next:** audit review/deploy, then MFG-FIN-E scrap/rejection or MFG-FIN-F manufactured COGS as separate approved sprints
6. Track C/D + remaining gaps as client demand dictates
