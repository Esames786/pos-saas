# Bingoo POS Work Summary: 2026-08-07 to 2026-08-12

**Audit date:** 2026-08-12 (Asia/Karachi)  
**Branch:** `feat/14d-2-plan-upgrade-requests`  
**Reviewed range:** `2dd32ee^..014a6f2`  
**Scale:** 122 commits, 322 files, 31,648 insertions, 1,220 deletions  
**Purpose:** requirement-to-delivery handoff after the Edge/offline, Khatri go-live,
POS integrity, printing, returns, reports, and platform-stability work.

This is a read-only engineering audit. No application code, migration, seeder,
configuration, or production transaction was changed while preparing it. The known
untracked local `tools/print-agent/dist/FakePrinter.exe` was left untouched.

> **Status addendum — 2026-08-13 (does not alter the historical audit below).**
> Two facts recorded above have since advanced; the body is kept as the point-in-time
> audit it was, and only this note reflects current reality:
> - The split-brain inventory fence (§2, §9, §13 item 1) **was subsequently built and
>   deployed** as `EDGE-SPLITBRAIN-STOCK-1` (`c4fc021`): one authoritative fence inside
>   `InventoryService` (all official paths), department custody sink secondary, both
>   transfer endpoints, Branch-Server always fails closed. It remains **dormant** —
>   `activation_ready=false`, no branch in Local Mode.
> - Production HEAD (§8, "not independently queried during this audit") **was
>   independently verified on 2026-08-13 to be `c4fc021`** after a dormant, Cloud-only
>   deploy: tracked tree clean, zero pending migrations, `APP_ROLE` Cloud, TB balanced,
>   Edge/Local Mode inactive.
>
> Current authoritative state lives in `docs/status/platform-checkpoint-2026-08-13.md`.
> Edge and Catering development now proceed in separate Git worktrees.

## 1. Executive status

The five-day period delivered two large bodies of work:

1. A substantial **Branch Edge/offline foundation**: restricted runtime artifact,
   local database/bootstrap, device-bound local authentication, canonical cross-system
   identities, local shifts/POS/table/KOT flows, and lease-safe local printing.
   These components are intentionally dormant. They are not permission to activate
   offline selling: `activation_ready=false` remains the controlling safety verdict.
2. A production-facing **Khatri Biryani and cloud POS hardening arc**: onboarding,
   menu and role setup, terminal/order-type scoping, customer and delivery workflows,
   KOT/Reminder/receipt routing, cancellation and discount approvals, delivery-charge
   accounting, returns, report reconciliation, printer-agent reliability, layout
   controls, shift closing, tenant context, permissions, and demo reset reliability.

The latest focused regression run is green:

- Unit: **18 tests / 132 assertions**.
- MySQL: **15 tests / 33 assertions** using `phpunit.mysql.xml`.
- The consolidated live-day record at `37a8e9f` reports the then-full gates as
  **184 fast tests / 31,641 assertions** and **275 MySQL tests / 1,618 assertions**.

No new confirmed application bug was found in the commits after that live-day record.
The open items in section 9 are release/operations gaps, explicitly deferred Edge work,
physical certification, or documentation drift.

## 2. Requirements mapped to delivered work

