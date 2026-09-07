# Catering Independent Architecture and QA Audit

Date: 2026-08-20

Status: Initial report before fixes

## Safety Record

- Audit worktree: `D:\laragon2\www\pos-saas-catering-codex`
- Audit branch: `audit/catering-e2e-qa-v1`
- Audited Catering HEAD: `21a12e0e38e2c3f98e30596d7e60e5839a173b16`
- Canonical comparison HEAD: `89037425c087802f58fd2697c4655a5d966813f2`
- Existing physical worktrees changed: no
- Khatri Biryani read or mutated: no
- Kashif Kitchen reset, reseeded, or mutated: no
- Deployment, merge, or push performed: no
- `KHATRI_MUTATED=no`

## Audit Scope

The audit reconstructed the Catering vertical from routes, controllers, models,
services, migrations, views, tests, seed/reset integration, commit history, and
the Catering status/audit documents. The current branch is 12 commits ahead of
the canonical comparison and exposes 61 Catering routes, 18 controllers, 22
services, 20 models, 21 views, and 35 focused MySQL test classes.

The newest commit adds the Commercial Rate Book and selective Rate Impact flow.
That slice received the deepest review because it can change quotations and
because it is the current development edge.

## Architecture Mind Map

```text
Tenant + subscription + route permission boundary
|
+-- Catering setup
|   +-- Materials (shared Product/Unit masters)
|   +-- Material Cost Rate Book (internal cost)
|   +-- Commercial Rate Book (customer charge recommendation)
|   +-- Catering Product Profiles
|   +-- Recipe costing OR named Cost Blocks
|   +-- Printer mappings and Catering settings
|
+-- Booking and commercial lifecycle
|   +-- Event
|       +-- Draft Estimate vN
|       |   +-- Estimate lines
|       |   |   +-- calculated rate
|       |   |   +-- quoted rate and optional override reason
|       |   |   +-- immutable line cost-block snapshots
|       |   +-- service charge, other charge, discount, tax, total
|       +-- Sent / Accepted Estimate vN (immutable)
|       +-- Revision vN+1 (new draft; old version superseded)
|       +-- Advances and refunds
|       +-- Production release
|       +-- Material issue
|       +-- Final invoice
|       +-- Event closure
|
+-- Rate Impact
|   +-- Preview Commercial Rate versus applied product blocks
|   +-- Selectively apply to eligible product blocks
|   +-- Preview Commercial Rate versus estimate snapshots
|   +-- Selectively apply to eligible draft snapshots
|   +-- Never auto-reprice
|   +-- Never rewrite sent/accepted/final documents
|
+-- Shared platform services
    +-- InventoryService for stock movement
    +-- JournalPostingService for GL posting
    +-- print_jobs transport for Catering documents
    +-- permission catalog and module entitlement
    +-- tenant database connection for isolation
```

## Lifecycle and Posting Boundaries

1. Creating or editing an event/draft estimate is configuration and commercial
   calculation only. It must not move stock, cash, shifts, `sales_orders`, or GL.
2. A sent estimate is immutable. A commercial change requires a new revision;
   the source version remains preserved and is marked superseded.
3. Production release freezes the production requirement. Material issue is the
   inventory movement boundary and must use `InventoryService`.
4. Advances, refunds, final invoice, and advance application are the finance
   boundary and must use `JournalPostingService` translators.
5. Final invoice is an immutable snapshot. Event closure is allowed only after
   the financial position has no unresolved balance.
6. Catering remains a separate business vertical and does not write POS
   `sales_orders`.

## Verified Strengths

- The cost book and commercial book are separate concepts and separate tables.
- Changing a Commercial Rate does not automatically reprice a product or quote.
- Apply services re-check manual/customer-supplied/draft eligibility instead of
  trusting preview checkboxes alone.
- Customer-supplied materials remain operational requirements while their
  customer charge is zero.
- Sent estimates are model-level immutable and the revision service preserves
  line costing state and cost-block snapshots.
- Draft repricing recalculates touched line amounts and then document totals in
  one tenant transaction.
- Final invoice creation posts through the shared journal service; stock issues
  use the shared inventory service.
- Catering permissions are registered in the permission catalog and routes sit
  inside the tenant middleware stack.
- Tenant reset transaction cleanup and Kashif UAT fixture setup know about the
  new commercial-rate data.

## Findings

### CAT-RATE-001 - P1 - Unit mismatch can silently produce wrong prices

Evidence:

- Commercial-rate input accepts any active `unit_id`.
- `CateringMaterialCommercialRate::rateFor()` returns only a float, discarding
  the unit identity.
- Product and draft impact multiply physical quantity directly by that float.
- No compatibility assertion or unit conversion exists in preview or apply.

