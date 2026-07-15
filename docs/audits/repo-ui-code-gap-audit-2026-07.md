# Repository, UI, and Code Gap Audit - July 2026

## Executive summary

REPO-AUDIT-UX-GAP-1 reviewed the latest 50 commits, project documentation and
Claude handoff memory, route/permission/module wiring, 338 Blade files, shared
posting/inventory services, seeders, and critical authenticated screens. Safe,
deterministic defects were fixed. No destructive reset, tenant wipe, demo reset,
production deployment, or new manufacturing phase was performed.

The most important corrections are manufacturing posting concurrency and
immutability guards, WIP-close state enforcement, stale route-catalog cleanup,
missing report navigation, and escaping of dynamic POS/plan-preview HTML. The
existing consumption, finished-goods receipt, and WIP-close postings were
verified in rollback-only integration tests with balanced journals and exact
stock restoration.

## Last 50 commits reviewed

- Range: `b32b3a0..8719858` (2026-06-27 through 2026-07-16).
- Scope: 229 files, 22,303 insertions, 2,085 deletions.
- Major work: product boundaries, manufacturing consumption/FG/WIP posting,
  kitchen costing, modifiers, product UX, plan builder, tenant operations,
  departments, purchasing UX, production hardening, purchase returns, delivery
  channels/riders, and branch-aware negative stock.
- Areas touched: `app` 104 files, `resources` 82, `database` 27, `docs` 9,
  `routes` 3, `config` 2, plus `deploy.sh` and `.env.example`.
- Eighteen migrations were reviewed: one master backup migration and seventeen
  additive tenant migrations for product roles, manufacturing, recipes,
  modifiers, purchasing, departments, returns, delivery, and negative stock.
- Most frequently changed shared files: `TenantProvisioner` (13 commits),
  `routes/tenant.php` (12), `TenantDemoSeeder` (10), sidebar (9), `ROADMAP.md`
  (9), and `MasterSeeder` (8). POS, SalesService, JournalPostingService, product
  forms/controllers, and report services were treated as higher-risk surfaces.
- Deployment gotchas: route cache must be cleared before route sync; tenant
  permission caches require per-database clearing; a release changing
  `deploy.sh` must execute the newly pulled script/steps a second time.

## Documentation and memory read

Reviewed README files; all files under `docs`, `docs/ops`, and `docs/audits`;
manufacturing design/backlog; negative-stock design; release, SMTP, queue,
backup/restore, destructive-command, and production handoff notes. Claude
project memory (`MEMORY.md`, next roadmap, production deployment, handoff, and
infrastructure notes) was also reviewed. No repository `CLAUDE.md`, `AGENTS.md`,
or `CODEX.md` exists.

Manufacturing docs incorrectly said all posting was future work. They now state
that A/B/C/D/G are implemented and E/F/H remain. Historical phase notes are
retained as historical context.

## Route, permission, module, and sidebar audit

- Current router: 573 routes, 571 named; 500 named tenant routes and 58 named
  central routes. The two-route difference includes the intentional unnamed
  tenant-root redirect.
- Route catalog now contains exactly the 571 current named routes. Stale
  `generated::*` entries are skipped and pruned by `PermissionSyncService`.
- Central permission seed is exact: 52 required and 52 seeded.
- Tenant provisioner permission coverage is exact after excluding documented
  API/printing bypasses. All seven active tenant Owner roles hold 500/500 named
  tenant permissions.
- Route module keys are covered for commercial modules except Manufacturing.
  Manufacturing currently fails open because no `manufacturing` module exists
  in the central module/plan registry. This needs an explicit pricing and plan
  migration decision and was not silently invented in this audit.
- Quotation and purchase-requisition Coming Soon routes are intentional.
- Added sidebar discovery for Negative Stock and Purchase Returns Report and
  corrected parent/report active-state permission coverage.

## UI/UX audit findings

- Audited the 338-file Blade inventory, with focused review of POS, products,
  branches, delivery, returns, stock operations, departments, purchasing,
  manufacturing, finance, billing/plans, users/roles, and reports.
- Authenticated render smoke returned HTTP 200 for 23 critical enterprise-tenant
  screens. No 403, 500, missing permission, or completed-feature Coming Soon
  response was found.
- Posted/reversed manufacturing documents previously exposed Edit/Cancel paths.
  Actions are now hidden in the views and rejected server-side.
- WIP showed a close action too early and described posting as unavailable. The
  close action now appears only at `ready_for_completion`, with current help text.
- Negative Stock and Purchase Returns Report existed but were hard to discover;
  both now appear in Reports navigation for authorized users.