| Requirement / problem | Delivered behavior | Main evidence |
|---|---|---|
| Build an offline-capable branch runtime without exposing the SaaS | Fail-closed `APP_ROLE`, default-deny Edge routes/CLI, restricted reproducible artifact, local DB safety guard, immutable branch/device binding, bootstrap v4 | `2dd32ee` through the Edge runtime/auth/identity commits; `docs/audits/edge-*.md` |
| Never ship cloud passwords or PIN hashes to the branch server | Ed25519 one-time enrollment and Edge-only Argon2 credentials; device/epoch binding; local lockout and manager re-auth | `docs/audits/edge-local-auth-2026-08.md` |
| Give Edge records stable cloud/local identities | ULID-based immutable identities and resolvers for sales, lines, payments, shifts, sessions, KOT/cancellation/approval records | `docs/audits/edge-identity-2026-08.md` |
| Support local POS operations during disconnection | Local shift lifecycle, paid sales, operational stock, table sessions, held orders/Add Round, KOT business events and settlement authority | `docs/audits/edge-local-pos-2026-08.md` |
| Print locally without cloud polling | Lease-owned delivery, per-printer FIFO, retries, stale-lease recovery, worker supervision and truthful readiness | `docs/audits/edge-local-print-2026-08.md` |
| Keep cloud and Edge from mutating the same stock | Exposure was mapped, but the central inventory-service fence is not built; 14 of 16 official cloud stock paths remain unfenced | `267d512`, `docs/audits/edge-splitbrain-stock-mutator-matrix.md` |
| Onboard Khatri Biryani repeatably | Idempotent tenant setup, protected owner credentials, plan/modules, branches, terminals, users, roles, menu, categories, customers, charges, printers, layouts, mappings, floors and tables | `KHATRI-*` commits; `OnboardKhatriBiryaniCommand` |
| Restrict cashiers by branch, terminal and order type | Shared `UserDataScope`; POS selection validation; list/report scope; shift open/close restricted to assigned terminals; Delivery and Dine In roles separated | `dc0b870`, `b795405`, `6192092`, `014a6f2` |
| Make delivery operationally complete | Customer required, address book, channel/rider/vehicle capture, branch-locked delivery charge, rider reassignment audit, bold delivery details on print | `d0ac021`, `ce6f21f`, `f153d68`, `ca492f6`, `49694a8` |
| Prevent silent post-KOT item/order deletion | Separate cancellation policies and manager-code flows, immutable cancellation snapshots, cancellation KOT/Reminder output, default reasons and branch approval modes | `a91865f`, `439cf22` and POS/KOT integrity tests |
| Allow controlled POS discounts | Manual fixed/percentage discount with branch approval policy; short tender remains underpayment and cannot silently become discount | `bdfd1db` |
| Preserve direct-pay printing parity | Direct pay and held/add-round paths retain KOT delta, Reminder and final-receipt orchestration, retries and browser fallback semantics | printing orchestration tests and `docs/audits/print-routing-reminder-preflight-2026-08-03.md` |
| Make KOT routing reliable across kitchen stations | Per-category logical jobs, order-type/category mapping, combo routing, historical destination, duplicate/cancel/addition headings | `9c471cd`, `e28aa75`, KOT MySQL/unit tests |
| Improve printer-agent resilience | Transient retry, version-stamped download, keep-awake, print/poke exclusion, short parallel probes, registration cleanup and agent 2.3.x | `bdc6fcd`, `0409a5c`, `e1098ee`, `e5c471f`, `f4758c9` |
| Make printed output readable on 80 mm / 72 mm effective width | 42-column profile, ESC/POS scale bands, wrap at effective width, zero browser print margin, aligned money, column receipt/KOT/Reminder, rounded display only for whole rupees | `7138700`, `847f4fa`, `f4758c9`, `e4568c5`, `014a6f2` |
| Make layout controls actually control paper and preview | One toggle registry, document-specific switches, delivery samples, paper/preview parity, KOT branch and vehicle gates | `64eb58d` |
| Correct return accounting | Customer-facing return lines only, order/line locking, discount/tax proration, mandatory refund method, full-return delivery-charge reversal, GL/cash/shift/report parity | `85ebcfd`, `cedb073`, `e451202`, `6fbe2ba`, `e49ba3b`, `e5c471f` |
| Reconcile reports to real cash | Report Centre bridges billed sales, discounts, tax, delivery, returns and net sales; Dashboard, Shift and Sales Summary use the same population; return-date accounting preserved | `0ec2fc7`, `752daad`, `c66f254` |
| Make report/list filters respect user scope | Branch/order-type/terminal authority reused; Sales Orders and Returns have local-day From/To, Today and Yesterday; returns now terminal-scoped | `dc0b870`, `014a6f2` |
| Stop accidental zero-count drawer closes | Every close flow requires an explicit count; blank denomination arrays are not zero; Daily Closing groups by frozen business date | `81bfd77`, `37a8e9f` |
| Stop tenant context and permission leakage | Tenant identification precedes session/CSRF handling; permission collections clear on activation; per-request permission cache prevents warm-worker cross-tenant poisoning | `db9194f`, `3348b98`, `e17d6b5`, `267d512` |
| Make multi-demo resets reliable | Each tenant reset runs in its own process, pending demos on the explicit list can heal, failures no longer stop later demos | `04156b6`, `889b44b` |
| Remove obsolete queue jobs without false print history | `cancelObsolete` marks cancelled without print counters/timestamps/KOT side effects; retry remains available | `267d512` |

