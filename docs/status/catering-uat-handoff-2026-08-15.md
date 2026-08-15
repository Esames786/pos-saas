# Catering / Kashif Kitchen — session handoff, 2026-08-15

**Read this first if you are picking up the Catering workstream.**

Production HEAD at handoff: **`55949b2`** on `feat/14d-2-plan-upgrade-requests`.
Kashif Kitchen status: **LIVE UAT — GREEN**. Not customer-production-ready (see §7).

---

## 1. Ground rules that still apply

Carried from the owner's standing instructions. These are not negotiable.

- **Canonical branch is `feat/14d-2-plan-upgrade-requests`.** Production must never
  be left running from `release/catering-go-live-2`.
- **Forbidden on production, always:** `system:reset`, `migrate:fresh`, `db:wipe`,
  `DROP DATABASE`, `TRUNCATE`, `demo:reset`, reprovisioning an existing tenant,
  restoring a local/demo DB over a live one, editing live financial or stock rows
  by hand.
- **Never** print, copy, commit or echo `.env` values, DB credentials, the SSH key
  or its passphrase. Never stage `FakePrinter.exe`.
- **Khatri Biryani is a live restaurant.** Do not enable Catering there. Do not
  create test transactions on it. Do not use it for Catering testing.
- **Preserve on Kashif:** Estimate #8353, its 28 July 2026 event date, products,
  Urdu translations, recipes, rates, printer mappings, users.
- Deploy only with a verified backup first. Split commits: PLATFORM vs CATERING.

**Outstanding operator action (not ours):** the production root password was
exposed in chat earlier in this workstream and still needs rotating.

---

## 2. What this session changed

Three commits, all deployed.

| Commit | Kind | What |
|---|---|---|
| `5202c95` | docs | Recorded PLATFORM-ENTITLEMENT-BOUNDARY-1 in the shared-platform changelog |
| `3fb2ae9` | PLATFORM | Product index/form can mount at a third path via `$contextBase`; catalog banner made entitlement-honest |
| `55949b2` | CATERING | False finance labels, tooltips, print margins, Materials screen, Guide, date/time enhancement |

### 2.1 The two defects that mattered most

**False finance labels.** The event screen claimed *"Operational records only in
V1 — no accounting entries are posted"* and *"V1 posts no accounting entries"*.
Both were untrue. `CateringAdvanceService` posts a journal entry **and** moves the
cash/bank balance; `CateringFinalInvoiceService` posts revenue/receivables and
applies advances. An operator trusting those labels would have misread their own
books. Fixed, and every consequential button now carries a tooltip naming its
effect before the click.

**Materials were unreachable.** All 14 of Kashif's raw materials were created with
`is_pos_visible=0`, `is_sellable=0`, `can_be_bom_component=0`,
`product_kind=sale_item`. The catalog list requires at least one of
*pos_visible / sellable / recipe / service*; the manufacturing list requires
*bom_component / bom_output / finished_good*. They matched neither, so they
appeared on **no screen at all** — searching "chicken" returned only the dish.
They survived only because Recipes and the Rate Book hold them by ID.

### 2.2 Everything else in `55949b2`

- **Print margins**, all three documents (estimate, final invoice, kitchen sheet).
  `@page` margin applies to paper only and `body` was `margin:0` with no padding,
  so on screen every document sat flush against the browser edge. The fixed Print
  button overlapped the header — in Urdu it flipped left and covered the estimate
  number. No page-break rules existed on estimate/invoice, so a 15-line estimate
  split rows and orphaned its totals. Screen now draws a real A4 sheet, undone
  under `@media print` so paper margins are never doubled. Urdu leading raised
  (2.0, 2.1 on the kitchen sheet) because Nastaliq descenders clip at Latin
  line-height.
- **Catering › Materials** — reuses ProductController's manufacturing context
  under the catering module key. Only the route name and redirect differ.
- **Catering › Guide** — EN/اردو manual: 10-step flow with per-step
  finance/stock/print/email impact, where-to-manage-what, status meanings, quoted
  vs actual FEFO costing, and an honest "what does not work yet". Renders with
  zero data by design.
- **Event date/time** — progressive enhancement, deliberately **not** a picker
  library (branch terminals run offline; a CDN widget fails exactly where it is
  needed). Adds weekday readout, past-date and before-booking-date warnings,
  quick chips, and an inline **clash warning** for bookings already held that night.

---

## 3. Gates at handoff

| Gate | Result |
|---|---|
| Full MySQL suite | **347 / 2131** — zero skips, zero exclusions |
| Fast suite | **187 / 31868** |
| Compiled-Blade PHP lint (`tests/Unit/CompiledBladeSyntaxTest`) | PASS |
| Catering render + entitlement matrix | 3 tests / 44 assertions |
| `git diff --check` | clean |
| Pint | `ProductController` fails **identically at HEAD** — pre-existing, not introduced |

**`view:cache` is NOT a gate.** It compiles Blade to PHP but never validates the
PHP it emits; it reported success on two views that could not parse and 500'd in
production. `CompiledBladeSyntaxTest` is the real signal. Do not regress this.

### Test commands

