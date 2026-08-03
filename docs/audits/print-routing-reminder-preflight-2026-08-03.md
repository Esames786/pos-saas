# PRINT-ROUTING-REMINDER-PREFLIGHT-1

Date: 2026-08-03
Status: Implemented by `REMINDER-PRINT-1`; `LOCAL-PRINT-LAN-CERT-1` software preflight passed but physical certification is blocked by unavailable real LAN hardware. Production remains undeployed.
Depends on: `pos-table-kot-integrity-investigation-2026-08-03.md` and the current POS/KOT integrity working tree.

## Executive decision

The requirement can be implemented by extending the existing printing infrastructure. A replacement printing engine, a separate role system, and a separate Reminder routing table are not required.

The minimum-change design is:

1. Extend `category_printer_mappings` with order type, Reminder role, and the additional-round confirmation flag.
2. Keep `terminal_printer_settings` unchanged for Receipt and default KOT selection.
3. Keep `printers.print_role` for its existing Receipt/KOT capability and add only `supports_reminder` for the third independent capability.
4. Extend `receipt_layout_settings` and `print_jobs` with `reminder` as a document type.
5. Reuse `kot_batches.sequence_no` as the order revision/event number and `kot_batch_lines` as the current-round delta used for `(R)`.
6. Extend `PrintRoutingService`, `PrintJobService`, `EscPosPayloadService`, and the current Print Agent contract. Do not add another queue or another printing service.
7. Keep cloud behavior canonical. Export configuration in Edge bootstrap later, but do not activate offline transaction processing in this feature.

No new table is proposed. No new service class is proposed.

## Implementation result (REMINDER-PRINT-1)

The implementation follows the locked contract without adding a second engine, queue, routing table, approval flow, or local runtime.

- Reminder is `document_type=reminder` in the existing `print_jobs` queue and current Print Agent claim/printed/failed contract.
- Eligibility is active branch/global mapping + order type + category + Reminder-capable physical printer. Category matches decide the destination only; every queued Reminder snapshots the complete effective positive order.
- Automatic identity is `reminder:{kot_event_uuid}:{printer_id}`. The database unique key and existing race recovery return the same row on HTTP/process replay.
- First accepted KOT round automatically queues all Reminder destinations. Skipping the existing KOT prompt never calls the endpoint, so it produces neither Reminder nor Reminder prompt.
- Additional KOT remains delta-only. Its lines render `(R)`. Updated Reminder is complete-order and derives new/increased quantities from immutable `kot_batch_lines`, rendering `(R) Item` or `Item (R +delta)`.
- `reminder_confirm_on_addition` is evaluated per physical printer; any matching Ask rule wins. Auto destinations are durable before the response. Ask destinations are returned once with an encrypted token bound to tenant user, branch, sale, batch UUID, revision, printer IDs, and expiry.
- Manual Reminder reprint copies the original immutable payload. Its counter is source revision + printer scoped and independent from KOT copies and network retries.
- Approved sent-item cancellation keeps the existing Cancel KOT and adds an automatic non-interactive correction Reminder. Destinations are the union of current eligible printers and every prior non-cancelled Reminder printer, including queued/offline jobs. Whole-order cancellation renders `CANCELLED ORDER`; partial cancellation renders cancelled and complete remaining sections.
- Operational Reminder has no default/browser fallback. A configured network destination remains queued while its agent is offline. Settings preview remains available for 58mm/80mm layout review.
- The Reminder ESC/POS and Blade renderers consume immutable payload only and contain no code path for price, amount, discount, tax, service charge, tip, total, payment, paid, or balance fields.
- Edge bootstrap is configuration-only `edge-bootstrap-v3`, exporting Reminder printer capability, order-aware routing/Ask policy, and timestamp layout flags. `EDGE_FEATURE_ENABLED` is unchanged; Local Mode and sync are not implemented.

### QA evidence

- PHPUnit: 12 passed / 44 assertions; three isolated SQLite routing tests skipped because this PHP build has no `pdo_sqlite`.
- Real tenant MySQL: all seven tenant migrations passed.
- Transactional MySQL harness passed multi-printer deduplication, same physical KOT+Reminder, Ask precedence, all four order types, first/addition rounds, confirmation replay, immutable `(R)` data, non-fiscal output, printer-scoped Duplicate 1/2/1, cancellation historical union, whole cancellation, and unsent/zero suppression; transaction rolled back.
- Real two-process MySQL enqueue race: both workers returned the same job ID and verification found one Reminder logical row; synthetic rows were deleted.
- Post-edit cancellation harness proved the correction Reminder is snapshotted after the final cart rewrite: removed unsent quantity stayed absent, a newly added item was present, and the cancelled sent quantity retained its immutable product snapshot after old line replacement; transaction rolled back.
- Route catalog synchronization found 592 application routes; all seven tenants migrated and received 515 tenant permissions, including the new Reminder endpoints.
- Route cache and Blade cache compile successfully. All seven tenants passed the final accounting/inventory sweep with zero unbalanced journals, zero prohibited negative branch stock, and zero negative department stock.

Physical printing remains at-least-once: if a printer succeeds but agent acknowledgment is lost, a retry can physically print again. Exactly-once physical delivery is not claimed. `LOCAL-PRINT-LAN-CERT-1` remains the next certification step.

### LOCAL-PRINT-LAN-CERT-1 result

