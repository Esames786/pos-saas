# POS Table, KOT, and Order Integrity Investigation

Date: 2026-08-03 (Asia/Karachi)
Scope: User-reported POS issues 1-8, cancellation policy, local code trace, read-only production demo audit, and Edge/offline parity review
Production tenant inspected: `demo`
Implementation status: developed locally; verification in progress

## Implementation progress

The approved integrity plan is now implemented in the working tree:

- table sessions use one cashier-facing held check; **Add Round** updates that check and produces only the new KOT delta;
- table-session and held-order mutations are transactionally locked, branch-bound, and reject foreign line IDs;
- changing order type starts a fresh context and users can be limited to assigned order types with a validated default;
- KOT batches and immutable batch-line snapshots distinguish normal, addition, cancellation, and duplicate output;
- reductions of KOT-sent quantities are calculated by the server and require a reason plus either a single-use manager approval or the branch `auto_approve` policy;
- whole held-order cancellation follows the same audited flow and creates cancellation KOT work before the sale becomes cancelled;
- a dedicated `tenant.pos.void-kot-item` permission is provisioned and enforced server-side;
- branch cancellation policy, user order types, permissions, role assignments, and Reminder printing configuration are included in the versioned `edge-bootstrap-v3` snapshot; manager PIN hashes are not exported;
- demo seeders include branch-policy and per-user order-type examples.

The existing Edge work remains a bootstrap foundation rather than a complete offline transaction runtime. Under `manager_required`, disconnected cancellation is therefore represented as blocked until an online manager approval can be verified; `auto_approve` remains available offline from the signed branch snapshot. Live production records listed below have not been modified or auto-repaired.

### Local verification evidence

- The tenant migration was executed against the local MySQL `demo` database and is recorded successfully.
- The new KOT tables and `tenant.pos.void-kot-item` permission were verified in that tenant.
- Existing roles with `tenant.held-sales.cancel` inherit the new permission during migration; local verification confirmed Cashier, Manager, and Owner.
- All changed PHP files pass `php -l`; Laravel route and Blade caches compile.
- Automated suite: 8 tests, 17 assertions. Added coverage checks user order-type fallback/filtering and normal, addition, cancellation, and duplicate KOT formatting.
- `git diff --check` passes.
- A Vite production build was not available because `node_modules` is not installed in this workspace. The modified POS behavior is inline in the Blade view and does not require a new Vite asset bundle.

Before production deployment, run browser QA for the required regression matrix below using both a real Print Agent and browser-print fallback. Concurrency is protected by row locks and stale table-check creation is rejected, but multi-terminal interaction still requires an end-to-end browser test. Production demo rows identified in this report remain unchanged.

## Executive conclusion

The report contains four confirmed P0 integrity defects, not only UI problems:

1. **New Order detaches a dine-in order from its table.** The browser clears the table and table-session hidden fields. The second CT3 order was saved and its KOT printed, but it was saved as an orphan dine-in order, so CT3 correctly could not include it.
2. **KOT history is lost when a held order is completed.** Checkout deletes held-sale lines and creates new line IDs without carrying `kot_sent_quantity`. This can print the same items again as a normal KOT during payment.
3. **A KOT-sent held order can be cancelled without a cancellation KOT or approval trail.** Production contains a cancelled held order whose six sent kitchen quantities have no manager approval and no cancellation print job.
4. **Sent quantities can be reduced through paths that bypass cancellation control.** The minus control does not invoke the void flow, the cross-button flow becomes permissive when no void reasons are configured, and the server trusts optional client-supplied void metadata instead of calculating the sent-quantity delta itself.

The financial audit did **not** find unbalanced journals or payment/sales-ledger mismatches in the reviewed demo window. The primary exposure is table attribution, kitchen fulfilment, and silent held-order cancellation.

## Production evidence

All timestamps below are database/server UTC. Add five hours for Pakistan time.

### CT3 reproduction is confirmed

| Sale | Status | Table session | Table | Total | KOT |
|---|---|---:|---|---:|---|
| `HS-20260802192925-176` (ID 50) | Held | 7 | CT3 / ID 25 | 431.00 | Printed at 19:29 |
| `HS-20260802193455-937` (ID 51) | Held | **NULL** | **NULL** | 210.00 | Printed at 19:34 |

