@extends('layouts.app')

@section('title', 'Modules')

@section('content')
<div class="mu-head">
    <div>
        <p class="mu-eyebrow">Bingoo Master</p>
        <h1>Modules</h1>
        <p class="mu-sub">A module is a set of screens a plan can switch on. Plans decide which tenant gets which module.</p>
    </div>
    @can('central.plans.index')
        <a href="{{ url('/plans') }}" class="btn btn-light"><i class="ti ti-credit-card me-1" aria-hidden="true"></i>Plans</a>
    @endcan
</div>

@foreach($groups as $category => $items)
    <section class="mb-3">
        <h2 class="mu-section-title"><i class="ti ti-folder" aria-hidden="true"></i>{{ $category }} <span class="mu-count">{{ $items->count() }} {{ $items->count() === 1 ? 'module' : 'modules' }}</span></h2>
        <div class="mu-grid is-wide">
            @foreach($items as $row)
                @php
                    $module = $row['module'];
                    $main = $row['main_screens'];
                @endphp
                <article class="mu-module {{ $module->is_active ? '' : 'is-off' }}" aria-label="{{ $module->name }}">
                    <div class="mu-module-thumb">
                        @if($row['image'])
                            <img src="{{ $row['image'] }}" alt="{{ $module->name }} screen" loading="lazy">
                        @else
                            <i class="ti ti-puzzle" aria-hidden="true"></i>
                        @endif
                    </div>
                    <div class="mu-module-body">
                        <div class="mu-module-title">
                            <h3>{{ $module->name }}</h3>
                            <span class="mu-pill {{ $module->is_active ? 'is-on' : 'is-off' }}">{{ $module->is_active ? 'Active' : 'Inactive' }}</span>
                        </div>
                        <p class="mu-module-desc">{{ $row['description'] }}</p>
                        @include('central.partials.screen-shots', ['shots' => $row['newer'] ?? [], 'title' => 'Newer screens', 'small' => true])
                        <div class="mu-module-meta">
                            <span>{{ $row['screens'] }} screens · {{ $row['permissions'] }} permissions</span>
                            <span>{{ $row['plans'] }} {{ $row['plans'] === 1 ? 'plan' : 'plans' }}</span>
                            <span>{{ $row['tenants'] }} {{ $row['tenants'] === 1 ? 'tenant' : 'tenants' }}</span>
                            <span>{{ $module->is_core ? 'Core' : 'Optional' }}</span>
                        </div>
                        @if($main !== [])
                            <p class="mu-module-screens"><b>Screens:</b> {{ implode(', ', array_slice($main, 0, 6)) }}@if(count($main) > 6) and {{ count($main) - 6 }} more @endif</p>
                        @endif
                    </div>
                    <div class="mu-module-foot">
                        <code class="small">{{ $module->key }}</code>
                        @can('central.modules.edit')
                            <a href="{{ url('/modules/' . $module->id . '/edit') }}" class="btn btn-sm btn-primary">Edit</a>
                        @endcan
                    </div>
                </article>
            @endforeach
        </div>
    </section>
@endforeach

@if($groups->isEmpty())
    <div class="mu-empty"><i class="ti ti-puzzle" aria-hidden="true"></i>No modules yet.</div>
@endif

<p class="mu-note mt-2"><i class="ti ti-info-circle me-1" aria-hidden="true"></i>Screens = pages a person can open (GET routes). Permissions = every action the module allows (one per route). Both are counted from the route catalogue, which every deploy refreshes.</p>
@endsection