The certification preflight was rerun on immutable candidate `a0452e7`. Repository scope, lint, tests, config/route/Blade caches, explicit non-fiscal ESC/POS + HTML rendering, historical printed/queued destination correction routing, all-seven-tenant accounting/inventory smoke, and `EDGE_FEATURE_ENABLED=false` passed.

Physical certification is **BLOCKED** because this environment exposes only loopback fake printers, no Reminder-capable printer, no category mappings, and a stale Print Agent. No physical paper, failure/reconnect, Ask/Auto fan-out, or multi-terminal evidence was available. See `docs/audits/local-print-lan-certification-2026-08-03.md` for the matrix and resume prerequisites.

## Non-regression contract: KOT and Receipt are frozen

Reminder is an additional document layer. It must not replace, delay, or change the existing KOT or Receipt workflows.

### Existing KOT behavior to preserve exactly

1. The current terminal/device auto-KOT setting remains authoritative.
2. When auto-KOT is off, the existing `Print Kitchen Order?` Yes/Skip prompt remains unchanged.
3. Choosing Yes continues through the existing KOT endpoint and current category/default-printer routing.
4. If no KOT printer route exists, the existing browser/manual KOT preview fallback remains unchanged.
5. If a configured network printer exists but its Print Agent is temporarily disconnected, the same queued KOT job remains pending/retryable under the current Print Agent workflow. Reminder must not convert, cancel, or duplicate it.
6. Addition KOT still contains only new/increased quantities.
7. Explicit KOT reprint, Cancel KOT, sent-quantity mutation, fallback preview, and print status handling remain KOT-owned behavior.
8. A Reminder failure, decline, or missing configuration can never fail or roll back a KOT job.

### Existing Receipt behavior to preserve exactly

1. Receipt remains payment/completion driven.
2. Terminal receipt printer, auto-receipt toggle, ensure-once behavior, manual preview fallback, reprint, and Receipt layout remain unchanged.
3. Reminder mapping is never consulted when selecting a Receipt printer.
4. A Reminder failure, decline, missing route, or retry can never block payment completion or Receipt output.

### Reminder-only behavior

Reminder starts only after the cashier has chosen the existing KOT send path and the server has created the kitchen round event. It produces separate `print_jobs` rows and a separate immutable payload. It does not alter KOT jobs or Receipt jobs.

For an additional round, the new popup is only a Reminder resend decision. It is shown after KOT is durable. It must never be worded as a KOT confirmation and must never repeat the existing KOT prompt.

## A. Current printing architecture

### A1. Printer and routing configuration

- `database/migrations/tenant/0001_01_01_000013_create_printing_tables.php:13-28` creates `printers`.
- `printers.print_role` is an enum of `receipt`, `kot`, and `both` (`:19`). It currently acts as both a capability flag and a fallback-routing selector.
- `category_printer_mappings` is created at `:33-43` with branch, category, printer, and `print_role` (`kot` or `receipt`).
- Its current unique key is `(branch_id, category_id, print_role)` at `:42`. Because `printer_id` is absent, only one configured printer can survive for a branch/category/role through the current controller flow.
- `2026_05_21_000003_make_category_printer_branch_nullable.php` later permits global mappings, although the current create UI/controller still requires a branch.
- `terminal_printer_settings` at `:47-56` selects one receipt printer and one KOT fallback printer per terminal, with separate auto-print flags.

`app/Http/Controllers/Tenant/CategoryPrinterMappingController.php` is the current configuration boundary. `store()` validates one branch/category/printer/role and uses `updateOrCreate()` keyed by branch, category, and role. A second printer currently replaces the first mapping instead of creating a second destination.

### A2. KOT routing

`app/Services/Printing/PrintRoutingService.php` is the exact category-to-printer routing implementation.

- `kotRoutesForSale()` calculates the unsent delta as `quantity - kot_sent_quantity`.
- For every line, it reads active `category_printer_mappings` for the sale branch or a global mapping.
- It then loads active printers and groups lines by `printer_{id}`. This already deduplicates several matching lines/categories into one job per physical printer.
- If no category mapping exists, `defaultKotPrinter()` uses the terminal KOT printer and then the active branch/global default KOT printer.
- If no printer exists, it returns one browser/manual route.
- `kotRoutesForQuantities()` repeats the same routing logic for immutable cancellation quantities.

There is a small existing inconsistency: routing queries category mappings for `kot` and `both`, while the category-mapping database enum and controller only permit `kot` or `receipt`. The Reminder migration should normalize this rather than preserve an unreachable `both` mapping value.

### A3. Receipt routing

`PrintRoutingService::receiptPrinter()` first checks `terminal_printer_settings.receipt_printer_id`, then falls back to an active branch/global printer whose `printers.print_role` is `receipt` or `both`.

A single physical printer can already be selected for both Receipt and KOT by using `print_role=both` and assigning the same printer in terminal settings. Reminder should be a third independent capability and should not require another terminal printer column because Reminder destinations are selected by routing rules.

### A4. Job creation and Print Agent delivery

`app/Services/Printing/PrintJobService.php` creates all current Receipt and KOT jobs.

