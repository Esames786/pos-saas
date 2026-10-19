{{-- MASTER-UNIVERSE-1 — Modules: what this tenant's plan switches on, in plain words. Read-only here:
     the plan decides the modules; the button opens the plan builder. --}}
@php
    $plan = $tenant->subscription?->plan;
    $onCount = $modules->filter(fn ($m) => in_array($m['module']->key, $planModules, true))->count();
@endphp
<section class="mu-panel">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h2>Modules from the plan</h2>
            <p class="mu-panel-sub mb-0">
                @if($plan)
                    Plan <strong>{{ $plan->name }}</strong> switches on <strong>{{ $onCount }}</strong> of {{ $modules->count() }} modules.
                    To turn one on or off, change the plan — every tenant on that plan changes with it.
                @else
                    This tenant has <strong>no plan</strong>, so no module is on. Choose a plan in <em>Billing &amp; invoices</em>.
                @endif
            </p>
        </div>
        @if($plan)
            @can('central.plans.edit')
                <a href="{{ url('/plans/' . $plan->id . '/edit') }}" class="btn btn-outline-primary"><i class="ti ti-adjustments me-1" aria-hidden="true"></i>Open plan builder</a>
            @endcan
        @endif
    </div>
</section>

<div class="mu-grid is-wide">
    @foreach($modules as $m)
        @php
            $on = in_array($m['module']->key, $planModules, true);
            $main = $m['main_screens'];
        @endphp
        <article class="mu-module {{ $on ? 'is-on' : 'is-off' }}" aria-label="{{ $m['module']->name }}: {{ $on ? 'on' : 'off' }}">
            <div class="mu-module-thumb">
                @if($m['image'])
                    <img src="{{ $m['image'] }}" alt="{{ $m['module']->name }} screen" loading="lazy">
                @else
                    <i class="ti ti-puzzle" aria-hidden="true"></i>
                @endif
            </div>
            <div class="mu-module-body">
                <div class="mu-module-title">
                    <h3>{{ $m['module']->name }}</h3>
                    <span class="mu-pill {{ $on ? 'is-on' : 'is-off' }}">{{ $on ? 'ON' : 'OFF' }}</span>
                </div>
                <p class="mu-module-desc">{{ $m['description'] }}</p>
                <div class="mu-module-meta">
                    <span>{{ $m['screens'] }} screens · {{ $m['permissions'] }} permissions</span>
                    @if($m['module']->is_core)
                        <span>Core</span>
                    @endif
                </div>
                @if($main !== [])
                    <p class="mu-module-screens">
                        <b>Turning this on gives these screens in the sidebar:</b>
                        {{ implode(', ', array_slice($main, 0, 8)) }}@if(count($main) > 8) and {{ count($main) - 8 }} more @endif
                    </p>
                @else
                    <p class="mu-module-screens">No list screens are mapped to this module yet.</p>
                @endif
            </div>
        </article>
    @endforeach
</div>
