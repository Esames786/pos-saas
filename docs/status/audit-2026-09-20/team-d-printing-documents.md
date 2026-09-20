# TEAM D — PRINTING PARITY AUDIT (read-only; no file touched, no process run)

Path roots used below: **R** = `D:\laragon2\www\pos-saas-edge` (worktree, HEAD 4affc67) · **I** = `C:\Users\Dell\BingooEdgeLab\install\BingooEdge\runtime\versions\0.6.0-edge` (installed 0.6.0-edge). ACTUAL_BROWSER_PROOF is "none in this pass" for every record (no test asserts rendered controls; no browser was run).

**Installed-vs-source check:** every printing file below is byte-identical between R and I (`diff -q`): `routes/edge_runtime.php`, `app/Http/Controllers/Edge/EdgeLocalPosController.php`, `EdgeQuickReportController.php`, `app/Services/Edge/EdgeLocalPosService.php`, `EdgeLocalPrintDeliveryService.php`, `EdgeNetworkPrinterTransport.php`, `EdgeBootstrapService.php`, `EdgeApplianceHealthService.php`, `app/Console/Commands/EdgeLocalPrint{Worker,Status}Command.php`, `config/edge.php`, `app/Services/Printing/{PrintJobService,PrintRoutingService,EscPosPayloadService,KotTerminalRoutingRewriter}.php`, `app/Services/Sales/KotCancellationService.php`, `app/Http/Controllers/Tenant/{PrintDocumentController,PosQuickReportController}.php`, `app/Support/KotTicketTime.php`, `resources/views/edge/pos/index.blade.php`, `resources/views/edge/health.blade.php`, `resources/views/tenant/printing/documents/{kot,receipt,reminder}.blade.php`, `resources/views/tenant/reports/center/print.blade.php`. Only printing-related commit since 623f887 is 99b3afa (heartbeat ACK, not printing). ⇒ no PRESENT_IN_SOURCE_NOT_INSTALLED for printing; EDGE_INSTALLED_ROUTE/VIEW = EDGE_SOURCE_ROUTE in every record.

---

## D-01 KOT generation (first round) + station routing
- ONLINE_ROUTE= `POST /printing/jobs/kot/{salesOrder}` (`R\routes\tenant.php:643`), fired by POS JS `fireKotSilently` after Hold/Add Round with `?terminal_id=<current>` (`R\resources\views\tenant\pos\index.blade.php:4279-4283`, `handleKotAfterSale` 4368-4389)
- ONLINE_VIEW= `tenant/pos/index.blade.php` (Swal "Print Kitchen Order?" or silent when `auto_print_kot`)
- ONLINE_CONTROLLER_OR_SERVICE= `PrintJobController::queueKot` (`R\app\Http\Controllers\Tenant\PrintJobController.php:79-96`) → `PrintJobService::queueKot(..., terminalId=operator's terminal)` → `PrintRoutingService::kotRoutesForSale` (`R\app\Services\Printing\PrintRoutingService.php:237-312`)
- EDGE_INSTALLED_ROUTE= `POST /edge/local/pos/held-sales/{sale}/kot` (`I\routes\edge_runtime.php:92`, allowlisted `config/edge.php:251`)
- EDGE_INSTALLED_VIEW= `edge/pos/index.blade.php` — "KOT" button on a held check (`:346`), `sendKot()` (`:626-634`)
- EDGE_SOURCE_ROUTE= same (`R\routes\edge_runtime.php:92`)
- ONLINE_BEHAVIOUR= delta only (qty − kot_sent_quantity); one ticket PER CATEGORY per printer; per line: terminal-aware category mapping (terminal-pinned rule wins over NULL-terminal rule, never mixed — `applyTerminalPrecedence` :220-235) → terminal `kot_printer_id` → branch default kot/both printer → browser fallback; sent bookkeeping at queue time for network printers, at Mark Printed for browser; then `planRemindersForKotJobs` (D-04); network delivery by Cloud Print Agent.
- EDGE_BEHAVIOUR= `EdgeLocalPosService::queueKotEvents` (`R\app\Services\Edge\EdgeLocalPosService.php:1003-1032`) calls the SAME `PrintJobService::queueKot($sale)` but with **NO terminalId** (`:1021`) → routing resolves on the sale's own `terminal_id`, not the operator's current counter; applies `applyKotSentBookkeeping` for normal/addition jobs; draft refused (`:1017`). Delivery by the Edge worker (D-21). No reminder planning at all.
- VISUAL_DIFFERENCE= Online: Swal prompt / silent auto-KOT; Edge: manual "KOT" button + toast "KOT #n sent · k line(s)".
- NAVIGATION_DIFFERENCE= Online KOT fires from Hold; Edge requires Hold ("Order held — send the KOT when ready" `:560`) then KOT.
- WORKFLOW_DIFFERENCE= (1) operator-terminal override not threaded on Edge (matters when a check held at Counter A gets its KOT sent from Counter B: Online routes counter-category items to B, Edge to A); (2) `auto_print_kot` ignored on Edge (D-27); (3) no reminders (D-04).
- VALIDATION_DIFFERENCE= Edge 422 on draft (Online skips client-side); Edge requires selected terminal (session) — Online passes terminal_id param.
- PRINTING_DIFFERENCE= same PrintJobService/PrintRoutingService/EscPos → identical bytes & station split when same terminal; category mappings/terminal settings/printers synced (D-25). A route to a **USB-type** printer row yields a `printer_id` job the Edge worker never claims (network-only, `EdgeLocalPrintDeliveryService.php:73-78`) and no fallback flag → silently stuck (also true for the Cloud agent, see D-22).
- PERMISSION_PARITY= Online route is permission-exempt but `assertSaleAccess` applies UserDataScope (branch/terminal/order-type) (`PrintJobController.php:299-303`); Edge: edge.auth + bound branch only (D-24).
- ACTUAL_BROWSER_PROOF= none in this pass
- AUTOMATED_TEST_PROOF= Edge: `R\tests\MySql\EdgeCashierDineInHttpMySqlTest.php:147-210` (round 1 batch, seq 1), `EdgeLocalRestaurantHttpMySqlTest.php:117-204`, `EdgeNoDuplicatePrintAfterSyncMySqlTest.php:203-215` (KOT routes to network printer, delivered once), `EdgeCanonicalAlignmentMySqlTest.php:112` (draft never queues KOT), `EdgeCashierDealsDiscountsHttpMySqlTest.php:198-210` (deal name on Edge KOT doc). Multi-station category-mapping routing on Edge: **no Edge test seeds `category_printer_mappings` for a KOT** (only default printer); Online: `PrintRoutingTerminalMySqlTest.php:71-96`, `KotPerCategoryMySqlTest.php:21`, `tests/Feature/Printing/PrintRoutingFoundationTest.php:224`.
- STATUS= FUNCTIONAL_BUT_UI_DIFFERENT
- EVIDENCE= `R\app\Services\Edge\EdgeLocalPosService.php:1021` vs `R\app\Http\Controllers\Tenant\PrintJobController.php:90-96`; `R\app\Services\Printing\PrintRoutingService.php:179-312`
- REQUIRED_ACTION= pass `(string)$terminal->id` into `queueKot` in `queueKotEvents` (parity with RECALL-REPRINT-TERMINAL-1); add an Edge HTTP test with a 3-station mapping (BBQ/Fastfood/Counter, terminal-pinned rule) asserting one job per printer+category; decide auto-KOT-after-hold parity.