- `queueReceipt()` creates an ensure-once automatic Receipt job and stores an immutable `raw_payload`.
- `queueKot()` creates one `kot_batches` event for the logical send and one print job per resolved printer route.
- `queueCancellationKot()` creates a cancel batch and routes exact immutable cancellation quantities.
- `createKotJob()` stores batch, sequence, event type, copy number, line quantities, and line snapshots in `print_jobs.payload`, then builds `raw_payload`.
- `markPrinted()` is idempotent by print job status and does not mutate sent quantities for cancel/duplicate events.

`app/Http/Controllers/Tenant/Api/PrintAgentApiController.php:95-149` claims queued network jobs. A claim expires after two minutes, but the job row and `job_no` remain the same. `printed()` marks that same job complete. Therefore a queue/agent retry is not a user duplicate and must never increment a Reminder duplicate counter.

Current limitation: `print_jobs` has no unique logical event key. Receipt has an application-level ensure-once query, but KOT/Reminder event creation is not protected by a database-level `(event, printer, document)` identity. Also, current KOT manual copy numbering is derived from the sale-wide `kot_print_count`, which can be distorted by multi-printer jobs. This must be corrected before Reminder duplicate numbering is considered complete.

### A5. Layout and payload rendering

- `receipt_layout_settings` currently allows only `receipt` and `kot` (`0001_01_01_000013_create_printing_tables.php:72-98`).
- `ReceiptLayoutController` validates only those two types and previews one of two Blade documents.
- `PrintDocumentController` loads the matching branch layout and renders KOT or Receipt.
- `EscPosPayloadService::build()` switches on the job document type and currently supports Receipt and KOT only.
- `resources/views/tenant/printing/layouts/index.blade.php` and `_form.blade.php` expose Receipt/KOT layouts only.

### A6. POS print trigger

The current browser flow is in `resources/views/tenant/pos/index.blade.php`:

- `submitHeldSale()` saves/updates the same held order and then calls `handleKotAfterSale()` only when a KOT delta remains (`:3121-3190`).
- `handleKotAfterSale()` applies the device auto-KOT override or asks whether to print (`:2923-2944`).
- `fireKotSilently()` calls `POST /printing/jobs/kot/{sale}` and updates client-side sent quantities from the server response (`:2882-2920`).
- Completed sale uses the same KOT endpoint and a separate ensure-once Receipt endpoint (`:3060-3104`).

The Reminder confirmation belongs after the KOT endpoint returns its server-computed Reminder plan. It must not run before saving the order and must not calculate eligible printers in JavaScript.

### A7. KOT rounds and cancellations in the current working tree

`database/migrations/tenant/2026_08_03_000001_add_kot_cancellation_controls.php` adds:

- `kot_batches` with immutable UUID, sale, sequence, event type, reprint reference, and copy number;
- `kot_batch_lines` with immutable product/variant/combo/modifier/note snapshots and delta quantity;
- `sales_order_line_cancellations` with immutable reason, requester, approver, policy snapshot, KOT batch, and time;
- user order-type policy and branch cancellation policy.

`KotCancellationService` validates permission, sent quantity, prior cancellation quantity, active reason, and branch approval policy. It consumes an action-bound manager approval where required and queues `CANCEL KOT` through the same `PrintJobService`.

### A8. Completed-sale returns

`SalesReturnController::store()` permits paid/partially-returned sales, enforces branch access and branch operating mode, and calls `SalesReturnService`.

`SalesReturnService::processReturn()` caps remaining returnable quantity, restores tracked inventory, updates return status, writes the sales ledger and shift refund totals, and posts GL/cash-bank reversals. This is the correct online completed-sale path. Reminder work must not create a second return or negative-sale mechanism.

## B. Components to reuse unchanged

The following behavior remains canonical:

- one open table check with Add Round on the same held order;
- server-calculated KOT delta and `kot_sent_quantity` protection;
- `kot_batches` and immutable `kot_batch_lines`;
- normal, addition, cancellation, and explicit duplicate event concepts;
- category route grouping by physical printer;
- one `print_jobs` queue consumed by Print Agent;
- immutable `raw_payload` produced when a job is queued;
- existing users, roles, permissions, manager approval, and branch auto-approval policy;
- existing completed-sale return/refund workflow;
- current terminal Receipt/KOT settings and device auto-print overrides;
- existing Edge snapshot signing, acknowledgment, source revision, and effective-permission export.

## C. Minimum database changes

### C1. `category_printer_mappings`

Add:

- `order_type` string(30), not null, default `all`;
- allow `print_role` values `kot`, `receipt`, and `reminder` (prefer changing the enum to a constrained string to avoid repeated enum migrations);
- `reminder_confirm_on_addition` boolean, default false.

Replace the current unique key with:

`(branch_id, category_id, printer_id, print_role, order_type)`

This permits several physical printers for the same category and document while preventing an exact duplicate rule. Normalize empty/null order type to `all` in validation and migration so old rows preserve all-order-type behavior.

MySQL permits multiple null values in a composite unique key. Existing global mappings with `branch_id=null` therefore still require an application-level conflict check inside a transaction. The current UI requires a branch, so branch-specific mappings remain the normal supported path.

### C2. `printers`

Add:

- `supports_reminder` boolean, default false.

Keep the current `print_role` for Receipt/KOT compatibility:

- `receipt` + supports Reminder;
- `kot` + supports Reminder;
- `both` + supports Reminder.

This supports every requested combination with one new flag and avoids replacing terminal settings. The Printer form should label the existing field `Receipt/KOT capability` and add a `Reminder capable` toggle.

