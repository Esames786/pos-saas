# Edge switchover auto-reconcile: no manager step when the internet returns (24 Nov 2026)

**Owner decision (24 Nov, ~12:00 PKT):**
- "i dont want manager involement i need to auto fix when internet on"
- Then "teekh hai kardo build", accepting the recommended rule: the bill the customer paid offline is the money truth; unpaid online items the cashier did not bill are closed and reported, not kept as an amount owed.

Readers: the engineers who build, review and deploy this.

## Problem

- The live-state mirror refreshes about every 30–40 s. Anything done online in the last seconds before the cut is missing on the Branch Server.
- Today the Cloud refuses the offline sale for such an order. The refusal is a terminal `conflict` (ADOPTED_ORDER_CONFLICT, ADOPTED_LINES_DIVERGED, …), and the appliance parks the row as `failed_permanent`.
- That blocks Return to Online (PERMANENT_SYNC_FAILURE) until a person fixes it. A table re-punched offline also leaves its old unpaid online bill open.

## Rules (Cloud ingestion only; the appliance is unchanged)

Every case below answers `applied`, so the 0.9.0 appliance acknowledges it as today and nothing is parked. Each automatic fix is written to the sale's ACK under `continuity.reconcile` (stored on `edge_inbound_sale_ingestions.ack_payload`) and shown on the Offline Edge page.

| # | Situation on the Cloud when the offline sale arrives | Automatic result | `reconcile.action` |
|---|---|---|---|
| R1 | Adopted order still held and unpaid, but changed online after the copy; the offline bill covers every online line | Merge into the same order (as today) | `merged_after_online_change` |
| R2 | Adopted order held and unpaid; some online lines are not on the paid offline bill (added online after the copy, or voided offline) | The paid offline bill is the sale. Those unpaid online lines are removed from the order (snapshot kept in the ACK). No kitchen print. | `online_items_not_on_offline_bill` |
| R3 | Adopted order already **paid** online (paid / partially_returned / returned) | Nothing posted: no second sale, payment, stock, GL or shift effect. ACK points at the online order. | `already_paid_online_not_posted` |
| R4 | Adopted order **cancelled** online after the copy, but sold offline | The order is completed with the offline bill (money truth) | `cancelled_online_sold_offline` |
| R5 | Adopted order no longer exists on the Cloud | Posted as a new sale with the same identity | `online_order_missing_posted_new` |
| R6 | The continued online shift is unknown, or belongs to another counter or branch | Posted without a shift, like any pre-0.9 Edge sale | `continued_shift_not_used` |
| R7 | A **new** offline dine-in sale on table X; the Cloud still has an unpaid held bill on table X opened before the offline table, and the offline bill covers its items | That online bill is closed as replaced (`cancelled`, note), and its table session is released. No kitchen print. | `replaced_online_bill` |
| R7b | As R7, but the offline bill does not cover the online bill | Left open (it is a real open table) and reported | `online_bill_left_open` |

Still manual (rare): an adopted order **part-paid online in the last seconds** before the cut. Both payments are real money, so splitting them needs a person. It stays the existing terminal conflict (ADOPTED_ORDER_CONFLICT). Unsupported line kinds and a wrong branch also stay refused.

## Financial invariants kept

- **One order:** the adopted order is completed in place, or (R3) not posted at all. Never a second copy.
- **Money once:** R3 posts nothing. Every other case posts only the offline payments, once.
- **Stock and GL once:** only for the final billed lines, in the same ingestion transaction. Removed or replaced unpaid online lines never posted stock or GL (held bills post nothing).
- **No kitchen reprint:** removal and replacement create no KOT batch, print job or cancellation print.
- **Atomic:** every reconcile write happens inside the existing ingestion transaction. A failure rolls the whole sale back as an `exception`, which is retryable.
- **Replay:** an exact replay is `already_applied` from the registry with zero effects (unchanged).

## Impact (system map, `docs/system-map/system-map.json`)