## D-02 New rounds — ADDITION KOT deltas
- ONLINE_ROUTE= same as D-01 after Add Round save (`HeldSaleController::store` → JS `handleKotAfterSale`)
- ONLINE_VIEW= `tenant/pos/index.blade.php:4368`
- ONLINE_CONTROLLER_OR_SERVICE= `PrintJobService::createKotBatch` seq = max+1, event_type `addition` when seq>1 (`PrintJobService.php:802-812`); heading `*** ADDITION KOT #n ***`, `(R)` prefix on added lines (`EscPosPayloadService.php:1000-1004,1102`; `kot.blade.php:79-80,191`)
- EDGE_INSTALLED_ROUTE= `POST /edge/local/pos/held-sales` (revise) then `POST …/held-sales/{sale}/kot`
- EDGE_INSTALLED_VIEW= "Save round" + "KOT" buttons (`edge/pos/index.blade.php:345-346`)
- EDGE_SOURCE_ROUTE= same
- ONLINE_BEHAVIOUR= addition batch with only the delta; identity `ADDITION KOT #n`.
- EDGE_BEHAVIOUR= identical service path; Edge test asserts seq 2 / `addition` / exact delta / round-1 never re-sent.
- VISUAL_DIFFERENCE= as D-01 (manual button vs auto/prompt).
- NAVIGATION_DIFFERENCE= two clicks (Save round, KOT) vs one.
- WORKFLOW_DIFFERENCE= as D-01 (terminal override, no reminder revision).
- VALIDATION_DIFFERENCE= none beyond D-01.
- PRINTING_DIFFERENCE= none (shared payload; `ADDITION KOT #n` heading proven on shared code).
- PERMISSION_PARITY= as D-01.
- ACTUAL_BROWSER_PROOF= none in this pass
- AUTOMATED_TEST_PROOF= `EdgeCashierDineInHttpMySqlTest.php:198-210`, `EdgeLocalRestaurantHttpMySqlTest.php:202-204`; heading string `R\tests\Unit\Printing\EscPosKotPayloadTest.php:15-22`.
- STATUS= FUNCTIONAL_BUT_UI_DIFFERENT
- EVIDENCE= `R\app\Services\Printing\PrintJobService.php:802-812`, `R\app\Services\Printing\EscPosPayloadService.php:1000-1010`
- REQUIRED_ACTION= none for identity; inherit D-01 actions.

## D-03 KOT reprint — "DUPLICATE KOT #n" + copy numbering
- ONLINE_ROUTE= `POST /printing/jobs/kot/{salesOrder}?reprint=1&terminal_id=` (`tenant.php:643`)
- ONLINE_VIEW= Recent Prints modal `#reprint-all-kot-btn` (`pos/index.blade.php:1374,5917-5935`), per-row Reprint (`:5588-5599`), Completed Orders reprint (`:5743-5760`)
- ONLINE_CONTROLLER_OR_SERVICE= `PrintJobController::queueKot(isReprint)` → `createKotJob` copy key `kot-copy:<event_uuid>:<destination>:<n>` (`PrintJobService.php:715-723`); payload `is_reprint`, `copy_no`; EscPos `*** DUPLICATE KOT #seq ***` + `DUPLICATE <copy>` + `REPRINT:` stamp (`EscPosPayloadService.php:1003,1009-1010,1061-1063`); KOT-REPRINT-BLANK-1 snapshot fallback (`:958-978`)
- EDGE_INSTALLED_ROUTE= `POST /edge/local/pos/sales/{sale}/kot-reprint` (`edge_runtime.php:101`)
- EDGE_INSTALLED_VIEW= Recent Prints → "Reprint" on a KOT row (`edge/pos/index.blade.php:725,737`)
- EDGE_SOURCE_ROUTE= same
- ONLINE_BEHAVIOUR= duplicate event, no bookkeeping change, all lines, routed at operator's current terminal.
- EDGE_BEHAVIOUR= `EdgeLocalPosController::reprintKot` → `queueKot($order, null, [], (string)$terminal->id, true)` (`EdgeLocalPosController.php:1013-1027`) — same semantics incl. current-terminal routing; fallback → popup Print Here.
- VISUAL_DIFFERENCE= Online per-sale modal with "Reprint All KOT"; Edge branch-wide list, per-row "Reprint" (reprints all lines of that sale's KOT, same as Online's Reprint All).
- NAVIGATION_DIFFERENCE= Edge needs Recent Prints → find the sale's KOT row.
- WORKFLOW_DIFFERENCE= none material.
- VALIDATION_DIFFERENCE= Online validates `terminal_id` against operator's terminals (`operatorTerminalId`); Edge uses the session-selected terminal.
- PRINTING_DIFFERENCE= none (shared payload; stored-copy fallback proven on Edge).
- PERMISSION_PARITY= D-24.
- ACTUAL_BROWSER_PROOF= none in this pass
- AUTOMATED_TEST_PROOF= `EdgeCashierPrintingHttpMySqlTest.php:196-236` (event_type duplicate, terminal, render, snapshot bytes never blank, bookkeeping untouched); Online `KotReprintBlankMySqlTest.php:117-187`, `KotTicketTimeMySqlTest.php:137-180`, `EscPosKotPayloadTest.php:25-35`.
- STATUS= FUNCTIONAL_BUT_UI_DIFFERENT
- EVIDENCE= `R\app\Http\Controllers\Edge\EdgeLocalPosController.php:1024`; `R\app\Services\Printing\PrintJobService.php:715-723`
- REQUIRED_ACTION= optional: per-sale filter in Edge Recent Prints (API already supports `?sale_id=`, `EdgeLocalPosController.php:1033-1036`; page calls without it `:715`).

## D-04 KOT REMINDER (document_type=reminder, print_role reminder, confirm/reprint)
- ONLINE_ROUTE= planned inside `POST /printing/jobs/kot/{salesOrder}` (`PrintJobController.php:98-108`); `POST /printing/jobs/reminder/{salesOrder}/confirm` (`tenant.php:644`); `POST /printing/jobs/{printJob}/reminder-reprint` (`:645`); doc `GET /printing/documents/{printJob}/reminder` (`:652`); Direct Pay path `DirectPayPrintOrchestrator::ensureKotAndReminder` (`:105-150`)
- ONLINE_VIEW= `pos/index.blade.php` `handleReminderPlan` Swal "Resend updated Reminder?" (`:4322-4363`), reminder rows/Reprint in Recent Prints (`:5425,5443-5445,5575-5587`), `documents/reminder.blade.php`
- ONLINE_CONTROLLER_OR_SERVICE= `PrintJobService::planRemindersForKotJobs/queueConfirmedReminders/validateReminderConfirmation/queueReminderReprint/queueCancellationReminders` (`:260-455`); routing `PrintRoutingService::reminderRoutesForSale` (print_role reminder mappings, terminal precedence, `supports_reminder` printers, `ask_on_addition`) (`:19-99`); payload `buildReminder` (`EscPosPayloadService.php:238-372`)
- EDGE_INSTALLED_ROUTE= **none** — no reminder route in `I\routes\edge_runtime.php`; `printDocument` would render a reminder job if one existed (`PrintDocumentController::preview` :15-21)
- EDGE_INSTALLED_VIEW= none (Recent Prints has no reminder reprint; `reminder.blade.php` has no Mark Printed form `:130`)
- EDGE_SOURCE_ROUTE= none
- ONLINE_BEHAVIOUR= REMINDER slip (REVISION n, complete order, (R) deltas) to reminder-mapped printers on every normal/addition KOT; ask-on-addition confirmation token; reminder reprint with `DUPLICATE n`; cancellation reminders (`cancelled_order` / `cancelled_updated_order`).
- EDGE_BEHAVIOUR= `grep planReminders|queueConfirmedReminders|reminderRoutesForSale|queueReminderReprint` over `R\app\Services\Edge`, `R\app\Http\Controllers\Edge`, `R\resources\views\edge` = **0 hits**. Only side-effect: whole-order cancel via shared `KotCancellationService::cancelHeldOrder` DOES queue cancellation reminders (`KotCancellationService.php:66`) if reminder-capable printers/mappings are synced (they are: D-25) — these are network-claimable by the worker; line-void path does not (D-06).
- VISUAL_DIFFERENCE= n/a (absent).
- NAVIGATION_DIFFERENCE= n/a.
- WORKFLOW_DIFFERENCE= the punching-counter reminder (Kashif Food) never prints offline; revision tracking absent.
- VALIDATION_DIFFERENCE= n/a.
- PRINTING_DIFFERENCE= whole document class missing offline except cancellation reminders.
- PERMISSION_PARITY= n/a.
- ACTUAL_BROWSER_PROOF= none in this pass
- AUTOMATED_TEST_PROOF= none on Edge; Online `PrintRoutingMySqlTest.php:23-55`, `KotSplitAndReprintWalkthroughMySqlTest.php:255`, `PosPayloadResilienceMySqlTest.php:165`, `EscPosReminderPayloadTest.php:11-90`, `CancelKotTerminalMySqlTest.php:98-219`.
- STATUS= MISSING_IN_EDGE
- EVIDENCE= `R\routes\edge_runtime.php:99-105` (no reminder route); `R\docs\status\edge-online-pos-parity-register.md:74` already records NOT_IMPLEMENTED
- REQUIRED_ACTION= wire `planRemindersForKotJobs` after `queueKotEvents`, add confirm + reminder-reprint endpoints and page prompt/buttons; add Edge HTTP test with a `print_role=reminder` mapping + `supports_reminder` printer.