### C3. `receipt_layout_settings`

Allow `document_type=reminder` and add:

- `show_order_time` boolean default true;
- `show_updated_time` boolean default true;
- `show_print_time` boolean default true.

The existing branch/document unique key remains correct. Existing display flags, paper size, header/footer, and font size can be reused. Reminder rendering must ignore fiscal/payment flags even if legacy values are true.

### C4. `print_jobs`

Allow `document_type=reminder` and add:

- `logical_key` string, nullable, unique;
- `copy_no` unsigned integer, default 1.

`logical_key` is the database guard against repeated logical enqueue:

- automatic KOT: `kot:{kot_event_uuid}:{printer-or-browser}`;
- automatic Reminder: `reminder:{kot_event_uuid}:{printer_id}`;
- manual KOT copy: `kot-copy:{source_event_uuid}:{printer_id}:{copy_no}`;
- manual Reminder copy: `reminder-copy:{source_event_uuid}:{printer_id}:{copy_no}`.

Old jobs remain null and unaffected. New automatic jobs must use `firstOrCreate()` or catch the unique-key race and return the existing job. Print Agent retries continue using the same job ID/key.

No `reminder_batches` table is needed. `kot_batches` is the durable order event/revision source, while `print_jobs.copy_no` is document/printer-specific manual-copy state.

## D. Minimum code changes

### D1. Models/controllers

- Extend fillable/casts/constants in `Printer`, `CategoryPrinterMapping`, `ReceiptLayoutSetting`, and `PrintJob`.
- Extend Printer validation/form for `supports_reminder`.
- Extend Category Mapping validation for order type, Reminder, and confirm-on-addition.
- Change mapping persistence from the current branch/category/role overwrite behavior to an exact-rule create/update contract.
- Extend Receipt Layout validation/preview for Reminder and timestamp toggles.
- Add Reminder queue/reprint routes under the existing printing jobs controller and document preview route under `PrintDocumentController`.

Route-catalog permissions should remain the system permission source. New route permissions are expected for queue/reprint Reminder, while existing printer/layout/mapping management permissions remain unchanged. Seeder/provisioner permission grants must be updated with the feature.

### D2. `PrintRoutingService`

Add reusable mapping selectors rather than a second routing service:

1. Apply branch/global scope.
2. Match `order_type in ('all', sale.order_type)`.
3. Match document role.
4. Require active mapping and active/capable printer.
5. Group by printer ID.

KOT keeps its existing per-line delta and fallback behavior. Reminder builds a unique printer plan from categories in the complete effective order. Category matching decides eligibility only; it does not filter Reminder contents.

If several categories match the same Reminder printer, emit one destination. If matching rules disagree on confirmation, `Ask=true` wins for that printer. This is conservative and deterministic.

### D3. `PrintJobService`

Extend the existing service with:

- logical-key-safe creation for all new KOT/Reminder jobs;
- an order-round orchestration method that queues KOT first, automatically queues eligible Reminder jobs, and returns unique ask-required printers;
- Reminder payload snapshots containing the complete effective order and a map of current batch delta quantities;
- explicit manual Reminder reprint with an independent copy counter;
- cancellation Reminder creation from the approved immutable cancellation records.

No new queue and no new service class are required.

The cancellation transaction should record cancellation events before building the Reminder payload, then queue both `CANCEL KOT` and cancellation Reminder in the same tenant transaction. The payload must snapshot cancelled and remaining quantities before any mutable order-line cleanup.

### D4. Rendering

- Add `resources/views/tenant/printing/documents/reminder.blade.php`.
- Add `EscPosPayloadService::buildReminder()`.
- Extend `PrintDocumentController` and layout preview selection.
- Extend job/report type labels and filters.

Both browser preview and ESC/POS payload must use the immutable job payload, not reload mutable current order lines.

### D5. POS

`fireKotSilently()` remains the only client call needed for an ordinary round. The endpoint response should include:

```json
{
  "jobs": [],
  "batch": {"id": 12, "sequence_no": 2},
  "reminder": {
    "auto_jobs": [],
    "confirmation_token": "signed-or-server-bound-token",
    "ask_printers": [{"id": 4, "name": "Kitchen Printer"}]
  }
}
```

When `ask_printers` is non-empty, POS shows one Yes/No popup after KOT queue success. Yes posts the server-bound batch/token and selected destination IDs to the Reminder endpoint. No does nothing. KOT jobs are already durable and are never rolled back by that answer.

The existing `handleKotAfterSale()` and `fireKotSilently()` behavior must remain recognizable and backward compatible. The implementation should append Reminder response handling after a successful KOT response rather than combining the two user prompts or moving KOT decisions into a new workflow.

Required POS sequence:

```text
Save/Hold/Add Round
  -> existing KOT auto setting or existing Print KOT prompt
  -> existing KOT endpoint and fallback behavior
  -> KOT jobs are durable
  -> server returns Reminder plan
  -> auto Reminder jobs are already queued
  -> optional Reminder-only resend popup for Ask=true printers
```

If KOT is skipped, no Reminder is sent and no Reminder popup appears.

Manual Reminder reprint belongs in Recent Prints/Recent Orders beside Receipt and KOT. It must clearly say `Reprint Reminder` and display the target printer/revision before enqueue.

## E. Final mapping UI