Sale 50 contains French Fries x2 and Doodh Patti Chai x1. Sale 51 contains Kheer x1 and Karak Chai x1. Both KOTs printed, but only sale 50 belongs to CT3. This is why Select/Continue and CT3 Bill Preview show only 431.00.

The direct code cause is:

- `resources/views/tenant/pos/index.blade.php:3321`: New Order invokes `clearCart()`.
- `resources/views/tenant/pos/index.blade.php:2630`: `clearCart()` clears cart and recalled state.
- `resources/views/tenant/pos/index.blade.php:2647`: the same function also clears `restaurant_table_session_id` and `restaurant_table_id`.

Production also contains three earlier orphan dine-in sales in the reviewed customer-demo window:

| Sale | Status | Total |
|---|---|---:|
| `HS-20260802141748-107` (ID 40) | Held | 105.00 |
| `HS-20260802142751-805` (ID 41) | Paid | 242.00 |
| `HS-20260802143813-824` (ID 43) | Held | 212.00 |

This confirms the defect is repeatable and not unique to CT3.

### Duplicate KOT on completion is confirmed

The production print payloads show normal, non-reprint KOTs being generated again after checkout:

- Sale 39 printed line quantities 1 + 1 on hold, then the same 1 + 1 at completion under new line IDs.
- Sale 42 printed quantities 3 + 2 on hold, then the same 3 + 2 at completion under new line IDs.
- Similar additional completion KOT jobs exist for sales 37, 44, 45, 46, and 48.

The code cause is `app/Http/Controllers/Tenant/SalesOrderController.php:243`: completing a held order deletes all lines and recreates them. The recreated lines default to unsent, so the print service sees them as new kitchen quantities. Held-sale edits also replace lines at `HeldSaleController.php:388`, although that path attempts to restore sent quantities by a product/variant key.

### Silent post-KOT cancellation is confirmed

Sale `HS-20260802144637-522` (ID 47) sent Doodh Patti Chai x3 and French Fries x3 to the kitchen and was then cancelled. Production has:

- one printed normal KOT;
- no cancellation KOT;
- no manager approval row;
- no financial or stock posting, because it was still held.

`HeldSaleController.php:570` changes the status to `cancelled` without a reason, approval, or kitchen notification. Item-edit auditing is also fragile because old lines are deleted after optional frontend-provided `void_items` are processed.

#### How whole-order cancellation works today

For a recalled held sale, the POS **Cancel Order** button posts to `HeldSaleController::cancel()`. That endpoint checks branch mutation mode and verifies that the order is still held, then only changes the status to `cancelled`.

It currently does **not**:

- ask for a cancellation reason;
- require or create manager approval;
- calculate which KOT-sent quantities remain outstanding;
- create a cancellation event or cancellation KOT;
- call `PrintJobService` or enqueue anything for a Print Agent.

Therefore, cancelling a whole held order currently sends **nothing** to the kitchen printer or Print Agent. If the current cart is not a recalled held sale, **Cancel Order** only clears browser state and creates no server audit record.

There are also response-handling gaps: the primary browser handler does not require an HTTP-success response before clearing the cart, and its no-dialog fallback can clear the UI without cancelling the server record. A rejected cancellation can therefore appear successful to the cashier.

### Financial checks

For the reviewed demo window:

- each finalized sale's line sum matched its subtotal;
- payments matched paid amounts;
- sale-total ledger entries matched sale totals;
- all inspected posted journals balanced;
- the apparent journal-header difference was valid COGS accounting. Example: a 168.00 sale with 110.00 cost has 278.00 total debits and credits because the journal includes both cash/revenue and COGS/inventory legs;
- no application error was found in the business activity window.

## Findings and recommended behavior

### 1. Table orders and one customer bill

**Finding:** The current model permits multiple `sales_orders` under one table session. This can support rounds, split checks, and audit history, but the POS exposes those internal orders as separate cashier decisions. New Order then accidentally removes the table link.

**Recommendation:** A table should have one visible **Open Check** by default. Kitchen rounds must be KOT batches under that check, not new customer orders.

