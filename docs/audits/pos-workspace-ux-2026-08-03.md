# POS-WORKSPACE-UX-1 Audit and Implementation

Date: 2026-08-03
Branch: `feat/14d-2-plan-upgrade-requests`
Base: `fb00125` (`DIRECT-PAY-PRINT-ORCHESTRATION-1`)

## Outcome

Restaurant POS is now a compact cashier workspace. The permanently expanded Live Table Board was removed from the page and replaced by a compact Dine In context bar with `View Tables`, selected table, waiter, guest count, open check, Bill Preview, and Request Bill. Table operations run in one responsive `Table Workspace` modal and return to the current POS state.

No KOT, Reminder, Receipt, cancellation, Add Round, direct-pay, Local Mode, or synchronization business logic was redesigned in this sprint.

## Final desktop structure

- Top: title, order-type tabs, and compact Dine In table context.
- Left: branch/terminal/customer controls, search, categories, and internally scrollable products.
- Right: contained cart with an internally scrollable item list and visible totals/actions.
- Viewport fitting applies only from `1200px` wide and `720px` high. Smaller, zoomed, tablet, and mobile layouts retain normal page scrolling.
- Global `body` overflow is not disabled.

## Table Workspace

The responsive modal reuses the existing table-board endpoint and provides internal views for floor filtering, table selection, Open Table, Held Orders, Move, exact-order Split selection, and permission-controlled existing Floor/Table administration. Delegated events keep refreshed board markup interactive. Open, Continue, and Move refresh state without leaving POS.

## Bill Preview authority

One visual modal shell has two deliberately separate sources:

1. **Current Cart Preview** comes from unsaved browser cart state and its current subtotal, discount, tax, service charge, tip, and total.
2. **Table Bill Preview** is rendered by the server from the selected table session, including held lines and financial breakdown plus paid history.

Neither preview requires a normal new tab or redirect. Preview printing uses an internal print document and creates no operational Receipt or KOT print job.

## Move Table

The existing endpoint is reused. Its AJAX path now returns structured errors and success state. In one tenant transaction it locks the current session, locks source/destination tables in deterministic ID order, revalidates branch/status/availability, moves session and sale attribution, updates statuses, and uses bounded transaction retry.

## Split Bill

Split remains an operation on one exact held sale. One eligible order opens the existing split workflow directly. Multiple eligible orders first show an explicit selection inside Table Workspace, then pass the chosen sale ID to the existing split screen. Financial split logic is unchanged.

## Merge Table semantics and release blocker

The existing Merge endpoint was hardened, but Merge is deliberately **not exposed in the cashier UI** until a deterministic two-process concurrency certification can be completed. In one transaction the staged backend:

- locks source/target sessions and tables in deterministic ID order;
- verifies same branch, different tables, active states, and exactly one active session per table;
- locks and moves only source `held`/`draft` sales;
- leaves paid fiscal sales on their original table/session;
- closes the source session with an audit note and releases its table;
- preserves `bill_requested` if either side had requested the bill;
- returns `422` for stale business state and `409` for transaction conflict.

The lock ordering and stale-state checks are designed to serialize concurrent operations involving either table, but this sprint did not complete the required executable two-operator race proof. The safe release decision is therefore to keep Merge hidden rather than infer certification from static code.

## Permissions

Operational actions keep their named permissions. Floor and Table administration appear only under `tenant.restaurant.floors.index` and `tenant.restaurant.tables.index`; their existing mutation routes retain server-side permission checks. No new universal cashier administration path was added.

## Printing non-regression

This sprint does not change Direct Review & Pay KOT intent, durable paid-sale print state, delta/Addition/Cancel KOT, `(R)`, Reminder Auto/Ask, Receipt ensure-once, browser fallback, Print Agent retry, manual duplicates, or Hold Sale print orchestration.

## QA and limits

Static tests cover inline-board removal, separate previews, safe modal handoff, internal scrollers, exact split selection, locked Move, and Merge validation. PHP lint, Blade/route cache compilation, and `git diff --check` pass.

The local PHP runtime lacks `pdo_sqlite`, so six existing isolated printing feature tests remain skipped by their guards. Manual browser certification is still required at `1920x1080`, `1600x900`, `1366x768`, and a short viewport before production deployment.

## Deferred and unchanged

- Add Round and `create_separate_order` semantics are unchanged.
- Full Board and administration routes remain backward compatible.
- Local Mode is not activated; `EDGE_FEATURE_ENABLED` remains false by default.
- No Edge runtime or sync was built.
- Production was not deployed.
- `tools/print-agent/dist/FakePrinter.exe` remains untouched and untracked.