Rename `KOT Routing` to `Kitchen Print Routing` while keeping the existing route URL for compatibility.

The Add/Edit Routing modal should contain:

1. Branch
2. Order Type: All, Dine In, Takeaway, Quick Sale, Delivery
3. Category
4. Document: KOT or Reminder
5. Printer
6. Ask before resend on additional round (visible only for Reminder)
7. Active

Receipt remains terminal/default-printer driven and should not be forced through category routing.

The list should show Branch, Order Type, Category, Document, Printer, Additional Round (`Auto` or `Ask`), and Status. Exact duplicate rules should be rejected with a useful validation message.

The Layout screen adds `Reminder` as a third type and conditionally exposes Order Time, Updated Time, and Print Time toggles. Fiscal/payment controls should be hidden for Reminder.

## F. Exact routing algorithms

### F1. First KOT and Reminder

1. Persist the held/paid order and stable line identities.
2. Server computes unsent KOT quantities.
3. Create one normal KOT batch/revision 1.
4. Route each delta line by branch + order type + category + KOT.
5. Deduplicate by printer and create one KOT job per printer.
6. Find Reminder-eligible printers from all effective order categories.
7. Deduplicate by printer and automatically create one complete-order Reminder per printer.
8. Use logical keys to make a repeated request return the same jobs.

If the operator explicitly skips KOT under the current prompt, no order-round print event exists and no Reminder is sent. This decision is now locked.

KOT fallback remains independent:

- No configured KOT printer: open the existing browser/manual KOT preview.
- Configured printer but agent disconnected: leave the existing KOT job queued for the agent.
- Reminder has no browser fallback. A configured Reminder destination creates its own queued job; a missing Reminder route produces a readiness/configuration warning without affecting KOT.

### F2. Add Round

1. Save changes to the same held order.
2. Compute only increased/new KOT quantities.
3. Create Addition KOT batch/revision N.
4. Queue KOT delta jobs immediately.
5. Build Reminder destination set from the complete updated order.
6. Auto-queue printers whose matching rules are all `Ask=false`.
7. Return unique `Ask=true` printers to POS.
8. On Yes, queue one complete updated Reminder per approved printer; on No, queue none for those printers.

The same existing KOT auto/prompt/fallback sequence runs for every Add Round. Reminder processing begins only after that KOT send is accepted. If the cashier skips that round's KOT, neither automatic nor Ask-based updated Reminder is produced for that round.

### F3. Receipt

Receipt selection remains terminal receipt printer, then branch/global receipt fallback. Payment completion and ensure-once behavior remain unchanged. The same physical printer may receive independent Receipt, KOT, and Reminder jobs.

### F4. Approved cancellation

1. Preserve current permission/reason/manager-policy validation.
2. Lock the sale and lines.
3. Create the cancellation batch and immutable cancellation records.
4. Queue `CANCEL KOT` to category/order-type KOT destinations for cancelled quantities.
5. Resolve Reminder destinations as the union of currently eligible Reminder printers and printers that received the latest Reminder for this order. This prevents a configuration edit from hiding a required kitchen update.
6. Queue one cancellation Reminder per destination with cancelled quantities, complete remaining order, reason, requester, approver, and times.
7. Apply effective positive quantities to customer/fiscal output; never add negative fiscal lines.

Cancellation is an operational correction event and does not use the additional-round Reminder Ask popup. Once cancellation is approved, required Cancel KOT and cancellation Reminder jobs are automatic.

#### Sent item partially cancelled

Example: Burger quantity 3 was sent, then quantity 1 is approved for cancellation.

- Existing KOT cancellation routing prints `CANCEL KOT` for Burger x1 only.
- Reminder destinations receive one `CANCELLED / UPDATED ORDER` document.
- Its `Cancelled` section shows Burger x1.
- Its `Remaining Order` section shows the complete effective order, including Burger x2.
- The customer bill remains a positive Burger x2; no negative sale line is created.

#### Whole KOT-sent held order cancelled

- Current reason, permission, branch policy, and manager approval controls remain authoritative.
- All outstanding sent quantities are captured in one atomic cancellation action.
- Relevant KOT destinations receive Cancel KOT output through the existing fallback rules.
- Reminder destinations receive one `CANCELLED ORDER` document containing all cancelled items, reason, requester, approver, and times.
- Remaining-order section is empty or explicitly says `No remaining items`.
- Only after cancellation events and print jobs are durable may the held order become cancelled.

#### Unsent item removed

An item/quantity that has never been included in a successful KOT/Reminder round can be removed normally. It creates neither Cancel KOT nor cancellation Reminder because the kitchen was never instructed about it.

#### Cancellation destination history

Cancellation Reminder destinations are the union of:

1. printers currently eligible under branch + order type + category Reminder mappings; and
2. printers with a prior non-cancelled Reminder job for this order, including a job still queued because its agent was disconnected.

This union is deduplicated by physical printer. It intentionally includes a previous destination even when the mapping was later removed or the user declined an updated Reminder for a later round. That destination must receive the final correction because it may still be working from an earlier complete-order Reminder.

If the order never produced any Reminder and has no currently eligible Reminder route, cancellation remains Cancel-KOT-only. It must not invent a browser Reminder fallback.

Cancellation Reminder does not use `(R)`. Its visual priority is `CANCELLED`, cancelled quantities, and the complete remaining effective order.

### F5. Manual duplicate

