{{-- W5 js/printing (Team 5): Online POS printing parity on the Branch Server — the Printing panel (auto-print KOT / receipt,
     #print-pref-panel), receipt after payment honouring the auto-print preference, KOT request + Reminder plan ("Resend updated
     Reminder?"), Direct Pay printing, Print Here as a same-screen iframe (#printHereModal), per-sale Recent Prints / Last Print
     (#lastPrintModal) with Reprint / Retry / Dismiss / Reminder reprint, and the bill-preview document with Print here / Send to network.
     Interface for other teams: printPrefsHtml(), readPrintPrefs(), printBillPreview(payload), openPrintHere(job), openLastPrint(saleId),
     openKotReminder(saleId), fireKot(saleId, opts), handleReminderPlan(saleId, reminder), handlePrintJobs(jobs),
     processDirectPayPrinting(saleId, printing), afterSalePrinting(sale); autoReceipt / printHere / recentPrints keep their names. --}}
        // ---- Printing preferences (Online terminalPrintConfig + this-device overrides, same localStorage keys as Online). ----
        const PRINT_OVERRIDE_KEY = { kot: 'pos_auto_kot', receipt: 'pos_auto_receipt' };
        const printPrefs = { loaded: false, terminals: {}, user: null };
        let lastPrintSale = { id: null, no: null };
        async function loadPrintPrefs() {
            try { const r = await api('GET', '/print-preferences'); printPrefs.terminals = r.terminals || {}; printPrefs.user = r.user || null; printPrefs.loaded = true; }
            catch (e) { printPrefs.loaded = false; }
        }
        function printOverride(kind) { try { return localStorage.getItem(PRINT_OVERRIDE_KEY[kind]); } catch (e) { return null; } }
        function setPrintOverride(kind, on) { try { localStorage.setItem(PRINT_OVERRIDE_KEY[kind], on ? '1' : '0'); } catch (e) { /* private window: session-only */ } }
        // Online terminalAuto(): no terminal / no saved setting → not automatic (ask / manual).
        function terminalAuto(kind) {
            const cfg = state.terminalId ? printPrefs.terminals[String(state.terminalId)] : null;
            if (!cfg) return false;
            return kind === 'kot' ? !!cfg.auto_print_kot : !!cfg.auto_print_receipt;
        }
        // Online autoPrintEnabled(): a this-device override wins, else the terminal's saved setting.
        function autoPrintEnabled(kind) {
            const ov = printOverride(kind);
            if (ov === '1') return true;
            if (ov === '0') return false;
            return terminalAuto(kind);
        }
        // Units not yet sent to the kitchen in the current cart (Online kotPending — a deal counts once).
        function kotPending() {
            let sent = 0, pending = 0;
            state.cart.forEach(l => { const q = Number(l.quantity) || 0; const s = Number(l.kot_sent_quantity || 0); sent += Math.min(s, q); pending += Math.max(q - s, 0); });
            return { sent, pending };
        }
        function printPanelHints() {
            const pend = kotPending().pending, kotAuto = autoPrintEnabled('kot'), rcpAuto = autoPrintEnabled('receipt');
            const kot = pend <= 0 ? 'Kitchen: all items already sent ✓'
                : (!state.terminalId ? pend + ' new item(s) — no terminal → opens KOT for manual print'
                : (kotAuto ? pend + ' new item(s) → auto-send to kitchen' : pend + ' new item(s) → will ask before sending'));
            const rcp = !rcpAuto ? 'Off — no receipt will print' : (!state.terminalId ? 'No terminal → opens receipt for manual print' : 'Prints to receipt printer on complete');
            return { kot, rcp, kotAuto, rcpAuto };
        }
        // The Online Printing panel (#print-pref-panel) — markup with the Online ids; rendered by the Review & Pay owner.
        function printPrefsHtml() {
            const h = printPanelHints();
            const t = (DATA.terminals || []).find(x => x.id === state.terminalId);
            return '<div class="field" id="print-pref-panel" style="border:1px solid var(--line,#dee2e6);border-radius:6px;padding:.5rem .7rem">' +
                '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.25rem"><strong style="font-size:.85rem">Printing</strong>' +
                '<span class="muted" style="font-size:.8rem" id="print-terminal-label">' + esc(t ? t.name : 'No terminal') + '</span></div>' +
                '<label style="display:flex;gap:.5rem;align-items:flex-start;font-size:.85rem"><input type="checkbox" id="auto-kot-toggle"' + (h.kotAuto ? ' checked' : '') + '>' +
                '<span>Auto-print Kitchen Ticket (KOT)<span class="muted" style="display:block" id="kot-status-hint">' + esc(h.kot) + '</span></span></label>' +
                '<label style="display:flex;gap:.5rem;align-items:flex-start;font-size:.85rem"><input type="checkbox" id="auto-receipt-toggle"' + (h.rcpAuto ? ' checked' : '') + '>' +
                '<span>Auto-print Receipt<span class="muted" style="display:block" id="receipt-status-hint">' + esc(h.rcp) + '</span></span></label>' +
                '<div class="muted" style="font-size:.8rem;margin-top:.35rem">Reminder follows every accepted KOT round. Additional rounds use each printer\'s Auto/Ask setting.</div></div>';
        }
        function refreshPrintPanel() {
            const h = printPanelHints();
            const k = $('auto-kot-toggle'), r = $('auto-receipt-toggle');
            if (k) k.checked = h.kotAuto;
            if (r) r.checked = h.rcpAuto;
            if ($('kot-status-hint')) $('kot-status-hint').textContent = h.kot;
            if ($('receipt-status-hint')) $('receipt-status-hint').textContent = h.rcp;
        }
        // Session (this-device) overrides — Online stores them the same way when a toggle changes.
        document.addEventListener('change', e => {
            if (e.target && e.target.id === 'auto-kot-toggle') { setPrintOverride('kot', e.target.checked); refreshPrintPanel(); }
            if (e.target && e.target.id === 'auto-receipt-toggle') { setPrintOverride('receipt', e.target.checked); refreshPrintPanel(); }
        });
        // Online Direct Pay intents (kot_print_intent / receipt_print_intent), decided BEFORE payment completes:
        // nothing new for the kitchen → skip; auto KOT → print; otherwise the Online "Print Kitchen Order?" question.
        function readPrintPrefs() {
            let kot = 'skip';
            if (kotPending().pending > 0) {
                const k = $('auto-kot-toggle');
                const auto = k ? k.checked : autoPrintEnabled('kot');
                kot = auto ? 'print' : (window.confirm('Print Kitchen Order?\nChoose before payment is completed. Reminder follows an accepted KOT round.') ? 'print' : 'skip');
            }
            const r = $('auto-receipt-toggle');
            const receipt = (r ? r.checked : autoPrintEnabled('receipt')) ? 'print' : 'skip';
            return { kot_print_intent: kot, receipt_print_intent: receipt };
        }

        // ---- Receipt after payment (ensure-once) — honours the Auto-print Receipt preference exactly like Online maybePrintReceipt. ----
        function rememberLastSale(id, no) { if (id) lastPrintSale = { id: Number(id), no: no || null }; }
        async function autoReceipt(saleId, saleNo) {
            rememberLastSale(saleId, saleNo);
            if (!autoPrintEnabled('receipt')) { toast('Receipt: auto-print is off for this counter — reprint it from Recent Prints if the guest asks.'); return; }
            try {
                const job = await api('POST', '/sales/' + saleId + '/receipt', {});
                if (job.fallback) { toast('No printer found — opening receipt for manual print'); openPrintHere(job); }
                else { toast('Receipt → ' + job.printer_name); }
            } catch (e) { toast('Receipt not queued: ' + e.message); }
        }

        // ---- After a paid sale (Team 2 calls this with the POST /sales response): Direct Pay printing when the sale carries
        //      print intents (Online processDirectPayPrinting via the shared orchestrator), else the auto receipt. ----
        async function afterSalePrinting(sale) {
            if (!sale || !sale.sale_id) return;
            rememberLastSale(sale.sale_id, sale.sale_no);
            if (sale.printing) { await processDirectPayPrinting(sale.sale_id, sale.printing); return; }
            if (sale.print_intents) {
                try { const r = await api('POST', '/sales/' + sale.sale_id + '/printing/retry', {}); await processDirectPayPrinting(sale.sale_id, r.printing || {}); }
                catch (e) { toast('Printing: ' + e.message); }
                return;
            }
            autoReceipt(sale.sale_id, sale.sale_no);
        }

        // ---- KOT request (Online fireKotSilently): delta routed at THIS counter, then the Reminder round. ----
        async function fireKot(saleId, opts) {
            opts = opts || {};
            try {
                const r = await api('POST', '/sales/' + saleId + '/kot', { reprint: !!opts.reprint, line_ids: opts.lineIds || [] });
                if (!r.jobs || !r.jobs.length) { toast(r.message || 'No new items to send to kitchen'); return r; }
                toast((opts.reprint ? 'KOT reprint' : 'KOT sent to kitchen') + ' · ' + r.jobs.map(j => j.printer_name + (j.copy_no > 1 ? ' (copy ' + j.copy_no + ')' : '')).join(', '));
                handlePrintJobs(r.jobs, 'KOT');
                await handleReminderPlan(saleId, r.reminder || {});
                return r;
            } catch (e) { toast(e.message || 'KOT could not be queued.'); return null; }
        }
        // Any browser/fallback job in a response opens in Print Here (Online openFallbackPreviews); the rest list in Recent Prints.
        function handlePrintJobs(jobs, label) {
            const fb = (jobs || []).filter(j => j && j.fallback && j.print_status !== 'printed' && j.print_status !== 'cancelled');
            if (!fb.length) return;
            toast('No printer found — opening ' + (label || 'the document') + ' for manual print' + (fb.length > 1 ? ' (' + (fb.length - 1) + ' more in Recent Prints)' : ''));
            openPrintHere(fb[0]);
        }
        // Online handleReminderPlan: auto Reminders are already queued; Ask-on-addition printers need the operator's Yes / No.
        function handleReminderPlan(saleId, reminder) {
            reminder = reminder || {};
            if (reminder.warning) toast(reminder.warning);
            const printers = reminder.ask_printers || [];
            if (!printers.length || !reminder.confirmation_token) return Promise.resolve();
            return new Promise(resolve => {
                openModal('<h2 id="reminderConfirmLabel">Resend updated Reminder?</h2><p>Send the complete updated order to:<br><strong>' +
                    printers.map(p => esc(p.name)).join('<br>') + '</strong></p>' +
                    '<div class="btn-row"><button class="ghost" id="reminder-decline-btn">No</button><button class="ok" id="reminder-confirm-btn">Yes, Send</button></div>');
                const decide = async decision => {
                    closeModal();
                    try {
                        const d = await api('POST', '/sales/' + saleId + '/reminders/confirm', { confirmation_token: reminder.confirmation_token, decision });
                        if (decision === 'confirm') toast((d.jobs || []).length + ' updated Reminder job(s) queued');
                    } catch (e) { toast(e.message || 'Reminder could not be queued.'); }
                    resolve();
                };
                $('reminder-confirm-btn').onclick = () => decide('confirm');
                $('reminder-decline-btn').onclick = () => decide('decline');
            });
        }
        // Online processDirectPayPrinting: open fallbacks, answer the Reminder question, offer "Retry Printing" while anything is pending.
        async function processDirectPayPrinting(saleId, printing) {
            printing = printing || {};
            handlePrintJobs(printing.kot_jobs || [], 'KOT');
            if (printing.receipt && printing.receipt.fallback) { openPrintHere(printing.receipt); }
            await handleReminderPlan(saleId, printing.reminder || {});
            if (!printing.retry_available) return printing;
            return new Promise(resolve => {
                openModal('<h2>Payment Successful</h2><p>The sale is paid, but one or more print instructions are pending.</p>' +
                    '<div class="btn-row"><button class="ghost" id="dp-continue-btn">Continue</button><button class="warn" id="dp-retry-btn">Retry Printing</button></div>');
                $('dp-continue-btn').onclick = () => { closeModal(); resolve(printing); };
                $('dp-retry-btn').onclick = async () => {
                    closeModal();
                    try { const r = await api('POST', '/sales/' + saleId + '/printing/retry', {}); resolve(await processDirectPayPrinting(saleId, r.printing || {})); }
                    catch (e) { toast(e.message || 'Printing retry failed.'); resolve(printing); }
                };
            });
        }

        // ---- Print Here: the canonical document in a SAME-SCREEN frame (Online #printHereModal), print dialog auto-fired. ----
        function openPrintHere(job) {
            if (job && typeof job !== 'object') job = { id: Number(job), preview_url: BASE + '/print-jobs/' + Number(job) + '/document' };
            if (!job || !job.preview_url) return;
            if (job.has_document === false) { toast('This print job has no browser document — use Quick Report → View / Print here.'); return; }
            openModal('<div id="printHereModal"><h2 id="printHereModalLabel">Print Here' + (job.job_no ? ' — ' + esc(job.job_no) : '') + '</h2>' +
                '<iframe id="print-here-frame" title="Print document" src="' + esc(job.preview_url) + '" style="width:100%;height:68vh;border:0;background:#fff"></iframe>' +
                '<div class="btn-row">' +
                (job.fallback && job.print_status !== 'printed' && job.id ? '<button class="ok" id="print-here-printed-btn">Printed</button>' : '') +
                '<button class="ghost" id="print-here-window-btn">Open in window</button>' +
                '<button class="primary" id="print-here-print-btn">Print</button>' +
                '<button class="ghost" onclick="EdgePOS.closeModal()">Close</button></div></div>');
            const frame = $('print-here-frame');
            const doPrint = () => { try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch (e) { toast('Printing from this frame was blocked — use Open in window.'); } };
            frame.addEventListener('load', () => setTimeout(doPrint, 300), { once: true });
            $('print-here-print-btn').onclick = doPrint;
            // Fallback when the frame cannot print (browser policy): the same document in its own window.
            $('print-here-window-btn').onclick = () => { const w = window.open(job.preview_url, '_blank', 'width=420,height=640'); if (!w) toast('Allow pop-ups to open the document in a window.'); };
            const done = $('print-here-printed-btn');
            if (done) done.onclick = async () => { try { await api('POST', '/print-jobs/' + job.id + '/printed', {}); toast('Marked printed.'); closeModal(); } catch (e) { toast(e.message); } };
        }
        function printHere(job) { openPrintHere(job); }

        // ---- Recent Prints (branch) + per-sale Last Print (Online #lastPrintModal): Print Here / View / Retry / Dismiss / Reprint. ----
        function jobItemsCell(j) {
            if (j.document_type !== 'kot' && j.document_type !== 'reminder') return '—';
            let cell = j.line_count > 0 ? j.line_count + ' item' + (j.line_count !== 1 ? 's' : '') : 'All items';
            if (j.document_type === 'reminder') { cell += ' · Rev ' + Number(j.revision || 1); if (j.is_reprint) cell += ' · Duplicate ' + Number(j.copy_no || 1); }
            else if (j.event_type && j.event_type !== 'normal') { cell += ' · ' + j.event_type + (j.event_type === 'duplicate' ? ' ' + Number(j.copy_no || 1) : ''); }
            return cell;
        }
        function printJobRowsHtml(jobs, perSale) {
            const typeLabel = t => t === 'kot' ? 'KOT' : (t === 'reminder' ? 'Reminder' : (t === 'report' ? 'Report' : 'Receipt'));
            let html = '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:.85rem"><thead><tr style="text-align:left;border-bottom:1px solid var(--line,#dee2e6)">' +
                '<th>Job No</th>' + (perSale ? '' : '<th>Sale</th>') + '<th>Type</th><th>Status</th><th>Printer</th><th>Items</th><th>Time</th><th style="text-align:right">Action</th></tr></thead><tbody>';
            jobs.forEach(j => {
                const failed = j.print_status === 'failed', open = j.print_status === 'queued' || failed;
                const reprintAttr = j.document_type === 'reminder' ? (j.fallback ? '' : ' data-reminder-reprint="' + j.id + '"')
                    : ((j.document_type === 'receipt' || j.document_type === 'kot') && j.reference_id ? ' data-reprint="' + j.reference_id + '" data-kind="' + j.document_type + '"' : '');
                html += '<tr style="border-bottom:1px solid #eef0f3' + (failed ? ';background:#f8d7da' : '') + '">' +
                    '<td><strong>' + esc(j.job_no || ('#' + j.id)) + '</strong></td>' +
                    (perSale ? '' : '<td>' + (j.reference_id ? '<a href="#" data-last-print="' + j.reference_id + '" data-sale-no="' + esc(j.reference_no || '') + '">' + esc(j.reference_no || ('#' + j.reference_id)) + '</a>' : '—') + '</td>') +
                    '<td>' + typeLabel(j.document_type) + '</td>' +
                    '<td>' + esc(j.print_status) + (j.error_message && (failed || j.print_status === 'cancelled') ? '<div class="muted" style="font-size:.75rem">' + esc(j.error_message) + '</div>' : '') + '</td>' +
                    '<td class="muted">' + esc(j.printer_name) + '</td><td class="muted">' + esc(jobItemsCell(j)) + '</td>' +
                    '<td class="muted">' + esc(j.created_at ? new Date(j.created_at).toLocaleTimeString() : '') + '</td>' +
                    '<td style="text-align:right;white-space:nowrap">' +
                    (j.has_document !== false && (failed || j.fallback) ? '<button class="sm" data-open="' + j.id + '">' + (failed ? 'Print Here' : 'View') + '</button> ' : '') +
                    (j.fallback && j.print_status === 'queued' && j.has_document !== false ? '<button class="sm ok" data-printed="' + j.id + '">Printed</button> ' : '') +
                    (failed || j.print_status === 'cancelled' ? '<button class="sm warn" data-retry="' + j.id + '">Retry</button> ' : '') +
                    (open ? '<button class="sm ghost" data-dismiss="' + j.id + '">Dismiss</button> ' : '') +
                    (reprintAttr ? '<button class="sm"' + reprintAttr + '>Reprint</button>' : '') +
                    '</td></tr>';
            });
            return html + '</tbody></table></div>';
        }
        function wirePrintJobRows(jobs, reload) {
            const byJob = id => jobs.find(x => x.id === Number(id));
            const run = async (fn, ok) => { try { const r = await fn(); if (ok) toast(typeof ok === 'function' ? ok(r) : ok); } catch (e) { toast(e.message); } if (!$('print-here-frame')) reload(); };
            document.querySelectorAll('#modal [data-open]').forEach(b => b.onclick = () => openPrintHere(byJob(b.dataset.open)));
            document.querySelectorAll('#modal [data-printed]').forEach(b => b.onclick = () => run(() => api('POST', '/print-jobs/' + b.dataset.printed + '/printed', {}), 'Marked printed.'));
            document.querySelectorAll('#modal [data-retry]').forEach(b => b.onclick = () => run(() => api('POST', '/print-jobs/' + b.dataset.retry + '/retry', {}), r => 'Re-queued: ' + (r.job_no || '')));
            document.querySelectorAll('#modal [data-dismiss]').forEach(b => b.onclick = () => { if (window.confirm('Dismiss this print job? It will not print and nothing is counted.')) run(() => api('POST', '/print-jobs/' + b.dataset.dismiss + '/dismiss', {}), 'Print job dismissed.'); });
            document.querySelectorAll('#modal [data-reminder-reprint]').forEach(b => b.onclick = () => run(() => api('POST', '/print-jobs/' + b.dataset.reminderReprint + '/reminder-reprint', {}), r => 'Reminder Duplicate ' + (r.copy_no || '') + ' queued'));
            document.querySelectorAll('#modal [data-reprint]').forEach(b => b.onclick = () => run(async () => {
                if (b.dataset.kind === 'kot') { const k = await api('POST', '/sales/' + b.dataset.reprint + '/kot', { reprint: true }); handlePrintJobs(k.jobs, 'KOT'); return k; }
                const j = await api('POST', '/sales/' + b.dataset.reprint + '/receipt', { reprint: true }); handlePrintJobs([j], 'receipt'); return j;
            }, b.dataset.kind === 'kot' ? 'KOT re-queued' : 'Receipt re-queued'));
            document.querySelectorAll('#modal [data-last-print]').forEach(a => a.onclick = e => { e.preventDefault(); openLastPrint(Number(a.dataset.lastPrint), a.dataset.saleNo); });
        }
        async function recentPrints() {
            try {
                const r = await api('GET', '/print-jobs');
                let html = '<h2>Recent Prints</h2>';
                html += r.jobs.length ? printJobRowsHtml(r.jobs, false) : '<p class="muted">Nothing printed yet.</p>';
                html += '<div class="btn-row">' + (lastPrintSale.id ? '<button class="ghost" id="last-print-open-btn">Last sale ' + esc(lastPrintSale.no || ('#' + lastPrintSale.id)) + '</button>' : '') +
                    '<button class="ghost" onclick="EdgePOS.closeModal()">Close</button></div>';
                openModal(html);
                if ($('last-print-open-btn')) $('last-print-open-btn').onclick = () => openLastPrint(lastPrintSale.id, lastPrintSale.no);
                wirePrintJobRows(r.jobs, recentPrints);
            } catch (e) { toast(e.message); }
        }
        // Online openRecentPrints for ONE sale (Completed Orders / Last Print call this): every job of the sale + Reprint All KOT / Reprint Receipt.
        async function openLastPrint(saleId, saleNo) {
            if (!saleId) { toast('No recent sale to reprint'); return; }
            try {
                const r = await api('GET', '/print-jobs?sale_id=' + Number(saleId));
                const no = (r.sale && r.sale.sale_no) || saleNo || ('#' + saleId);
                rememberLastSale(saleId, no);
                let html = '<div id="lastPrintModal"><h2 id="lastPrintModalLabel">Recent Prints</h2><p class="muted" style="margin-top:-.5rem">Sale: <strong id="last-print-sale-no">' + esc(no) + '</strong></p>' +
                    '<div id="last-print-modal-body">' + (r.jobs.length ? printJobRowsHtml(r.jobs, true) : '<p class="muted">No print jobs found for this order.</p>') + '</div>' +
                    '<div class="btn-row" style="justify-content:space-between"><div style="display:flex;gap:.5rem;flex-wrap:wrap">' +
                    '<button class="warn" id="reprint-all-kot-btn">Reprint All KOT</button><button class="primary" id="reprint-receipt-btn">Reprint Receipt</button></div>' +
                    '<button class="ghost" onclick="EdgePOS.closeModal()">Close</button></div></div>';
                openModal(html);
                const reload = () => openLastPrint(saleId, no);
                $('reprint-all-kot-btn').onclick = async () => { try { const k = await api('POST', '/sales/' + saleId + '/kot', { reprint: true }); if (!k.jobs.length) toast(k.message || 'Nothing to reprint'); else { toast('KOT re-queued'); handlePrintJobs(k.jobs, 'KOT'); } } catch (e) { toast(e.message); } if (!$('print-here-frame')) reload(); };
                $('reprint-receipt-btn').onclick = async () => { try { const j = await api('POST', '/sales/' + saleId + '/receipt', { reprint: true }); toast('Receipt re-queued → ' + j.printer_name); handlePrintJobs([j], 'receipt'); } catch (e) { toast(e.message); } if (!$('print-here-frame')) reload(); };
                wirePrintJobRows(r.jobs, reload);
            } catch (e) { toast(e.message); }
        }
        // KOT REMINDER entry: the sale's Reminder slips (Rev n, Duplicate n) with Reprint — Reminders follow every accepted KOT round.
        async function openKotReminder(saleId) {
            if (!saleId) { toast('Open or select an order first.'); return; }
            try {
                const r = await api('GET', '/print-jobs?sale_id=' + Number(saleId));
                if (!r.jobs.some(j => j.document_type === 'reminder')) { toast('No Reminder has printed for this order yet — a Reminder follows an accepted KOT round when a Reminder printer is mapped.'); return; }
                openLastPrint(saleId, r.sale ? r.sale.sale_no : null);
            } catch (e) { toast(e.message); }
        }

        // ---- Bill preview document (Online #billPreviewModal footer: Send to network / Print here). ----
        // payload: { sale_id } for a saved check, or the /preview-bill body for the current cart; omitted → built from the page state.
        // Also accepts the Preview Bill modal's call shape: { target: 'here' | 'network', held_sale_id, totals, lines, ...quote payload }.
        // saleIds: one saved order, or a TABLE bill's held ids (canonical BILL-PREVIEW-WRONG-PRINT-1: the print target is the held ids).
        async function sendBillToNetwork(saleIds) {
            const ids = (Array.isArray(saleIds) ? saleIds : [saleIds]).map(Number).filter(Boolean);
            // Online WRONG-BILL rule: only a SAVED order can go to the network printer — never a previous sale.
            if (!ids.length) { toast('Hold or pay the order first — only a saved order can be sent to the network printer.'); return; }
            const sent = [], fallback = [];
            for (const id of ids) {
                try { const j = await api('POST', '/sales/' + id + '/receipt', { reprint: true }); (j.fallback ? fallback : sent).push(j); }
                catch (e) { toast('Could not send to the network printer: ' + e.message); return; }
            }
            if (fallback.length) { toast('No network receipt printer is mapped — opening a browser preview instead.'); openPrintHere(fallback[0]); }
            if (sent.length) toast('Bill' + (sent.length > 1 ? 's (' + sent.length + ')' : '') + ' sent to the network printer → ' + sent[0].printer_name);
        }
        // TABLE payload (Team 3 R14): { restaurant_table_session_id | table_session_id, held_sale_ids[], target? } — the table bill document
        // comes from the table workspace's own bill-preview endpoint (Online RestaurantTableSessionController bill preview; its permission applies).
        async function printTableBillPreview(payload) {
            const sessionId = Number(payload.restaurant_table_session_id || payload.table_session_id);
            try {
                const r = await api('GET', '/restaurant/table-sessions/' + sessionId + '/bill-preview');
                const heldIds = (payload.held_sale_ids && payload.held_sale_ids.length ? payload.held_sale_ids : (r.held_sale_ids || [])).map(Number);
                if (payload.target === 'network') { await sendBillToNetwork(heldIds); return; }
                showBillPreviewFrame(r.html, 'Table Bill Preview' + (r.session && r.session.session_no ? ' — ' + esc(r.session.session_no) : ''), heldIds, payload.target);
            } catch (e) { toast(e.message || 'Unable to load the table bill.'); }
        }
        function showBillPreviewFrame(html, title, networkIds, target) {
            openModal('<h2 id="billPreviewModalLabel">' + title + '</h2>' +
                '<div id="bill-preview-modal-body"><iframe id="bill-preview-frame" title="Bill preview" style="width:100%;min-height:60vh;border:0;background:#fff"></iframe></div>' +
                '<div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Close</button>' +
                '<button class="ghost" id="send-network-receipt-btn" title="Send this order\'s bill to the counter\'s network receipt printer.">Send to network</button>' +
                '<button class="primary" id="print-bill-preview-btn" title="Open the print dialog for the printer attached to this screen.">Print here</button></div>');
            const frame = $('bill-preview-frame');
            const doPrint = () => { try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch (e) { toast('The print dialog was blocked.'); } };
            frame.srcdoc = html;
            $('print-bill-preview-btn').onclick = doPrint;
            $('send-network-receipt-btn').onclick = () => sendBillToNetwork(networkIds);
            if (target === 'here') frame.addEventListener('load', () => setTimeout(doPrint, 300), { once: true });
        }
        async function printBillPreview(payload) {
            if (!payload) payload = state.held ? { sale_id: state.held.id } : Object.assign({ order_type: state.orderType, lines: cartLines() }, commercial());
            if ((payload.restaurant_table_session_id || payload.table_session_id) && Array.isArray(payload.held_sale_ids)) { await printTableBillPreview(payload); return; }
            const target = payload.target || null;
            const saleRef = payload.sale_id || payload.held_sale_id || null;
            // A saved check whose screen matches its row prints from the row; an edited / unsaved cart prints from the cart.
            const cartLinesIn = (payload.lines || []).filter(l => l && (l.product_id || l.combo_id) && !('line_total' in l));
            const body = cartLinesIn.length && !(saleRef && !state.dirty)
                ? Object.assign({}, payload, { lines: cartLinesIn, sale_id: undefined, held_sale_id: undefined, target: undefined, totals: undefined })
                : (saleRef ? { sale_id: saleRef } : null);
            if (target === 'network') { await sendBillToNetwork(saleRef); return; }
            if (!body) { toast('Add at least one item'); return; }
            try {
                const r = await api('POST', '/bill-preview/document', body);
                showBillPreviewFrame(r.html, 'Bill Preview' + (r.sale_no ? ' — ' + esc(r.sale_no) : ''), [r.sale_id || saleRef], target);
            } catch (e) { toast(e.message || 'Unable to build the bill preview.'); }
        }

        loadPrintPrefs();
