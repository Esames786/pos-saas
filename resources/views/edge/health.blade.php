{{--
  OFFLINE EDGE — P4 §11: the ONE operator/admin health page on the Branch Server.

  Same report as `php artisan edge:local:health` (EdgeApplianceHealthService). The cashier reads the headline
  (Ready as warm standby / Local Mode active / issues); the supervisor reads the sections: services, Cloud
  connectivity, authority state, binding, warm-standby freshness, sync outbox + permanent failures, print worker
  and printers, backup recency, updater/version, gateway certificate. NON-SECRET: no password, secret, key, token,
  hash or raw payload is ever rendered. Server-rendered, no scripts (works with NO Internet); refreshes itself.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="30">
    <title>Bingoo Edge — Status</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%23CAA23F'/%3E%3Cpath d='M18 14h17c8 0 13 4 13 10 0 4-2 7-6 8 5 1 8 5 8 10 0 7-6 12-15 12H18z' fill='%23fff'/%3E%3C/svg%3E">{{-- W1: inline icon — no /favicon.ico 404 on the appliance --}}
    <style>
        :root { --bg:#0f172a; --panel:#1e293b; --panel2:#172033; --line:#334155; --ink:#e2e8f0; --muted:#94a3b8; --accent:#4f46e5; --ok:#16a34a; --warn:#d97706; --danger:#dc2626; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:system-ui,Segoe UI,sans-serif; background:var(--bg); color:var(--ink); min-height:100vh; display:flex; flex-direction:column; }
        header { background:var(--panel); border-bottom:1px solid var(--line); padding:.5rem .9rem; display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; }
        header h1 { font-size:1.05rem; margin:0; }
        header .who { color:var(--muted); font-size:.8rem; }
        header .spacer { flex:1; }
        a.navbtn, button { font:inherit; color:var(--ink); cursor:pointer; border:1px solid var(--line); background:transparent; border-radius:8px; padding:.5rem .8rem; text-decoration:none; }
        main { padding:1rem; display:grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap:1rem; }
        .headline { grid-column: 1 / -1; background:var(--panel); border:1px solid var(--line); border-radius:12px; padding:1rem 1.2rem; display:flex; align-items:center; gap:1rem; flex-wrap:wrap; }
        .headline .big { font-size:1.4rem; font-weight:600; }
        .pill { display:inline-block; border-radius:999px; padding:.3rem .8rem; font-weight:600; font-size:.85rem; border:1px solid var(--line); }
        .pill.STANDBY_READY { background:rgba(22,163,74,.2); border-color:var(--ok); color:#86efac; }
        .pill.LOCAL_ACTIVE { background:rgba(79,70,229,.25); border-color:var(--accent); color:#c7d2fe; }
        .pill.TRANSITION { background:rgba(217,119,6,.2); border-color:var(--warn); color:#fcd34d; }
        .pill.DEGRADED, .pill.NOT_BOUND { background:rgba(220,38,38,.2); border-color:var(--danger); color:#fca5a5; }
        .card { background:var(--panel); border:1px solid var(--line); border-radius:12px; padding:.9rem 1rem; }
        .card h2 { margin:0 0 .6rem; font-size:.95rem; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
        dl { display:grid; grid-template-columns: max-content 1fr; gap:.3rem .8rem; margin:0; font-size:.88rem; }
        dt { color:var(--muted); }
        dd { margin:0; word-break:break-word; }
        .ok { color:#86efac; } .warn { color:#fcd34d; } .bad { color:#fca5a5; }
        ul.problems { margin:.4rem 0 0; padding-left:1.2rem; }
        ul.problems li { color:#fcd34d; margin:.15rem 0; }
        table { width:100%; border-collapse:collapse; font-size:.85rem; }
        th, td { padding:.35rem .45rem; border-bottom:1px solid var(--line); text-align:left; }
        th { color:var(--muted); font-weight:500; font-size:.72rem; text-transform:uppercase; }
        .muted { color:var(--muted); font-size:.8rem; }
    </style>
</head>
<body>
<header>
    <h1>Branch Server status</h1>
    <span class="who">Branch #{{ $branchId }} · {{ $userName }}</span>
    <span class="spacer"></span>
    <a class="navbtn" id="pos-link" href="{{ url('/edge/local/pos') }}">Back to POS</a>
    <form method="POST" action="{{ url('/edge/local/logout') }}" style="margin:0">@csrf<button>Logout</button></form>
</header>
<main>
    <section class="headline">
        <span class="pill {{ $report['status'] }}" id="status-pill">{{ str_replace('_', ' ', $report['status']) }}</span>
        <span class="big">{{ $report['status_label'] }}</span>
        <span class="muted">as of {{ $report['generated_at'] }} · auto-refresh 30s · automatic failover: off (supervised takeover only)</span>
        @if($report['problems'] !== [])
            <ul class="problems" id="problems">
                @foreach($report['problems'] as $p)
                    <li>{{ $p }}</li>
                @endforeach
            </ul>
        @else
            <span class="ok">No problems reported.</span>
        @endif
    </section>

    <section class="card">
        <h2>Cloud connection &amp; authority</h2>
        <dl>
            <dt>Authority</dt><dd>{{ $report['authority']['state'] ?? '—' }} @if(!empty($report['authority']['state_reason']))<span class="muted">· {{ $report['authority']['state_reason'] }}</span>@endif</dd>
            <dt>Connection</dt><dd class="{{ in_array($report['authority']['connection_state'] ?? '', ['online','connection_restored'], true) ? 'ok' : 'warn' }}">{{ $report['authority']['connection_state'] ?? '—' }} @if(!empty($report['authority']['connection_state_since']))<span class="muted">since {{ $report['authority']['connection_state_since'] }}</span>@endif</dd>
            <dt>Last Cloud acknowledgement</dt><dd>{{ $report['authority']['last_heartbeat_ack_at'] ?? 'never' }} @if(isset($report['authority']['last_heartbeat_ack_age_seconds']))<span class="muted">({{ $report['authority']['last_heartbeat_ack_age_seconds'] }}s ago)</span>@endif</dd>
            <dt>Heartbeat failures / acks</dt><dd>{{ $report['authority']['consecutive_failures'] ?? 0 }} / {{ $report['authority']['consecutive_acks'] ?? 0 }}</dd>
            @if(!empty($report['authority']['handback_blockers']))
                <dt>Handback blocked by</dt><dd class="warn">{{ implode(', ', $report['authority']['handback_blockers']) }}</dd>
            @endif
        </dl>
        @if(!empty($report['authority']['gates']) && !isset($report['authority']['gates']['error']))
            <table style="margin-top:.6rem">
                <thead><tr><th>Takeover gate</th><th>State</th></tr></thead>
                <tbody>
                @foreach($report['authority']['gates'] as $gate => $ok)
                    <tr><td>{{ $gate }}</td><td class="{{ $ok ? 'ok' : 'bad' }}">{{ $ok ? 'pass' : 'blocked' }}</td></tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="card">
        <h2>Binding &amp; runtime</h2>
        <dl>
            <dt>Bound</dt><dd class="{{ ($report['binding']['bound'] ?? false) ? 'ok' : 'bad' }}">{{ ($report['binding']['bound'] ?? false) ? 'yes' : 'no' }}</dd>
            <dt>Tenant / branch</dt><dd>{{ $report['binding']['tenant_code'] ?? '—' }} · #{{ $report['binding']['branch_id'] ?? '—' }}</dd>
            <dt>Device</dt><dd>{{ $report['binding']['device_uuid'] ?? '—' }} <span class="{{ ($report['binding']['device_identity_matches'] ?? false) ? 'ok' : 'bad' }}">({{ ($report['binding']['device_identity_configured'] ?? false) ? 'identity configured' : 'identity missing' }})</span></dd>
            <dt>Activation epoch</dt><dd>{{ $report['binding']['activation_epoch'] ?? '—' }}</dd>
            <dt>Enrolled local users</dt><dd>{{ $report['binding']['enrolled_local_users'] ?? 0 }}</dd>
            <dt>Schema</dt><dd class="{{ ($report['binding']['schema_compatible'] ?? false) ? 'ok' : 'bad' }}">{{ $report['binding']['bootstrap_schema'] ?? '—' }} / {{ $report['binding']['config_schema_version'] ?? '—' }}</dd>
            <dt>Edge version</dt><dd>{{ $report['runtime']['edge_app_version'] }} · PHP {{ $report['runtime']['php_version'] }} · {{ $report['runtime']['packaged_artifact'] ? 'packaged artifact' : 'development tree' }}</dd>
            <dt>Active runtime</dt><dd>{{ $report['update']['active_version_pointer'] ?? '—' }} · update key {{ $report['update']['public_key_configured'] ? 'ok' : 'missing' }}</dd>
            <dt>Local database</dt><dd class="{{ $report['database']['reachable'] ? 'ok' : 'bad' }}">{{ $report['database']['reachable'] ? 'reachable' : 'unreachable' }} · {{ $report['database']['database'] ?? '' }} @ {{ $report['database']['host'] ?? '' }}</dd>
        </dl>
    </section>

    <section class="card">
        <h2>Warm-standby freshness</h2>
        <table>
            <thead><tr><th>Cache</th><th>State</th><th>Refreshed</th></tr></thead>
            <tbody>
            @foreach(['config' => 'Configuration', 'stock' => 'Official stock', 'returnable' => 'Returnable sales', 'supplier_finance' => 'Supplier finance', 'purchase_return' => 'Purchase returns'] as $key => $label)
                @php($f = $report['freshness'][$key] ?? null)
                <tr>
                    <td>{{ $label }}</td>
                    <td class="{{ ($f['ok'] ?? false) ? 'ok' : 'warn' }}">{{ $f === null ? '—' : (($f['ok'] ?? false) ? 'current' : 'stale') }}@if(!empty($f['reasons']))<div class="muted">{{ implode('; ', $f['reasons']) }}</div>@endif</td>
                    <td class="muted">{{ $f['refreshed_at'] ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>

    <section class="card">
        <h2>Sync to Cloud</h2>
        <dl>
            <dt>Pending</dt><dd class="{{ ($report['sync']['outbox_pending'] ?? 0) > 0 ? 'warn' : 'ok' }}">{{ $report['sync']['outbox_pending'] ?? '—' }}</dd>
            <dt>In flight</dt><dd>{{ $report['sync']['outbox_leased'] ?? '—' }}</dd>
            <dt>Permanently refused</dt><dd class="{{ ($report['sync']['outbox_failed_permanent'] ?? 0) > 0 ? 'bad' : 'ok' }}">{{ $report['sync']['outbox_failed_permanent'] ?? '—' }}</dd>
            <dt>Acknowledged</dt><dd>{{ $report['sync']['outbox_acknowledged'] ?? '—' }}</dd>
            <dt>Last acknowledged</dt><dd>{{ $report['sync']['last_acknowledged_at'] ?? 'never' }}</dd>
            <dt>Last clean reconciliation</dt><dd>{{ $report['sync']['reconcile_clean_at'] ?? 'never' }}</dd>
        </dl>
        @if(!empty($report['sync']['permanent_failures']))
            <table style="margin-top:.6rem">
                <thead><tr><th>Event</th><th>Family</th><th>Reason</th></tr></thead>
                <tbody>
                @foreach($report['sync']['permanent_failures'] as $pf)
                    <tr><td>{{ $pf['uuid'] }}</td><td>{{ $pf['family'] }}</td><td class="bad">{{ $pf['error'] }}</td></tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="card">
        <h2>Services</h2>
        <table>
            <thead><tr><th>Service</th><th>State</th></tr></thead>
            <tbody>
            <tr><td>Print worker</td><td class="{{ ($report['workers']['print_worker']['state'] ?? '') === 'running' ? 'ok' : 'warn' }}">{{ $report['workers']['print_worker']['state'] ?? '—' }}</td></tr>
            <tr><td>Authority / heartbeat worker</td><td class="{{ ($report['workers']['authority_worker']['running'] ?? false) ? 'ok' : 'warn' }}">{{ ($report['workers']['authority_worker']['running'] ?? false) ? 'running' : 'not running' }}</td></tr>
            @foreach($report['workers']['web_backends'] ?? [] as $w)
                <tr><td>Web backend #{{ $w['worker'] }} ({{ $w['listen'] }})</td><td class="{{ $w['listening'] ? 'ok' : 'warn' }}">{{ $w['listening'] ? 'listening' : 'not listening' }}</td></tr>
            @endforeach
            <tr><td>TLS gateway :{{ $report['gateway']['https_port'] }}</td><td class="{{ $report['gateway']['https_listening'] ? 'ok' : 'warn' }}">{{ $report['gateway']['https_listening'] ? 'listening' : 'not listening' }} · certificate {{ $report['gateway']['certificate_present'] ? 'present' : 'missing' }}@if(isset($report['gateway']['certificate']['days_left'])) ({{ $report['gateway']['certificate']['days_left'] }} days left)@endif</td></tr>
            </tbody>
        </table>
    </section>

    <section class="card">
        <h2>Printing</h2>
        <p class="muted" style="margin:.2rem 0 .6rem">Network printers print directly from this Branch Server in Local Mode. USB printers need the Online POS.</p>
        <table>
            <thead><tr><th>Printer</th><th>Type</th><th>Role</th><th>Offline</th></tr></thead>
            <tbody>
            @forelse($report['print']['printers'] ?? [] as $p)
                <tr><td>{{ $p['name'] }}</td><td>{{ $p['type'] }}@if($p['ip']) <span class="muted">{{ $p['ip'] }}:{{ $p['port'] ?? 9100 }}</span>@endif</td><td>{{ $p['role'] }}</td><td class="{{ $p['edge_capable'] ? 'ok' : 'warn' }}">{{ $p['edge_capable'] ? 'yes' : 'Online only' }}</td></tr>
            @empty
                <tr><td colspan="4" class="muted">No printers configured for this branch.</td></tr>
            @endforelse
            </tbody>
        </table>
        @if(!empty($report['print']['jobs_by_status']))
            <p class="muted" style="margin:.6rem 0 0">Print jobs: @foreach($report['print']['jobs_by_status'] as $st => $n){{ $st }} {{ $n }}@if(!$loop->last) · @endif @endforeach</p>
        @endif
    </section>

    <section class="card">
        <h2>Backup &amp; recovery</h2>
        <dl>
            <dt>Recovery key</dt><dd class="{{ $report['backup']['recovery_key_configured'] ? 'ok' : 'bad' }}">{{ $report['backup']['recovery_key_configured'] ? 'configured (' . $report['backup']['recovery_key_id'] . ')' : 'missing' }}</dd>
            <dt>Last backup</dt><dd>{{ $report['backup']['last']['created_at'] ?? 'never' }}@if(isset($report['backup']['last']['age_seconds'])) <span class="muted">({{ (int) floor($report['backup']['last']['age_seconds'] / 60) }} min ago)</span>@endif</dd>
            <dt>Location</dt><dd class="muted">{{ $report['backup']['path'] }}</dd>
            <dt>Retention</dt><dd>{{ $report['backup']['retention'] }} copies</dd>
        </dl>
    </section>
</main>
</body>
</html>
