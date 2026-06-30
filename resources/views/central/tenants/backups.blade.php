@extends('layouts.app')

@section('title', 'Backups — ' . $tenant->tenant_code)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Backups — {{ $tenant->business_name }}</h1>
        <p class="fw-medium text-muted mb-0">Tenant <code>{{ $tenant->tenant_code }}</code> · database <code>{{ $tenant->database?->db_database }}</code></p>
    </div>
    <div class="d-flex gap-2">
        @can('central.tenants.backup')
            <form method="POST" action="{{ route('central.tenants.backup', $tenant) }}">@csrf
                <button class="btn btn-outline-primary"><i class="ti ti-database-export me-1"></i>Create Backup</button>
            </form>
        @endcan
        <a href="{{ url('/tenants/' . $tenant->id) }}" class="btn btn-light">Back</a>
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif
@if(session('status'))
    <div class="alert alert-success alert-dismissible fade show">{{ session('status') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<div class="card">
    <div class="card-body table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>Date / Time</th>
                    <th>File</th>
                    <th>Size</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Created by</th>
                    <th>Restored</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($backups as $backup)
                <tr>
                    <td>{{ $backup->created_at?->format('Y-m-d H:i') }}</td>
                    <td><span class="small">{{ $backup->file_name }}</span></td>
                    <td>{{ $backup->humanSize() }}</td>
                    <td><span class="badge bg-light text-dark">{{ str_replace('_', ' ', $backup->backup_type) }}</span></td>
                    <td>
                        @if($backup->status === 'completed' && $backup->fileExists())
                            <span class="badge bg-success-subtle text-success-emphasis">completed</span>
                        @elseif($backup->status === 'completed')
                            <span class="badge bg-warning-subtle text-warning-emphasis" title="Metadata exists but file missing">file missing</span>
                        @else
                            <span class="badge bg-danger-subtle text-danger-emphasis">failed</span>
                        @endif
                    </td>
                    <td class="small text-muted">{{ $backup->creator?->name ?? ($backup->created_by ? '#'.$backup->created_by : 'system') }}</td>
                    <td class="small text-muted">{{ $backup->restored_at?->format('Y-m-d H:i') ?? '—' }}</td>
                    <td class="text-end text-nowrap">
                        @can('central.tenant-backups.download')
                            @if($backup->fileExists())
                                <a href="{{ route('central.tenant-backups.download', $backup) }}" class="btn btn-sm btn-outline-secondary" title="Download SQL"><i class="ti ti-download"></i></a>
                            @endif
                        @endcan
                        @can('central.tenant-backups.restore')
                            @if($backup->fileExists())
                                <button type="button" class="btn btn-sm btn-outline-warning" title="Restore from this backup"
                                        data-bs-toggle="modal" data-bs-target="#restoreModal"
                                        data-action="{{ route('central.tenant-backups.restore', $backup) }}"
                                        data-code="{{ $tenant->tenant_code }}" data-file="{{ $backup->file_name }}">
                                    <i class="ti ti-restore"></i>
                                </button>
                            @endif
                        @endcan
                        @can('central.tenant-backups.delete')
                            <form method="POST" action="{{ route('central.tenant-backups.delete', $backup) }}" class="d-inline" onsubmit="return confirm('Delete this backup file permanently?');">@csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" title="Delete backup"><i class="ti ti-trash"></i></button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No backups yet.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $backups->links() }}</div>
    </div>
</div>

{{-- Restore confirmation modal --}}
<div class="modal fade" id="restoreModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" id="restoreForm">@csrf
            <div class="modal-content border-warning">
                <div class="modal-header"><h5 class="modal-title text-warning-emphasis"><i class="ti ti-alert-triangle me-1"></i>Restore Tenant</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="alert alert-warning small">This replaces <strong>{{ $tenant->tenant_code }}</strong> data from <span id="restoreFile" class="fw-semibold"></span>. A fresh pre-restore backup is created first, then migrations + permissions are re-synced.</div>
                    <label class="form-label">Type <code>RESTORE</code> to confirm</label>
                    <input type="text" name="confirm" class="form-control mb-2 ops-confirm-input" autocomplete="off" data-target="restoreSubmit" data-expect="RESTORE">
                    <label class="form-label">Type the tenant code (<code id="restoreCodeHint"></code>)</label>
                    <input type="text" name="tenant_code" class="form-control" autocomplete="off">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning" id="restoreSubmit" disabled>Restore</button>
                </div>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
    document.getElementById('restoreModal').addEventListener('show.bs.modal', function (e) {
        var b = e.relatedTarget;
        document.getElementById('restoreForm').setAttribute('action', b.getAttribute('data-action'));
        document.getElementById('restoreFile').textContent = b.getAttribute('data-file');
        document.getElementById('restoreCodeHint').textContent = b.getAttribute('data-code');
        document.getElementById('restoreForm').reset();
        document.getElementById('restoreSubmit').disabled = true;
    });
    document.addEventListener('input', function (e) {
        if (!e.target.classList.contains('ops-confirm-input')) return;
        var btn = document.getElementById(e.target.getAttribute('data-target'));
        if (btn) btn.disabled = (e.target.value.trim() !== (e.target.getAttribute('data-expect') || ''));
    });
})();
</script>
@endpush
@endsection