Example: a material snapshot measured as 500 GM can be multiplied by a rate of
PKR 800 per KG as `500 * 800`, producing P400,000 instead of P400.

Required fix: make rate resolution return amount plus unit; validate material,
block, and snapshot units server-side; either use the platform unit conversion
authority or fail closed when conversion is unavailable. Add mismatched-unit
HTTP and service tests.

### CAT-RATE-002 - P1 - Draft immutability has a concurrency race

Evidence:

- `applyToDrafts()` selects snapshots and their estimate without
  `lockForUpdate()`.
- It tests an in-memory `isDraft()` value and then updates snapshots.
- Estimate send/accept/final-invoice actions can race that transaction.

Result: a quote can become sent after the eligibility read but before snapshot
updates, allowing a commercial change to cross the immutable-document boundary.

Required fix: lock the selected snapshot rows, their estimate lines, and parent
estimates in deterministic order; re-read status while locked; make send/revise/
apply use a compatible lock order. Add a real-MySQL two-connection race test.

### CAT-RATE-003 - P1 - Commercial-rate history is overwritten on the same day

Evidence:

- Store uses `updateOrCreate(product_id, effective_from)`.
- The database has a unique `(product_id, effective_from)` constraint.
- Model and screen language promise that each new rate preserves the old rate.
- The existing history test uses different effective dates and does not cover
  two decisions on the same date.

Result: a second same-day commercial decision replaces rate, author, note, and
history, making prior calculations and operator accountability less explicable.

Required fix: use an append-only effective timestamp or a version/sequence that
permits multiple same-day entries. Never update an existing history row. Add a
same-day history regression test.

### CAT-RATE-004 - P1 - Apply actions have no business audit record

The system records who created a Commercial Rate, but it does not record who
applied which rate to which product block or draft snapshot, when, why, or what
the before/after values were. The HTTP action therefore changes customer-facing
commercial data without a durable business audit trail.

Required fix: append an application batch and application lines in the same
transaction as the changes. Record actor, source commercial-rate row, target,
old/new values, timestamp, and reason. The log must be read-only in normal UX.

### CAT-RATE-005 - P2 - Cost Block authoring cannot choose Commercial Book

The models and UAT seeder support `commercial_rate_source`, but the Cost Block
controller and UI neither accept nor persist a Manual versus Commercial Book
choice. An operator cannot author this feature through the product workflow.

Required fix: add an explicit segmented/source control for per-material-unit
blocks, server validation, current commercial-rate preview, and fail-closed
behavior when no compatible rate exists. Charges and per-dish legacy blocks
must remain manual.

### CAT-RATE-006 - P2 - Material and unit validation is too broad

`store()` uses generic `exists:products,id` and `exists:units,id`; route-model
binding also accepts any tenant product. The index UI filters material kinds,
but HTTP requests can create or inspect commercial rates for other product
kinds. Cost Block input has similar broad product/unit validation.

Required fix: centralize a Catering material rule (raw, packaging, or
semi-finished as deliberately supported), require tenant-active units, and
apply the rule in store, impact, apply, Cost Block authoring, and tests.

### CAT-RATE-007 - P2 - Rate Impact understates the commercial decision

Draft rows show material amount movement only. They do not show old calculated
line rate, projected calculated line rate, quoted rate, override reason, old
customer total, or projected customer total. A manager cannot see whether a
calculation change actually changes what the customer will pay.

Required fix: present separate columns/cards for calculation and quotation:
Old Calc, Projected Calc, Quoted, Quote Difference, Old Total, Projected Total.
Never imply that a negotiated quote is being changed when only calculation is.

### CAT-RATE-008 - P2 - Ineligible rows display a misleading difference

For manual or sent snapshots, the UI hides `Would become` but still prints the
numerically computed `difference`. This can look like an actionable impact even
though the row is deliberately ineligible.

Required fix: difference must be `--`/Not applicable for ineligible rows, or be
clearly labeled hypothetical and separated from selectable impact. Manual and
customer-supplied cases need view tests.

### CAT-RATE-009 - P2 - Sent quote workflow stops at advice

The screen says a sent quote must be revised, but offers no atomic Create
Revision and Apply operation. Operators must leave the impact context, revise,
find the new draft, and repeat the commercial decision manually.

Required fix: provide a manager-confirmed action that locks the source estimate,
creates vN+1, preserves vN, applies the selected compatible commercial-rate
change to the new draft, recomputes totals, and writes the audit batch in one
transaction. Failure must roll back the entire revision and apply operation.

### CAT-RATE-010 - P2 - Commercial Rate list can label a future rate as current

The index chooses the newest row by date/id without filtering
`effective_from <= today`, while impact resolution does filter by effective
date. A future rate may appear as the current house rate on the list but not be
the rate used by impact.

