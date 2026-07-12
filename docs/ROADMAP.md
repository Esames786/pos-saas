# Bingoo POS — Roadmap & System Gap Register

> Maintained working document. Update after every completed sprint.
> Last updated: **2026-07-10** · prod = `1d371f2` · branch `feat/14d-2-plan-upgrade-requests`

---

## ✅ Completed (major tracks)

| Track | Status |
|---|---|
| Core POS, inventory, purchasing, restaurant, printing, KDS | ✅ live |
| Finance (FIN-1..12: CoA, journals, TB/P&L/BS, receivables/payables) | ✅ live |
| SaaS platform (plans/modules/limits, billing+proof, upgrade requests, self-signup, 6 public demos) | ✅ live |
| Manufacturing foundation (MANUF-1..10, planning/tracking only) | ✅ live |
| Manufacturing finance infra (MFG-FIN A+B: settings, CoA 1410-6920, posting-state columns) — **no posting yet** | ✅ live |
| Department module (DEPT-1..5: mapping, custody stock, shadow consumption, counts/reconciliation, dashboard) | ✅ live |
| Purchasing UX + portal-wide UX hardening (AJAX pickers, batch dropdowns, shortcuts, Swal, POS images/colors) | ✅ live |
| Tenant ops (backup/restore/sync/reset from central panel) + provisioner config-cache fix | ✅ live |

---

## 🔜 TRACK A — Production readiness for real clients (DO FIRST)

| # | Item | Why | Size |
|---|---|---|---|
| A1 | **PRD-6 hardening**: login throttle, payment-proof upload validation (mime/size), dev-artifact cleanup, RELEASE_CHECKLIST.md | brute-force + upload abuse exposure | S |
| A2 | **SMTP**: prod `MAIL_MAILER=log` — signup/password-reset emails are silently never sent | real clients can't reset passwords | S |
| A3 | **Merge → main + tag `v0.9.0-pilot`** — ~60 commits live from feature branch, main is stale | release discipline / rollback point | S |
| A4 | **deploy.sh hardening**: append per-tenant spatie cache-row delete + `chown -R www-data storage bootstrap/cache` (recurring manual gotchas) | every deploy hits these by hand | S |
| A5 | Ops: confirm nightly `demo:reset-all` green end-to-end; SSL auto-renew via Cloudflare (cert expires ~Sep 2026); rotate root + MySQL passwords; re-register client tenant lost in system:reset | | S |

## 🔜 TRACK B — Manufacturing Finance Posting (biggest functional work)

Design: `docs/MANUFACTURING_FINANCE_POSTING_DESIGN.md` · backlog: `docs/MANUFACTURING_FINANCE_BACKLOG.md`. All gated behind per-tenant `manufacturing_posting_settings` (disabled by default).

| # | Item | Posting |
|---|---|---|
| B1 | **MFG-FIN-C** Consumption posting (next) | Dr WIP 1420 / Cr Raw Material 1410 + `manufacturing_material_issue` stock out |
| B2 | MFG-FIN-D/E FG receipt + WIP closing/variance | Dr FG 1430 / Cr WIP; variance to 5310 |
| B3 | MFG-FIN-F Manufactured FG COGS on sale | Dr COGS / Cr FG at FG cost |
| B4 | MFG-FIN-G Scrap / Rejection / Rework posting | 6900 / 6910 / 6920 |
| B5 | MFG-FIN-H WIP/FG valuation + variance reports | read-only |

## 🔜 TRACK C — Department module optional phases (v1 complete)

| # | Item |
|---|---|
| C1 | DEPT-3B strict mode — optional flag: block sale when department custody short (today: exception-only, never blocks) |
| C2 | Wastage shadow — `wastage_shadow_consumption_out` supported by service, wastage flow not wired to call it |
| C3 | Department count barcode scan + "add zero-custody product" button (service `addLine()` exists, no UI) |
| C4 | Optional approved branch adjustment from approved dept count (behind safe flag — deliberately skipped in DEPT-4) |

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
| **Purchase Returns is a Coming-Soon stub** | `purchase_return` enum value + route exist, but NO controller/screen/posting — supplier returns impossible | **HIGH** |
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
| **No scheduled backups + no offsite copy** | Backups are manual (panel/command) to LOCAL disk only — droplet loss = total loss | **HIGH** |
| **Queue worker missing** | `QUEUE_CONNECTION=database`, no worker/supervisor on prod → anything queued (future mail!) silently never runs | **HIGH** (bundle with A2) |
| No error monitoring / alerting | No Sentry/uptime checks; failures found by users | MED |
| Single 1vCPU/2GB droplet | No capacity plan for real multi-tenant load; MySQL+PHP same box | MED |
| Backup retention policy | tenant_backups grow unbounded | LOW |

### G4 — Engineering gaps
| Gap | Detail | Priority |
|---|---|---|
| **Zero automated tests** | tests/ has only ExampleTest; all QA is manual/tinker scripts | HIGH (long-term) |
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
4. **MFG-FIN-C** and onward (Track B)
5. Track C/D + remaining gaps as client demand dictates
