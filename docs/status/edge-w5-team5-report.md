# W5 — Team 5 report: kitchen printing & documents (Offline Edge cashier parity)

Baseline: branch `feat/edge-config-refresh-v1` @ 63b88d8 (+ other teams' uncommitted work in the same tree). No git operation, no
LAB / production / live-tenant touch, no second print agent, no envelope / outbox / authority edit. Shared printing services
(`PrintJobService`, `PrintRoutingService`, `KotCancellationService`, `DirectPayPrintOrchestrator`, `EscPosPayloadService`,
`PrintDocumentController`) are **used, not changed** (0-line diff in `app/Services/Printing`, `app/Services/Sales`).

## Files

| File | Change |
|---|---|
| `app/Http/Controllers/Edge/EdgeLocalPrintJobController.php` | rewritten: UserDataScope on every action (D-23), new actions `queueKot`, `confirmReminders`, `reprintReminder`, `dismissPrintJob`, `retryDirectPayPrinting`, `billPreviewDocument`, `printPreferences`; per-sale list returns every job + Online fields (`line_count`, `revision`, `copy_no`, `has_document`, `error_message`); report jobs get a business 404 on Print Here |
| `app/Services/Edge/EdgeLocalPrintKotService.php` (new) | Online `PrintJobController::queueKot/confirmReminders/reprintReminder` on the appliance (current-terminal routing, authority gate, draft refusal, Reminder planning) + `queueLineVoidCorrectionReminders()` (D-06) |
| `app/Services/Edge/EdgeLocalPrintDirectPayService.php` (new) | D-08: `afterPaidSale($sale, $kotIntent, $receiptIntent)` + `retry($sale)` through the shared `DirectPayPrintOrchestrator`, Edge document URLs |
| `app/Services/Edge/EdgeLocalPrintDocumentService.php` (new) | D-10 canonical BILL PREVIEW (cart via `EdgeLocalPosService::previewBill`, or a saved check); D-24 print preferences |
| `app/Services/Edge/EdgeLocalPrintDeliveryService.php` | `dismiss()` (shared `cancelObsolete` + live-lease guard); `retryTerminalFailed()` also re-queues a dismissed (`cancelled`) job (Online `requeueFailed` accepts cancelled) |
| `resources/views/edge/pos/js/printing.blade.php` | rewritten (see interface below) |
| `routes/edge_runtime.php` W5 block, `tests/Feature/Edge/EdgeBranchServerRegistrationTest.php` W5 block | 7 routes |
| `config/edge.php` `route_allowlist` | **7 names appended in a marked W5 block — see coordinator request C1** (without them every new route 404s on a branch_server; the global `EnsureEdgeRuntimeRouteAllowed` middleware is default-deny) |
| `tests/MySql/EdgeCashierPrintingParityHttpMySqlTest.php` (new) | 8 HTTP tests over the real `/edge/local/pos/*` routes |

The Edge print worker command needed **no change**: `EdgeLocalPrintDeliveryService::claimNext` is document-type agnostic, so
Reminder jobs (network printer, `raw_payload` built by the shared `buildReminder`) are claimed and delivered like KOTs — proven
byte-exact on a fake TCP printer (D-04 test).

### New endpoints (W5 block; Online `tenant.printing.jobs.*` / `documents.*` are permission-free + UserDataScope-scoped — mirrored)

| Edge route (name) | Online equivalent |
|---|---|
| `POST /edge/local/pos/sales/{sale}/kot` (`sales.kot`) — body `reprint`, `line_ids[]` | `POST /printing/jobs/kot/{salesOrder}` (`PrintJobController::queueKot` :79-155) |
| `POST /edge/local/pos/sales/{sale}/reminders/confirm` (`sales.reminders.confirm`) | `POST /printing/jobs/reminder/{salesOrder}/confirm` (:157-180) |
| `POST /edge/local/pos/print-jobs/{job}/reminder-reprint` (`print-jobs.reminder-reprint`) | `POST /printing/jobs/{printJob}/reminder-reprint` (:182-193) |
| `POST /edge/local/pos/print-jobs/{job}/dismiss` (`print-jobs.dismiss`) | `POST /printing/jobs/{printJob}/dismiss` (:266-285) |
| `POST /edge/local/pos/sales/{sale}/printing/retry` (`sales.printing.retry`) | `POST /pos/{salesOrder}/printing/retry` (`SalesOrderController::retryDirectPayPrinting` :887-900) |
| `POST /edge/local/pos/bill-preview/document` (`bill-preview.document`) | `POST /api/pos/bill-preview` (`POSController::billPreview` :626-800) |
| `GET /edge/local/pos/print-preferences` (`print-preferences`) | `terminalPrintConfig` in `POSController` :484-489 |

### JS interface (js/printing) for other teams

`printPrefsHtml()` (Online `#print-pref-panel` markup with Online ids) · `readPrintPrefs()` → `{kot_print_intent, receipt_print_intent}`
(Online `resolveDirectPayKotIntent`: nothing pending → skip; auto → print; otherwise the "Print Kitchen Order?" question) ·
`printBillPreview(payload)` (also accepts the Preview Bill modal's `{target:'here'|'network', held_sale_id, lines, …}`) ·
`openPrintHere(job|jobId)` · `openLastPrint(saleId, saleNo)` · `openKotReminder(saleId)` · `fireKot(saleId, {reprint, lineIds})` ·
`handleReminderPlan(saleId, reminder)` · `handlePrintJobs(jobs, label)` · `processDirectPayPrinting(saleId, printing)` ·
`afterSalePrinting(saleResponse)` · kept: `autoReceipt(saleId, saleNo)`, `printHere(job)` (→ `openPrintHere`), `recentPrints()`.
State: `lastPrintSale` (used by Team 1's `#last-print-btn` wiring), `printPrefs`, `autoPrintEnabled(kind)` (same localStorage keys
as Online: `pos_auto_kot`, `pos_auto_receipt`).

---

## Records

### D-01 KOT routing — 3-station category → printer mapping on the Edge path
- ONLINE_BEHAVIOUR: `PrintJobController::queueKot` :79-96 passes `operatorTerminalId(terminal_id)`; `PrintRoutingService::kotRoutesForSale` (category rules with terminal precedence → terminal kot printer → branch default → browser), one ticket per category per printer (`PrintJobService::createKotJob` destination `printer-N#cat-M`).
- EDGE_IMPLEMENTATION: `EdgeLocalPrintKotService::queueKot` → shared `queueKot($sale, null, $lineIds, (string)$terminal->id)`; endpoint `POST /sales/{sale}/kot`. Sent bookkeeping exactly as Online (network at queue time via shared `markKotLinesQueued`; browser ticket at Mark Printed).
- PERMISSION_AND_VALIDATION: permission-free (Online exempt prefix) + `UserDataScope::deniesSale` (403); terminal = `selectedTerminal()`; authority gate (`assertLocalMutationAllowed`); held/paid only; draft refused (422).
- EXECUTABLE_TEST: `EdgeCashierPrintingParityHttpMySqlTest::test_three_station_kot_routing_from_another_counter_additions_and_duplicates_delivered_by_the_edge_worker` — BBQ + Fastfood station rules + Beverages pinned per terminal; order punched at A, KOT sent from B → 3 tickets (BBQ Station, Fastfood Station, Counter B KOT), all stamped terminal B, sale keeps A, `kot_category` per ticket; the real `edge:local:print-worker --once` ×3 delivers each ticket's exact stored bytes + `\n\n\n` to three separate fake TCP printers (free ports, never 9100); a station ticket never carries another category.
- BROWSER_ACCEPTANCE_STEP: after Team 3 switches `sendKot` to `fireKot` (request T3-1): hold a dine-in order with items from two station categories → KOT → toast lists each printer; Recent Prints shows one KOT row per station. LAB 3-station proof = coordinator.
- CENSUS_FLIPS: none (KOT has no Online control id).
- REMAINING_DIFFERENCE: the existing Team 3 `held.kot` path (`EdgeLocalPosService::queueKotEvents`) still routes on the sale's terminal and marks browser tickets sent at queue time, and plans no Reminder — until T3-1/T2-2 land, the page's "KOT" button uses it. Online also auto-fires / asks for the KOT on Hold (`handleKotAfterSale`); Edge's Hold → KOT flow is Team 3's (request T3-1 gives them `autoPrintEnabled('kot')`).
- STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN (HTTP + worker proven; page wiring pending T3-1).

### D-02 Addition KOT deltas — verify
- ONLINE_BEHAVIOUR: `PrintJobService::createKotBatch` :802-812 (seq max+1, `addition`), heading `*** ADDITION KOT #n ***` (`EscPosPayloadService` :1002).
- EDGE_IMPLEMENTATION: same shared path through `POST /sales/{sale}/kot`.
- PERMISSION_AND_VALIDATION: as D-01.
- EXECUTABLE_TEST: same test — Add Round (+1 BBQ) → one job, BBQ Station only, `event_type=addition`, `line_quantities=[1]`, `kot_sequence_no=2`, raw payload contains `ADDITION KOT #2`; a second send → 200 "No new items to send to kitchen".
- BROWSER_ACCEPTANCE_STEP: Save round → KOT → Recent Prints row "KOT · addition".
- CENSUS_FLIPS: none. REMAINING_DIFFERENCE: none on paper. STATUS: MATCHED_AND_PROVEN (HTTP).

### D-03 DUPLICATE KOT #n — verify
- ONLINE_BEHAVIOUR: `queueKot(isReprint)` → `kot-copy:<event>:<destination>:<n>` (`PrintJobService` :715-723); `*** DUPLICATE KOT #seq ***` + `DUPLICATE <copy>`.
- EDGE_IMPLEMENTATION: `POST /sales/{sale}/kot {reprint:true}` (new, Online-shaped) and the existing `POST /sales/{sale}/kot-reprint`; Last Print `#reprint-all-kot-btn` / row Reprint use the new route.
- EXECUTABLE_TEST: same test — two reprints → copy 1 then 2 per destination, `event_type=duplicate`, `DUPLICATE KOT #2` + `DUPLICATE 2` in bytes, counter category at the reprinting counter, no new kot_batch. Existing `EdgeCashierPrintingHttpMySqlTest::test_kot_reprint_falls_back_to_the_stored_copy_after_line_churn_and_reprint_renders` still green.
- BROWSER_ACCEPTANCE_STEP: Recent Prints → click a sale no → `#lastPrintModal` → `#reprint-all-kot-btn`.
- CENSUS_FLIPS: `reprint-all-kot-btn` equivalent → **present** (`#reprint-all-kot-btn`). STATUS: MATCHED_AND_PROVEN (HTTP).

### D-04 KOT REMINDER
- ONLINE_BEHAVIOUR: `PrintJobController::queueKot` :98-114 (`planRemindersForKotJobs`), `confirmReminders` :157-180, `reprintReminder` :182-193; `PrintRoutingService::reminderRoutesForSale` :19-99; page `handleReminderPlan` (`pos/index.blade.php` :4322-4363), Recent Prints Reminder rows (:5425-5445, :5575-5587).
- EDGE_IMPLEMENTATION: `EdgeLocalPrintKotService::queueKot` plans the round after every normal/addition KOT (failure → Online warning, KOT kept); `confirmReminders` (shared token validation + `markReminderDecision`); `reprintReminder` (shared `queueReminderReprint`). Endpoints `POST /sales/{sale}/kot` (returns `reminder{revision, auto_jobs, ask_printers, confirmation_token, warning}`), `POST /sales/{sale}/reminders/confirm`, `POST /print-jobs/{job}/reminder-reprint`. Worker: no change needed (document-type agnostic). UI: `handleReminderPlan` → `#modal` "Resend updated Reminder?" (`#reminder-confirm-btn` / `#reminder-decline-btn`); Last Print Reminder rows show "Rev n · Duplicate n" with Reprint; `openKotReminder(saleId)` opens the sale's Reminder slips (or explains that none printed yet).
- PERMISSION_AND_VALIDATION: permission-free + deniesSale / job scope; token bound to sale, branch, user, round, routing (422 on forged / stale); network-only reprint (422 on a browser job).
- EXECUTABLE_TEST: `test_reminder_auto_round_delivered_ask_on_addition_confirm_decline_and_duplicate_reprint` — round 1 auto Reminder to a `supports_reminder` printer (all-categories `print_role=reminder` mapping, Ask on addition) → worker delivers exact bytes, delivery `delivered`; round 2 → `ask_printers=[Punching Counter]` + token; forged token 422; Yes → 1 slip, `UPDATED ORDER`, `REVISION 2`; same token again → no second slip; round 3 → No → no slip; Reminder reprint → copy 1 then 2 (`DUPLICATE 1`), `is_reprint`; browser KOT reminder-reprint 422; Reminder Print Here document renders; per-sale list `revision [1,2,1,1]`, `copy_no [1,1,1,2]`, `is_reprint [f,f,t,t]`.
- BROWSER_ACCEPTANCE_STEP: (after T3-1) hold → KOT with a Reminder printer mapped (Ask on addition) → add a round → KOT → `#modal` "Resend updated Reminder?" → Yes/No; Recent Prints → sale → Reminder rows → Reprint.
- CENSUS_FLIPS: none (Online uses Swal, no id).
- REMAINING_DIFFERENCE: fires from the page only once Team 3 calls `fireKot` (T3-1) and Team 2's direct pay reaches the orchestrator (T2-1). Swal vs Edge `#modal`.
- STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN.

### D-05 Cancellation KOT — whole order (verify)
- ONLINE_BEHAVIOUR: `HeldSaleController::cancel` :1043-1080 → `KotCancellationService::cancelHeldOrder(…, operator terminal)`; page opens fallback previews for `cancel_kot_jobs` (:4905-4921).
- EDGE_IMPLEMENTATION: unchanged Team 3 route `POST /held-sales/{sale}/cancel` (already passes the session terminal); W5 provides `handlePrintJobs(jobs,'CANCEL KOT')` for fallback tickets.
- EXECUTABLE_TEST: `test_whole_order_cancel_and_line_void_print_cancel_kot_and_correction_reminder_at_the_acting_counter` — cancel from Counter B → CANCEL KOT on **B's network printer**, terminal B, `CANCEL KOT #3` in bytes, one `cancelled_order` Reminder at B, order keeps terminal A.
- BROWSER_ACCEPTANCE_STEP: open check → Cancel order → reason → Recent Prints shows `KOT · cancel` on the counter printer.
- CENSUS_FLIPS: none. REMAINING_DIFFERENCE: the cancel response carries no `jobs`, so a browser-fallback CANCEL KOT is not auto-opened (request T3-2). STATUS: FUNCTIONAL_BUT_UI_DIFFERENT.

### D-06 Line-void cancellation KOT + correction Reminder
- ONLINE_BEHAVIOUR: `HeldSaleController` :569-575 `recordLineCancellations(…, operatorTerminalId)` then :806-813 `queueCorrectionReminders($sale, $batch, false, operatorTerminalId)` after the lines are recreated.
- EDGE_IMPLEMENTATION: `EdgeLocalPrintKotService::queueLineVoidCorrectionReminders($sale, $terminalId, $afterBatchId)` — targets the cancel batch the revise just created (newer than `$afterBatchId`; without it only a cancel batch from the last 10 min), checks it carries line-scope cancellations, calls the shared `KotCancellationService::queueCorrectionReminders` on the fresh (revised) sale. Idempotent (shared logical key). KotCancellationService not edited (Team 6 reconcile).
- PERMISSION_AND_VALIDATION: shared `tenant.pos.void-kot-item` + reason + approval mode inside `recordLineCancellations`.
- EXECUTABLE_TEST: same test — revise with `void_items` at Counter B → one CANCEL KOT (`CANCEL KOT #2`); service call → ONE `cancelled_updated_order` slip on B's printer, terminal B, `CANCELLED / UPDATED ORDER`; two repeat calls → still one.
- BROWSER_ACCEPTANCE_STEP: needs Team 3's void-with-reason UI (A11/R25) + T3-3.
- CENSUS_FLIPS: none.
- REMAINING_DIFFERENCE: (1) `EdgeLocalPosService::reviseHeldSale` (Team 2, line ~1030) calls `recordLineCancellations` **without the terminal** → the CANCEL KOT itself routes on the sale's terminal (shared fallback `$terminalId ?: $sale->terminal_id`; request T2-2); (2) the correction Reminder needs the caller hook (T3-3). 
- STATUS: PARTIALLY_IMPLEMENTED (W5 part done + proven; caller wiring = T2-2/T3-3).

### D-07 Receipt after payment honouring the auto-print preference
- ONLINE_BEHAVIOUR: `maybePrintReceipt` (`pos/index.blade.php` :4389-4406): `autoPrintEnabled('receipt')` = this-device override (`localStorage pos_auto_receipt`) else `terminalPrintConfig[terminal].auto_print_receipt`; off → no receipt; fallback → preview.
- EDGE_IMPLEMENTATION: `autoReceipt(saleId, saleNo)` now checks `autoPrintEnabled('receipt')` (prefs from `GET /print-preferences`, same localStorage keys); fallback opens `openPrintHere`. `afterSalePrinting(sale)` prefers Direct Pay printing when the sale carries intents.
- PERMISSION_AND_VALIDATION: deniesSale on `/sales/{sale}/receipt`; ensure-once unchanged.
- EXECUTABLE_TEST: existing `EdgeCashierPrintingHttpMySqlTest::test_receipt_is_ensure_once_…` green; preference data `test_bill_preview_document_…` (D-24 part); ensure-once shared with Direct Pay in `test_direct_pay_intents_…`.
- BROWSER_ACCEPTANCE_STEP: Review & Pay → `#auto-receipt-toggle` off → Complete Sale → toast "Receipt: auto-print is off…", no job; toggle on → receipt job.
- CENSUS_FLIPS: see D-24.
- REMAINING_DIFFERENCE: **behaviour change to note**: like Online, a counter with no `terminal_printer_settings` row (or `auto_print_receipt=0`) now prints NO automatic receipt unless the operator ticks the toggle (Online `terminalAuto` returns false). Online `watchPrintFailure` (failed-print Swal within 15 s) not mirrored — the Edge worker only fails terminally after 6 attempts (~4 min), so the poll would never fire; failures show in Recent Prints (red row, Print Here / Retry).
- STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN.

### D-08 Direct Pay KOT parity (kot_print_intent / receipt_print_intent)
- ONLINE_BEHAVIOUR: `SalesOrderController::store` :111-118 (intents required on `tenant.pos.store`, `initialState`), :616 `orchestrate`; replay re-orchestrates (:629-643); `retryDirectPayPrinting` :887-900; page `resolveDirectPayKotIntent` / `processDirectPayPrinting` (:4508-4596).
- EDGE_IMPLEMENTATION: `EdgeLocalPrintDirectPayService::afterPaidSale()` (initial state if absent → shared `orchestrate`, Edge URLs, never fails the sale) + `retry()`; endpoint `POST /sales/{sale}/printing/retry`; JS `readPrintPrefs()`, `processDirectPayPrinting()`, `afterSalePrinting()`. Team 2 already validates the two fields and persists `direct_pay_print_state` inside the sale transaction (`EdgeLocalPosService::directPayPrintState`, `EdgeLocalPosController` :467-468, :524 returns `print_intents`); with that, the page path already works through `afterSalePrinting` → `/printing/retry` (= orchestrate).
- PERMISSION_AND_VALIDATION: intents `in:print,skip` (Team 2); retry → deniesSale; 422 "This sale has no Direct Pay print intent." / "Only a paid sale can resume Direct Pay printing." (Online messages).
- EXECUTABLE_TEST: `test_direct_pay_intents_print_the_kot_and_receipt_once_through_the_shared_orchestrator` — no intents → null; print/print → KOT on the kitchen printer + receipt on the counter's receipt printer, Edge preview URLs; repeat + HTTP retry reuse the same jobs (1 KOT, 1 receipt); page ensure-once receipt returns the same bill; skip/skip → nothing; retry on a no-intent sale → 422.
- BROWSER_ACCEPTANCE_STEP: (after T2-1) takeaway → Review & Pay shows `#print-pref-panel` → Complete Sale → KOT + receipt jobs in Recent Prints (or Print Here for fallback), "Resend updated Reminder?" when applicable.
- CENSUS_FLIPS: none beyond D-24.
- REMAINING_DIFFERENCE: Team 2 must render `printPrefsHtml()`, send `readPrintPrefs()` and call `afterSalePrinting(sale)` instead of `autoReceipt(sale.sale_id)` (T2-1). The Direct-Pay KOT batch is created AFTER the sale's outbox envelope was built, so its `kot_events` entry is not in the envelope (Online-identical timing; Team 6 note W6-1). Intents stay local (the envelope builder reads explicit fields only; `direct_pay_print_state` is not among them).
- STATUS: PARTIALLY_IMPLEMENTED (server + JS done and proven; the page call is Team 2's).

### D-09 Receipt reprint (copy identity) — verify
- ONLINE_BEHAVIOUR: `queueReceipt(ensureOnce=false)` (`PrintJobService` :92-96), no DUPLICATE marker on receipts.
- EDGE_IMPLEMENTATION: unchanged route; `#reprint-receipt-btn` in `#lastPrintModal`, row Reprint, bill-preview `#send-network-receipt-btn`.
- EXECUTABLE_TEST: existing `EdgeCashierPrintingHttpMySqlTest` (fresh job, cross-counter routing, worker claims exact bytes); `test_bill_preview_document_…` (send-to-network on a saved check → counter receipt printer).
- BROWSER_ACCEPTANCE_STEP: Recent Prints → sale → `#reprint-receipt-btn`.
- CENSUS_FLIPS: `reprint-receipt-btn` equivalent → **present**; `send-network-receipt-btn` equivalent → **present** (`#send-network-receipt-btn`, bill preview). STATUS: MATCHED_AND_PROVEN (HTTP).

### D-10 Bill preview print — Print Here + Send to network
- ONLINE_BEHAVIOUR: `POSController::billPreview` :626-800 renders `tenant.printing.documents.receipt` (`isPreview`) for a transient sale; footer `#send-network-receipt-btn` (saved order only, WRONG-BILL rule :5845-5872) / `#print-bill-preview-btn` (frame print :5878-5890).
- EDGE_IMPLEMENTATION: `POST /bill-preview/document` → `EdgeLocalPrintDocumentService` (cart: server prices/totals from `EdgeLocalPosService::previewBill`, deal header→component linkage; saved check: the real row); `printBillPreview(payload)` renders `#bill-preview-frame` (srcdoc) with `#send-network-receipt-btn` and `#print-bill-preview-btn`; `target:'network'` sends the saved order's receipt directly, `target:'here'` auto-opens the print dialog (Team 2's Preview Bill already calls it with this shape). TABLE payload (Team 3 R14 request): `{restaurant_table_session_id | table_session_id, held_sale_ids[], target?}` → renders Team 3's `GET /restaurant/table-sessions/{s}/bill-preview` `html` in the same frame; Send to network re-queues the receipt of EACH held id (canonical BILL-PREVIEW-WRONG-PRINT-1 print target).
- PERMISSION_AND_VALIDATION: selected terminal required; deniesSale for `sale_id`; only held/paid orders; cart needs lines (422); zero mutation.
- EXECUTABLE_TEST: `test_bill_preview_document_renders_the_canonical_bill_without_mutation_and_preferences_expose_terminal_auto_print` — cart → "BILL PREVIEW", item names, 750.00 server total, no sale / print job created; saved check → its sale no; send to network → counter receipt printer.
- BROWSER_ACCEPTANCE_STEP: Preview Bill → Print here (print dialog) / Send to network (unsaved cart → "Hold or pay the order first").
- CENSUS_FLIPS: `print-bill-preview-btn` planned → **present** (`#print-bill-preview-btn`); `bill-preview-frame` planned → **present** (`#bill-preview-frame`).
- REMAINING_DIFFERENCE: canonical 243e01d TABLE-BILL-PREVIEW-PARITY-1 (`@isset($tableBill)` rounds / previously-paid block in `receipt.blade.php`) is not in this tree — the per-table bill renders the single check without the rounds section until Team 6 reconciles (audit §F).
- STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN.

### D-11 Print Here as an iframe modal
- ONLINE_BEHAVIOUR: `#printHereModal` / `#print-here-frame` / `#print-here-print-btn`, `openPrintHere(jobId)` auto-fires print on load (`pos/index.blade.php` :1835-1870).
- EDGE_IMPLEMENTATION: `openPrintHere(job)` renders the canonical document in `#print-here-frame` inside the shared `#modal`, auto-prints after load, Print / "Open in window" (policy fallback) / Printed (fallback jobs) / Close; `printHere()` delegates. The document's own Mark Printed form still posts to the Edge route.
- PERMISSION_AND_VALIDATION: document + printed follow the job's sale scope (D-23); network jobs still refuse Mark Printed (422).
- EXECUTABLE_TEST: documents render via HTTP in D-04 / D-13 tests; page carries the ids (`test_the_cashier_page_carries_the_w5_printing_controls`).
- BROWSER_ACCEPTANCE_STEP: Recent Prints → View on a fallback row → frame opens, print dialog appears.
- CENSUS_FLIPS: group `print-here` partial → **present**: `printHereModal` (`#printHereModal`), `printHereModalLabel` (`#printHereModalLabel`), `print-here-frame` (`#print-here-frame`), `print-here-print-btn` (`#print-here-print-btn`, was equivalent). The old selector `js:window.open(job.preview_url` still exists (Open in window).
- STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN.

### D-12 Recent Prints + per-sale Last Print
- ONLINE_BEHAVIOUR: `openRecentPrints` / `loadRecentPrintJobs` (:5397-5512) on `GET /api/pos/print-jobs/{saleId}` (`ajaxForSale` :195-228): Job No / Type / Status / Printer / Items (Rev, Duplicate) / Time / Print Here (failed) / View (fallback) / Retry (failed) / Reprint; footer Reprint All KOT / Reprint Receipt.
- EDGE_IMPLEMENTATION: `recentPrints()` (branch list, same columns + Sale link) and `openLastPrint(saleId)` (`#lastPrintModal`, `#lastPrintModalLabel`, `#last-print-sale-no`, `#last-print-modal-body`, `#reprint-all-kot-btn`, `#reprint-receipt-btn`); row actions Print Here / View / Printed / Retry / Dismiss / Reprint (Reminder → reminder-reprint); report rows get no Print Here (`has_document=false`) and the document route answers a business 404.
- EXECUTABLE_TEST: per-sale fields in the D-04 test; report 404 in `test_retry_dismiss_…`; scope in D-23 test.
- BROWSER_ACCEPTANCE_STEP: `#recent-prints-btn` → click a sale no → `#lastPrintModal`.
- CENSUS_FLIPS: group `last-print` partial → **present** for `lastPrintModal`, `lastPrintModalLabel`, `last-print-modal-body`; `last-print-sale-no` planned → **present** (`#last-print-sale-no`); `last-print-btn` equivalent (`#recent-prints-btn`) — a `#last-print-btn` also renders inside Recent Prints (Team 1 wires the header one).
- STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN.

### D-13 Retry — test
- ONLINE_BEHAVIOUR: `PrintJobController::retry` :242-260 → shared `requeueFailed`.
- EDGE_IMPLEMENTATION: unchanged terminal-failure retry + dismissed-job retry.
- EXECUTABLE_TEST: `test_retry_dismiss_and_retry_of_a_dismissed_job_over_http` — queued job retry 422; 6 real failed delivery attempts → `failed`; HTTP retry → `queued`, delivery `waiting`.
- CENSUS_FLIPS: none. STATUS: MATCHED_AND_PROVEN (HTTP; browser button = row Retry).

### D-14 Dismiss
- ONLINE_BEHAVIOUR: `PrintJobController::dismiss` :266-285 (permission-free prefix, data scope) → `cancelObsolete` (queued/failed only, no counters).
- EDGE_IMPLEMENTATION: `POST /print-jobs/{job}/dismiss` → `EdgeLocalPrintDeliveryService::dismiss` (+ live-lease guard: never while the worker is writing to the printer); row "Dismiss" button (queued/failed) with confirm; Retry re-queues a dismissed job.
- EXECUTABLE_TEST: same test — live lease → 422; dismiss → `cancelled`, reason stored, no receipt counter, never claimed; second dismiss 422; retry → queued and claimable; printed job → 422.
- BROWSER_ACCEPTANCE_STEP: Recent Prints → Dismiss on a queued/failed row.
- REMAINING_DIFFERENCE: Online shows Dismiss on the admin Jobs page, Edge on the POS Recent Prints (the appliance has no admin jobs page — D-15).
- STATUS: MATCHED_AND_PROVEN (HTTP).

### D-23 Print-job data scope
- ONLINE_BEHAVIOUR: `assertSaleAccess` / `assertPrintJobAccess` (:300-326), `UserDataScope::deniesSale` (:237-251).
- EDGE_IMPLEMENTATION: `scopedSale()` / `scopedJob()` on receipt, kot, kot-reprint, list (`sale_id`), document, printed, retry, dismiss, reminder-reprint, reminders/confirm, printing/retry, bill-preview/document.
- EXECUTABLE_TEST: `test_print_endpoints_follow_the_operator_data_scope` — unscoped allowed; after a terminal assignment to A, every action on a Counter B sale / job → 403 and Recent Prints hides it.
- STATUS: MATCHED_AND_PROVEN (HTTP).

### D-24 Terminal auto-print preferences
- ONLINE_BEHAVIOUR: `terminalPrintConfig` (`POSController` :484-489), `#print-pref-panel` (:942-966), `refreshPrintPanel` (:4409-4455), this-device overrides (:6259-6270).
- EDGE_IMPLEMENTATION: `GET /print-preferences` (per terminal: configured, auto flags, routed receipt/KOT printer; user printer settings when a row exists); `printPrefsHtml()` / `refreshPrintPanel()` / overrides on the same localStorage keys; `autoReceipt` + `readPrintPrefs` honour them.
- EXECUTABLE_TEST: `test_bill_preview_document_…` (prefs part) — current terminal, A auto receipt on / KOT off, routed printer name, B unconfigured → false.
- BROWSER_ACCEPTANCE_STEP: (after T2-1) Review & Pay → `#print-pref-panel` shows `#print-terminal-label`, toggles `#auto-kot-toggle` / `#auto-receipt-toggle`, hints `#kot-status-hint` / `#receipt-status-hint`.
- CENSUS_FLIPS: planned → **present**: `print-pref-panel`, `print-terminal-label`, `auto-kot-toggle`, `kot-status-hint`, `auto-receipt-toggle`, `receipt-status-hint` (all `#<same id>`; markup is in the page script today, rendered on screen once Team 2 inserts `printPrefsHtml()`).
- REMAINING_DIFFERENCE: `user_printer_settings` is not in the Edge bootstrap export and Online's POS does not consume it either (only the model exists) — returned only if a row exists. Editing terminal settings stays Cloud (D-16).
- STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN.

### D-16 / D-17 / D-18 / D-21 — document only
- D-16 printer / mapping / terminal-setting / layout administration and D-17 printer health (status/ping/reset/reboot): **owner ruling pending** (execution board, owner-dependent table) — not started; data is synced read-only.
- D-18 print agents: ACCEPTED_ONLINE_REQUIRED (`config/edge.php` print-authority lock; the Edge worker is the only local deliverer — no second agent introduced).
- D-21 USB: ACCEPTED_ONLINE_REQUIRED / owner re-check (a USB-routed job is undeliverable on both sides; Print Here works for NULL-printer jobs).

---

## Census flips (coordinator applies; I did not edit the fixture)

| Online id | from → to | Edge selector |
|---|---|---|
| print-pref-panel, print-terminal-label, auto-kot-toggle, kot-status-hint, auto-receipt-toggle, receipt-status-hint | planned → present | `#<same id>` |
| print-bill-preview-btn, bill-preview-frame | planned → present | `#print-bill-preview-btn`, `#bill-preview-frame` |
| last-print-sale-no | planned → present | `#last-print-sale-no` |
| lastPrintModal, lastPrintModalLabel, last-print-modal-body | partial (group) → present | `#lastPrintModal`, `#lastPrintModalLabel`, `#last-print-modal-body` |
| reprint-all-kot-btn, reprint-receipt-btn, send-network-receipt-btn | equivalent → present | `#reprint-all-kot-btn`, `#reprint-receipt-btn`, `#send-network-receipt-btn` |
| printHereModal, printHereModalLabel, print-here-frame | partial (group) → present | `#printHereModal`, `#printHereModalLabel`, `#print-here-frame` |
| print-here-print-btn | equivalent → present | `#print-here-print-btn` |

Planned rows carry no Edge selectors, so the census gate does not fail on these flips; they are listed for the coordinator to apply.

## Requests

**Coordinator**
- C1 `config/edge.php` `route_allowlist`: I appended a marked W5 block (7 names) because the global default-deny route boundary 404s every unlisted route on a branch_server (the MySQL HTTP tests boot as branch_server). The file is not in the Team 5 ownership table, so please accept or move it. Every team adding routes needs the same step: no test currently checks that each `edge.local.pos.*` route is on the allowlist, so a missing name only shows up at runtime.
- C2 apply the census flips above; run the LAB 3-station proof (three FakePrinter listeners, mappings as in the D-01 test).

**Team 2**
- T2-1 (D-08 / D-24 / D-07) in `js/payment` Review & Pay: insert `printPrefsHtml()` into the modal; call `refreshPrintPanel()` after render; on Complete Sale (new sale) add `Object.assign(payload, readPrintPrefs())` to the `POST /sales` body (you already validate and persist the intents); after success replace `autoReceipt(sale.sale_id)` with `afterSalePrinting(sale)` (it runs Direct Pay printing when `sale.printing` / `sale.print_intents` is present, else the auto receipt). Optional server shortcut (saves one round trip): in `EdgeLocalPosController::storeSale` after the paid sale commits (and on an idempotent replay), `$printing = app(\App\Services\Edge\EdgeLocalPrintDirectPayService::class)->afterPaidSale($sale, $data['kot_print_intent'] ?? null, $data['receipt_print_intent'] ?? null);` and add `'printing' => $printing` to the JSON. Keep the intents out of `EdgeSaleEnvelopeBuilder` (Team 6).
- T2-2 (D-06 / D-01) `EdgeLocalPosService::reviseHeldSale` (~line 1030): `recordLineCancellations($sale, $detected, (int) $user->id, (string) $terminal->id)` so the CANCEL KOT routes at the voiding counter (Online passes `operatorTerminalId`). `queueKotEvents` (~:1021): pass `(string) $terminalId` to `queueKot` (RECALL-REPRINT-TERMINAL). Or retire it in favour of `POST /sales/{sale}/kot` (T3-1).

**Team 3**
- T3-1 (D-01/D-02/D-04) `js/held` `sendKot()`: after the save, call `await fireKot(state.held.id)` (routes at this counter, Online bookkeeping, opens Print Here for browser tickets, runs the Reminder question) and then `loadHeld(state.held.id)`. For Online's auto-KOT on Hold: after a successful Hold/Save round, if `autoPrintEnabled('kot')` call `fireKot(saleId)`, else ask "Print Kitchen Order?" (Online `handleKotAfterSale`).
- T3-2 (D-05) `EdgeLocalHeldSalesController::cancelHeldSale`: return `jobs` (use the same fields as `EdgeLocalPrintJobController::printJobView`: `id, fallback, preview_url, printer_name, print_status, document_type`) from `$result['jobs']`; page: `handlePrintJobs(r.jobs, 'CANCEL KOT')`.
- T3-3 (D-06) `EdgeLocalHeldSalesController::storeHeldSale` when `void_items` is present: capture `$before = (int) KotBatch::on('tenant')->where('sales_order_id', $data['held_sale_id'])->max('id')` before `holdOrReviseSale`, then after it returns: `app(\App\Services\Edge\EdgeLocalPrintKotService::class)->queueLineVoidCorrectionReminders($sale, (int) $terminal->id, $before);`.
- T3-4 Completed Orders (A27): the row "Prints" action → `openLastPrint(sale.id, sale.sale_no)`.
- T3-5 (R14, done on my side): `openTableBillPreview` can now call `printBillPreview({ restaurant_table_session_id, held_sale_ids, target: 'here' | 'network' })` instead of printing its own iframe / looping the held checks.

**Team 1**: `#last-print-btn` is already wired to `openLastPrint(lastPrintSale.id)` in js/boot (thank you); `lastPrintSale` is set by `autoReceipt` / `afterSalePrinting` / `openLastPrint`.

**Team 6**
- W6-1: a Direct-Pay KOT batch (and any KOT sent after payment) is created after the sale envelope is built, so it is missing from the envelope's `kot_events`. Online has the same timing. Decide whether the contract needs it. Print intents / `direct_pay_print_state` never enter the envelope (the builder reads explicit fields only).
- W6-2: once canonical 243e01d is reconciled, re-apply `$edgeMarkPrintedUrl ??` in `kot.blade.php` / `receipt.blade.php` (D-25), and the `@isset($tableBill)` block will light up D-10's per-table bill once Team 3 passes table-bill data.

## Tests run (team DBs `pos_test_*_edgewt_t5`; fake printers on OS-assigned free ports, never 9100)

```
export PATH="/d/laragon2/bin/php/php-8.3.16-Win32-vs16-x64:$PATH"
export DB_DATABASE=pos_test_master_edgewt_t5 EDGE_TEST_TENANT_DB=pos_test_tenant_edgewt_t5 EDGE_TEST_LOCAL_DB=pos_test_edge_local_edgewt_t5
vendor/bin/phpunit -c phpunit.mysql.xml --filter 'EdgeCashierPrinting|EdgeLocalPrint|EdgeCashierDineInHttpMySqlTest|EdgeCashierRouteGatesHttpMySqlTest|EdgeCashierScreenRendersHttpMySqlTest'
  → OK (48 tests, 762 assertions), 8m26s   [includes the 8 new EdgeCashierPrintingParityHttpMySqlTest tests, the existing
    EdgeCashierPrintingHttpMySqlTest, EdgeLocalPrintDelivery / Race / WorkerLifecycle, DineIn, RouteGates, ScreenRenders]
EDGE_NODE_BIN=D:/laragon2/bin/nodejs/node-v20.20.1-win-x64/node.exe vendor/bin/phpunit -c phpunit.mysql.xml --filter EdgeCashierControlCensusHttpMySqlTest
  → 3/4: registration + pinned reference OK, deferral grep OK, node --check of the composed page script OK (re-run after the
    last JS change). The row gate fails on 26 rows, and none of them is a W5 row. They are other teams' selectors that have
    left the page while those teams rework it (#cm-cust-q, #cm-cust-clear, #cm-channel, #cm-rider, #cm-address, #cm-charge,
    #cm-promo, #cm-disc-type, #cm-disc-value, #cm-cust-results, #cm-cust-picked, #cm-addr-pick, #category-tabs, #returns-btn).
    The fixture owners (coordinator / Teams 1, 2, 4) must flip them. Planned W5 rows have no selectors, so the W5 flips above
    do not fail the gate.
vendor/bin/phpunit tests/Feature/Edge/EdgeBranchServerRegistrationTest.php tests/Feature/Edge/EdgeArtifactTest.php
  → OK (17 tests, 31236 assertions)
vendor/bin/phpunit tests/Feature/Printing        (Cloud printing: DirectPayPrintOrchestratorTest, PrintRoutingFoundationTest)
  → OK (9 tests, 49 assertions). No shared printing service was modified (0-line diff in app/Services/Printing and app/Services/Sales).
```
Browser: no read-only dev-instance proof was run in this pass. The dev instance serves the committed tree, not this
uncommitted work, so a proof there would not show the W5 controls. The BROWSER_ACCEPTANCE_STEP lines above are for the
coordinator's post-integration proof.
