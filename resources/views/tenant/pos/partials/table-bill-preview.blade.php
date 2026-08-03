@php
    $heldSales = $session->salesOrders->where('status', 'held');
    $paidSales = $session->salesOrders->where('status', 'paid');
    $heldTotal = (float) $heldSales->sum('grand_total');
    $paidTotal = (float) $paidSales->sum('grand_total');
@endphp

<div class="table-bill-preview" data-session-id="{{ $session->id }}">
    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
        <div>
            <h3 class="h5 mb-1">Table {{ $session->table?->table_no }}</h3>
            <div class="text-muted small">
                {{ $session->session_no }} &middot; {{ $session->waiter?->name ?? 'No waiter' }}
                &middot; {{ $session->guest_count }} guests
            </div>
        </div>
        <span class="badge bg-light text-dark">{{ str_replace('_', ' ', ucfirst($session->status)) }}</span>
    </div>

    <div class="row g-2 mb-3">
        <div class="col-6">
            <div class="border rounded p-2 h-100">
                <div class="text-muted small">Open check</div>
                <strong class="fs-5">{{ number_format($heldTotal, 2) }}</strong>
            </div>
        </div>
        <div class="col-6">
            <div class="border rounded p-2 h-100">
                <div class="text-muted small">Previously paid</div>
                <strong class="fs-5">{{ number_format($paidTotal, 2) }}</strong>
            </div>
        </div>
    </div>

    <h4 class="h6">Held / Unpaid Orders</h4>
    @forelse($heldSales as $sale)
        <section class="border rounded mb-2">
            <div class="d-flex justify-content-between align-items-center gap-2 p-2 bg-light">
                <div>
                    <strong>{{ $sale->sale_no }}</strong>
                    <div class="small text-muted">
                        Sub {{ number_format((float) $sale->subtotal, 2) }}
                        @if((float) $sale->discount_amount > 0)
                            &middot; Disc -{{ number_format((float) $sale->discount_amount, 2) }}
                        @endif
                        @if((float) $sale->tax_amount > 0)
                            &middot; Tax {{ number_format((float) $sale->tax_amount, 2) }}
                        @endif
                        @if((float) $sale->service_charge_amount > 0)
                            &middot; Service {{ number_format((float) $sale->service_charge_amount, 2) }}
                        @endif
                        @if((float) $sale->tip_amount > 0)
                            &middot; Tip {{ number_format((float) $sale->tip_amount, 2) }}
                        @endif
                    </div>
                </div>
                <strong class="text-nowrap">{{ number_format((float) $sale->grand_total, 2) }}</strong>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <tbody>
                    @foreach($sale->lines as $line)
                        @if(($line->line_kind ?? 'standard') === 'component') @continue @endif
                        <tr>
                            <td>
                                {{ $line->product_name }}
                                @if($line->variant_name)<span class="text-muted small">({{ $line->variant_name }})</span>@endif
                                @foreach(($line->modifiers ?? []) as $modifier)
                                    @if(!empty($modifier['name']))
                                        <div class="small text-muted ps-2">+ {{ $modifier['name'] }}</div>
                                    @endif
                                @endforeach
                            </td>
                            <td class="text-end text-nowrap">{{ number_format((float) $line->quantity, 3) }}</td>
                            <td class="text-end text-nowrap">{{ number_format((float) $line->line_total, 2) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @empty
        <div class="alert alert-light border">No held orders for this table.</div>
    @endforelse

    @if($paidSales->isNotEmpty())
        <details class="mt-3">
            <summary class="fw-semibold">Paid order history ({{ $paidSales->count() }})</summary>
            <div class="table-responsive mt-2">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Sale</th><th>Date</th><th class="text-end">Total</th></tr></thead>
                    <tbody>
                    @foreach($paidSales as $sale)
                        <tr>
                            <td>{{ $sale->sale_no }}</td>
                            <td>{{ $sale->sale_date?->format('d M H:i') }}</td>
                            <td class="text-end">{{ number_format((float) $sale->grand_total, 2) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endif
</div>