| Table | Used by | Change |
|---|---|---|
| `sales_orders` | 173 | Edge ingestion only: R2/R4 complete an adopted order, R7 sets a held bill to `cancelled` + note |
| `sales_order_lines` | 84 | R2/R4 delete unpaid online lines of the adopted order (the Cloud POS already re-saves held lines by delete + insert) |
| `restaurant_table_sessions`, `restaurant_tables` | 45 / 92 | R7 releases the replaced bill's session (same rule as the held-order cancel) |
| `edge_inbound_sale_ingestions` | (Edge) | ACK carries `continuity.reconcile`; R3 rows point at the online order |
| `sale_payments`, `shifts` | 8 / — | No new writers (R3 writes nothing) |

- **Routes:** none added or changed.
- **Screens:** Settings → Offline Edge gains a read-only "Switchover auto-fixes (last 30 days)" list.
- **No migration.** The appliance build is unchanged (0.9.0-edge stays).
- **Code:**
  - `app/Services/Edge/EdgeInboundSaleIngestionService.php`
  - new `app/Services/Edge/EdgeSwitchoverReportService.php`
  - `app/Http/Controllers/Tenant/OfflineEdgeController.php`
  - `resources/views/tenant/offline-edge/index.blade.php`
- **Tests:** `tests/MySql/EdgeContinuityMergeHttpMySqlTest.php` (real appliance + Cloud, two databases). The old "changed online → refused" test becomes R2; new tests cover R1, R3, R4, R7, R7b and the remaining manual case.

## Rollout

1. MySQL tests green.
2. LAB internet-off drill.
3. Attended closed-window Cloud deploy with an owner GO, with a rollback branch. No appliance step.

## Test evidence (24 Nov 2026, MySQL, isolated `*_edgefix24` databases, sequential)

- **`EdgeContinuityMergeHttpMySqlTest`: 13/13.**
  - New: R1, R2 (replaces the old "refused" test), R3, R4, R7, R7b, and the part-paid case that still needs a person.
  - Unchanged: the original merge, auto-takeover, stale-mirror, deal and refund tests.
- **Regression, 22 classes that touch sale sync, the sender and reconciliation, handback, returns, supervision and the Offline Edge page: 117/117 (1,744 assertions).**
  - Classes: `EdgeInboundSaleIngestionMySqlTest`, `EdgeSyncIngestionHttpMySqlTest`, `EdgeSyncReconciliationHttpMySqlTest`, `EdgeSyncSenderMySqlTest`, `EdgeHandbackOrchestratorHttpMySqlTest`, `EdgeCustomerIngestionMySqlTest`, `EdgeW6ContractIngestionMySqlTest`, `EdgeNoDuplicatePrintAfterSyncMySqlTest`, `EdgeTerminalRefusalIdentityMySqlTest`, `EdgeCashierShiftAndNetworkDownHttpMySqlTest`, `EdgeRefundShiftH1MySqlTest`, `OfflineEdgePageSetupHttpMySqlTest`, `OfflineEdgeAutoTakeoverCounterHttpMySqlTest`, `EdgeHeartbeatBuildDevicePageHttpMySqlTest`, `EdgeReturnSyncHttpMySqlTest`, `EdgeSupervisionMySqlTest`, `EdgeEnrollmentAuthzHttpMySqlTest`, `EdgeLocalAuthorityControlHttpMySqlTest`, `EdgeReleaseHttpMySqlTest`, `EdgeReminderPauseHttpMySqlTest`, `EdgePurchaseReturnSyncHttpMySqlTest`, `EdgeSupplierFinanceSyncHttpMySqlTest`.
- **Foreign keys into `sales_order_lines`** are all SET NULL or CASCADE (`kot_batch_lines`, `sales_order_line_cancellations`, `recipe_consumptions`, `sales_return_lines`, the parent line). Removing an unpaid online line can never be blocked and turn a sale into an endless retry.