An explicit reprint locks the sale/source event, calculates the next copy number for that document + revision + printer, creates one new logical key, and prints `DUPLICATE n`. Retrying that job preserves its copy number and key.

## G. Idempotency and duplicate numbering

Three identities must remain separate:

- Order revision/event: `kot_batches.event_uuid` + `sequence_no`.
- Destination document: event UUID + document type + printer.
- Explicit user copy: destination document + `copy_no`.

Automatic enqueue is one job per event/document/printer. Manual duplicate is a new job with a new copy number. Agent retry is the same job.

The current two-minute claim expiry can physically re-send a job if a printer succeeded but the agent lost the acknowledgment. That is a normal at-least-once delivery limitation, not a manual duplicate. The Print Agent should later retain a small local completed-job cache keyed by job ID/logical key to suppress this acknowledgment-loss replay. It still must not increment `copy_no`.

Before implementation, correct current KOT manual numbering so it is based on source revision + printer copies, not sale-wide `kot_print_count` (which increments once per printed destination).

## H. Running-order `(R)` derivation

No new line flag is required.

- Complete Reminder items come from the effective current order snapshot.
- Current-round quantities come from `kot_batch_lines` for the current normal/addition batch.
- On revision 1, no `(R)` marker is shown.
- On revision N > 1, a line is marked `(R)` only for the quantity represented by the current batch.
- If an existing item increases from 1 to 3, the Reminder can render the complete quantity with an explicit running delta, for example `Burger x3 (R +2)`. This is less ambiguous than marking all three as newly added.
- Addition KOT prints only the batch quantity and prefixes that line `(R)`.

Combo headers, components, modifiers, add-ons, and notes use the existing line kind/combo/modifier snapshots. Rendering must avoid double-counting combo headers and components.

## I. Online and future-offline behavior

| Capability | Cloud now | Future Edge | Until Edge runtime exists |
|---|---|---|---|
| KOT/order-type routing | Canonical server rules | Consume snapshot rules | Cloud only |
| First/updated Reminder | Cloud queue + Print Agent | Local queue + LAN agent | Cloud only |
| Manager-required cancellation | Existing authenticated manager approval | Authenticated local manager session using exported effective permissions | Block while disconnected |
| Branch auto-approved cancellation | Existing audited policy | Local policy snapshot + immutable event | Do not claim support yet |
| Completed sale return | Existing SalesReturnService with stock/ledger/GL | Synced local authority needed | Online only / pending cloud |
| Print retry | Same cloud job identity | Same local event/job identity | No manual duplicate increment |

The current Edge snapshot already exports users, role names, effective permissions, allowed/default order types, and cancellation restrictions. It intentionally does not export manager PIN hashes. Future local approval should use an authenticated local manager session and effective permission checks; it should not copy cloud PIN hashes into a new approval system.

The remaining offline dependency is a secure local authentication/session mechanism with revocation/expiry tied to acknowledged snapshots. Full offline sales capture, stock authority, finance reconciliation, returns, and sync are outside this feature.

## J. Edge bootstrap changes required later

`EdgeBootstrapService::sourceRevision()` currently includes `printers`, `receipt_layout_settings`, `category_printer_mappings`, and `terminal_printer_settings` (`:398-406`). Keep those tables in the watermark and ensure all new columns change `updated_at`.

Expand snapshot fields:

- `printers`: add `supports_reminder`;
- `receipt_layout_settings`: allow Reminder rows and include three timestamp flags;
- `category_printer_mappings`: add `order_type` and `reminder_confirm_on_addition`;
- no terminal setting change is required.

Bump `SCHEMA_VERSION` only when the future Edge consumer is ready for the new contract. Do not activate Local Mode as part of cloud Reminder delivery.

## K. Regression test matrix

### Routing and compatibility

- Old mapping migrated to `order_type=all` behaves exactly as before.
- Same category routes to different KOT printers for all four order types.
- Two printers can receive the same category/document/order-type event.
- Several matching categories create one Reminder job per printer.
- Branch rule/global rule precedence and deduplication are deterministic.
- Receipt/KOT/Reminder can target the same physical printer as independent jobs.

### Order rounds

- First send: KOT #1 plus one complete Reminder per eligible printer.
- Add Round: Addition KOT contains only delta; Reminder contains complete order.
- Ask=false auto-sends; Ask=true produces one unique-printer popup.
- No response/No never blocks or cancels KOT.
- Replayed HTTP request returns existing logical jobs.
- Existing quantity increase renders only the delta as running.
- Combo/deal components, modifiers, add-ons, and notes render once and correctly.

### Duplicate/retry

- Manual KOT copies increment independently per source revision/printer.
- Manual Reminder copies increment independently from KOT.
- Agent retry, queue retry, and lost response do not increment copy count.
- Concurrent enqueue attempts produce one automatic job per logical key.
- Multi-printer printing does not distort copy numbers.

### Cancellation/return

- Manager-required and auto-approved cancellation both emit Cancel KOT and cancellation Reminder.
- Partial cancellation lists cancelled and remaining quantities without amounts.
- Whole held-order cancellation lists all cancelled items and no remaining items.
- Reason/requester/approver/times are immutable snapshots.
- Cancel reprint increments only the relevant document copy counter.
- Customer receipt, ledger, stock, and GL remain based on effective positive quantities.
- Completed sale uses Sales Return; no negative sale or Reminder cancellation path bypasses it.

