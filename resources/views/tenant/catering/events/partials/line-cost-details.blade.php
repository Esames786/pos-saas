{{-- ── Cost Details for one estimate line ──────────────────────────────────
     KASHIF-CATERING-OPERATOR-UI-1: one source of truth for the line breakdown,
     shown wherever the line is shown — the draft builder and the immutable
     view both include this. It reads the LINE SNAPSHOT, never the product
     master: the quotation explains itself from what was quoted.

     KASHIF-COSTPANEL-SIMPLE-1: one rate, one place — the row's Quoted Rate box
     is the ONLY place the item's final rate is set; this panel deliberately
     carries no duplicate of it. Per part, the operator answers two plain
     questions: kitchen ko kitna chahiye, aur us mein se customer kitna dega —
     two LINKED boxes whose sum is always the kitchen total.

     Draft actions deliberately use [data-act] + .js-act (a page-level helper
     posts them from a dynamically built form) instead of inline <form> tags:
     inside the draft builder this markup lives within the estimate <form>,
     and HTML silently drops nested forms. --}}
@php
    $blocks = $line->costBlocks;
    $editable = $current->isDraft() && $event->isOpen();
    $fmtQty = fn ($q) => rtrim(rtrim(number_format((float) $q, 4, '.', ''), '0'), '.');

    // KASHIF-LEGACY-ALIGN-2 — the old software's one-glance strip, computed
    // from the SAME snapshot numbers the table below shows. Read-only by
    // design: a second editing surface would be a second set of rules.
    $stripQty = (float) $line->quantity;
    $stripPerUnit = fn ($b) => $stripQty > 0 ? round(((float) $b->amount) / $stripQty, 2) : 0.0;
    $stripMaking = $blocks->first(fn ($b) => $b->isMaking());
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
                @elseif($stripPrimary->isPartiallyCustomerSupplied())
                    <div class="fs-12 text-success-emphasis">split · <span dir="rtl">گاہک</span> {{ $fmtQty($stripPrimary->suppliedQty()) }} {{ $stripPrimary->unit_code }}</div>
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
        <span class="fs-12 d-block mt-1">Change it in the row's Quoted Rate box — typing the calculated figure puts the line back on the calculation.</span>
    </div>
@endif