- The large POS redesign was not revisited; its functional layout and payment
  modal were covered by render and regression inspection.

## Code quality and security findings

- Dynamic values inserted through POS template strings were incompletely
  escaped. Product/promotion, held-order, table-session, print-job, and preview
  values now use HTML escaping; numeric identifiers are normalized and preview
  windows use `noopener`.
- Central plan live-preview module labels and limits are now escaped.
- Consumption and FG post/reverse flows now lock the source document row and
  repeat state/journal/stock idempotency checks inside the tenant transaction.
- WIP closing now locks and revalidates the row and requires the business-ready
  state. Concurrent requests can no longer pass only an earlier stale check.
- No debug `dd`, `dump`, or new secret was introduced. Demo fallback credentials
  remain an intentional public-demo configuration concern, not production auth.

## Finance and inventory safety findings

Rollback-only integration verification on Finance Demo covered:

- Consumption post: stock out, `Dr WIP / Cr Raw Material`, balanced TB.
- Consumption duplicate post/reverse blocked; reversal restored exact stock.
- FG receipt post: stock in, `Dr FG / Cr WIP`, balanced TB.
- FG duplicate post/reverse blocked; reversal restored exact stock.
- Posted documents could not be cancelled through CRUD controllers.
- Premature WIP close from `in_progress` was blocked.

Across all seven active tenants: `tb_diff=0`, negatives on disallowed branches
`=0`, negatives on allowed branches `=0`, and department negatives `=0`.
Tenant migrations were current and no test transaction was committed.

## Fixes applied in this sprint

1. Stale route-catalog pruning and generated-name exclusion.
2. Manufacturing document locking, in-transaction idempotency rechecks, CRUD
   immutability guards, and WIP-ready close enforcement.
3. Accurate manufacturing UI and documentation status text.
4. POS and plan-preview DOM XSS hardening.
5. Negative-stock and purchase-return report navigation.
6. Release checklist self-update warning and manual production-readiness list.
7. Framework health test corrected to use `/up` rather than a database-backed
   public homepage under SQLite test configuration.

## Remaining backlog

### Must fix before real clients

- Decide and implement Manufacturing as a commercial module, map its route keys,
  assign it to intended plans, migrate existing subscriptions, and verify denial
  behavior. Current fail-open access is a commercial entitlement risk.
- Configure/verify real SMTP, queue Supervisor, scheduled plus offsite backups,
  TLS renewal, credential rotation, uptime/error monitoring, and restore drills.
- Build automated integration coverage for sales, inventory, returns, permissions,
  and manufacturing post/reverse invariants.

### Should fix soon

- Central admin activity/audit log and fiscal-period closing locks.
- Credit notes/store credit and live unit conversion in purchasing/consumption.
- Paginate/cache/AJAX-load the large POS product payload before large catalogs.
- Manufacturing E/F/H: scrap/rejection/rework, manufactured FG COGS, and
  valuation/reconciliation reports, each as a separately approved sprint.

### Nice to have

- 2FA, tenant export/offboarding, support impersonation, localization completion,
  loyalty/gift cards, and reorder suggestions.

### Large feature / separate sprint

- POS offline operation, FBR integration, multi-currency completion, and
  manufacturing standard costing/labour/overhead allocation.

## Risks not fixed and why

- Manufacturing entitlement was not auto-created because module pricing, plan
  assignment, and existing-customer migration are business decisions.
- No broad UI redesign was attempted; this audit only changed deterministic
  defects and navigation gaps.
- Frontend asset build could not run because this checkout lacks a callable local
  `vite` binary. No dependency install was performed during the safety audit.

## Verification results

- PHP syntax: all changed PHP/Blade files passed.
- `php artisan test`: 2 tests, 2 assertions, passed (suite remains minimal).
- Master and seven tenant migrations: current; nothing pending.
- Route sync, permission reset, seven tenant permission-cache clears: passed.
- `view:cache` and `route:cache`: passed; route cache cleared afterward.
- Authenticated HTTP-kernel smoke: 23/23 critical screens returned 200.
- `git diff --check`: passed.
- Generated local audit inventories: `storage/app/audit-route-list.txt` and
  `storage/app/audit-blade-files.txt`.

## Recommended next roadmap item

Review and deploy this audit commit through the normal release checklist. Then
make the Manufacturing commercial-module decision before starting another
posting phase. After entitlement is explicit, choose either Phase E
(scrap/rejection/rework) or Phase F (manufactured FG COGS) as a separate sprint.