## 3. Daily delivery timeline

### August 7: runtime boundary, shift UX and accounting extensions

- Hardened the restricted Edge artifact and fail-closed application role.
- Made build provenance and physical artifact audits reproducible on Windows/Linux.
- Began local DB, migration, bootstrap and immutable-binding implementation.
- Added branch-oriented shift open/close UX while retaining per-terminal shifts.
- Added third-party department handover accounting and fixed report array filters.
- Seeded the demo restaurant's Receipt/KOT/Reminder path to the fake printer.

### August 8: local runtime, authentication and identities

- Closed bootstrap importer and local DB safety defects.
- Added device-bound local user enrollment and authentication without cloud secrets.
- Added canonical operational identities and migration/backfill contracts.
- Added real MySQL concurrency and authority proofs for the Edge foundation.
- Locked update/backup behavior so normal appliance updates can never wipe local history.

### August 9: local POS/print and Khatri go-live foundation

- Built local POS shifts, paid sales, stock authority, table/held/Add Round and KOT events.
- Built lease-safe local print transport and worker lifecycle; deployed dormant/cloud-safe.
- Kept Local Mode inactive and activation readiness false.
- Added Khatri onboarding, report/permission centers, menu and users.
- Added delivery-charge accounting, network KOT routing, owner-password preservation,
  vehicle number support and initial printer automation.

### August 10: Khatri counter UX and operational controls

- Corrected receipt change, auto-cut and branding.
- Improved POS tile/category readability and print-format parity.
- Added the customer/address workflow and branch-locked delivery charge.
- Added Khatri child-category menu structure and a compact POS context bar.
- Added Report Centre sections, combo/cancellation parity and permissions.
- Added guarded tenant transaction reset that preserves master data.
- Added Owner System Reset and draft cash-shortage expense handling.
- Finalized the two-printer, named-terminal and delivery-counter setup.

### August 11: live POS integrity, returns, money and printing

- Hardened submissions, branch/terminal/order-type scope and double-submit/fallback paths.
- Fixed combo KOT routing and linkage for modifiers/splits.
- Required customers on delivery and corrected cancellation manager-code policy.
- Added manual discount approval and fixed previously silent customer-modal buttons.
- Added audited rider reassignment and consistent historical timezone display.
- Refused zero-quantity sale lines.
- Rebuilt return accounting, made refund method mandatory and added delivery-charge
  reversal for full-order returns.
- Reconciled Report Centre, Dashboard, Shift and Sales Summary to net sales.
- Reworked 72 mm thermal reports and production printer profiles.
- Hardened Print Agent retries, installer cache/version behavior, keep-awake and locking.
- Connected layout font size to actual ESC/POS commands and corrected print margins.

### August 12: closing integrity, tenant stability and final layout/report polish

- Fixed layout-toggle data loss and made preview/paper controls share one registry.
- Required explicit drawer counts and moved Daily Closing to frozen business date.
- Fixed raw UTC timestamps on Print Jobs.
- Moved tenant identification before session/CSRF and eliminated cross-tenant permission
  cache poisoning.
- Isolated demo resets by process and made reset-all resilient.
- Added safe obsolete-job dismissal and repaired two known false print-history rows under
  backup/fingerprint controls.
- Restricted shift opening/closing to assigned terminals and created a distinct Dine In role.
- Restored column-based receipt/KOT/Reminder layouts, AM/PM timestamps, combo/modifier
  preview parity, whole-rupee display formatting and boxed item rows.
- Added Sales Orders/Returns date filters and terminal-scoped return lists.
- Added Khatri floors/tables to the onboarding seeder.

## 4. POS behavior now

