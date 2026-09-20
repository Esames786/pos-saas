{{-- W0 js/core (Team 1 owns): shared runtime — bootstrap DATA, CSRF, BASE, state, api(), toast(), modal shell, helpers.
     Every js/* fragment is concatenated INSIDE one IIFE by edge/pos/index.blade.php, so declarations here are visible to all. --}}
        const DATA = JSON.parse(document.getElementById('edge-pos-data').textContent);
        const CSRF = document.querySelector('meta[name=csrf-token]').content;
        const BASE = '{{ url('/edge/local/pos') }}';
        const money = n => (Math.round((Number(n) || 0) * 100) / 100).toFixed(2);
        const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

        // ---- state: a plain cart, OR a table session with no check yet, OR a loaded (recalled) open check. ----
        const state = { orderType: DATA.defaultOrderType, terminalId: null, category: null, cart: [], dirty: false,
                        session: null,   // {id, table_id, table_no, waiter_name}
                        held: null,      // {id, sale_no, sale_uuid, is_draft, order_type, session_id, table_no, waiter_name, terminal_id, totals...}
                        customer: null,  // {id, name, phone, addresses[]} picked from the synced book
                        pendingClientUuid: null,
                        // Commercial intent — the same fields the Online Review & Pay carries (discount, promo, delivery).
                        commercial: { discount_type: 'none', discount_value: 0, promo_code: '', manager_approval_id: null,
                                      delivery_channel_id: null, delivery_rider_id: null, delivery_address: '', delivery_charge_amount: DATA.defaultDeliveryCharge } };

        // ---- API helper: all mutations go to the Edge-local POS endpoints only. ----
        async function api(method, path, body) {
            const opt = { method, headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' } };
            if (body !== undefined) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
            let res;
            try { res = await fetch(BASE + path, opt); }
            catch (e) { document.getElementById('offline-banner').hidden = false; throw new Error('The branch server did not answer — check the LAN connection.'); }
            document.getElementById('offline-banner').hidden = true;
            const json = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(json.message || (json.errors ? Object.values(json.errors).flat().join(' ') : 'Request failed (' + res.status + ')'));
            return json;
        }
        function toast(msg) { const t = document.getElementById('toast'); t.textContent = msg; t.style.display = 'block'; setTimeout(() => t.style.display = 'none', 3200); }
        function uuid() { return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16); }); }
        function openModal(html) { document.getElementById('modal-body').innerHTML = html; document.getElementById('modal').classList.add('open'); }
        function closeModal() { document.getElementById('modal').classList.remove('open'); }
        document.getElementById('modal').addEventListener('click', e => { if (e.target.id === 'modal') closeModal(); });
        const $ = id => document.getElementById(id);