<div class="table-responsive">
    <table class="table table-sm mb-0">
        <thead>
            <tr>
                <th>Part</th>
                <th class="text-end">Customer charge</th>
                <th class="text-end" style="min-width:330px">Kitchen uses &amp; supply</th>
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
                </td>
                <td class="text-end">
                    {{-- KASHIF-COSTPANEL-SIMPLE-1: the part's SYSTEM RATE, right
                         beside it, changeable for THIS BOOKING ONLY. The dish's
                         own block never moves; a hand-set rate stops following
                         the house rate book. --}}
                    <span class="fw-semibold">{{ number_format($block->rate, 2) }}</span>
                    <div class="fs-12 text-muted">
                        @if($block->isLumpSum())
                            charged once
                        @elseif($block->isPerMaterialUnit())
                            per {{ $block->unit_code ?? 'unit' }} {{ $block->material_name }}
                        @else
                            per {{ $line->unit_code ?? 'unit' }} dish
                        @endif
                    </div>
                    @if($editable)
                        @can('tenant.catering.estimates.update')
                        <div class="rate-edit d-none mt-1"
                             data-act="{{ url('/catering/line-cost-blocks/' . $block->id . '/rate') }}"
                             data-act-method="PUT">
                            <div class="d-inline-flex gap-1 align-items-center">
                                <input type="number" step="0.01" min="0" data-field="rate"
                                       value="{{ rtrim(rtrim(number_format((float) $block->rate, 4, '.', ''), '0'), '.') }}"
                                       class="form-control form-control-sm text-end" style="width:100px">
                                <button type="button" class="btn btn-sm btn-light js-act" title="Use this rate for this booking only">
                                    <i class="ti ti-check"></i>
                                </button>
                            </div>
                        </div>
                        <button type="button" class="btn btn-link btn-sm p-0 fs-12 js-rate-toggle">change rate</button>
                        @endcan
                    @endif
                </td>
                <td class="text-end">
                    @if($block->isMaterial() && $block->event_material_qty !== null)
                        @if($editable)
                            @can('tenant.catering.estimates.update')
                            {{-- Question 1: kitchen ko kitna chahiye? --}}
                            <div class="d-inline-flex gap-1 align-items-center"
                                 data-act="{{ url('/catering/line-cost-blocks/' . $block->id) }}"
                                 data-act-method="PUT">
                                <span class="fs-12 text-muted">Kitchen needs</span>
                                <input type="number" step="0.0001" min="0" data-field="event_material_qty"
                                       value="{{ $fmtQty($block->event_material_qty) }}"
                                       class="form-control form-control-sm text-end" style="width:85px">
                                <span class="fs-12 text-muted">{{ $block->unit_code }}</span>
                                <button type="button" class="btn btn-sm btn-light js-act" title="Use this quantity for this booking only">
                                    <i class="ti ti-check"></i>
                                </button>
                            </div>

                            {{-- Question 2: us mein se kaun kitna dega? Two
                                 LINKED boxes — the sum is ALWAYS the kitchen
                                 total, so the two shares can never contradict.
                                 Customer 0 = hum sab; customer = total = poora
                                 customer ka (the service normalizes that to the
                                 full flag). Only the customer share posts. --}}
                            <div class="d-inline-flex gap-1 align-items-center mt-1 supply-split"
                                 data-total="{{ $fmtQty($block->physicalRequirement()) }}"
                                 data-act="{{ url('/catering/line-cost-blocks/' . $block->id . '/customer-supplied') }}"
                                 data-act-method="PUT">
                                <input type="hidden" data-field="is_customer_supplied" value="0">
                                <span class="fs-12">Customer dega · <span dir="rtl">گاہک</span></span>
                                <input type="number" step="0.0001" min="0" data-field="customer_supplied_qty"
                                       value="{{ $block->suppliedQty() > 0 ? $fmtQty($block->suppliedQty()) : '0' }}"
                                       class="form-control form-control-sm text-end split-customer" style="width:75px">
                                <span class="fs-12">Hum denge · <span dir="rtl">ہم</span></span>
                                <input type="number" step="0.0001" min="0"
                                       value="{{ $fmtQty($block->billableQty()) }}"
                                       class="form-control form-control-sm text-end split-ours" style="width:75px">
                                <button type="button" class="btn btn-sm btn-light js-act" title="Save the split">
                                    <i class="ti ti-check"></i>
                                </button>
                            </div>

                            <div class="fs-12 text-muted mt-1">
                                Recipe says: {{ $fmtQty($block->default_material_qty) }} {{ $block->unit_code }}
                                @if($block->is_overridden)
                                    · <span class="d-inline"
                                          data-act="{{ url('/catering/line-cost-blocks/' . $block->id . '/reset') }}"
                                          data-act-method="POST">
                                        <button type="button" class="btn btn-link btn-sm p-0 align-baseline fs-12 js-act">back to the recipe</button>
                                    </span>
                                @endif
                            </div>
                            @else
                                {{ $fmtQty($block->event_material_qty) }} {{ $block->unit_code }}
                            @endcan
                        @else
                            {{ $fmtQty($block->event_material_qty) }} {{ $block->unit_code }}
                        @endif

                        {{-- The state, in one plain sentence. --}}
                        @if($block->isCustomerSupplied())
                            <div class="fs-12 text-success-emphasis">
                                customer brings ALL {{ $fmtQty($block->physicalRequirement()) }} {{ $block->unit_code }} · we issue 0 · charge 0
                            </div>
                        @elseif($block->isPartiallyCustomerSupplied())
                            <div class="fs-12 text-success-emphasis">
                                customer {{ $fmtQty($block->suppliedQty()) }} {{ $block->unit_code }} ·
                                we issue &amp; charge {{ $fmtQty($block->billableQty()) }} {{ $block->unit_code }}
                            </div>
                        @endif
                        @if($block->isPerMaterialUnit() && ! $block->isCustomerSupplied())
                            <div class="fs-12 text-muted">
                                charge: {{ $fmtQty($block->billableQty()) }} × {{ number_format($block->rate, 2) }}
                                = {{ number_format((float) $block->amount, 2) }}
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

{{-- KASHIF-COSTPANEL-SIMPLE-1: the panel carries NO quoted-rate control any
     more. One rate, one place — the row's Quoted Rate box above. --}}

<div class="text-muted fs-12 mt-2">
    <strong>Customer charge</strong> is what this part adds to the bill.
    <strong>Kitchen uses &amp; supply</strong> is what the kitchen needs and who brings it.
    <strong>Costs us</strong> is our share at the
    <a href="{{ url('/catering/material-rates') }}">Material Rate Book</a> rate
    when this was quoted. They are meant to differ — the gap is the margin.
    Everything here affects <strong>this booking only</strong> — never the
    dish, and never another quotation.
</div>