- The workspace is compact and uses internal scrolling; table operations are available in
  the POS workspace. Merge UI remains intentionally hidden until its race certification.
- Dine-in uses one table session as the bill authority while held orders/add rounds remain
  auditable rounds beneath that session.
- Changing order context clears stale cart/recall/customer state.
- Delivery cannot hold or pay without an attached customer.
- KOT is delta-based: only new quantities are sent. Reprints carry duplicate/copy metadata.
- Sent items are cancelled through explicit cancellation flows, not silently removed.
- Branch policy decides whether item reduction, whole-order cancellation or manual discount
  needs manager approval.
- Review & Pay preserves KOT/Reminder/receipt behavior when Hold was skipped.
- Busy-button protection covers the important POS write actions; server-side locking and
  idempotency remain the final authority.

## 5. Printing architecture now

There are three separate output paths and they must remain distinct:

1. Network ESC/POS KOT/Reminder through queued jobs and the Print Agent.
2. Network ESC/POS final receipt through the same transport.
3. Browser bill preview/print through the workstation's browser/Windows driver.

Current Khatri configuration is based on 80 mm paper, 72 mm effective width and 42
characters at normal scale. The BlackCopper and XPrinter routes are seeded without
overwriting onsite IP addresses. Queue transport is at-least-once: logical keys,
copy numbers and history make duplicates explicit, but ESC/POS cannot provide a true
exactly-once physical-print guarantee.

The formal controlled LAN certification document remains open even though production
printing has operated successfully. That distinction matters: live success is operational
evidence, not the full disconnect/reconnect/two-terminal/paper-size certification matrix.

## 6. Returns, finance and reports

- A partial return refunds selected item value and its allocated discount/tax; delivery
  charge remains because the trip happened.
- A full customer-facing return also reverses the delivery charge and its income GL.
- Refund method is mandatory, so cash/bank movement cannot be stranded in an unclear account.
- Component/modifier rows cannot determine whether the customer-facing order is returned.
- Return posting locks the order and lines, preventing concurrent over-return.
- Reports show service/delivery/tax/discount bridges and reconcile to Net Sales.
- Returns belong to the date the return was posted in Report Centre. A historic Daily
  Closing is a frozen drawer snapshot and is not recomputed by a later return.
- Sales Orders and Sales Returns now support branch-local date ranges and quick local-day
  filters. Terminal-bound users see only assigned-terminal rows; unbound Owner/Manager
  users retain their wider authority.

## 7. Production data corrections recorded in the reviewed history

The existing live-day chronicle records four bounded Khatri corrections, each backed up,
precondition-guarded and fingerprint-verified:

1. Correcting GL entries for returns that had no refund method: PKR 1,530.
2. Delivery-charge refunds for three full returns: PKR 350 total.
3. Correcting Shift #1 counted cash from an accidental blank-as-zero close and resizing
   the draft shortage expense from PKR 28,400 to PKR 10,050.
4. Creating the August 11 Daily Closing through the deployed controller without duplicating
   the shortage voucher.

Commit `267d512` additionally records a guarded correction of print jobs #55/#63 from
false `printed` history to `cancelled`, reversing only their false counter/timestamp effects.

This audit did not connect to production and did not repeat or alter any correction.

## 8. Deployment and verification boundary

- `docs/status/khatri-live-day-2026-08-11.md` confirms production was deployed and verified
  at `37a8e9f`, with reconciled books and green full suites at that point.
- Several later commit messages record targeted production corrections or role changes,
  but current production HEAD was **not independently queried during this audit**.
- Therefore `014a6f2` is confirmed as the current local branch HEAD, not asserted as the
  currently deployed server HEAD.
- The post-chronicle focused tests run during this audit passed:
  - Unit: layout toggle registry, timezone display, tenant permission cache, middleware
    priority, KOT and Reminder payloads.
  - MySQL: obsolete-job dismissal, return-list scope/date filters, terminal shift authority,
    and tenant login/CSRF context.

## 9. Open gaps and risks

