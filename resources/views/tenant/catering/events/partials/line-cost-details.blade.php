{{-- ── Cost Details for one estimate line ──────────────────────────────────
     KASHIF-CATERING-OPERATOR-UI-1: one source of truth for the line breakdown,
     shown wherever the line is shown — the draft builder and the immutable
     view both include this. It reads the LINE SNAPSHOT, never the product
     master: the quotation explains itself from what was quoted.

     Draft actions deliberately use [data-act] + .js-act (a page-level helper
     posts them from a dynamically built form) instead of inline <form> tags:
     inside the draft builder this markup lives within the estimate <form>,
     and HTML silently drops nested forms — the old reason these controls
     could never appear where drafts actually needed them. --}}
@php
    $blocks = $line->costBlocks;
    $editable = $current->isDraft() && $event->isOpen();

    // KASHIF-LEGACY-ALIGN-2 — the old software's one-glance strip, computed
    // from the SAME snapshot numbers the table below shows. Read-only by
    // design: a second editing surface would be a second set of rules.
    $stripQty = (float) $line->quantity;
    $stripPerUnit = fn ($b) => $stripQty > 0 ? round(((float) $b->amount) / $stripQty, 2) : 0.0;
    $stripMaking = $blocks->first(fn ($b) => $b->isMaking());
    // The headline material is the physically biggest one, judged by what it
    // WOULD bill (qty × charged rate) — so a customer-supplied Beef, billed 0,
    // still owns its box instead of quietly handing it to Rice.
    $stripNotional = fn ($b) => $b->isPerMaterialUnit()
        ? (float) ($b->event_material_qty ?? 0) * (float) $b->rate
        : (float) $b->amount;
    $stripPrimary = $blocks->filter->isMaterial()->sortByDesc($stripNotional)->first();
    $stripLumps = $blocks->filter->isLumpSum();
    $stripOthers = $blocks->reject(fn ($b) => $b->isLumpSum()
        || ($stripMaking && $b->is($stripMaking))
        || ($stripPrimary && $b->is($stripPrimary)));
    $stripAdditional = round($stripOthers->sum(fn ($b) => $stripPerUnit($b)), 2);
@endphp

@if($blocks->isNotEmpty())
    <div class="d-flex flex-wrap gap-2 mb-3">
        <div class="border border-primary rounded px-3 py-2 bg-primary-subtle">
            <div class="fs-12 text-uppercase fw-bold text-muted">Order Rate</div>
            <div class="fs-4 fw-bold">{{ number_format((float) ($line->calculated_rate ?? 0), 2) }}</div>
            <div class="fs-12 text-muted">per {{ $line->unit_code }}</div>
        </div>
        @if($stripMaking)
            <div class="border rounded px-3 py-2">
                <div class="fs-12 text-uppercase fw-bold text-muted">Making Chrg</div>
                <div class="fs-4 fw-semibold">{{ number_format($stripMaking->isLumpSum() ? (float) $stripMaking->amount : $stripPerUnit($stripMaking), 2) }}</div>
                @if($stripMaking->isLumpSum())<div class="fs-12 text-muted">charged once</div>@endif
            </div>
        @endif
        @if($stripPrimary && ! $stripPrimary->isLumpSum())
            <div class="border rounded px-3 py-2">
                <div class="fs-12 text-uppercase fw-bold text-muted">{{ $stripPrimary->material_name ?: $stripPrimary->label }} Rate</div>
                <div class="fs-4 fw-semibold">{{ number_format($stripPerUnit($stripPrimary), 2) }}</div>
                @if($stripPrimary->isCustomerSupplied())
                    <div class="fs-12 text-success-emphasis">customer provides · <span dir="rtl">گاہک دے گا</span></div>
                @endif
            </div>
        @endif
        @if($stripAdditional > 0)
            <div class="border rounded px-3 py-2">
                <div class="fs-12 text-uppercase fw-bold text-muted">Additional</div>
                <div class="fs-4 fw-semibold">{{ number_format($stripAdditional, 2) }}</div>
                <div class="fs-12 text-muted">other parts, per {{ $line->unit_code }}</div>
            </div>
        @endif
        @if($stripLumps->isNotEmpty())
            <div class="border rounded px-3 py-2">
                <div class="fs-12 text-uppercase fw-bold text-muted">One-time</div>
                <div class="fs-4 fw-semibold">{{ number_format((float) $stripLumps->sum('amount'), 2) }}</div>
                <div class="fs-12 text-muted">never inside the per-{{ $line->unit_code }} rate</div>
            </div>
        @endif
        @if($line->hasQuotedRateOverride())
            <div class="border border-warning rounded px-3 py-2 bg-warning-subtle">
                <div class="fs-12 text-uppercase fw-bold text-muted">Quoted</div>
                <div class="fs-4 fw-bold">{{ number_format((float) $line->rate, 2) }}</div>
                <div class="fs-12 text-muted">per {{ $line->unit_code }}</div>
            </div>
        @endif
    </div>
