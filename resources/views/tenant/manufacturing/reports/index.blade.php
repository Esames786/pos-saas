@extends('layouts.app')

@section('title', 'Production Reports')

@section('content')
@php
    $poColors   = \App\Models\Tenant\ProductionOrder::STATUS_COLORS;
    $wipColors  = \App\Models\Tenant\WipJob::STATUS_COLORS;
    $fgColors   = \App\Models\Tenant\FinishedGoodReceipt::STATUS_COLORS;
    $fmt   = fn ($v) => number_format((float) $v, 2);
    $fmt4  = fn ($v) => number_format((float) $v, 4);
    $label = fn ($v) => $v ? ucfirst(str_replace('_', ' ', $v)) : '—';
@endphp

        <div class="page-header">
            <div class="page-title">
                <h4>Production Reports</h4>
                <h6>Read-only manufacturing analytics ({{ $filters['date_from'] }} → {{ $filters['date_to'] }})</h6>
            </div>
            <div class="page-btn">
                @include('tenant.manufacturing.reports.partials.export-buttons')
            </div>
        </div>

        <div class="alert alert-info">
            <i class="ti ti-info-circle me-1"></i>
            These reports are <strong>read-only</strong>. They do not post inventory, WIP, COGS, variance or GL entries, and do not modify any manufacturing record.
        </div>

        @include('tenant.manufacturing.reports.partials.filters')

        {{-- 1. Production Overview --}}
        <h6 class="mt-4 mb-2 text-uppercase text-muted fw-bold" style="letter-spacing:.04em;">1 · Production Overview</h6>
        @include('tenant.manufacturing.reports.partials.overview-cards')

        {{-- 9. Yield / Variance --}}
        <div class="card mb-4">
            <div class="card-header"><h6 class="mb-0">Production Yield / Variance</h6></div>
            <div class="card-body row text-center g-2">
                @php $y = $yield; @endphp
                <div class="col-6 col-md-3"><div class="border rounded py-2 bg-success bg-opacity-10"><div class="text-muted small">FG Acceptance</div><div class="fw-bold text-success">{{ $y['fg_acceptance_rate'] }}%</div></div></div>
                <div class="col-6 col-md-3"><div class="border rounded py-2"><div class="text-muted small">FG Rejection</div><div class="fw-bold text-danger">{{ $y['fg_rejection_rate'] }}%</div></div></div>
                <div class="col-6 col-md-3"><div class="border rounded py-2"><div class="text-muted small">FG Scrap</div><div class="fw-bold text-warning">{{ $y['fg_scrap_rate'] }}%</div></div></div>
                <div class="col-6 col-md-3"><div class="border rounded py-2"><div class="text-muted small">Consumption Variance</div><div class="fw-bold">{{ $fmt4($y['consumption_variance']) }} ({{ $y['consumption_variance_pct'] }}%)</div></div></div>
                <div class="col-6 col-md-3"><div class="border rounded py-2"><div class="text-muted small">Scrap vs FG Received</div><div class="fw-bold">{{ $y['scrap_vs_fg_received'] }}%</div></div></div>
                <div class="col-6 col-md-3"><div class="border rounded py-2"><div class="text-muted small">Rejection vs FG Received</div><div class="fw-bold">{{ $y['rejection_vs_fg_received'] }}%</div></div></div>
            </div>
        </div>

        <div class="row g-3">
            {{-- 2. Production Orders --}}
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h6 class="mb-0">Production Orders by Status</h6></div>
                    <div class="card-body table-responsive p-0">
                        <table class="table table-sm mb-0"><thead class="thead-light"><tr><th>Status</th><th class="text-end">Orders</th><th class="text-end">Planned Qty</th></tr></thead>
                        <tbody>
                        @forelse($poSummary['by_status'] as $r)
                            <tr><td><span class="badge bg-{{ $poColors[$r->status] ?? 'secondary' }}">{{ $label($r->status) }}</span></td><td class="text-end">{{ number_format($r->orders) }}</td><td class="text-end">{{ $fmt($r->planned_qty) }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted py-3">No production orders in range.</td></tr>
                        @endforelse
                        </tbody></table>
                    </div>
                </div>
            </div>

            {{-- 4. WIP --}}
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h6 class="mb-0">WIP by Status <span class="text-muted small fw-normal">(avg progress {{ $wipSummary['cards']['avg_progress'] }}%)</span></h6></div>
                    <div class="card-body table-responsive p-0">
                        <table class="table table-sm mb-0"><thead class="thead-light"><tr><th>Status</th><th class="text-end">Jobs</th><th class="text-end">Planned</th><th class="text-end">Completed</th><th class="text-end">Avg %</th></tr></thead>
                        <tbody>
                        @forelse($wipSummary['by_status'] as $r)
                            <tr><td><span class="badge bg-{{ $wipColors[$r->status] ?? 'secondary' }}">{{ $label($r->status) }}</span></td><td class="text-end">{{ number_format($r->wip_count) }}</td><td class="text-end">{{ $fmt($r->planned_qty) }}</td><td class="text-end">{{ $fmt($r->completed_qty) }}</td><td class="text-end">{{ number_format((float)$r->avg_progress, 1) }}</td></tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-3">No WIP jobs in range.</td></tr>
                        @endforelse
                        </tbody></table>
                    </div>
                </div>
            </div>

            {{-- 3. MRC --}}
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h6 class="mb-0">MRC by Status <span class="text-muted small fw-normal">(req {{ $fmt($mrcSummary['cards']['required']) }} / issued {{ $fmt($mrcSummary['cards']['issued']) }})</span></h6></div>
                    <div class="card-body table-responsive p-0">
                        <table class="table table-sm mb-0"><thead class="thead-light"><tr><th>Status</th><th class="text-end">MRCs</th><th class="text-end">Required</th><th class="text-end">Issued</th></tr></thead>
                        <tbody>
                        @forelse($mrcSummary['by_status'] as $r)
                            <tr><td>{{ $label($r->status) }}</td><td class="text-end">{{ number_format($r->mrc_count) }}</td><td class="text-end">{{ $fmt($r->required_qty) }}</td><td class="text-end">{{ $fmt($r->issued_qty) }}</td></tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-3">No requisitions in range.</td></tr>
                        @endforelse
                        </tbody></table>
                    </div>
                </div>
            </div>

            {{-- 5. Finished Goods --}}
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h6 class="mb-0">Finished Goods <span class="text-muted small fw-normal">(acceptance {{ $fgSummary['cards']['acceptance_pc'] }}%)</span></h6></div>
                    <div class="card-body table-responsive p-0">
                        <table class="table table-sm mb-0"><thead class="thead-light"><tr><th>Status</th><th>Quality</th><th class="text-end">Recv</th><th class="text-end">Acc</th><th class="text-end">Rej</th><th class="text-end">Scrap</th></tr></thead>
                        <tbody>
                        @forelse($fgSummary['by_status'] as $r)
                            <tr><td><span class="badge bg-{{ $fgColors[$r->status] ?? 'secondary' }}">{{ $label($r->status) }}</span></td><td>{{ $label($r->quality_status) }}</td><td class="text-end">{{ $fmt($r->received_qty) }}</td><td class="text-end">{{ $fmt($r->accepted_qty) }}</td><td class="text-end">{{ $fmt($r->rejected_qty) }}</td><td class="text-end">{{ $fmt($r->scrap_qty) }}</td></tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-3">No finished goods in range.</td></tr>
                        @endforelse
                        </tbody></table>
                    </div>
                </div>
            </div>

            {{-- 6. Scrap --}}
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h6 class="mb-0">Scrap / Hard Waste <span class="text-muted small fw-normal">(total {{ $fmt($scrapSummary['cards']['total']) }})</span></h6></div>
                    <div class="card-body table-responsive p-0">
                        <table class="table table-sm mb-0"><thead class="thead-light"><tr><th>Type</th><th>Reason</th><th class="text-end">Recs</th><th class="text-end">Total</th><th class="text-end">Recov.</th><th class="text-end">Disp.</th><th class="text-end">Est. Loss</th></tr></thead>
                        <tbody>
                        @forelse($scrapSummary['by_type'] as $r)
                            <tr><td>{{ $label($r->scrap_type) }}</td><td>{{ $label($r->reason_code) }}</td><td class="text-end">{{ number_format($r->records) }}</td><td class="text-end">{{ $fmt($r->total_qty) }}</td><td class="text-end">{{ $fmt($r->recoverable_qty) }}</td><td class="text-end">{{ $fmt($r->disposed_qty) }}</td><td class="text-end">{{ $fmt($r->estimated_loss) }}</td></tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-3">No scrap in range.</td></tr>
                        @endforelse
                        </tbody></table>
                    </div>
                </div>
            </div>

            {{-- 7. Rejections --}}
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h6 class="mb-0">Rejections <span class="text-muted small fw-normal">(total {{ $fmt($rejSummary['cards']['total']) }})</span></h6></div>
                    <div class="card-body table-responsive p-0">
                        <table class="table table-sm mb-0"><thead class="thead-light"><tr><th>Type</th><th>Sev.</th><th>Disp.</th><th class="text-end">Recs</th><th class="text-end">Total</th><th class="text-end">Rework</th><th class="text-end">Scrap</th></tr></thead>
                        <tbody>
                        @forelse($rejSummary['by_type'] as $r)
                            <tr><td>{{ $label($r->rejection_type) }}</td><td>{{ $label($r->severity) }}</td><td>{{ $label($r->disposition) }}</td><td class="text-end">{{ number_format($r->records) }}</td><td class="text-end">{{ $fmt($r->total_qty) }}</td><td class="text-end">{{ $fmt($r->rework_qty) }}</td><td class="text-end">{{ $fmt($r->scrap_qty) }}</td></tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-3">No rejections in range.</td></tr>
                        @endforelse
                        </tbody></table>
                    </div>
                </div>
            </div>

            {{-- 8. Consumption --}}
            <div class="col-12">
                <div class="card">
                    <div class="card-header"><h6 class="mb-0">Consumption <span class="text-muted small fw-normal">(planned {{ $fmt($consSummary['cards']['planned']) }} / consumed {{ $fmt($consSummary['cards']['consumed']) }} / variance {{ $fmt($consSummary['cards']['variance']) }})</span></h6></div>
                    <div class="card-body table-responsive p-0">
                        <table class="table table-sm mb-0"><thead class="thead-light"><tr><th>Type</th><th>Variance</th><th class="text-end">Recs</th><th class="text-end">Planned</th><th class="text-end">Consumed</th><th class="text-end">Wastage</th><th class="text-end">Variance</th><th class="text-end">Est. Value</th></tr></thead>
                        <tbody>
                        @forelse($consSummary['by_type'] as $r)
                            <tr>
                                <td>{{ $label($r->consumption_type) }}</td>
                                <td><span class="badge bg-{{ $r->variance_status === 'over_consumed' ? 'danger' : ($r->variance_status === 'under_consumed' ? 'warning' : 'success') }}">{{ $label($r->variance_status) }}</span></td>
                                <td class="text-end">{{ number_format($r->records) }}</td>
                                <td class="text-end">{{ $fmt($r->planned_qty) }}</td>
                                <td class="text-end">{{ $fmt($r->consumed_qty) }}</td>
                                <td class="text-end">{{ $fmt($r->wastage_qty) }}</td>
                                <td class="text-end">{{ $fmt($r->variance_qty) }}</td>
                                <td class="text-end">{{ $fmt($r->estimated_value) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-3">No consumption in range.</td></tr>
                        @endforelse
                        </tbody></table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Latest production orders --}}
        <div class="card mt-4 table-list-card">
            <div class="card-header"><h6 class="mb-0">Latest Production Orders</h6></div>
            <div class="card-body table-responsive">
                <table class="table datanew"><thead class="thead-light"><tr><th>Order No</th><th>Date</th><th>Customer</th><th>Product</th><th>Branch</th><th>Status</th><th class="text-end">Planned</th><th>Priority</th></tr></thead>
                <tbody>
                @forelse($poSummary['latest'] as $o)
                    <tr>
                        <td><a href="{{ url('/manufacturing/production-orders/' . $o->id) }}" class="fw-semibold">{{ $o->order_no }}</a></td>
                        <td>{{ $o->order_date?->format('d M Y') }}</td>
                        <td>{{ $o->manufacturingCustomer?->name ?? '—' }}</td>
                        <td>{{ $o->product?->name }}<small class="d-block text-muted">{{ $o->product?->sku }}</small></td>
                        <td>{{ $o->branch?->name ?? '—' }}</td>
                        <td><span class="badge bg-{{ $poColors[$o->status] ?? 'secondary' }}">{{ $label($o->status) }}</span></td>
                        <td class="text-end">{{ $fmt($o->planned_quantity) }}</td>
                        <td>{{ $label($o->priority) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">No production orders in range.</td></tr>
                @endforelse
                </tbody></table>
            </div>
        </div>
@endsection