| Priority | Gap / risk | Required action |
|---|---|---|
| P0, dated | Wildcard TLS certificate expires 2026-09-17; current manual DNS-01 profile cannot auto-renew | Complete the runbook by **2026-09-01**; prefer Namecheap API automation, retain manual fallback |
| P0 before Edge activation | 14 of 16 official cloud stock mutation paths are unfenced | Implement one authoritative fence inside `InventoryService`, then re-run the mutator matrix |
| P0 before offline selling | Edge sync ingestion/outbox completion, activation fencing, backup/restore/update appliance and recovery gates are incomplete or planned | Keep `activation_ready=false`; complete and certify these tracks before Local Mode |
| P1 | Formal physical LAN printer matrix is still incomplete | Certify KOT/Reminder/receipt, retry/reconnect, duplicate/cancel, two terminals and both 80 mm printers on real paper |
| P1 | Latest commits after `37a8e9f` lack one consolidated production-head record | On the next approved deploy, record server HEAD, migrations, focused smoke results and printer-agent version without changing transaction data |
| P1 | New receipt columns/boxing and report filters are automated-test green but need final operator/paper acceptance | Test long Khatri names, combos, modifiers, tax/discount/delivery lines and AM/PM time on both physical printers |
| P1 | Installer is unsigned | Obtain a code-signing certificate or retain documented SmartScreen instructions |
| P2 | POS table merge UI remains hidden | Finish deterministic two-process merge certification before exposing it |
| P2 | Native XLSX/PDF report export remains deferred | Add only after dependency/security approval; existing browser/CSV/thermal paths remain usable |
| P2 documentation | `ROADMAP.md`, the onboarding header and the live-day chronicle describe different historical deployment points | Refresh status markers after the next verified deploy; do not rewrite historical evidence |

## 10. Recommended next sequence

1. Renew/automate TLS before September 1.
2. Verify the actual production HEAD and run non-mutating smoke checks for tenant login,
   permissions, POS scope, report filters and print queue.
3. Perform controlled paper QA for the latest receipt/KOT/Reminder layout on BlackCopper
   and XPrinter; record photos and agent logs in the certification audit.
4. Keep Khatri transaction history untouched. Use onboarding/reset only when explicitly
   requested and preserve owner credentials and onsite printer IPs.
5. Resume Edge work at the split-brain inventory fence, then sync/backup/update/recovery;
   do not activate offline selling before every release gate is green.

## 11. Commit anchors by workstream

- **Edge runtime/release:** `2dd32ee`, `af1dd4c`, `d1b94c7` and subsequent Edge runtime commits.
- **Edge auth/identity/POS/print:** see the frozen audit documents under `docs/audits/edge-*`.
- **Khatri onboarding/go-live:** `KHATRI-*`, `3816596`, `9c471cd`, `15802c0`, `7138700`.
- **POS integrity/customer/delivery:** `dc0b870`, `d0ac021`, `35b08ac`, `ca492f6`.
- **Returns/finance/reports:** `85ebcfd`, `cedb073`, `e451202`, `e49ba3b`, `0ec2fc7`, `752daad`.
- **Printing/layout:** `bdc6fcd`, `e1098ee`, `847f4fa`, `e5c471f`, `f4758c9`, `64eb58d`, `e4568c5`, `014a6f2`.
- **Shift/tenant/platform stability:** `81bfd77`, `db9194f`, `3348b98`, `e17d6b5`, `b795405`.
- **Demo reset resilience:** `04156b6`, `889b44b`.

For exact per-commit narratives, retain the commit bodies as the source of truth and use
`docs/status/khatri-live-day-2026-08-11.md` for the production-day sequence. This summary
is the cross-workstream map, not a replacement for those detailed forensic records.

## 12. Does an online change automatically update Offline Edge?

**No, not with the current implementation.** The word "snapshot" must not be read as
continuous replication. The current bootstrap is a deterministic, immutable,
branch-scoped package of explicitly allowlisted records at one source revision. It is an
initial provisioning mechanism, not a live mirror of the cloud database.

The current importer makes that boundary executable: after a successful bootstrap, a
second import is refused with `REFRESH_NOT_IMPLEMENTED`. This is intentional. Blindly
replacing the local database with a newer cloud snapshot could erase unsynced sales,
payments, shifts, KOT history, stock movements and approvals.

### Current propagation matrix

