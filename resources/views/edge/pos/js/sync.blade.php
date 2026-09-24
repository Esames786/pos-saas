{{-- W0 js/sync (Team 1 owns from W1; labels are the Q state machine's — never change them without the state-machine owner): sync chip + offline banner. --}}
        // ---- Sync state chip: business-friendly only (never leases / hashes / epochs on the till). ----
        async function refreshSync() {
            try {
                const s = await api('GET', '/sync/summary', undefined, { quiet: true });
                const c = $('sync-chip'); c.hidden = false;
                // Q — the business connection state leads (ONLINE / INTERNET CONNECTION UNSTABLE / INTERNET CONNECTION
                // LOST / PREPARING LOCAL MODE / LOCAL MODE ACTIVE / CONNECTION RESTORED / SYNCHRONIZING / RETURNING TO
                // ONLINE); the sync depth follows. Never a timestamp, uuid, hash or epoch on the till.
                const conn = (s.connection || 'ONLINE');
                const sync = s.state === 'up_to_date' ? 'Synced' : (s.state === 'pending' ? 'Pending sync: ' + s.pending_sales : 'Sync needs attention');
                c.textContent = conn === 'ONLINE' ? sync : (conn + ' · ' + sync);
                c.dataset.connection = conn;
                // W1 severity colours (Online badge language): green synced, amber pending / connection change, red attention / lost.
                const lost = conn === 'INTERNET CONNECTION LOST' || conn === 'PREPARING LOCAL MODE' || conn === 'LOCAL MODE ACTIVE';
                c.className = 'chip ' + (s.state === 'attention' || (s.state !== 'up_to_date' && s.state !== 'pending') ? 'bad' : (lost ? 'bad' : (conn !== 'ONLINE' || s.state === 'pending' ? 'hot' : 'ok')));
                c.title = s.message;
                document.getElementById('offline-banner').hidden = !lost;
            } catch (e) { /* the chip is informational; a failed poll never blocks selling */ }
        }