### Layout, permissions, and Edge contract

- Reminder browser/58mm/80mm output contains no fiscal fields.
- Time toggles work independently.
- Mapping/layout/job routes enforce generated permissions.
- Seeder creates an understandable KOT + Reminder demo configuration.
- Bootstrap source revision changes when routing/layout/printer capability changes.
- Snapshot contains the new fields but Local Mode remains inactive.

### Real agent certification

- Normal KOT, Addition KOT, `(R)`, Reminder, Updated Reminder, manual copies, Cancel KOT, cancellation Reminder, Receipt, failure/retry, and reconnect are tested against a real LAN Print Agent.
- Verify acknowledgment-loss behavior with local completed-job cache before claiming exactly-once physical printing.

## L. Locked decisions and remaining blockers

The five product decisions are locked:

1. Skip KOT also skips Reminder for that round.
2. Increased complete-order quantity renders as `Burger x3 (R +2)`.
3. Mixed Reminder rules for one printer resolve to Ask=true.
4. Cancellation Reminder includes previous Reminder destinations even if current mapping changed.
5. Reminder has no browser fallback; missing/unavailable configuration is exposed as readiness status without changing KOT fallback.

Additional cancellation decisions are locked:

6. Approved cancellation never asks the additional-round Reminder resend question; cancellation documents are automatic.
7. Sent-item cancellation produces both relevant Cancel KOT and cancellation Reminder documents.
8. Whole sent-order cancellation uses the same audited path and automatic correction documents.
9. Removing never-sent quantity produces no kitchen cancellation document.
10. A previously queued Reminder destination counts as a historical destination even if its agent was offline.

Technical blockers to close during implementation:

- replace the old category mapping unique key and overwrite behavior;
- add database logical keys for concurrent enqueue idempotency;
- correct KOT manual copy numbering before sharing the mechanism with Reminder;
- snapshot complete Reminder data before mutable order changes;
- add Print Agent local completed-job suppression for acknowledgment-loss retries if exactly-once physical output is required.

## Recommended implementation sequence

1. Treat deployed POS/KOT integrity behavior as the frozen baseline.
2. Migration + model/controller compatibility for routing/document types.
3. Order-type-aware KOT routing while preserving all current fallback/prompt behavior.
4. Logical-key and per-document/per-printer duplicate hardening.
5. Reminder layouts and immutable payload rendering.
6. First/additional Reminder orchestration and POS Reminder-only confirmation.
7. Cancellation Reminder integration and historical-destination fan-out.
8. Seeders, permissions, reports, and Edge bootstrap configuration export.
9. Full cloud regression and real LAN Print Agent certification for KOT, Receipt, and Reminder.
10. Release as one tested printing candidate; offline runtime/auth/sync remains a separate future phase.

## M. PRINT-ROUTING-FOUNDATION-1 implementation audit

Implemented on 2026-08-03 as the foundation-only sprint. Reminder queueing, rendering, POS prompts, cancellation Reminder output, Edge activation, and sync remain out of scope.

### Schema and compatibility

- Tenant migration `2026_08_03_000002_add_print_routing_foundation.php` adds `category_printer_mappings.order_type` with `NOT NULL DEFAULT 'all'` and normalizes null/empty legacy values to `all`.
- The old `(branch_id, category_id, print_role)` unique key is replaced by `cpm_route_printer_unique` on `(branch_id, category_id, printer_id, print_role, order_type)`.
- Global mappings remain nullable by branch. The controller locks the category and performs an exact duplicate check transactionally because MySQL permits duplicate composite keys when the nullable branch component is `NULL`.
- `category_printer_mappings.print_role`, `receipt_layout_settings.document_type`, and `print_jobs.document_type` are widened to strings so the future `reminder` value is schema-safe. Reminder remains hidden from creation screens until its renderer exists.
- `printers.supports_reminder` defaults to false. Existing `printers.print_role` and all terminal printer settings remain unchanged.
- Layout rows gain `show_order_time`, `show_updated_time`, and `show_print_time`, all defaulting true. Existing Receipt/KOT output does not consume these new flags.
- Print jobs gain nullable unique `logical_key` and unsigned `copy_no` defaulting to 1. Existing rows remain valid because nullable unique keys permit legacy `NULL` values.

### Routing and logical print identity

- Existing `PrintRoutingService` now accepts active rules whose `order_type` is either `all` or the sale's exact order type, then deduplicates by capable physical printer.
- Existing line delta, terminal/default KOT fallback, browser fallback, queued-agent behavior, KOT prompt, auto-KOT setting, Receipt routing, and ensure-once Receipt logic are unchanged.
- Automatic KOT jobs use `kot:{kot_batch.event_uuid}:{printer-N|browser}`. Delta calculation, batch creation, job creation, and sent-quantity reservation are serialized under the sale lock.
- A concurrent unique-key loser returns the existing print job. Print Agent retry continues to update and retry that same row.
- Manual KOT duplicates use `kot-copy:{source-event}:{destination}:{copy_no}`. `copy_no` is an explicit duplicate ordinal scoped to source KOT revision plus physical printer/browser. The first manual copy is `DUPLICATE 1`; automatic fan-out and agent retries do not increment it.

### QA evidence

