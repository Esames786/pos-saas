# Bingoo POS — Roadmap & System Gap Register

> Maintained working document. Update after every completed sprint.
> Last updated: **2026-07-16** (REPO-AUDIT-UX-GAP-1 local audit; production not deployed) · branch `feat/14d-2-plan-upgrade-requests`

---

## ✅ Completed (major tracks)

| Track | Status |
|---|---|
| Core POS, inventory, purchasing, restaurant, printing, KDS | ✅ live |
| Finance (FIN-1..12: CoA, journals, TB/P&L/BS, receivables/payables) | ✅ live |
| SaaS platform (plans/modules/limits, billing+proof, upgrade requests, self-signup, 6 public demos) | ✅ live |
| Manufacturing foundation (MANUF-1..10, planning/tracking only) | ✅ live |
| Manufacturing finance (A-D + G: settings/infrastructure, consumption, FG receipt, WIP close/variance) | ✅ built; audit-hardened locally, production deployment pending |
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

## 🔜 TRACK B — Manufacturing Finance Posting (biggest functional work)

Design: `docs/MANUFACTURING_FINANCE_POSTING_DESIGN.md` · backlog: `docs/MANUFACTURING_FINANCE_BACKLOG.md`. All gated behind per-tenant `manufacturing_posting_settings` (disabled by default).

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