| Change made online | Automatically reaches an existing Edge? | Current/future mechanism |
|---|---:|---|
| PHP/Blade/JavaScript/service code | No | A separately built, signed/versioned Edge software release and safe updater are required |
| New database migration | No | Edge release must include compatible forward-only Edge migrations; never fresh/reset an existing appliance |
| Product/category/price/tax/modifier/combo/recipe changes | No | Initial bootstrap includes only supported allowlisted fields; ongoing config refresh is not implemented |
| Users, roles, permissions and order-type access | No | Initial bootstrap plus Edge-specific credential enrollment; later identity/config refresh is still required |
| Branch, terminal, printer and KOT-routing changes | No | Initial bootstrap only today; a future revisioned config refresh must update/tombstone safely |
| Cloud sales, stock movements and returns | No | They remain cloud-authoritative; future baseline/cursor reconciliation controls what Edge may sell |
| Offline sales created on Edge | No cloud sync yet | They remain provisional with `edge_sync_state=pending`; the official ingestion/sync engine is not implemented |
| Receipt/KOT layout changes | No | Requires a compatible Edge software/config revision; a browser refresh alone cannot update an offline appliance |

### What happens for a brand-new Edge installation?

If a new snapshot is generated **after** an online feature is developed, that feature is
available offline only when all of these are also true:

1. The bootstrap builder explicitly exports its required fields and related records.
2. The Edge schema and importer understand the snapshot schema version.
3. The local service/UI implements the same business rule.
4. The future sync envelope can send the resulting operation back to Cloud without losing
   money, tax, stock, customer, printer or audit meaning.

For example, adding a new delivery-charge rule online does not make Edge understand it.
The Edge artifact needs the schema and calculation logic, the snapshot/config contract
needs the branch setting, local receipts need the display, and sync ingestion needs the
charge and GL meaning. If any layer is absent, the feature must fail closed or be disabled
offline.

### Required mature-system rule

Every future cloud feature should carry an **Edge impact declaration** during review:

- `Cloud only`: deliberately unavailable while offline, with a clear disabled state.
- `Config replicated`: add bootstrap/config-refresh schema, provenance and tombstone rules.
- `Offline transactional`: also add local authority, stable identities, envelope/sync,
  conflict policy, accounting/inventory replay and offline tests.
- `Software only`: add Edge artifact version compatibility and forward migration/update QA.

An online deploy and an Edge deploy are therefore related releases, but not the same
event. This separation is what prevents a mature cloud system from silently breaking a
branch that is disconnected or running an older appliance version.

## 13. Where Edge development should resume

The next implementation should continue from the existing frozen foundations in this
order:

1. **EDGE-SPLITBRAIN-STOCK-1 implementation:** put the Local-Mode authority fence at the
   shared `InventoryService` mutation boundary, cover all 16 official paths, and expose a
   narrowly scoped device/ingestion bypass. The read-only matrix currently proves 14 paths
   are unfenced.
2. **OFFLINE-SYNC-ENGINE-1:** immutable, idempotent sale envelopes; activation-epoch and
   config-revision verification; Cloud official posting through the existing sales service;
   acknowledgement only after accounting and inventory post successfully.
3. **EDGE-CONFIG-REFRESH-1:** archived cloud revisions, hash/provenance checks, transactional
   stable-ID UPSERT and tombstone-missing behavior. Never delete referenced configuration
   and never overwrite local operational history.
4. **Stock baseline/cursor reconciliation:** a refresh must preserve quantities consumed by
   unsynced local activity; it cannot replace stock with a newer snapshot blindly.
5. **EDGE-APPLIANCE-UPDATE-BACKUP-1:** mandatory verified pre-update backup, forward-only
   migrations, rollback/recovery evidence and a versioned release channel.
6. **Activation and physical certification:** complete leases, recovery, LAN printing,
   disconnect/reconnect and two-terminal tests before changing `activation_ready`.

Khatri production is outside this Edge development work. It is operating normally and must
not be used as an Edge test environment, reset, re-onboarded, or changed for exploratory
testing. Edge work belongs in dedicated test databases and controlled branch-server
artifacts until every activation gate is green.
