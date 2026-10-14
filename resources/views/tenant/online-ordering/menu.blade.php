@extends('layouts.app')

@section('title', 'Website Menu')

@section('content')
@php
    $money = fn ($v) => 'Rs '.number_format((float) $v, 0);
    $tags = \App\Models\Tenant\Product::WEBSITE_TAGS;
    $cardCount = collect($menu)->sum(fn ($c) => count($c['items']));
@endphp

<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
    <div>
        <h1 class="mb-1">Website Menu</h1>
        <p class="text-muted mb-0">How your menu looks on the website. To change it, edit the product, deal or category — open its <strong>Website</strong> section.</p>
    </div>
    @if($branches->count() > 1)
        <form method="GET" action="{{ url('/online-ordering/menu') }}">
            <select name="branch" class="form-select" onchange="this.form.submit()">
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" @selected($branch && $branch->id === $b->id)>{{ $b->name }}</option>
                @endforeach
            </select>
        </form>
    @endif
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
    <span class="badge bg-light text-dark border">{{ count($menu) }} categories</span>
    <span class="badge bg-light text-dark border">{{ $cardCount }} cards</span>
    <span class="badge bg-light text-dark border">{{ $notOnWebsite->count() }} POS items not on the website</span>
</div>

@forelse($menu as $category)
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <strong>{{ $category['name'] }}</strong>
            <span class="text-muted small">
                @if($category['visible_from'] && $category['visible_to'])
                    <i class="ti ti-clock me-1"></i>Shown {{ substr($category['visible_from'], 0, 5) }}–{{ substr($category['visible_to'], 0, 5) }} only ·
                @endif
                {{ count($category['items']) }} cards
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th style="width: 34%">Card</th><th>Size → POS item</th><th class="text-end">POS</th><th class="text-end">Website</th><th>Add-ons</th></tr></thead>
                <tbody>
                @forelse($category['items'] as $card)
                    @foreach($card['options'] as $i => $o)
                        <tr class="{{ $o['available'] ? '' : 'table-warning' }}">
                            @if($i === 0)
                                <td rowspan="{{ count($card['options']) }}">
                                    <div class="d-flex gap-2 align-items-start">
                                        @if($card['image_path'])
                                            <img src="{{ asset('storage/'.$card['image_path']) }}" alt="" loading="lazy" style="width: 44px; height: 44px; object-fit: cover; border-radius: 6px;">
                                        @else
                                            <span class="d-inline-flex align-items-center justify-content-center bg-light text-muted" style="width: 44px; height: 44px; border-radius: 6px;" title="No photo"><i class="ti ti-photo-off"></i></span>
                                        @endif
                                        <div>
                                            <strong>{{ $card['name'] }}</strong>
                                            @if($card['tag'])<span class="badge bg-warning text-dark ms-1">{{ $tags[$card['tag']] ?? $card['tag'] }}</span>@endif
                                            @if($card['popular'])<span class="badge bg-info text-dark ms-1">Popular Items</span>@endif
                                            @if($card['description'])<div class="text-muted small">{{ \Illuminate\Support\Str::limit($card['description'], 80) }}</div>@endif
                                        </div>
                                    </div>
                                </td>
                            @endif
                            <td>
                                @if($o['label'])<span class="badge bg-light text-dark border me-1">{{ $o['label'] }}</span>@endif
                                <a href="{{ url($o['kind'] === 'combo' ? '/combos/'.$o['pos_id'].'/edit' : '/products/'.$o['pos_id'].'/edit') }}">{{ $o['pos_name'] }}</a>
                                @if($o['kind'] === 'combo')<span class="text-muted small">(deal)</span>@endif
                                @unless($o['available'])<span class="badge bg-warning text-dark ms-1">Not available at this branch</span>@endunless
                            </td>
                            <td class="text-end text-muted">{{ $money($o['pos_price']) }}</td>
                            <td class="text-end {{ (float) $o['price'] !== (float) $o['pos_price'] ? 'fw-semibold text-primary' : '' }}">{{ $money($o['price']) }}</td>
                            <td class="small">
                                @foreach($o['addons'] as $g)
                                    <div><strong>{{ $g['name'] }}</strong>{{ $g['required'] ? ' (required)' : '' }}:
                                        {{ collect($g['options'])->map(fn ($x) => $x['name'].($x['price'] > 0 ? ' +'.number_format($x['price'], 0) : ''))->implode(', ') }}</div>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                @empty
                    <tr><td colspan="5" class="text-muted">Nothing in this category is on the website yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@empty
    <div class="alert alert-info">Nothing is on the website yet. Tick <strong>Show on website</strong> on a category, then on its products and deals.</div>
@endforelse

<div class="card mt-4">
    <div class="card-header"><strong>On the POS, not on the website</strong> <span class="text-muted small">— active items your counter sells that customers cannot order online</span></div>
    <div class="table-responsive" style="max-height: 420px;">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Item</th><th>POS category</th><th></th></tr></thead>
            <tbody>
            @forelse($notOnWebsite as $row)
                <tr>
                    <td>{{ $row['name'] }} @if($row['kind'] === 'combo')<span class="text-muted small">(deal)</span>@endif</td>
                    <td class="text-muted">{{ $row['category'] ?? '—' }}</td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ url($row['kind'] === 'combo' ? '/combos/'.$row['id'].'/edit' : '/products/'.$row['id'].'/edit') }}">Edit</a></td>
                </tr>
            @empty
                <tr><td colspan="3" class="text-muted">Everything you sell is on the website.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