## D-05 Cancellation KOT — whole-order cancel ("CANCEL KOT #n")
- ONLINE_ROUTE= `POST /held-sales/{salesOrder}/cancel` (`HeldSaleController::cancel` `:1043-1080`), body `terminal_id`
- ONLINE_VIEW= `pos/index.blade.php` cancel flow (`:4905-4921`) — opens fallback previews for `cancel_kot_jobs`
- ONLINE_CONTROLLER_OR_SERVICE= `KotCancellationService::cancelHeldOrder(…, terminalId=operator)` → `queueCancellationKot` (`kotRoutesForQuantities`, category-specific no wildcard, terminal-aware) + `queueCorrectionReminders(wholeOrder=true)` + frees table (`KotCancellationService.php:24-92`); heading `*** CANCEL KOT #n ***` from `line_snapshots` (`EscPosPayloadService.php:954-956,1001`; `kot.blade.php:77-78`)
- EDGE_INSTALLED_ROUTE= `POST /edge/local/pos/held-sales/{sale}/cancel` (`edge_runtime.php:94`)
- EDGE_INSTALLED_VIEW= "Cancel order" button → reason modal (`edge/pos/index.blade.php:350,774-783`)
- EDGE_SOURCE_ROUTE= same
- ONLINE_BEHAVIOUR= cancel KOT + cancellation reminder print at the CANCELLING counter; browser-fallback jobs auto-open.
- EDGE_BEHAVIOUR= `EdgeLocalPosController::cancelHeldSale` passes the session terminal (`:717-733`) → `EdgeLocalPosService::cancelHeldSale` → same shared service with `(string)$terminal->id` (`EdgeLocalPosService.php:1228-1242`). Response returns only `sale_id/status` — the page never learns about fallback jobs; a browser-fallback CANCEL KOT only appears in Recent Prints.
- VISUAL_DIFFERENCE= Edge modal (reason select); Online Swal chain.
- NAVIGATION_DIFFERENCE= none material.
- WORKFLOW_DIFFERENCE= no manager PIN on Edge (manager re-auth with own credential — parity by design); fallback CANCEL KOT not auto-opened on Edge.
- VALIDATION_DIFFERENCE= same service rules (reason active, approval mode); Edge does not accept `terminal_id` in body (uses session).
- PRINTING_DIFFERENCE= identical routing/payload; cancellation reminders also queued (if configured).
- PERMISSION_PARITY= shared `assertCancellationPermission`; plus D-24.
- ACTUAL_BROWSER_PROOF= none in this pass
- AUTOMATED_TEST_PROOF= `EdgeCashierDineInHttpMySqlTest.php:267-296` (cancel KOT job exists, `terminal_id` = current counter B, order keeps A); `EdgeLocalRestaurantHttpMySqlTest.php:409`; Online `CancelKotTerminalMySqlTest.php:81-244`, `EscPosKotPayloadTest.php:38-61`.
- STATUS= FUNCTIONAL_BUT_UI_DIFFERENT (see D-26 for the combo-void canonical drift)
- EVIDENCE= `R\app\Http\Controllers\Edge\EdgeLocalPosController.php:725-733`; `R\app\Services\Sales\KotCancellationService.php:57,66`
- REQUIRED_ACTION= return `jobs` (with `fallback`, `preview_url`) from the Edge cancel endpoint and open Print Here for fallback cancel KOTs; add an Edge test asserting the CANCEL KOT printer_id when a network kot printer is configured.

## D-06 Cancellation KOT — line void (reduce below sent) + correction reminder
- ONLINE_ROUTE= `POST /held-sales` (revise) with `void_items[]` (`HeldSaleController.php:290-310,569-575,806-813`)
- ONLINE_VIEW= POS void-with-reason UI (line reduction prompts reason/manager)
- ONLINE_CONTROLLER_OR_SERVICE= `KotCancellationService::recordLineCancellations(…, terminalId=operator)` (`:127-225`) → `queueCancellationKot`; then controller calls `queueCorrectionReminders(wholeOrder=false, operator terminal)` (`HeldSaleController.php:806-813`)
- EDGE_INSTALLED_ROUTE= `POST /edge/local/pos/held-sales` (revise) with `void_items` — API only
- EDGE_INSTALLED_VIEW= **none**: page blocks reduction with toast "Already sent to the kitchen — reducing needs a void with a reason." (`edge/pos/index.blade.php:285`); `grep void_items` in the Edge view = 0 hits
- EDGE_SOURCE_ROUTE= same
- ONLINE_BEHAVIOUR= CANCEL KOT for the voided qty at the operator's counter + correction REMINDER; grouped/single approval rules (canonical changed 19 Sep, D-26).
- EDGE_BEHAVIOUR= server: `holdOrReviseSale` detects reductions and calls `recordLineCancellations($sale, $detected, $user->id)` with **no terminal** (`EdgeLocalPosService.php:909`) → CANCEL KOT routes on the sale's terminal; **no `queueCorrectionReminders` call** (only call sites `:909`, `:1241`). Screen: cannot void a line at all.
- VISUAL_DIFFERENCE= no void UI on Edge.
- NAVIGATION_DIFFERENCE= operator must "Cancel order" instead (whole order).
- WORKFLOW_DIFFERENCE= line void impossible from the Edge screen; via API, no correction reminder and wrong-counter routing on recalled orders.
- VALIDATION_DIFFERENCE= same service rules when called.
- PRINTING_DIFFERENCE= missing correction reminder; counter routing differs.
- PERMISSION_PARITY= shared permission check; D-24.
- ACTUAL_BROWSER_PROOF= none in this pass
- AUTOMATED_TEST_PROOF= API-level only: `EdgeLocalRestaurantHttpMySqlTest.php:241-317` (cancel batch + cancellation row; does NOT assert printer/terminal of the cancel job); Online `CancelKotTerminalMySqlTest.php:148`.
- STATUS= PARTIALLY_IMPLEMENTED
- EVIDENCE= `R\app\Services\Edge\EdgeLocalPosService.php:905-909`; `R\resources\views\edge\pos\index.blade.php:285`; `R\app\Http\Controllers\Tenant\HeldSaleController.php:569-575,806-813`
- REQUIRED_ACTION= add void-with-reason UI to the Edge page; pass terminal to `recordLineCancellations`; call `queueCorrectionReminders(..., false, terminal)` after the revise commits; test the cancel job's `terminal_id`/printer.