Recommended workflow:

1. Open CT3 -> create one Open Check.
2. Add fries and tea -> Send/Hold -> `KOT #1`.
3. Tap **Add Round** (not New Order) -> cart remains attached to CT3 and the same check.
4. Add kheer and chai -> Send/Hold -> `ADDITION KOT #2` containing only the new quantities.
5. Bill Preview and Close & Pay show one consolidated CT3 bill.
6. Separate checks exist only through an explicit **New Check / Split Check** action with permission and a clear guest/check label.

Internal IDs should remain for database safety and auditability. The cashier should not have to choose which internal order becomes the customer's bill.

### 2. New Order placement

Move the table-aware action beside **Calc** and **Clear** in the cart header, where it remains visible. Its label must depend on context:

- active table: **Add Round**;
- non-table recalled hold: **New Order**;
- explicit multi-check permission: menu option **New Separate Check**.

The action must clear only editable cart/recall state while preserving the active table session for Add Round.

### 3. Changing order type must start clean

**Finding:** `applyModeTab()` at `index.blade.php:3962` says the cart is preserved. It calls `clearTableStateInputs()`, which clears IDs but does not clear the cart, hide the recalled bar through `updateRecalledBar()`, or fully reset locked/edit state. The Edit Order modal can also change type in place.

**Recommendation:** Order-type change is a destructive context switch:

- if cart/recalled/table state is empty, switch immediately;
- otherwise show one confirmation;
- after confirmation reset cart, held-sale ID/no, table/session, separate-order flag, customer/delivery fields, promo, tip, void state, lock state, payment state, badges, URL parameters, and server-total quote;
- never allow a dine-in submission without a valid open table session;
- enforce allowed order types server-side as well as in the UI.

### 4. KOT sequence and duplicate labels

Current output only supports `*** KOT ***` plus `** REPRINT **` (`EscPosPayloadService.php:158`). `kot_print_count` is not a safe sequence because routing can create multiple printer jobs for one send action.

Introduce a KOT batch identity shared by all printer jobs from one send action:

- first send: `KOT #1`;
- later new quantities: `ADDITION KOT #2`;
- kitchen cancellation: `CANCEL KOT #3`;
- explicit reprint: `DUPLICATE - KOT #1 - COPY 2`.

Store `kot_batch_id`, `sequence_no`, `event_type`, `reprint_of_batch_id`, and `copy_no`. Reprints must never change sent quantities.

### 5. Removing or cancelling KOT-sent items

The user's concern is correct, but negative sale lines should **not** be stored in the fiscal sale. Negative sale lines would complicate tax, discounts, inventory, returns, revenue, and reporting.

Use immutable kitchen adjustments instead:

1. An unsent cart item can be removed normally.
2. Once quantity is KOT-sent, replace the cross button with **Void KOT Item**.
3. Require a cancellation quantity, void reason, user identity, and manager approval according to policy.
4. Server validates cancellation quantity cannot exceed sent quantity minus prior cancellations.
5. Save an immutable line-cancellation event; do not delete the original sent line.
6. Print a prominent `*** CANCEL KOT ***` showing table, check, original KOT, item, and cancellation quantity.
7. Customer bill shows only the effective positive quantity. Example: ordered 3, cancelled 1 -> bill shows quantity 2; it does not show a `-1` sale line.
8. Cancelling an entire KOT-sent held order must use the same reason/approval flow and send cancellation KOTs for every outstanding kitchen quantity.

The protection must cover every mutation path. Today the minus button can reduce a sent quantity without opening the void flow, and the cross-button callback allows removal without a reason when no active void reasons are configured. The backend must compare persisted sent/cancelled quantities with the requested new effective quantity and reject an unexplained reduction regardless of which browser control or API client submitted it.

Suggested records:

- `kot_batches` - one kitchen event/round;
- `kot_batch_lines` - exact immutable quantities sent;
- `sales_order_line_cancellations` - line, quantity, reason, requested by, approved by, cancellation KOT batch, timestamp.

Also add a permission such as `tenant.pos.void-kot-item`. The backend must enforce this; hiding the cross button alone is insufficient.

#### Branch cancellation approval policy

