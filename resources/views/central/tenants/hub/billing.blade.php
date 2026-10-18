{{-- MASTER-UNIVERSE-1 — Billing & invoices: plan, price, subscription form and recent invoices
     (everything the old page's Subscription + Billing cards did). --}}
@php
    $plan = $tenant->subscription?->plan;
    $due = $card['due'];
@endphp
<div class="row g-3">
    <div class="col-xl-4">
        <section class="mu-panel">
            <h2>Plan</h2>
            <div class="mu-kv mt-2">
                <div><div class="mu-k">Plan</div><div class="mu-v">{{ $plan?->name ?? 'No plan' }}</div></div>
                <div><div class="mu-k">Price</div><div class="mu-v">
                    @if($plan)
                        {{ $plan->currency_code ?? $tenant->currency_code }} {{ number_format((float) $plan->price, 2) }}{{ $plan->billing_period ? ' / ' . str_replace('_', ' ', $plan->billing_period) : '' }}
                    @else
                        —
                    @endif
                </div></div>
                <div><div class="mu-k">Subscription</div><div class="mu-v">{{ $tenant->subscription?->status ? ucfirst(str_replace('_', ' ', $tenant->subscription->status)) : '-' }}</div></div>
                <div><div class="mu-k">Period ends</div><div class="mu-v">{{ optional($tenant->subscription?->current_period_ends_at)->format('Y-m-d') ?? '-' }}</div></div>
                <div><div class="mu-k">Trial ends</div><div class="mu-v">{{ optional($tenant->subscription?->trial_ends_at)->format('Y-m-d') ?? '-' }}</div></div>
                <div><div class="mu-k">Unpaid</div><div class="mu-v {{ $due ? (($due['overdue'] ?? 0) > 0 ? 'text-danger' : 'text-warning') : 'text-success' }}">
                    {{ $due ? $due['currency'] . ' ' . number_format($due['amount'], 2) . ' (' . $due['count'] . ' invoice' . ($due['count'] > 1 ? 's' : '') . (($due['overdue'] ?? 0) > 0 ? ', ' . $due['overdue'] . ' overdue' : '') . ')' : 'Nothing due' }}
                </div></div>
            </div>
            @if($plan)
                @can('central.plans.edit')
                    <a href="{{ url('/plans/' . $plan->id . '/edit') }}" class="btn btn-light btn-sm mt-3"><i class="ti ti-adjustments me-1" aria-hidden="true"></i>Open plan builder</a>
                @endcan
            @endif
        </section>
    </div>

    <div class="col-xl-8">
        {{-- Subscription card --}}
        @can('central.tenants.subscription.update')
        <section class="mu-panel">
            <h2>Subscription</h2>
            <p class="mu-panel-sub">Change the plan or the subscription dates. The plan decides the modules.</p>
            <form method="POST" action="{{ url('/tenants/' . $tenant->id . '/subscription') }}">
                @csrf
                @method('PUT')

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="sub-plan">Plan</label>
                        <select name="plan_id" id="sub-plan" class="form-select">
                            <option value="">No Plan</option>
                            @foreach($plans as $p)
                                <option value="{{ $p->id }}" @selected(old('plan_id', $tenant->subscription?->plan_id) == $p->id)>
                                    {{ $p->name }} ({{ $p->code }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="sub-status">Subscription Status</label>
                        <select name="status" id="sub-status" class="form-select">
                            @foreach(['trial', 'active', 'past_due', 'cancelled'] as $status)
                                <option value="{{ $status }}" @selected(old('status', $tenant->subscription?->status ?? 'trial') === $status)>
                                    {{ ucfirst(str_replace('_', ' ', $status)) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="sub-trial">Trial Ends At</label>
                        <input type="date" name="trial_ends_at" id="sub-trial" class="form-control"
                               value="{{ old('trial_ends_at', optional($tenant->subscription?->trial_ends_at)->format('Y-m-d')) }}">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="sub-period">Current Period Ends At</label>
                        <input type="date" name="current_period_ends_at" id="sub-period" class="form-control"
                               value="{{ old('current_period_ends_at', optional($tenant->subscription?->current_period_ends_at)->format('Y-m-d')) }}">
                    </div>
                </div>

                <div class="text-end mt-3">
                    <button class="btn btn-primary">Update Subscription</button>
                </div>
            </form>
        </section>
        @endcan

        {{-- Billing / Invoices card --}}
        @can('central.invoices.index')
        <section class="mu-panel p-0">
            <div class="d-flex justify-content-between align-items-center p-3 pb-2">
                <div>
                    <h2 class="mb-0">Billing</h2>
                    <p class="mu-panel-sub mb-0">The last 5 invoices.</p>
                </div>
                @can('central.tenants.invoices.create')
                    <a href="{{ url('/tenants/' . $tenant->id . '/invoices/create') }}" class="btn btn-sm btn-primary">
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>Create Invoice
                    </a>
                @endcan
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead>
                        <tr><th>Invoice #</th><th>Status</th><th>Due date</th><th class="text-end">Total</th><th class="text-end">Balance</th><th class="text-end">Action</th></tr>
                    </thead>
                    <tbody>
                    @forelse(($recentInvoices ?? []) as $invoice)
                        <tr>
                            <td><code>{{ $invoice->invoice_no }}</code></td>
                            <td>{{ ucfirst(str_replace('_',' ',$invoice->status)) }}</td>
                            <td>{{ $invoice->due_date ? \Illuminate\Support\Carbon::parse($invoice->due_date)->format('Y-m-d') : '—' }}</td>
                            <td class="text-end">{{ $invoice->currency_code }} {{ number_format((float) $invoice->total_amount, 2) }}</td>
                            <td class="text-end">{{ number_format((float) $invoice->balance_amount, 2) }}</td>
                            <td class="text-end"><a href="{{ url('/invoices/' . $invoice->id) }}" class="btn btn-sm btn-light">View</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-3">No invoices yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        @endcan
    </div>
</div>