Required fix: show Current and Scheduled separately, with effective dates, and
resolve both through the same rate resolver.

### CAT-TEST-001 - P2 - New tests do not cover the highest-risk boundaries

The 22-test Commercial Rate suite covers selective apply, manual exclusions,
customer-supplied behavior, sent estimate refusal, revision copy, and no stock/
GL movement. It does not cover:

- incompatible units or conversion;
- two same-day rate entries;
- apply audit records;
- concurrent send versus draft apply;
- explicit final-invoice exclusion;
- two-tenant Rate Impact isolation;
- HTTP product-kind/source manipulation;
- future scheduled rate display;
- sent Create Revision and Apply atomic rollback.

### CAT-DOC-001 - P3 - A seeder docblock is attached to the wrong method

The Material Rate Book comment now immediately precedes
`ensureCommercialRates()` due insertion order, while `ensureMaterialRates()`
starts after it. Runtime behavior is unaffected, but maintenance guidance is
misleading.

## Final Invoice and Tenant Boundary Assessment

- Ordinary apply currently excludes final-invoice source estimates indirectly
  because only draft estimates are eligible. This is directionally correct.
- It is not concurrency-safe until CAT-RATE-002 is fixed. A final invoice or
  send action can cross an unlocked apply eligibility read.
- Tenant models use the `tenant` connection, so route-model binding and queries
  are database-isolated under the active tenant. No direct cross-tenant write
  was found in the Rate Impact code.
- The new slice lacks a dedicated two-real-tenant HTTP/service proof. That test
  is still required before calling the tenant boundary release-certified.

## Seed and Reset Assessment

- The Kashif UAT command seeds Commercial Rates aligned with the authored demo
  dish rates, which is appropriate for a neutral starting fixture.
- Tenant transaction reset deletes the new commercial-rate table.
- Reset coverage was updated to expect the table.
- The UAT seeder also uses same-day `updateOrCreate`; it is acceptable for an
  idempotent fixture but should call an explicit fixture upsert helper so the
  production append-only rule is not copied accidentally into business code.
- No UAT reset or seed was executed during this audit.

## Test Evidence

- `php -l` passed for all 18 PHP files changed by `21a12e0`.
- Focused MySQL suite attempted: `CateringCommercialRateImpactMySqlTest`.
- Result: infrastructure-invalid, not a product pass or product failure.
- Cause 1: the isolated worktree has no independent Composer vendor tree; a
  junction resolved Composer `App` and base `Tests` classes to the main worktree,
  so it did not represent the audited source consistently.
- Cause 2: another existing Catering worktree was concurrently running
  `CateringSeedUatMySqlTest` against the same `_cat` databases. The audit run
  collided with that schema rebuild and hit a deadlock. Audit-owned timed-out
  PHP children were stopped; the unrelated test process was left untouched. No
  live database was involved.
- Therefore the report does not claim that the 22 tests pass at current HEAD.
  A source-correct isolated dependency/bootstrap setup is required before the
  test suite is admissible evidence.

## Initial Architecture Verdict

Verdict: `CONDITIONALLY SOUND, NOT RELEASE-READY`.

The vertical boundaries, immutable estimate model, costing snapshots, shared
inventory/finance service reuse, and selective repricing principle are good.
The current Commercial Rate implementation has three release-significant
integrity gaps: unit safety, immutable-document concurrency, and append-only
history. Missing apply auditing is also unacceptable before broad production
use. These are local to the new Rate Impact edge; no evidence in this audit says
that Khatri's live POS transactions should be touched.

## Fix Order

1. P1 correctness foundation: typed rate/unit resolver, compatibility/conversion
   rule, material-kind validation, same-day append-only history.
2. P1 concurrency and audit: deterministic row locks, application batch/lines,
   send/apply race tests, explicit final-invoice and two-tenant tests.
3. P2 operator completion: Cost Block source authoring, clearer impact figures,
   ineligible-row presentation, Current versus Scheduled rates.
4. P2 sent workflow: atomic Create Revision and Apply with full rollback tests.
5. Run all Catering MySQL, HTTP authz/entitlement, reset/seed, finance, inventory,
   view-render, and route-permission suites from a source-correct isolated test
   setup. Then perform browser UAT only on Kashif Kitchen with explicit approval.

## Initial Report Contract

- Areas inspected: branch history, Catering docs/memory, routes, permissions,
  controllers, models, services, migrations, views, Commercial Rate tests,
  revision flow, final invoice boundary, seeder, transaction reset.
- Fixes made: none; this is the required pre-fix report.
- Known blockers: CAT-RATE-001 through CAT-RATE-004 and source-correct MySQL test
  execution.
- Recommended next slice: fix and test the P1 correctness foundation only; do
  not merge or deploy the current audit branch.