Add a cloud-owned setting to each branch. The UI may be a checkbox named **Auto-approve held/KOT cancellations at this branch**, but store an explicit policy value so it can be extended safely:

- `manager_required` - default and backward-compatible behavior;
- `auto_approve` - skips the manager PIN step for rush-hour branches.

The policy applies to both partial sent-item cancellation and whole held-order cancellation. `auto_approve` removes only the manager interaction. It must still require an active cancellation reason, record the requesting cashier, timestamp, branch policy and policy version used, preserve the original sent quantity, create an immutable cancellation event, and print a `CANCEL KOT` for the kitchen. It must never re-enable silent line deletion.

When `manager_required` is active, an approval must be action-specific, reference the exact order/line and quantity, be single-use, and be consumed atomically with the cancellation. The current `manager_approvals` schema/service does not track consumption and the verify endpoint does not bind the generated approval to a reference. A generic approval ID must not be reusable for another item, quantity, order, or cashier request.

Suggested server decision:

1. Unsent quantity: cashier may remove it normally.
2. Sent quantity + branch `auto_approve`: require reason, create system auto-approval audit, save event, enqueue Cancel KOT.
3. Sent quantity + branch `manager_required`: require reason and a fresh locally/verifiably authorized manager approval, then save event and enqueue Cancel KOT.
4. Whole held order: apply the same decision to every outstanding sent quantity in one transaction; only mark the order cancelled after all cancellation events and print jobs are durable.

### 6. Per-user order types

**Finding:** Users currently have default/access branch and terminal settings, but no order-type assignment in `User`, `TenantUserController`, or the user form.

Add:

- `allowed_order_types` JSON on users, defaulting to all types for backward compatibility;
- optional `default_order_type`, which must be one of the allowed values;
- user-form checkboxes for Dine In, Takeaway, Quick Sale, and Delivery;
- POS tabs filtered to allowed types;
- server validation in paid sale, held sale, totals quote, table APIs, and delivery flow;
- the same fields in branch bootstrap/offline snapshots so Edge mode cannot bypass the restriction;
- seeder examples for cashier roles such as dine-in only, takeaway + delivery, and all types.

### 7. Product/category and cart sizing

**Finding:** The product grid uses a 132px minimum tile and 112px minimum height (`index.blade.php:103`). At wide resolution this produces too many narrow columns and small labels.

Recommended desktop sizing:

- product grid: approximately 6 columns at 1920px, 5 at 1366-1600px, responsive below that;
- tile minimum width 165-180px and minimum height 145-160px;
- product name 16px, price 17-18px, metadata no smaller than 13px;
- category controls at least 44px high with 15-16px text;
- keep the cart around 440-480px but reduce total/review and action-panel vertical padding;
- put New Order/Add Round in the cart header;
- keep cart item history and latest item visible with auto-scroll, while totals/actions remain sticky.

This should be verified at 1920x1080, 1366x768, and a touch/tablet viewport with screenshots and no text clipping.

### 8. Customer-demo data audit

Confirmed issues in live data:

- four dine-in sales lost table/session attribution;
- CT3's second order exists but is orphaned;
- repeated normal KOTs occurred on completion after line recreation;
- one KOT-sent held order was cancelled without approval or cancellation KOT.

Not found in the reviewed window:

- unbalanced GL journals;
- payment versus paid-total mismatch;
- sales-ledger versus sale-total mismatch;
- line-total versus subtotal mismatch.

Do not auto-repair production records blindly. Proposed controlled remediation:

- after user confirmation, attach sale 51 to CT3 session 7 because the timestamps and reproduction establish that relationship;
- review orphan held sales 40 and 43 and either attach them to the confirmed historical session or cancel them through an audited administrative repair;
- do not reassign paid sale 41 without operator/session evidence;
- record every repair in an admin audit log and do not create financial postings for held-order repairs.

## Edge/offline parity review

### Current implementation status

The last 15 commits were reviewed, from `05ee2b5` through `3922eb7`. They establish the cloud-side foundation for Local POS: branch operating-mode protection, sale idempotency, Edge entitlement, device pairing, and secure branch bootstrap snapshots. The Edge-related PHP files lint cleanly and the current `/api/edge` route set exposes pairing, device identity, and bootstrap snapshot delivery.