@endif

@if($line->hasQuotedRateOverride() && $line->rate_override_reason)
    <div class="alert alert-warning py-2 mb-3 fs-13">
        <i class="ti ti-alert-triangle me-1"></i>
        Quoted at {{ number_format($line->rate, 2) }} instead of the calculated
        {{ number_format($line->calculated_rate, 2) }} —
        <em>{{ $line->rate_override_reason }}</em>
    </div>
@endif

<div class="table-responsive">
    <table class="table table-sm mb-0">
        <thead>
            <tr>
                <th>Part</th>
                <th class="text-end">Customer charge</th>
                <th class="text-end">Kitchen uses</th>
                <th class="text-end">Costs us</th>
                <th class="text-end">Contribution</th>
            </tr>
        </thead>
        <tbody>
            @foreach($blocks as $block)
            <tr>
                <td>
                    {{ $block->label }}
                    @if($block->isMaterial())
                        <span class="badge bg-info-subtle text-info-emphasis fs-12">Material</span>
                    @else
                        <span class="badge bg-secondary-subtle text-secondary-emphasis fs-12">Charge</span>
                    @endif
                    @if($block->isLumpSum())
                        <span class="badge bg-light text-muted fs-12">once</span>
                    @endif
                    @if($block->is_overridden)
                        <span class="badge bg-warning-subtle text-warning-emphasis fs-12">this event</span>
                    @endif
                    @if($block->isCustomerSupplied())
                        <span class="badge bg-success-subtle text-success-emphasis fs-12">Customer provides</span>
                    @endif
                </td>
                <td class="text-end">
                    {{ number_format($block->rate, 2) }}
                    <div class="fs-12 text-muted">
                        @if($block->isLumpSum())
                            charged once
                        @elseif($block->isPerMaterialUnit())
                            per {{ $block->unit_code ?? 'unit' }} {{ $block->material_name }}
                        @else
                            per {{ $line->unit_code ?? 'unit' }} dish
                        @endif
                    </div>
                </td>
                <td class="text-end">
                    @if($block->isMaterial() && $block->event_material_qty !== null)
                        @if($editable)
                            {{-- Editable only while the quotation is a draft: a
                                 sent one's costing is history. --}}
                            @can('tenant.catering.estimates.update')
                            <div class="d-inline-flex gap-1 align-items-center"
                                 data-act="{{ url('/catering/line-cost-blocks/' . $block->id) }}"
                                 data-act-method="PUT">
                                <input type="number" step="0.0001" min="0" data-field="event_material_qty"
                                       value="{{ rtrim(rtrim(number_format($block->event_material_qty, 4, '.', ''), '0'), '.') }}"
                                       class="form-control form-control-sm text-end" style="width:90px">
                                <span class="fs-12 text-muted">{{ $block->unit_code }}</span>
                                <button type="button" class="btn btn-sm btn-light js-act" title="Use this quantity for this booking only">
                                    <i class="ti ti-check"></i>
                                </button>
                            </div>
                            @else
                                {{ rtrim(rtrim(number_format($block->event_material_qty, 4), '0'), '.') }}
                                {{ $block->unit_code }}
                            @endcan
                        @else
                            {{ rtrim(rtrim(number_format($block->event_material_qty, 4), '0'), '.') }}
                            {{ $block->unit_code }}
                        @endif

                        {{-- Two facts at once: the kitchen still needs it, and
                             our store hands over none of it. --}}
                        @if($block->isCustomerSupplied())
                            <div class="fs-12 text-success-emphasis">
                                customer provides it · we issue 0
                            </div>
                        @endif

                        @if($editable)
                            @can('tenant.catering.estimates.update')
                            <div class="mt-1"
                                 data-act="{{ url('/catering/line-cost-blocks/' . $block->id . '/customer-supplied') }}"
                                 data-act-method="PUT">
                                <input type="hidden" data-field="is_customer_supplied"
                                       value="{{ $block->isCustomerSupplied() ? 0 : 1 }}">
                                <button type="button" class="btn btn-link btn-sm p-0 fs-12 js-act">
                                    {{ $block->isCustomerSupplied()
                                        ? 'We will provide this instead'
                                        : 'Customer will provide this' }}
                                </button>
                            </div>
                            @endcan
                        @endif

                        @if($block->is_overridden)
                            <div class="fs-12 text-muted">
                                dish says {{ rtrim(rtrim(number_format($block->default_material_qty, 4), '0'), '.') }}
                                @if($editable)
                                    @can('tenant.catering.estimates.update')
                                    <span class="d-inline"
                                          data-act="{{ url('/catering/line-cost-blocks/' . $block->id . '/reset') }}"
                                          data-act-method="POST">
                                        <button type="button" class="btn btn-link btn-sm p-0 align-baseline fs-12 js-act">Reset</button>
                                    </span>
                                    @endcan
                                @endif
                            </div>
                        @endif
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
                <td class="text-end text-muted">
                    @if($block->material_cost !== null)
                        {{ number_format($block->material_cost, 2) }}
                    @elseif($block->isMaterial())
                        <span title="No rate in the Material Rate Book yet">not known</span>
                    @else
                        —
                    @endif
                </td>
                <td class="text-end fw-semibold">{{ number_format($block->amount, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="border-top">
                <td colspan="4" class="text-end fw-bold">Calculated rate</td>
                <td class="text-end fw-bold">
                    {{ number_format($line->calculated_rate ?? 0, 2) }} / {{ $line->unit_code }}
                </td>
            </tr>
        </tfoot>
    </table>
</div>

@if($editable)
    @can('tenant.catering.estimates.update')
    <div class="row g-2 align-items-end mt-2"
         data-act="{{ url('/catering/estimate-lines/' . $line->id . '/quoted-rate') }}"
         data-act-method="PUT">
        <div class="col-auto">
            <label class="form-label fs-12 text-muted mb-1">Quote a different rate</label>
            <input type="number" step="0.01" min="0" data-field="quoted_rate"
                   value="{{ number_format($line->rate, 2, '.', '') }}"
                   class="form-control form-control-sm" style="width:120px">
        </div>
        <div class="col-md-5">
            <label class="form-label fs-12 text-muted mb-1">Why</label>
            <input type="text" data-field="reason" maxlength="255"
                   value="{{ $line->rate_override_reason }}"
                   class="form-control form-control-sm"
                   placeholder="e.g. wedding package agreed rate">
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-sm btn-outline-primary js-act">Use this rate</button>
        </div>
    </div>

    @if($line->hasQuotedRateOverride())
        <div class="mt-2"
             data-act="{{ url('/catering/estimate-lines/' . $line->id . '/use-calculated-rate') }}"
             data-act-method="POST">
            <button type="button" class="btn btn-link btn-sm p-0 fs-12 js-act">
                Use calculated rate ({{ number_format($line->calculated_rate, 2) }}) instead
            </button>
        </div>
    @endif
    @endcan
@endif

<div class="text-muted fs-12 mt-2">
    <strong>Customer charge</strong> is what this part adds to the bill.
    <strong>Kitchen uses</strong> is what leaves the store.
    <strong>Costs us</strong> is that quantity at the
    <a href="{{ url('/catering/material-rates') }}">Material Rate Book</a> rate
    when this was quoted. They are meant to differ — the gap is the margin.
    Changing a quantity here affects <strong>this booking only</strong> — never the
    dish, and never another quotation.
</div>
