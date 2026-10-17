{{-- ONLINE-ORDERING-1 (Phase 4b) — usage invoice ki child rows: har service ka subtotal, khol kar din-wise.
     Tenant ki /billing aur platform admin ka invoice — dono yahi dikhate hain. Lines na hon (plan wali
     invoice) to kuch nahi. --}}
@php
    $groups = $invoice->lines->sortBy('usage_date')->groupBy('line_type');
@endphp
@if($groups->isNotEmpty())
<div class="card mb-3">
    <div class="card-header"><h5 class="mb-0">Charges</h5></div>
    <div class="card-body p-0">
        @foreach($groups as $type => $lines)
            <details class="border-bottom">
                <summary class="d-flex justify-content-between align-items-center px-3 py-2" style="cursor:pointer">
                    <span class="fw-semibold">
                        {{ \App\Models\Master\SubscriptionInvoiceLine::LABELS[$type] ?? ucfirst(str_replace('_', ' ', $type)) }}
                        <small class="text-muted fw-normal">
                            @if($type === \App\Models\Master\SubscriptionInvoiceLine::TYPE_WHATSAPP)
                                · {{ number_format((float) $lines->sum('quantity')) }} messages
                            @elseif($type === \App\Models\Master\SubscriptionInvoiceLine::TYPE_WEBSITE)
                                · {{ number_format((float) $lines->sum('quantity')) }} orders · net sale {{ number_format((float) $lines->sum('base_amount'), 2) }}
                            @endif
                            · {{ $lines->count() }} {{ $lines->count() === 1 ? 'day' : 'days' }}
                        </small>
                    </span>
                    <span class="fw-bold" style="font-variant-numeric:tabular-nums">{{ number_format((float) $lines->sum('amount'), 2) }}</span>
                </summary>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <tbody>
                        @foreach($lines as $line)
                            <tr>
                                <td class="ps-4 text-nowrap">{{ $line->usage_date?->format('d M') ?? '—' }}</td>
                                <td class="small text-muted">{{ $line->description }}</td>
                                <td class="text-end pe-3" style="font-variant-numeric:tabular-nums">{{ number_format((float) $line->amount, 2) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @endforeach
    </div>
</div>
@endif