```bash
export PATH="/d/laragon2/bin/php/php-8.3.16-Win32-vs16-x64:/d/laragon2/bin/mysql/mysql-8.0.30-winx64/bin:$PATH"
cd /d/laragon2/www/pos-saas
export DB_DATABASE=pos_test_master_cat EDGE_TEST_TENANT_DB=pos_test_tenant_cat EDGE_TEST_LOCAL_DB=pos_test_edge_local_cat
vendor/bin/phpunit -c phpunit.mysql.xml          # full MySQL suite
vendor/bin/phpunit                               # fast suite
```

Do **not** edit files while a suite runs — the Blade lint test reads views at
runtime and a mid-write file produces a false failure.

---

## 4. Deployment record

- Backup **`backups/20260815_123344`** — 10 SQL dumps, none zero-size, SHA-256 manifest
- Zero incoming migrations → **code-only** deploy
- `deploy.sh` exit **0**, **9/9 tenants OK**, 0 pending migrations
- Deployed HEAD **`55949b2`**

### Post-deploy verification

| Check | Result |
|---|---|
| Entitlement matrix (Kashif vs Khatri) | exact — Kashif ALLOW guide/materials/catering/products/printers, DENY pos/manufacturing/KOT-routing; Khatri the inverse |
| Live render: guide EN/UR, materials, event form, estimate doc | all OK (35.6k / 37.4k / 36.2k / 25.8k / 13.1k bytes) |
| **Estimate #8353** | intact — 1,485,800.00, `EV-20260815-0001`, 15 lines, 28 Jul 2026 |
| Trial-balance difference, all 9 tenants | 0.00 |
| Orphan journal lines, all 9 tenants | 0 |
| ERROR/CRITICAL in log since deploy | 0 |

Khatri's sales rose 122 → 124 and journals 144 → 149 across the deploy window.
That is **real business activity** on a live restaurant, not a side effect.

---

## 5. Open item requiring an owner decision

**Kashif's 14 materials are still misclassified.** The new Materials screen will
render **empty** until `can_be_bom_component=1` and `product_kind=raw_material`
are set on them.

This is metadata only — no transaction, stock row or ledger entry references
those flags, and the products were created by us, not by the customer. It was
raised with the owner twice and has not been answered, so the data was left
untouched. **Do not change it without an explicit instruction.**

---

## 6. Agreed backlog, not yet built

In the owner's priority order. Items 1–4 and 8 are done; these remain:

| # | Item | Notes |
|---|---|---|
| 5 | Print options on Estimate + Final Invoice — manual / network / thermal | Only the kitchen sheet reaches the network today |
| 6 | Manual "Email to Customer" button + resend | No manual send exists; email only rides along with send/advance/invoice |
| 7 | Cancel reason field + explicit advance handling on cancel | Cancel works but records no reason |
| 9 | **SMTP configuration** | ⚠️ **Blocked on the owner** — needs a real mail account |

---

## 7. Honest limitations — do not overclaim

- **Email delivers nothing.** Production runs `MAIL_MAILER=log`. Estimate-sent,
  advance and final-invoice mails are written to `catering_email_logs` and to the
  Laravel log, and reach no customer. Reminders (D-7/D-3/D-1/same-day) depend on
  the same dead transport **and** on the scheduler running `catering:reminders`.
- **Urdu cannot print on thermal.** The ESC/POS builder emits no codepage and no
  raster commands. Urdu is A4/browser only. Making it work means rendering text to
  an image and sending raster ESC/POS — a separate project, explicitly out of scope.
- **Kashif recipes and material rates are UAT placeholders**, owner-acknowledged.
  Real costing data is required before any production claim.
- **Business-flow UAT not yet exercised** on real bookings: send → confirm →
  advance → final invoice → closure.
- `PLATFORM-OWNER-ENTITLEMENT-PERMISSIONS-1` remains open — `deploy.sh` grants the
  Owner every `tenant.*` permission regardless of plan, so `@can` alone is **not**
  an entitlement decision. Route-level checks cover it today; the permission sync
  should eventually be made entitlement-aware.

---

## 8. Carried-forward chores

- Cherry-pick the PLATFORM commits (`1da0bc4`, `3fb2ae9`) into
  `feat/edge-config-refresh-v1` and `feat/cloud-billing-onboarding-v1`. Never merge
  feature branches together; never carry Catering code into them.
- Add changelog rows for `3fb2ae9` once those cherry-picks land — the
  PLATFORM-ENTITLEMENT-BOUNDARY-1 row still reads *pending cherry-pick*.
- Create a separate future-dated UAT/TEST event on Kashif for reminder testing,
  with a safe internal contact. It must not alter Estimate #8353 or its date.

---

## 9. Architecture reminders

Authorities that must never be forked or bypassed:

- `InventoryService::postIn / postOutFefo / transfer` — sole stock mutator.
  In Catering, **only** `CateringMaterialIssueService` calls it.
- `JournalPostingService` — only approved GL entry point, idempotent per
  `(source_type, source_id)`. In Catering: advances, material issues, final invoices.
- `TenantSubscriptionAccessService` — entitlement authority.
  `Module::forRouteModuleKey()` resolves a key to exactly **one** module, which is
  why `ROUTE_ANY_OF_MODULES` exists for genuinely shared routes.
- `PermissionSyncService::moduleKey()` — first 2 dot segments, plus
  `MODULE_KEY_OVERRIDES` for routes narrower than their prefix.
- Catering never writes `sales_orders`, never touches POS KOT mappings, and posts
  nothing at all while an estimate is a draft. Tests enforce all three.
