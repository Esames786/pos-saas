{{-- W0 js/printing (Team 5 owns from W5): receipt after payment (ensure-once), Print Here fallback, Recent Prints, Reprint, Retry. --}}
        // ---- Printing: receipt after payment (ensure-once), Recent Prints, Reprint, Print Here fallback, Retry. ----
        async function autoReceipt(saleId) {
            try {
                const job = await api('POST', '/sales/' + saleId + '/receipt', {});
                if (job.fallback) { printHere(job); }
                else { toast('Receipt → ' + job.printer_name); }
            } catch (e) { toast('Receipt not queued: ' + e.message); }
        }
        function printHere(job) {
            // Print Here / local fallback: the canonical document opens in a print window; the operator confirms it printed.
            const w = window.open(job.preview_url, '_blank', 'width=420,height=640');
            if (!w) { toast('Allow pop-ups to print here — or open Recent Prints.'); return; }
            toast((job.document_type === 'kot' ? 'KOT' : 'Receipt') + ' opened for printing here.');
        }
        async function recentPrints() {
            try {
                const r = await api('GET', '/print-jobs');
                let html = '<h2>Recent Prints</h2>';
                if (!r.jobs.length) html += '<p class="muted">Nothing printed yet.</p>';
                r.jobs.forEach(j => {
                    const kind = (j.document_type || '').toUpperCase() + (j.event_type && j.event_type !== 'normal' ? ' · ' + j.event_type : '');
                    html += '<div class="list-row" style="cursor:default"><div><strong>' + esc(kind) + '</strong> ' + esc(j.reference_no || '') +
                        '<div class="muted">' + esc(j.printer_name) + ' · ' + esc(j.print_status) + ' · ' + esc(new Date(j.created_at).toLocaleTimeString()) + '</div></div>' +
                        '<div style="display:flex;gap:.3rem;flex-wrap:wrap;justify-content:flex-end">' +
                        '<button class="sm" data-open="' + j.id + '">Print here</button>' +
                        (j.fallback && j.print_status !== 'printed' ? '<button class="sm ok" data-printed="' + j.id + '">Printed</button>' : '') +
                        (j.reference_id && (j.document_type === 'receipt' || j.document_type === 'kot') ? '<button class="sm" data-reprint="' + j.reference_id + '" data-kind="' + j.document_type + '">Reprint</button>' : '') +
                        (j.print_status === 'failed' ? '<button class="sm warn" data-retry="' + j.id + '">Retry</button>' : '') +
                        '</div></div>';
                });
                html += '<div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Close</button></div>';
                openModal(html);
                const byJob = id => r.jobs.find(x => x.id === Number(id));
                document.querySelectorAll('#modal [data-open]').forEach(b => b.onclick = () => printHere(byJob(b.dataset.open)));
                document.querySelectorAll('#modal [data-printed]').forEach(b => b.onclick = async () => { try { await api('POST', '/print-jobs/' + b.dataset.printed + '/printed', {}); toast('Marked printed.'); recentPrints(); } catch (e) { toast(e.message); } });
                document.querySelectorAll('#modal [data-retry]').forEach(b => b.onclick = async () => { try { await api('POST', '/print-jobs/' + b.dataset.retry + '/retry', {}); toast('Queued for retry.'); recentPrints(); } catch (e) { toast(e.message); } });
                document.querySelectorAll('#modal [data-reprint]').forEach(b => b.onclick = async () => {
                    try {
                        if (b.dataset.kind === 'kot') { const k = await api('POST', '/sales/' + b.dataset.reprint + '/kot-reprint', {}); toast('KOT reprint → ' + (k.jobs[0]?.printer_name || 'queued')); if (k.jobs[0]?.fallback) printHere(k.jobs[0]); }
                        else { const j = await api('POST', '/sales/' + b.dataset.reprint + '/receipt', { reprint: true }); toast('Receipt reprint → ' + j.printer_name); if (j.fallback) printHere(j); }
                        recentPrints();
                    } catch (e) { toast(e.message); }
                });
            } catch (e) { toast(e.message); }
        }