- Migration applied cleanly to all seven local tenants. Every tenant reported zero null/empty legacy order types.
- Actual MySQL index inspection returned the exact five-column `cpm_route_printer_unique` order.
- Rollback-clean routing harness proved Dine In, Takeaway, Quick Sale, and Delivery can route the same category to four different printers.
- The same category/order type produced two routes and two automatic logical keys, one per physical printer.
- Default KOT printer fallback and browser fallback both remained operational.
- Receipt `ensureOnce` returned the same job on repeated requests.
- Manual duplicate QA returned copies `[1, 2]` independently for Printer A and Printer B; marking a job failed/retryable kept copy 1.
- Real two-process enqueue against the same MySQL tenant returned the same job ID and logical key from both workers; the database contained exactly one logical job and no worker failed.
- Unit regression passed for POS fresh-cart initialization, KOT/addition/cancel payloads, duplicate label, and user order-type policy. The focused routing test is committed and runs where `pdo_sqlite` is available; local PHP lacks that extension, so the MySQL harness is the local routing authority.
- All seven tenants finished with `tb_diff=0`, zero negative stock on disallowed branches, and zero negative department balances. Temporary routes, printers, batches, and jobs were rolled back or deleted.

### Edge configuration contract

`EdgeBootstrapService::sourceRevision()` includes printers, layouts, category mappings, and terminal printer settings. `REMINDER-PRINT-1` bumps the configuration snapshot to `edge-bootstrap-v3` and exports `printers.supports_reminder`, `category_printer_mappings.order_type`, `reminder_confirm_on_addition`, and the three Reminder timestamp flags. This is configuration delivery only: Local Mode remains inactive and no local Reminder runtime or sync behavior is implemented.

## N. DIRECT REVIEW & PAY PRINT ORCHESTRATION

Implemented by `DIRECT-PAY-PRINT-ORCHESTRATION-1` on 2026-08-03.

### Previous race

Direct Pay previously committed the paid sale, then launched Receipt and KOT requests from unrelated browser promises and immediately cleared the cart. A browser/network loss could therefore leave a valid paid sale without a durable record of whether KOT was accepted or skipped. Additional-round Reminder Ask could also outlive the mutable cart it came from.

### Canonical flow

1. Before final payment submission, POS resolves KOT intent to `print` or explicit `skip`. Auto-KOT resolves to `print`; otherwise the existing Print KOT/Skip choice is shown before payment.
2. The payment transaction stores `direct_pay_print_state` with KOT and Receipt intent/status alongside the paid sale. Accepted KOT therefore survives browser loss.
3. After the payment transaction commits, `DirectPayPrintOrchestrator` locks the sale and reuses `PrintJobService` for Receipt ensure-once, delta-only KOT batching/routing, automatic Reminder jobs, and server-bound Reminder Ask tokens.
4. Each recoverable application failure is stored as `pending_retry`; it never rolls back or invalidates payment.
5. The POS clears the cart only after a stable server result exists. Fallback preview URLs and the Reminder Ask plan no longer read mutable cart lines.

### Locked behavior

- Skip KOT is durable and produces no KOT, no Reminder, and no KOT retry warning.
- Receipt remains payment-driven and independent of KOT/Reminder success.
- KOT routing, browser fallback, queued-agent behavior, and manual Duplicate numbering are unchanged.
- A recalled/held sale with no unsent delta becomes `not_required`; Direct Pay never reuses or reopens an older KOT batch as a new round.
- Reminder follows an accepted KOT batch only. First revision auto-sends; later Auto/Ask routing is unchanged.
- Ask Yes/No is recorded against the exact server-bound batch. Browser disappearance leaves it `awaiting_confirmation`; it is never silently auto-sent.
- Recent Orders exposes `Printing needs attention` and a Resume action for pending/retryable or unanswered Ask state, so recovery after browser loss uses the original automatic identities rather than manual reprint.
- Retry Printing resumes the same sale/state and automatic logical jobs. It is not a manual duplicate and does not increment `copy_no`.
- Hold Sale and cancellation/return flows do not write this Direct Pay state and retain their existing behavior.

### Idempotency and QA evidence

- Auto Receipt now uses unique logical key `receipt:auto:sale-{id}`; simultaneous ensure requests converge while manual receipt reprint remains separate.
- Existing KOT identity remains `kot:{batch_event_uuid}:{destination}` and Reminder remains `reminder:{batch_event_uuid}:{printer}`.
- All seven local tenants accepted migration `2026_08_03_000004_add_direct_pay_print_state.php`.
- A rollback-clean real MySQL Direct Pay replay created one KOT batch and one logical KOT job on the first run; the second run created zero batches/jobs and returned the same job IDs. The sale remained paid.
- A committed two-process MySQL race converged to one Receipt job, one KOT batch, and one KOT job; both workers returned identical IDs, no worker failed, and `copy_no > 1` remained zero. The synthetic sale and all related rows were deleted after evidence capture.
- Blade and route caches compile. KOT/Reminder payload regression is green. Isolated SQLite feature tests are committed but skip on this local PHP build because `pdo_sqlite` is unavailable.

### Future Edge parity

Local Branch Server payment/order finalization must persist the same print intent/event state before browser state is discarded. Local browser JavaScript must never be the sole owner of pending KOT, Reminder, or Receipt work. Edge retry/reconnect must preserve the cloud event identities above. This sprint does not activate Local Mode and does not implement sales synchronization.
