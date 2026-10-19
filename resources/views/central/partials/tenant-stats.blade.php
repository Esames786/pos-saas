{{-- MASTER-UNIVERSE-1: one tenant's numbers (dashboard card + hub overview). "—" always says why. --}}
@php
    $fmtBytes = fn ($b) => \App\Services\Central\SystemUsageService::bytes($b);
    $why = $c['read_error'] ?? 'Not measured yet.';
    $whyOf = fn (string $k) => isset($c['skipped'][$k]) ? 'Could not be read: ' . $c['skipped'][$k] : $why;
    $backup = $c['backup'];
    $backupOld = $backup && $backup['at']->lt(now()->subDays(\App\Services\Central\MasterOverviewService::BACKUP_STALE_DAYS));
    $due = $c['due'];
@endphp
<div class="mu-stats">
    <div class="mu-stat">
        <div class="mu-stat-label">Database</div>
        <div class="mu-stat-value">
            @if($c['db_bytes'] !== null) {{ $fmtBytes($c['db_bytes']) }} @else <span class="mu-unknown" title="{{ $whyOf('db_bytes') }}">—</span> @endif
        </div>
    </div>
    <div class="mu-stat">
        <div class="mu-stat-label">Files</div>
        <div class="mu-stat-value">
            @if($c['files_bytes'] !== null) {{ $fmtBytes($c['files_bytes']) }} @else <span class="mu-unknown" title="{{ $whyOf('files_bytes') }}">—</span> @endif
        </div>
    </div>
    <div class="mu-stat">
        <div class="mu-stat-label">Last backup</div>
        <div class="mu-stat-value {{ ! $backup ? 'is-bad' : ($backupOld ? 'is-warn' : 'is-good') }}" title="{{ $backup ? \App\Services\Central\MasterOverviewService::pkt($backup['at']) . ' (' . $backup['kind'] . ')' : 'No backup found for this tenant.' }}">
            {{ $backup ? $backup['at']->diffForHumans(null, true) . ' ago' : 'Never' }}
        </div>
    </div>
    <div class="mu-stat">
        <div class="mu-stat-label">Invoices due</div>
        <div class="mu-stat-value {{ $due ? (($due['overdue'] ?? 0) > 0 ? 'is-bad' : 'is-warn') : 'is-good' }}"
             title="{{ $due ? $due['count'] . ' unpaid invoice(s)' . (($due['overdue'] ?? 0) > 0 ? ', ' . $due['overdue'] . ' overdue' : '') : 'Nothing unpaid.' }}">
            {{ $due ? $due['currency'] . ' ' . number_format($due['amount']) : 'Nothing due' }}
        </div>
    </div>
    <div class="mu-stat">
        <div class="mu-stat-label">Sales · 30 days</div>
        <div class="mu-stat-value">
            @if($c['sales_30d'] !== null)
                <span title="{{ number_format($c['sales_30d']) }} paid POS bills{{ $c['sales_amount'] !== null ? ' worth ' . number_format($c['sales_amount']) : '' }}">{{ number_format($c['sales_30d']) }} bills</span>
                @if(! $c['sales_30d'] && ($c['events_30d'] ?? 0) > 0)
                    <span class="mu-note">· {{ $c['events_30d'] }} events</span>
                @endif
            @else
                <span class="mu-unknown" title="{{ $whyOf('sales_30d') }}">—</span>
            @endif
        </div>
    </div>
    <div class="mu-stat">
        <div class="mu-stat-label">Activity share</div>
        <div class="mu-stat-value">
            @if($c['share'] !== null)
                <span title="Estimate: share of all paid POS bills on the server in the last 30 days">{{ $c['share'] }}% <span class="mu-note">est.</span></span>
            @else
                <span class="mu-unknown" title="{{ $c['sales_30d'] === null ? $whyOf('sales_30d') : 'No POS bills on the server in 30 days to compare.' }}">—</span>
            @endif
        </div>
    </div>
</div>
@if($c['read_at'] && $c['read_error'])
    <div class="mu-note"><i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ $c['read_error'] }}</div>
@endif
