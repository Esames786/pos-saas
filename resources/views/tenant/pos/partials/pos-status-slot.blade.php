{{-- W-A / owner decision A5 (modified) — the ONE shared runtime-status slot. Same place (right end of the POS title row),
     same fixed box (28px high, 260px wide, content clipped) in BOTH runtimes, so the text can change without moving a
     button or changing the row height (the row is 38px tall: the sidebar-toggle button sets it):
       Online     → "ONLINE · CLOUD"
       Edge       → e.g. "STANDBY · CLOUD AUTHORITY", "LOCAL MODE ACTIVE" (+ pending-sync count)
     Theme badge classes only. Repainted at runtime by POS.setAuthority(); urgent warnings use the shared fixed overlay
     (#pos-runtime-overlay in layouts.pos), never extra rows. --}}
@php
    $posAuthority = $posRuntime->authority;
    $posAuthorityTone = ['ok' => 'bg-success', 'warn' => 'bg-warning text-dark', 'danger' => 'bg-danger'][$posAuthority['tone'] ?? 'ok'] ?? 'bg-success';
    $posPendingSync = (int) ($posAuthority['pending_sync'] ?? 0);
@endphp
<div id="pos-runtime-slot" class="ms-auto d-inline-flex align-items-center justify-content-end gap-1 flex-nowrap small"
     style="height:28px;width:260px;max-width:100%;overflow:hidden;white-space:nowrap"
     role="status" aria-live="polite" data-runtime-mode="{{ $posRuntime->mode }}">
    <span class="badge {{ $posAuthorityTone }}" id="pos-runtime-state">{{ $posAuthority['label'] ?? '' }}</span>
    <span class="text-muted fw-semibold" id="pos-runtime-sub">{{ ! empty($posAuthority['sub_label']) ? '· ' . $posAuthority['sub_label'] : '' }}</span>
    <span class="badge bg-warning text-dark {{ $posPendingSync > 0 ? '' : 'd-none' }}" id="pos-runtime-pending">{{ $posPendingSync }} pending sync</span>
</div>
