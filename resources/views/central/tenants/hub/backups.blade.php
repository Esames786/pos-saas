{{-- MASTER-UNIVERSE-1 — Backups in plain words: last backup, "Backup now", the automatic schedule and
     how long they are kept. Restore and delete stay on the full backups page (one click away). --}}
@php
    $last = $card['backup'];
    $slots = $backupSetting->times ?? [];
    $nightlyOn = (bool) config('backup.schedule_enabled', false);
@endphp
<div class="row g-3">
    <div class="col-xl-4">
        <section class="mu-panel">
            <h2>Last backup</h2>
            @if($last)
                <p class="mb-1" style="font-size:20px;font-weight:800;color:var(--mu-text)">{{ $last['at']->diffForHumans() }}</p>
                <p class="mu-note mb-3">{{ \App\Services\Central\MasterOverviewService::pkt($last['at']) }} · {{ $last['kind'] }}</p>
            @else
                <p class="mb-1 text-danger" style="font-size:20px;font-weight:800">Never</p>
                <p class="mu-note mb-3">No backup of this tenant was found.</p>
            @endif
            @can('central.tenants.backup')
                <form method="POST" action="{{ route('central.tenants.backup', $tenant) }}">@csrf
                    <button class="btn btn-primary btn-lg w-100"><i class="ti ti-database-export me-1" aria-hidden="true"></i>Backup now</button>
                </form>
                <p class="mu-note mt-2 mb-0">Safe: it only copies the database to a file. Nothing in the tenant changes.</p>
            @endcan
        </section>
        <section class="mu-panel">
            <h2>Whole-server backup</h2>
            @if($nightlyOn)
                <p class="mu-panel-sub mb-0">Every night at 02:00 the whole server (all tenants) is also backed up and kept {{ (int) config('backup.retention_days', 14) }} days.</p>
            @else
                <p class="mu-panel-sub mb-0">The nightly whole-server backup is <strong>off</strong> on this machine. Only this tenant's own backups (below) are taken.</p>
            @endif
        </section>
    </div>

    <div class="col-xl-8">
        @can('central.tenants.backup-settings.save')
        <section class="mu-panel">
            <form method="POST" action="{{ route('central.tenants.backup-settings.save', $tenant) }}">@csrf
                <input type="hidden" name="return" value="hub">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                    <h2 class="mb-0"><i class="ti ti-clock-play me-1" aria-hidden="true"></i>Automatic backups</h2>
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="hubAutoEnabled" name="is_enabled" value="1" {{ $backupSetting->is_enabled ? 'checked' : '' }}>
                        <label class="form-check-label" for="hubAutoEnabled">{{ $backupSetting->is_enabled ? 'On' : 'Off' }}</label>
                    </div>
                </div>
                <p class="mu-panel-sub">Up to <strong>3 backups a day</strong> at these times (<strong>Pakistan time</strong>). Automatic backups older than the days you keep are deleted; backups you take by hand are never deleted.</p>
                <input type="hidden" name="timezone" value="{{ $backupSetting->timezone ?: 'Asia/Karachi' }}">
                <div class="row g-2 align-items-end">
                    @for($i = 0; $i < 3; $i++)
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="hub-time-{{ $i }}">Time {{ $i + 1 }}</label>
                            <input type="time" name="times[]" id="hub-time-{{ $i }}" class="form-control" value="{{ $slots[$i] ?? '' }}">
                        </div>
                    @endfor
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="hub-keep">Keep (days)</label>
                        <input type="number" name="retention_days" id="hub-keep" class="form-control" min="1" max="30" value="{{ $backupSetting->retention_days ?: 7 }}">
                    </div>
                    <div class="col-md-1">
                        <button class="btn btn-primary w-100">Save</button>
                    </div>
                </div>
                <p class="mu-note mt-2 mb-0">
                    @if($backupSetting->is_enabled && $backupSetting->slotList())
                        Now: {{ implode(', ', $backupSetting->slotList()) }} every day, kept {{ $backupSetting->retention_days }} days.
                    @else
                        Now: no automatic backups for this tenant.
                    @endif
                </p>
            </form>
        </section>
        @endcan

        <section class="mu-panel p-0">
            <div class="d-flex justify-content-between align-items-center p-3 pb-2">
                <div>
                    <h2 class="mb-0">Recent backups</h2>
                    <p class="mu-panel-sub mb-0">{{ $backupCount }} kept in total for this tenant.</p>
                </div>
                @can('central.tenants.backups')
                    <a href="{{ route('central.tenants.backups', $tenant) }}" class="btn btn-sm btn-light"><i class="ti ti-history me-1" aria-hidden="true"></i>All backups, restore &amp; delete</a>
                @endcan
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>When</th><th>Size</th><th>Type</th><th>Status</th><th>By</th><th class="text-end">File</th></tr></thead>
                    <tbody>
                    @forelse($backups as $backup)
                        <tr>
                            <td>{{ \App\Services\Central\MasterOverviewService::pkt($backup->created_at) }}</td>
                            <td>{{ $backup->humanSize() }}</td>
                            <td><span class="badge bg-light text-dark">{{ str_replace('_', ' ', $backup->backup_type) }}</span></td>
                            <td>
                                @if($backup->status === 'completed' && $backup->fileExists())
                                    <span class="mu-pill is-active">Ready</span>
                                @elseif($backup->status === 'completed')
                                    <span class="mu-pill is-pending" title="The record exists but the file is missing">File missing</span>
                                @else
                                    <span class="mu-pill is-suspended">Failed</span>
                                @endif
                            </td>
                            <td class="text-muted">{{ $backup->creator?->name ?? ($backup->created_by ? '#' . $backup->created_by : 'system') }}</td>
                            <td class="text-end">
                                @can('central.tenant-backups.download')
                                    @if($backup->fileExists())
                                        <a href="{{ route('central.tenant-backups.download', $backup) }}" class="btn btn-sm btn-outline-secondary" title="Download SQL"><i class="ti ti-download" aria-hidden="true"></i><span class="visually-hidden">Download</span></a>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-3">No backups of this tenant yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