## D-07 Customer receipt after payment (ensure-once) + auto-print preference
- ONLINE_ROUTE= `POST /printing/jobs/receipt/{salesOrder}?terminal_id=` (`tenant.php:642`); Direct Pay: `SalesOrderController::store` orchestrates (`:112-118,616`) and `POST /pos/{salesOrder}/printing/retry` (`retryDirectPayPrinting` `:887-900`)
- ONLINE_VIEW= `maybePrintReceipt` honours Auto-Receipt toggle (`pos/index.blade.php:4387-4405`), `#print-pref-panel` toggles (`:942-966`), `processDirectPayPrinting` retry Swal (`:4544-4596`)
- ONLINE_CONTROLLER_OR_SERVICE= `PrintJobService::queueReceipt(ensureOnce)` logical key `receipt:final|proforma:sale-<id>` (`:24-97`); `PrintRoutingService::receiptPrinter` (terminal `receipt_printer_id` → default receipt/both) (`:125-145`); `DirectPayPrintOrchestrator::ensureReceipt` (`:82-101`)
- EDGE_INSTALLED_ROUTE= `POST /edge/local/pos/sales/{sale}/receipt` (`edge_runtime.php:100`)
- EDGE_INSTALLED_VIEW= `autoReceipt(sale.sale_id)` called unconditionally after `completeSale` (`edge/pos/index.blade.php:683,700-706`); fallback → popup Print Here
- EDGE_SOURCE_ROUTE= same
- ONLINE_BEHAVIOUR= respects `auto_print_receipt` (terminal setting + session override); Direct Pay intents (`kot_print_intent`/`receipt_print_intent`) with durable retry state.
- EDGE_BEHAVIOUR= `EdgeLocalPosController::queueReceipt` → shared `queueReceipt(order, terminalId=current, ensureOnce=!reprint)` (`:995-1010`); always prints; no intents/orchestrator; no auto-print flags read (`grep auto_print` in Edge code = bootstrap column list only, `EdgeBootstrapService.php:746`).
- VISUAL_DIFFERENCE= no Printing panel/toggles on Edge; toast "Receipt → <printer>".
- NAVIGATION_DIFFERENCE= none.
- WORKFLOW_DIFFERENCE= "No receipt" option absent on Edge; no Direct-Pay print-state retry prompt; Edge has no `watchPrintFailure` → failure surfaces only in Recent Prints.
- VALIDATION_DIFFERENCE= none.
- PRINTING_DIFFERENCE= same bytes; ensure-once + proforma/final split shared.
- PERMISSION_PARITY= D-24.
- ACTUAL_BROWSER_PROOF= none in this pass
- AUTOMATED_TEST_PROOF= `EdgeCashierPrintingHttpMySqlTest.php:117-153` (ensure-once returns same job, reprint fresh, fallback doc, mark printed → `receipt_print_count`=1), `:156-193` (routes to current counter's `receipt_printer_id`, claimable by Edge delivery with exact bytes), `EdgeNoDuplicatePrintAfterSyncMySqlTest.php:214-219`; Online `ReceiptProformaVsFinalMySqlTest.php:44-87`, `tests/Feature/Printing/DirectPayPrintOrchestratorTest.php`.
- STATUS= FUNCTIONAL_BUT_UI_DIFFERENT
- EVIDENCE= `R\resources\views\edge\pos\index.blade.php:683`; `R\app\Http\Controllers\Edge\EdgeLocalPosController.php:1007`
- REQUIRED_ACTION= honour synced `terminal_printer_settings.auto_print_receipt` (or a page toggle); consider Direct-Pay intent parity (D-08).

## D-08 Direct Pay KOT (KOT for a sale paid without Hold — quick sale / takeaway / delivery)
- ONLINE_ROUTE= inside `POST /sales` (`SalesOrderController::store` with `kot_print_intent`) and `POST /pos/{salesOrder}/printing/retry`
- ONLINE_VIEW= Direct Pay Swal for KOT intent (`pos/index.blade.php:~4540`), `processDirectPayPrinting`
- ONLINE_CONTROLLER_OR_SERVICE= `DirectPayPrintOrchestrator::ensureKotAndReminder` → `queueKot(sale, terminalId=sale->terminal_id)` + reminder plan (`:105-150`)
- EDGE_INSTALLED_ROUTE= server supports it: `queueKotEvents` accepts `status in (held, paid)` (`EdgeLocalPosService.php:1010-1011`) via `POST /held-sales/{sale}/kot`; **page never calls it for a paid sale** (only `sendKot` on `state.held`, `:626-630`)
- EDGE_INSTALLED_VIEW= none (after `completeSale` only `autoReceipt`, `:683`)
- EDGE_SOURCE_ROUTE= same
- ONLINE_BEHAVIOUR= kitchen ticket printed for direct-pay orders when intent=print (default prompt).
- EDGE_BEHAVIOUR= a direct-pay takeaway/quick sale on the Edge screen produces a receipt but **no KOT**; `EdgeNoDuplicatePrintAfterSyncMySqlTest` reaches the KOT only by calling the service directly (`:210-212`).
- VISUAL_DIFFERENCE= no intent prompt.
- NAVIGATION_DIFFERENCE= operator would have to Hold then KOT then Review & Pay.
- WORKFLOW_DIFFERENCE= kitchen never gets a ticket for direct-pay sales from the Edge screen.
- VALIDATION_DIFFERENCE= n/a.
- PRINTING_DIFFERENCE= missing KOT document for this flow.
- PERMISSION_PARITY= n/a.
- ACTUAL_BROWSER_PROOF= none in this pass
- AUTOMATED_TEST_PROOF= none through the Edge page path; service-level only (`EdgeNoDuplicatePrintAfterSyncMySqlTest.php:210-212`).
- STATUS= PARTIALLY_IMPLEMENTED
- EVIDENCE= `R\resources\views\edge\pos\index.blade.php:670-684`; `R\app\Services\Printing\DirectPayPrintOrchestrator.php:112-116`
- REQUIRED_ACTION= after `POST /sales` success (non-held), call `/held-sales/{sale}/kot` (or add an intent prompt) and open fallback previews; add HTTP test.

## D-09 Receipt reprint (fresh job; copy identity)
- ONLINE_ROUTE= `POST /printing/jobs/receipt/{salesOrder}?reprint=1&terminal_id=`
- ONLINE_VIEW= `#reprint-receipt-btn` (`:1377,5937-5949`), per-row Reprint (`:5601-5610`), Completed Orders (`:5743-5760`), bill preview "Send to network" (`:5845-5872`)
- ONLINE_CONTROLLER_OR_SERVICE= `queueReceipt(ensureOnce=false)` → `jobFactory->create` + raw_payload (`PrintJobService.php:92-96`); `copy_no` always 1; **no DUPLICATE marker on receipts** (grep `duplicate|copy|reprint` in `receipt.blade.php` and `EscPos receipt()` = none)
- EDGE_INSTALLED_ROUTE= `POST /edge/local/pos/sales/{sale}/receipt` body `{reprint:true}`
- EDGE_INSTALLED_VIEW= Recent Prints → Reprint on a receipt row (`edge/pos/index.blade.php:738`)
- EDGE_SOURCE_ROUTE= same
- ONLINE_BEHAVIOUR= new receipt job at operator's counter; sale row untouched; paper identical to the original (no copy number).
- EDGE_BEHAVIOUR= same service call with current terminal (`EdgeLocalPosController.php:1006-1007`); proven cross-counter.
- VISUAL_DIFFERENCE= modal shape (D-12).
- NAVIGATION_DIFFERENCE= via Recent Prints only (no Completed Orders modal on Edge).
- WORKFLOW_DIFFERENCE= none.
- VALIDATION_DIFFERENCE= none.
- PRINTING_DIFFERENCE= none (both sides print an unmarked identical bill — `receipt_print_count` is the only duplicate record).
- PERMISSION_PARITY= D-24.
- ACTUAL_BROWSER_PROOF= none in this pass
- AUTOMATED_TEST_PROOF= `EdgeCashierPrintingHttpMySqlTest.php:134-141,156-193`; Online `ReceiptProformaVsFinalMySqlTest.php:87`.
- STATUS= FUNCTIONAL_BUT_UI_DIFFERENT
- EVIDENCE= `R\app\Services\Printing\PrintJobService.php:62-96`
- REQUIRED_ACTION= none for parity (if a "DUPLICATE" receipt marker is desired it is a canonical change, not Edge-only).

## D-10 Bill preview print — "Print here" vs "Send to network"
- ONLINE_ROUTE= preview HTML via `POSController` (renders `documents/receipt.blade.php` for a transient sale, `POSController.php:796`); "Send to network" = `POST /printing/jobs/receipt/{saleId}?reprint=1` for a SAVED order (`pos/index.blade.php:5845-5872`); "Print here" = browser print of the preview (`:5878-5890`)
- ONLINE_VIEW= `#billPreviewModal` footer buttons (`:1127-1135`)
- ONLINE_CONTROLLER_OR_SERVICE= as above (canonical 7cd5924/636ab9d changed this modal — D-26)
- EDGE_INSTALLED_ROUTE= `POST /edge/local/pos/preview-bill` (JSON totals only, `edge_runtime.php:53`)
- EDGE_INSTALLED_VIEW= Preview Bill modal shows totals + Close only (`edge/pos/index.blade.php:375-385`) — no print buttons
- EDGE_SOURCE_ROUTE= same
- ONLINE_BEHAVIOUR= proforma bill printable locally or to the network receipt printer (proforma logical key distinct from final).
- EDGE_BEHAVIOUR= no proforma printing path at all.
- VISUAL_DIFFERENCE= totals-only modal vs full rendered receipt preview.
- NAVIGATION_DIFFERENCE= n/a.
- WORKFLOW_DIFFERENCE= cannot hand a dine-in guest a pre-payment bill offline.
- VALIDATION_DIFFERENCE= n/a.
- PRINTING_DIFFERENCE= proforma receipt document missing offline.
- PERMISSION_PARITY= n/a.
- ACTUAL_BROWSER_PROOF= none in this pass
- AUTOMATED_TEST_PROOF= none on Edge (`previewBill` tested as zero-mutation only); Online `ReceiptProformaVsFinalMySqlTest.php:44`.
- STATUS= MISSING_IN_EDGE
- EVIDENCE= `R\resources\views\edge\pos\index.blade.php:372-385`; register `R\docs\status\edge-online-pos-parity-register.md:94` (UX_DRIFT note)
- REQUIRED_ACTION= add "Print here" (render `documents/receipt.blade.php` via a held-sale receipt job with ensureOnce proforma) and "Send to network" (queueReceipt on the held sale) to the Edge preview.

## D-11 Print Here document (browser fallback) + Mark Printed
- ONLINE_ROUTE= `GET /printing/documents/{printJob}/{receipt|kot|reminder|preview}` (`tenant.php:649-652`); `POST /printing/jobs/{printJob}/mark-printed` (`:646`)
- ONLINE_VIEW= `#printHereModal` iframe (`pos/index.blade.php:1835-1870`), failed-job Swal `watchPrintFailure` (`:1881-1890`), `openPreviewTab` for fallback jobs; documents auto-`window.print()` only when `printer_type==='browser'` (`kot.blade.php:236-240`, `receipt.blade.php:335-339`)
- ONLINE_CONTROLLER_OR_SERVICE= `PrintDocumentController::preview` (`:13-99`); `PrintJobService::markPrinted` (any job; counters + KOT bookkeeping) (`:496-537`)
- EDGE_INSTALLED_ROUTE= `GET /edge/local/pos/print-jobs/{job}/document`, `POST /edge/local/pos/print-jobs/{job}/printed` (`edge_runtime.php:103-104`)
- EDGE_INSTALLED_VIEW= `printHere(job)` popup `window.open(preview_url)` (`edge/pos/index.blade.php:707-712`); Recent Prints "Print here"/"Printed" buttons (`:723-724`); canonical Blade "Mark Printed" form retargeted via `edgeMarkPrintedUrl` (`kot.blade.php:228`, `receipt.blade.php:327`; shared from `EdgeLocalPosController.php:1052`)
- EDGE_SOURCE_ROUTE= same
- ONLINE_BEHAVIOUR= same-screen iframe print; Mark Printed allowed on any job.
- EDGE_BEHAVIOUR= same canonical renderer (`app(PrintDocumentController::class)->preview`), popup window; Mark Printed **refused (422) for network-printer jobs** (`:1057-1069`); non-JSON POST redirects back to the document (`:1071-1073`).
- VISUAL_DIFFERENCE= popup vs modal iframe; Edge has no failed-print Swal.
- NAVIGATION_DIFFERENCE= Edge "Print here" available on every job row; Online only failed (Print Here) / fallback (View).
- WORKFLOW_DIFFERENCE= on Edge a network job can be printed locally too but can't be marked printed (worker owns completion) — a stuck network job can therefore be double-printed and still shows queued.
- VALIDATION_DIFFERENCE= Edge stricter (422 on network jobs); branch-scoped `firstOrFail`.
- PRINTING_DIFFERENCE= none (same Blade, same layout row).
- PERMISSION_PARITY= Online `assertPrintJobAccess` (branch/terminal scope); Edge branch only (D-24).
- ACTUAL_BROWSER_PROOF= none in this pass
- AUTOMATED_TEST_PROOF= `EdgeCashierPrintingHttpMySqlTest.php:143-148,193,212,233` (doc renders sale/product names; printed → `receipt_print_count`; 422 on network job).
- STATUS= FUNCTIONAL_BUT_UI_DIFFERENT
- EVIDENCE= `R\app\Http\Controllers\Edge\EdgeLocalPosController.php:1045-1076`
- REQUIRED_ACTION= optional: failed-print watcher/Swal parity; note the `edgeMarkPrintedUrl` Blade edit is Edge-only (canonical kot.blade.php lacks it — merge hazard, D-26).

## D-12 Recent Prints list
- ONLINE_ROUTE= `GET /api/pos/print-jobs/{saleId}` (`PrintJobController::ajaxForSale` `:189-220`)
- ONLINE_VIEW= `#lastPrintModal` per SALE (`pos/index.blade.php:1355-1385,5397-5512`): Job No / Type badge (KOT/Reminder/Receipt) / Status / Printer / Items (Rev, Duplicate n) / Time / Print Here (failed) / View (fallback) / Retry (failed) / Reprint
- ONLINE_CONTROLLER_OR_SERVICE= as above
- EDGE_INSTALLED_ROUTE= `GET /edge/local/pos/print-jobs[?sale_id=]` (`edge_runtime.php:102`)
- EDGE_INSTALLED_VIEW= "Recent Prints" button (`edge/pos/index.blade.php:365`), modal `:713-741`: last 50 BRANCH jobs; kind + event_type, printer, status, time; buttons Print here (always), Printed (fallback & not printed), Reprint (receipt/kot), Retry (failed)
- EDGE_SOURCE_ROUTE= same
- ONLINE_BEHAVIOUR= per-sale history incl. reminders with revision/copy.
- EDGE_BEHAVIOUR= branch-wide feed (`EdgeLocalPosController.php:1030-1042`); reminder rows (only cancellation reminders) have no reprint; "Print here" on a `report` job hits `PrintDocumentController::preview` which `abort(404)`s (no sales order, `:23-26`).
- VISUAL_DIFFERENCE= list rows vs table/badges; no job_no shown on Edge (returned but unused).
- NAVIGATION_DIFFERENCE= Edge not tied to the last sale.
- WORKFLOW_DIFFERENCE= as D-11; report-job Print here 404.
- VALIDATION_DIFFERENCE= n/a.
- PRINTING_DIFFERENCE= n/a.
- PERMISSION_PARITY= Online list filtered by UserDataScope terminals/order types (`PrintJobController::index` :20-40; `ajaxForSale` sale access); Edge: whole branch.
- ACTUAL_BROWSER_PROOF= none in this pass
- AUTOMATED_TEST_PROOF= `EdgeCashierPrintingHttpMySqlTest.php:138-141` (list, per-sale filter).
- STATUS= FUNCTIONAL_BUT_UI_DIFFERENT
- EVIDENCE= `R\resources\views\edge\pos\index.blade.php:713-741`
- REQUIRED_ACTION= hide "Print here" for `report` jobs (or render the thermal report); consider per-sale scoping.

## D-13 Retry a failed job
- ONLINE_ROUTE= `POST /printing/jobs/{printJob}/retry` (`tenant.php:647`)
- ONLINE_VIEW= Retry button on failed rows (`pos/index.blade.php:5452-5454,5612-5636`)
- ONLINE_CONTROLLER_OR_SERVICE= `PrintJobController::retry` → shared `PrintJobService::requeueFailed` (failed|cancelled) (`:236-255`; `PrintJobService.php:611-624`)
- EDGE_INSTALLED_ROUTE= `POST /edge/local/pos/print-jobs/{job}/retry` (`edge_runtime.php:105`)
- EDGE_INSTALLED_VIEW= Retry button when `print_status==='failed'` (`edge/pos/index.blade.php:726,734`)
- EDGE_SOURCE_ROUTE= same
- ONLINE_BEHAVIOUR= requeue; agent picks up.
- EDGE_BEHAVIOUR= `EdgeLocalPrintDeliveryService::retryTerminalFailed` → same `requeueFailed` + delivery metadata reset; refuses anything that is not an Edge `terminal_failed` delivery (`:222-240`).
- VISUAL_DIFFERENCE= none material.
- NAVIGATION_DIFFERENCE= none.
- WORKFLOW_DIFFERENCE= Edge failure is terminal only after 6 attempts with backoff [5,15,30,60,120]s (`:47-51`) vs Online MAX_AUTO_REQUEUE=3 transient + printer-health defer.
- VALIDATION_DIFFERENCE= Edge cannot retry a `cancelled` job or a job without a delivery row.
- PRINTING_DIFFERENCE= none.
- PERMISSION_PARITY= D-24.
- ACTUAL_BROWSER_PROOF= none in this pass
- AUTOMATED_TEST_PROOF= service: `EdgeLocalPrintDeliveryMySqlTest.php:163` (backoff → terminal → local retry delivers), `EdgeLocalPrintWorkerLifecycleMySqlTest.php:204`; HTTP `/print-jobs/{job}/retry` not exercised by any Edge test found.
- STATUS= FUNCTIONAL_BUT_NOT_BROWSER_PROVEN
- EVIDENCE= `R\app\Services\Edge\EdgeLocalPrintDeliveryService.php:222-240`; `R\app\Http\Controllers\Edge\EdgeLocalPosController.php:1078-1092`
- REQUIRED_ACTION= add HTTP test for the Edge retry endpoint.

## D-14 Dismiss a job
- ONLINE_ROUTE= `POST /printing/jobs/{printJob}/dismiss` (`tenant.php:648`) — exposed only on admin `resources/views/tenant/printing/jobs/index.blade.php:127-135`, not on the POS page
- ONLINE_VIEW= admin jobs page
- ONLINE_CONTROLLER_OR_SERVICE= `PrintJobService::cancelObsolete` (`:555-580`)
- EDGE_INSTALLED_ROUTE= none · EDGE_INSTALLED_VIEW= none · EDGE_SOURCE_ROUTE= none
- ONLINE_BEHAVIOUR= abandon queued/failed job without counters.
- EDGE_BEHAVIOUR= no way to clear a terminal_failed/stuck job except retry.
- VISUAL/NAVIGATION/WORKFLOW/VALIDATION_DIFFERENCE= feature absent; POS screens are equal (neither has Dismiss).
- PRINTING_DIFFERENCE= none.
- PERMISSION_PARITY= n/a.
- ACTUAL_BROWSER_PROOF= none in this pass
- AUTOMATED_TEST_PROOF= Online `PrintJobDismissMySqlTest.php:38-96`; none on Edge.
- STATUS= MISSING_IN_EDGE
- EVIDENCE= `R\routes\edge_runtime.php:99-105`
- REQUIRED_ACTION= owner decision (admin-only feature); optional Edge endpoint delegating to `cancelObsolete`.

## D-15 Printing → Jobs admin index
- ONLINE_ROUTE= `GET /printing/jobs` (`tenant.php:641`) · ONLINE_VIEW= `tenant/printing/jobs/index.blade.php` · ONLINE_CONTROLLER_OR_SERVICE= `PrintJobController::index` (branch/terminal/order-type scoped, filters)
- EDGE_INSTALLED_ROUTE= none (operator health page `GET /edge/local/pos/health` shows print worker state, printers with "Offline" capability, jobs-by-status counts: `edge/health.blade.php:154,165-178`; CLI `edge:local:print-status` read-only, `EdgeLocalPrintStatusCommand.php`)
- EDGE_INSTALLED_VIEW= health page (counts only) · EDGE_SOURCE_ROUTE= same
- ONLINE_BEHAVIOUR/EDGE_BEHAVIOUR= per-job admin list vs aggregate counts; Recent Prints (D-12) is the per-job surface on Edge.
- Differences= admin list absent; LAB evidence `C:\Users\Dell\BingooEdgeLab\evidence\print-status.json` shows worker running, 1 network printer (127.0.0.1:9100, role both), queue all zero.
- PERMISSION_PARITY= Online `tenant.printing.jobs.index` is permission-exempt prefix but scoped by UserDataScope.
- ACTUAL_BROWSER_PROOF= none in this pass · AUTOMATED_TEST_PROOF= `EdgeLocalPrintWorkerLifecycleMySqlTest.php:363` (status command reports), `EdgeApplianceHealthMySqlTest.php` (USB warning)
- STATUS= FUNCTIONAL_BUT_UI_DIFFERENT
- EVIDENCE= `R\app\Console\Commands\EdgeLocalPrintStatusCommand.php:35-60`; `R\app\Services\Edge\EdgeApplianceHealthService.php:349-371`
- REQUIRED_ACTION= none unless owner wants an on-appliance per-job admin list.

## D-16 Printer configuration management (printers CRUD, terminal settings, category mappings, layouts + preview)
- ONLINE_ROUTE= `/printing/printers` CRUD (`tenant.php:620-623`), `POST /printing/terminal-settings` (`:629`), `/printing/category-mappings` (`:632-634`), `/printing/layouts` + `/{id}/preview` (`:637-639`)
- ONLINE_VIEW= `tenant/printing/printers/*`, `category-mappings/*`, `layouts/*` (`_form.blade.php:22` types browser/network/usb, `:73` supports_reminder)
- ONLINE_CONTROLLER_OR_SERVICE= `PrinterController` (`:103-192`), `CategoryPrinterMappingController` (roles kot/receipt/reminder, capability check `:57-74`), `ReceiptLayoutController` (`:30-160`)
- EDGE_INSTALLED_ROUTE= none (no management routes) — DATA is synced: bootstrap sections `printers` (branch OR global, incl. `printer_type, print_role, supports_reminder, ip, port, paper_size, characters_per_line, is_default, is_active, agent_enabled`), `receipt_layout_settings` (all layout toggles incl. PRINT-LAYOUT-ROWS-1 columns), `category_printer_mappings` (incl. `terminal_id, print_role, order_type, reminder_confirm_on_addition`), `terminal_printer_settings` (`receipt_printer_id, kot_printer_id, auto_print_*`) (`EdgeBootstrapService.php:733-746`); watermark includes them (`:584-597`); refresh applies updates/tombstones
- EDGE_INSTALLED_VIEW= none · EDGE_SOURCE_ROUTE= none
- ONLINE_BEHAVIOUR= Cloud-authored config.
- EDGE_BEHAVIOUR= read-only consumer ("printer destination comes ONLY from the bootstrapped trusted printer config", `EdgeLocalPrintDeliveryService.php:34-35`); changes reach Edge on config refresh only.
- Differences= no local editing; `auto_print_*` synced but unused (D-27); reminder mappings synced but only consumed by cancellation reminders (D-04).
- PERMISSION_PARITY= Online per-route permissions `tenant.printing.*` (module `printing`, `MasterSeeder.php:285-292`); n/a on Edge.
- ACTUAL_BROWSER_PROOF= none in this pass
- AUTOMATED_TEST_PROOF= `EdgeLocalImportMySqlTest.php:276-320` (global printer/mapping parity, foreign printer rejected), `EdgeConfigRefreshMySqlTest.php:117-123,289-302,320` (printer IP update, layout footer update, mapping tombstone), `EdgeCanonicalAlignmentMySqlTest.php:237-254` (terminal_id + layout row columns exported)
- STATUS= MISSING_IN_EDGE (management UI) — data dependency itself MATCHED_AND_PROVEN
- EVIDENCE= `R\app\Services\Edge\EdgeBootstrapService.php:733-746`
- REQUIRED_ACTION= owner to confirm Cloud-only printer administration is accepted for the pilot (design intent documented in code; no owner acceptance located in this pass).

## D-17 Printer health — status / ping / reset / reboot
- ONLINE_ROUTE= `GET /printing/printers/{printer}/status`, `POST …/ping|reset|reboot` (`tenant.php:625-628`) → `PrintAgentCommand` executed by the agent (`PrinterController.php:31-100`)
- EDGE_INSTALLED_ROUTE= none (no agent, no command queue); worker connect/write errors recorded per delivery (`edge_local_print_deliveries.last_error`, `EdgeLocalPrintDeliveryService.php:196-206`); health page/CLI show worker heartbeat
- Differences= no remote test/reset/reboot offline; no printer `last_ping_*` on Edge.
- ACTUAL_BROWSER_PROOF= none in this pass · AUTOMATED_TEST_PROOF= Online `PrinterHealthLifecycleMySqlTest.php:83-158`; Edge worker lifecycle tests (`EdgeLocalPrintWorkerLifecycleMySqlTest.php:141-363`)
- STATUS= MISSING_IN_EDGE
- EVIDENCE= `R\app\Http\Controllers\Tenant\PrinterController.php:31-100`
- REQUIRED_ACTION= owner decision; a local "test print"/ping via `EdgeNetworkPrinterTransport` would be small.

## D-18 Print Agents (pairing, test-print, download)
- ONLINE_ROUTE= `/print/agents*` (`tenant.php:655-665`) · ONLINE_CONTROLLER_OR_SERVICE= `PrintAgentController`, `PrintAgentApiController` (claims ONLY `printer_type='network'` jobs, `:113,148,304`), agent `tools/print-agent/print-agent.js` (skips non-network `:462`, writes payload + `\n\n\n` `:407-410`)
- EDGE_INSTALLED_ROUTE= none by architecture lock: `config/edge.php:533-545` (`network_printer_edge_direct=true`, `second_edge_agent_for_network_printer=false`, `usb_dual_mode_agent=not_built`, `usb_status_for_pilot=ONLINE_REQUIRED`); no `print_agents` rows on Edge
- Differences= Edge worker replaces the agent for network printers (D-21).
- ACTUAL_BROWSER_PROOF= none in this pass · AUTOMATED_TEST_PROOF= lock asserted `EdgeNoDuplicatePrintAfterSyncMySqlTest.php:298-302`; Online `CloudPrintAgentContractMySqlTest.php:72-186`
- STATUS= ACCEPTED_ONLINE_REQUIRED
- EVIDENCE= `R\config\edge.php:539-545`
- REQUIRED_ACTION= none.

## D-19 Quick Report — View/Print here + Send to network (+ email)
- ONLINE_ROUTE= `PosQuickReportController::print` (thermal Blade) / `sendToNetwork` (`:169-231`) / `email`
- ONLINE_VIEW= Quick Report modal (`pos/index.blade.php:1528-1666`: Email / Print here / Send to network, branch pick, network printer select)
- ONLINE_CONTROLLER_OR_SERVICE= `SalesReportEngine` + `SalesReportDocumentService` + `EscPosPayloadService::buildReport` job `document_type=report`, prefix RPT
- EDGE_INSTALLED_ROUTE= `GET /quick-report/options|view`, `POST /quick-report/network|email` (`edge_runtime.php:108-111`)
- EDGE_INSTALLED_VIEW= "Quick Report" button + modal (`edge/pos/index.blade.php:364,886-920`: network printer select, "Send to network", "View / Print here"; email truthfully unavailable)
- EDGE_SOURCE_ROUTE= same
- ONLINE_BEHAVIOUR/EDGE_BEHAVIOUR= same engine/Blade/bytes; Edge bound to appliance branch + operator scope; stamps current terminal on the job (`EdgeQuickReportController.php:152-166`); email 422 "Internet required" (`:172-181`).
- VISUAL_DIFFERENCE= Edge modal minimal; no branch selector (single branch), no email.
- NAVIGATION_DIFFERENCE= none.
- WORKFLOW_DIFFERENCE= email = Internet required (by design).
- VALIDATION_DIFFERENCE= Edge 422 for non-network printer (Online same rule `:188-190`).
- PRINTING_DIFFERENCE= none (same `buildReport`, paper from printer `paper_size`).
- PERMISSION_PARITY= both gate on `tenant.pos.quick-report-send` (`EdgeQuickReportController.php:39,48`; exempt prefix + in-controller guard online).
- ACTUAL_BROWSER_PROOF= none in this pass
- AUTOMATED_TEST_PROOF= `EdgeCashierQuickReportHttpMySqlTest.php:133-197` (view renders canonical thermal, network job report/printer/terminal/raw bytes claimable by Edge delivery, USB refused 422, permission required)
- STATUS= MATCHED_AND_PROVEN (email ACCEPTED_ONLINE_REQUIRED)
- EVIDENCE= `R\app\Http\Controllers\Edge\EdgeQuickReportController.php:120-184`
- REQUIRED_ACTION= none.

## D-20 Physical network transport & delivery semantics
- ONLINE= Cloud Print Agent: claim via `PrintAgentApiController::pending` (2-min lease, network only), TCP 9100 write `payload + "\n\n\n"` (`print-agent.js:407-410`), `markFailed` auto-requeue ≤3 transient (`PrintJobService.php:627-675`), printer-health defer (`:590-608`), `printing:autoclose-stuck` every 15 min (`routes/console.php:63`)
- EDGE= `edge:local:print-worker` (supervised singleton, `EdgeLocalPrintWorkerCommand.php`) → `EdgeLocalPrintDeliveryService::claimNext` (queued + non-null printer + active network printer + branch scope + per-printer FIFO + lease token 120s, `:63-135`) → `EdgeNetworkPrinterTransport::send` (`payload + "\n\n\n"`, connect/write 8s, `:20-56`) → `completeSuccess` (shared `markPrinted`) / `completeFailure` (backoff, terminal at 6) — stale tokens never mutate
- PRINTING_DIFFERENCE= **bytes identical** (raw_payload stored at job creation incl. ESC/POS `CUT` inside payload, `EscPosPayloadService.php:19,921,1149`; never rebuilt at delivery). Semantic differences: Edge backoff/terminal counts vs Online 3-requeue/defer; FIFO per printer enforced on Edge; Edge does not run the autoclose sweep (not in `cli_allowlist`, `config/edge.php:280-296` — scheduler status on the appliance NOT verified).
- ACTUAL_BROWSER_PROOF= none in this pass
- AUTOMATED_TEST_PROOF= `EdgeLocalPrintDeliveryMySqlTest.php:104-160` (real fake printer receives EXACT stored bytes + feed; sale mutation after queue does not change paper; KOT completion never touches the business event), `:163-345` (backoff, stale/expired lease, FIFO), `EdgeLocalPrintRaceTest.php:132-274`, `EdgeLocalPrintWorkerLifecycleMySqlTest.php:141-363` (real process, master DB dead, duplicate worker exits, restart), `EdgeNoDuplicatePrintAfterSyncMySqlTest.php:222-296` (one KOT + one receipt once, Cloud never re-prints after sync/replay/lost-ACK/handback); Online `CloudPrintAgentContractMySqlTest.php`, `PrintAutoRequeueMySqlTest.php`
- STATUS= MATCHED_AND_PROVEN
- EVIDENCE= `R\app\Services\Edge\EdgeNetworkPrinterTransport.php:24,44`; `R\tools\print-agent\print-agent.js:407-410`
- REQUIRED_ACTION= verify on the appliance whether any scheduled sweep for stuck `queued` jobs exists (none found in Edge allowlist).

## D-21 USB printers
- ONLINE= printer rows of type `usb` are accepted by CRUD (`PrinterController.php:109`) but the Cloud agent skips non-network printers (`print-agent.js:462-465`) and `pending` never offers them (`PrintAgentApiController.php:113`) → on Online a job routed to a USB row is not delivered by the agent either; practical Online USB printing = browser Print Here / `browser`-type printer auto `window.print()`.
- EDGE= same routing; worker network-only (`EdgeLocalPrintDeliveryService.php:73-78`); health page warns "USB printing is Online-only" (`EdgeApplianceHealthService.php:366-369`; `edge/health.blade.php:166`); quick report refuses USB (422). Browser Print Here works on Edge for `printer_id NULL` jobs only.
- PRINTING_DIFFERENCE= a USB-mapped station/receipt on either side yields a queued job with `fallback=false` that never prints and never auto-opens; Edge cannot Mark Printed it (422).
- ACTUAL_BROWSER_PROOF= none in this pass · AUTOMATED_TEST_PROOF= `EdgeCashierQuickReportHttpMySqlTest.php:189-190`, `EdgeApplianceHealthMySqlTest.php` (USB warning), lock assertion `EdgeNoDuplicatePrintAfterSyncMySqlTest.php:302`
- STATUS= ACCEPTED_ONLINE_REQUIRED (owner rule) — with finding: the premise "Online prints USB" is not backed by the agent code; USB rows are undeliverable on both sides.
- EVIDENCE= `R\tools\print-agent\print-agent.js:462-465`; `R\config\edge.php:542-543`
- REQUIRED_ACTION= owner to re-check the USB classification: if pilot sites print USB via browser dialog online, Edge's Print Here popup gives the same outcome only for `printer_id NULL` routes — routing a USB row is a dead end on both.

## D-22 Document layouts & ESC/POS payloads (same Blade/bytes?)
- ONLINE/EDGE= identical files in I and R (see header): `documents/{kot,receipt,reminder}.blade.php`, `EscPosPayloadService`, `PrintDocumentController`, `KotTicketTime`, `reports/center/print.blade.php`. `layoutFor(branch, type)` reads synced `receipt_layout_settings` (`EscPosPayloadService.php:166-178`); combo deal names from synced `combos` (`PrintDocumentController.php:105-114`); times from frozen shift tz → payload tz → branch tz (`:195-202`).
- PRINTING_DIFFERENCE (on paper)= none for KOT/receipt/report given identical layout rows; REMINDER slips absent (D-04); no auto-cut difference (CUT is in payload). Table/waiter/order-type/category station header identical. Edge-only Blade change: `$edgeMarkPrintedUrl ??` in kot/receipt (no-print UI only, not on paper).
- ACTUAL_BROWSER_PROOF= none in this pass · AUTOMATED_TEST_PROOF= `EdgeCashierPrintingHttpMySqlTest.php:212,226,233` (rendered doc + bytes on Edge), `EdgeCashierDealsDiscountsHttpMySqlTest.php:198-210` (deal identity on Edge KOT doc), `EdgeNoDuplicatePrintAfterSyncMySqlTest.php:243` (bytes on the wire); shared-code unit tests `tests/Unit/Printing/*`
- STATUS= MATCHED_AND_PROVEN
- EVIDENCE= `diff -q` results above; `R\app\Services\Printing\EscPosPayloadService.php:166-178,204-236`
- REQUIRED_ACTION= none.

## D-23 Printing permissions / data scope
- ONLINE= `EnsureRoutePermission` exempts `tenant.printing.jobs.*`, `tenant.printing.documents.*`, `tenant.printing.layouts.preview`, `tenant.pos.quick-report.*` (`R\app\Http\Middleware\EnsureRoutePermission.php:23-45`); controllers apply `UserDataScope::deniesSale` (branch + assigned terminals + order types, `UserDataScope.php:237-251`) and `operatorTerminalId` (foreign terminal → null, `:158-168`); management routes need `tenant.printing.*` / `tenant.print-agents.*`.
- EDGE= `edge.auth` (401) + `edge.branch` (bound branch/tenant 403) (`EnsureEdgeAuthenticated.php:72`, `EnsureEdgeBranchBound.php:36-39`); print endpoints check branch only — **no terminal/order-type scope** on reprint/list/document; quick report checks `tenant.pos.quick-report-send` (proven); cancel/void use the shared permission (`tenant.pos.void-kot-item` manager marker, `EdgeLocalPosService.php:1037-1043`).
- PERMISSION_PARITY= partial: an operator restricted to Terminal A online cannot reprint a Terminal B sale; on Edge he can.
- ACTUAL_BROWSER_PROOF= none in this pass · AUTOMATED_TEST_PROOF= `EdgeCashierQuickReportHttpMySqlTest.php:197`; `EdgeCashierPermissionHttpMySqlTest.php:97` (no printing assertions)
- STATUS= PARTIALLY_IMPLEMENTED
- EVIDENCE= `R\app\Http\Controllers\Edge\EdgeLocalPosController.php:997-1000,1030-1036` vs `R\app\Http\Controllers\Tenant\PrintJobController.php:299-321`
- REQUIRED_ACTION= apply `UserDataScope::deniesSale` on Edge print endpoints (permissions/terminals are synced per EDGE_OFFLINE_PERMISSION_AUTHORITY).

## D-24 Terminal auto-print preferences + Printing panel
- ONLINE_ROUTE= `POST /printing/terminal-settings` (`tenant.php:629`) + POS `terminalPrintConfig` (`POSController.php:484-489`) · ONLINE_VIEW= `#print-pref-panel` toggles + hints (`pos/index.blade.php:942-966`), `refreshPrintPanel` (`:4408`), `terminalAutoKot` / `autoPrintEnabled`
- EDGE= settings synced (`terminal_printer_settings.auto_print_receipt/auto_print_kot`) but never read; receipt always printed, KOT always manual.
- STATUS= MISSING_IN_EDGE
- ACTUAL_BROWSER_PROOF= none in this pass · AUTOMATED_TEST_PROOF= none on Edge
- EVIDENCE= `grep -rn auto_print R\app\Services\Edge R\app\Http\Controllers\Edge R\resources\views\edge` → only `EdgeBootstrapService.php:746`
- REQUIRED_ACTION= read the synced flags in `EdgeLocalPosController::screen` and honour them in `autoReceipt`/hold flow.

## D-25 Canonical drift affecting printing (14d-2 head 243e01d, not merged into 4affc67)
- Commits on canonical not in HEAD touching printing paths: `8d11bfb` MANAGER-APPROVAL-COMBO-VOID-1 (`KotCancellationService.php` ±94 lines — approval shape for a single combo-line void; Edge executes the pre-fix service → a combo single-line void may be refused and no CANCEL KOT queued), `636ab9d` TABLE-BILL-PREVIEW-PARITY-1 (`documents/receipt.blade.php` +47 `@isset($tableBill)` — dormant on Edge), `7cd5924` BILL-PREVIEW-WRONG-PRINT-1 (`pos/index.blade.php` — Online only). Canonical `kot.blade.php`/`receipt.blade.php` LACK the Edge-only `$edgeMarkPrintedUrl ??` (git diff HEAD..243e01d) → a straight merge would re-point the Edge "Mark Printed" form at the Cloud route.
- STATUS= CANONICAL_DRIFT_NOT_RECONCILED
- EVIDENCE= `git diff --stat HEAD..feat/14d-2-plan-upgrade-requests` (KotCancellationService 94, receipt.blade 47, kot.blade 2, pos/index 98); register `R\docs\status\edge-online-pos-parity-register.md:79`
- REQUIRED_ACTION= reconcile `KotCancellationService` (+ `ManagerApprovalComboVoidMySqlTest`) and re-apply `edgeMarkPrintedUrl` when merging the Blades.

---

## Answers to (a)–(j)
- **(a) KOT routing on Edge:** the SAME `PrintRoutingService::kotRoutesForSale` (category mapping with terminal precedence → terminal `kot_printer_id` → branch default → browser), on synced `printers`/`category_printer_mappings`/`terminal_printer_settings` (branch OR global, `EdgeBootstrapService.php:733-746`). For a 3-station Kashif-Food setup the per-category/per-printer split is identical **when the KOT is sent from the counter that holds the sale**; Edge omits the operator-terminal override (`EdgeLocalPosService.php:1021`), so a round sent from another counter routes counter-category items to the ORIGINAL counter (Online: current counter). No Edge test seeds multi-station mappings; LAB has one printer (`print-status.json`).
- **(b) Rounds/deltas:** identical (`ADDITION KOT #n`, `(R)` prefix, delta-only) — Edge-proven at batch level, heading proven on shared code.
- **(c) Reminder:** Online = `document_type=reminder`, `print_role=reminder` mappings, `supports_reminder` printers, auto/ask routes, confirm + reminder-reprint routes, `DUPLICATE n`. Edge = nothing wired (0 references); only whole-order-cancel correction reminders emerge via the shared cancellation service.
- **(d) Cancellation KOT:** whole-order cancel = shared `CANCEL KOT #n` at the current counter, proven; line void = server-only (no screen UI), routed on the sale's terminal, no correction reminder.
- **(e) Receipt:** ensure-once (`receipt:final|proforma:sale-id`) + reprint fresh job at current counter, proven on Edge; no DUPLICATE marker on receipts on either side (`copy_no` fixed 1).
- **(f) Bill preview:** Online has Print here + Send to network on the preview; Edge preview is totals-only, no printing.
- **(g) Recent Prints/retry/dismiss:** Edge list is branch-wide with Print here on every row; retry limited to Edge terminal_failed; no dismiss; report rows 404 on Print here.
- **(h) Permissions:** quick report parity proven; print endpoints lack UserDataScope terminal/order-type scoping on Edge; management permissions n/a.
- **(i) Config on Edge:** synced & proven: printers (all types), layouts (all columns), category mappings (incl. reminder role/confirm flag/terminal), terminal settings (incl. unused auto flags). Not on Edge: print agents/commands, printer health columns, reminder consumption, USB delivery.
- **(j) Documents/payloads:** same Blade + EscPos + layout rows → same paper for KOT/receipt/report; reminders absent; transport bytes identical (`raw_payload + "\n\n\n"`, CUT inside payload).

## Not verified (and why)
- Any rendered browser control (read-only pass; no browser/process run).
- LAB tenant's actual `category_printer_mappings`/`terminal_printer_settings` (seed evidence lists only `printer_id: 1`, `terminal_id: 1`; print-status shows one network printer, `is_default 0`) → real multi-station routing in the lab unproven.
- Whether the appliance runs the Laravel scheduler (`printing:autoclose-stuck`) — command not in `cli_allowlist`; runtime not inspected.
- Edge HTTP `/print-jobs/{job}/retry` behaviour end-to-end (service proven only).
- `EdgeCashierPrintingHttpMySqlTest.php:150-153` context for the 302 assertion (not read).
- Branch timezone / shift tz sync equivalence used by `printTz` (branches section not audited).
- Production Cloud behaviour and the live 14d-2 deploy state (only local git objects inspected).