There is not yet a deployable offline sales runtime to patch in parallel. The following planned pieces do not exist yet:

- `EdgeSaleCaptureService` and local operational sale persistence;
- offline sale/cancellation sync endpoints and reconciliation;
- the restricted Edge application build and installer;
- certified LAN KOT/receipt printing while disconnected;
- offline exception and reconciliation dashboards.

Consequently, this fix must first make the shared cloud POS rules canonical, then carry the same contract into the future Edge capture and sync implementation. Calling the current bootstrap foundation an already-working offline POS would be inaccurate.

### Snapshot and authorization gaps to include

The current Edge bootstrap requires expansion before this workflow can be safe offline:

- add the branch cancellation approval policy to the branch snapshot allowlist and source revision;
- add per-user allowed/default order types when those fields are implemented;
- select branch users by branch access assignment as well as default branch; the current snapshot includes only `users.default_branch_id = branch` and can omit an assigned cashier;
- include effective permissions, not only role names, so local APIs can enforce `void-kot-item` and order-type restrictions;
- implement a local manager-approval credential/verification contract for `manager_required`; the current Edge restrictions explicitly block cloud manager approval and the snapshot contains no manager PIN verifier;
- never send plaintext manager PINs. Use an appropriately protected verifier or branch-local approval credential with expiry/revocation rules.

### Offline cancellation and sync contract

Edge must preserve the same business result while the internet is down:

1. The Branch Server is authoritative for the active local table, held order, KOT batches, and cancellation events.
2. A local Cancel KOT is queued to the branch Print Agent immediately; cloud reconnection must not print that kitchen instruction a second time.
3. Store original ordered quantity, sent quantity, cancelled quantity, reason, actor, approval mode, approver where required, policy version/hash, KOT batch, local event UUID, and timestamps.
4. Sync the effective positive fiscal sale plus immutable KOT/cancellation history. Do not sync negative sale lines.
5. Cloud replay validates that cancellations never exceed sent quantities and remains idempotent by event UUID.
6. A fully cancelled held order must still sync its cancellation/audit and invoice-gap evidence; it must not disappear merely because no paid sale was produced.
7. A policy change in cloud affects new local events after a fresh acknowledged snapshot. Existing offline events retain the policy snapshot under which they were authorized.
8. Require successful sync/reconciliation before branch shift close according to the offline rollout policy.

The future LAN printing certification must explicitly test normal KOT, Addition KOT, Cancel KOT, duplicate copies, queue recovery, and reconnect behavior. Print Agent retries may reproduce the same event only as a marked duplicate/retry; they must not create a new cancellation or mutate quantities.

## Implementation plan

### Phase P0-A - Stop new integrity damage

1. Split `clearCart()` into `resetOrderState()` and `clearCartKeepingTableSession()`.
2. Make table Add Round preserve session/table/waiter and reuse the active check.
3. Reject server-side dine-in hold/payment requests without a valid open table session.
4. Make order-type switching perform one confirmed full reset.
5. Preserve line identity and KOT sent/cancelled quantities when converting held -> paid; do not delete/recreate sent lines.
6. Add regression tests reproducing CT3 and duplicate KOT on completion.

### Phase P0-B - Kitchen cancellation control

1. Add immutable cancellation records and cancellation KOT event type.
2. Replace removal for sent quantities with the controlled Void KOT Item flow.
3. Apply the same control to whole held-order cancellation.
4. Add permission, reason, branch approval policy, single-use action-bound manager approval, audit report, and cancellation-KOT printing.
5. Make the server derive sent-quantity reductions; reject missing or mismatched client void metadata.
6. Require successful HTTP responses before clearing recalled state and make dialog fallbacks execute the same server workflow.
7. Ensure customer receipts, GL, sales ledger, and inventory use net effective positive quantity only at finalization.

### Phase P1 - Restaurant check and KOT batch model

1. Enforce one default open check per table session.
2. Treat later sends as numbered KOT rounds under that check.
3. Keep separate checks only as an explicit split/multi-check workflow.
4. Make Bill Preview and Close & Pay consolidate the whole table check atomically.
5. Add KOT sequence, addition, cancellation, and duplicate-copy numbering independent of printer routing.

### Phase P1 - User policy and POS layout

1. Add and enforce per-user allowed/default order types, including Edge snapshots and seeders.
2. Move Add Round/New Order into the cart header.
3. Resize product/category controls and compress non-cart action panels.
4. Run responsive and touch-target visual QA.

### Phase P1 - Edge contract parity

1. Add branch cancellation policy and user order-type authorization to versioned bootstrap snapshots.
2. Fix branch-user selection and include effective local permissions.
3. Define local manager approval, cancellation event, KOT batch, and idempotent sync payload contracts.
4. Add these contracts to `EdgeSaleCaptureService` and sync implementation when those planned components are built.
5. Certify LAN Cancel KOT printing and prove cloud replay does not duplicate local prints.

### Phase P2 - Production repair and monitoring

1. Build a read-only orphan dine-in report before any repair command.
2. Add alerts for dine-in orders without table/session, sent quantities without a KOT batch, and cancelled sent quantities without a cancellation event.
3. Repair only confirmed demo records with an auditable, idempotent command.

## Required regression matrix

- same table: hold round 1, add round 2, recall, one consolidated bill;
- separate check deliberately created, split and payment behavior explicit;
- hold then complete with no new items -> no new KOT;
- hold then add quantity -> Addition KOT contains delta only;
- explicit reprint -> Duplicate label/copy count, no sent-quantity mutation;
- remove unsent item -> normal removal;
- cancel part/all of sent item -> reason/approval + Cancel KOT + net customer bill;
- cancel KOT-sent held order -> no silent cancellation path;
- minus, cross, clear, Cancel Order, modal, direct API, and stale-client submissions -> identical server enforcement;
- rejected cancellation response -> cart and recalled state remain visible with an error;
- branch manager-required policy -> one action-bound approval is consumed once;
- branch auto-approve policy -> no PIN prompt, but reason/audit/Cancel KOT remain mandatory;
- change each order type from recalled/table/cart states -> complete fresh reset;
- user allowed one, two, or all order types -> UI and API both enforce;
- payment, sales ledger, inventory, and GL remain balanced after each scenario;
- concurrency: two terminals add rounds to one table without losing or duplicating lines/KOT batches;
- offline: normal/addition/cancel/duplicate KOTs print locally exactly once by event identity;
- offline: auto-approved and manager-approved cancellation events sync idempotently without negative fiscal lines or cloud reprinting;
- offline: cashier with branch access but another default branch can authenticate and receives correct permissions/order types;
- offline: stale policy snapshot is rejected or refreshed before accepting newly restricted cancellation activity.

## Release recommendation

Do not treat this as a cosmetic POS release. Fix P0-A and P0-B together behind focused automated tests, run the demo repair separately, then deploy. The table/KOT data model work should be completed before further restaurant UX polish, because the current UI is exposing unsafe state transitions.

The cloud fix must define the canonical behavior now. The Edge implementation must not be advertised as cancellation-safe until local authorization, event capture, LAN Cancel KOT printing, idempotent sync, and reconciliation tests are implemented and pass. No production or offline activation should rely on browser-only controls.

## Direct Review & Pay race closure

`DIRECT-PAY-PRINT-ORCHESTRATION-1` closes the separate paid-sale browser race without changing the table/check or cancellation model above:

- KOT Print/Skip intent is decided before payment finalization and stored on the paid sale.
- Direct Pay uses the same `kot_sent_quantity` delta and immutable KOT batch rules as Hold/Add Round. A recalled check with only Fries added produces only the Fries Addition KOT; Reminder remains a complete updated-order document.
- Payment is authoritative. KOT, Reminder, Receipt, agent, or browser-preview failures cannot revert a paid sale.
- Cart clear waits for a stable queued/skipped/pending-retry result. Optional Reminder confirmation is bound to sale + exact batch/revision, not cart objects.
- Automatic retry converges on the original batch/logical jobs; explicit manual reprint remains the only path that creates Duplicate numbering.
- `direct_pay_print_state` is the cloud contract future Local Branch Server storage must mirror before UI state is discarded. Local Mode remains disabled and sync remains unbuilt.
