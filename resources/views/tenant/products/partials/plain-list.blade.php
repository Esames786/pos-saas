{{--
    W3/W4 S1 — the product list where the new product screen is on. Three things kept apart:
      STRUCTURE — how the item is built (Simple, With variants, or a Combo — a deal);
      ROLE      — what it is for the business (the role names a shop recognises);
      BEHAVIOUR — what the system does with it (Bought, Sold, Stock counted, On the counter, Recipe-based).
    Each behaviour is one question with three answers (Any / Yes / No), so two filters can never contradict.
    Presentation and filtering only — nothing here changes a product's role or flags.
    Expects (ProductController@index): $products, $categories, $indexUrl, $base, $roleOptions, $plainRoleNames,
    $plainStructure, $plainRole, $plainBeh, $plainBehaviours, $plainPacksOn, $plainCombos, $plainDealCount.
--}}
@php
    $beh = $plainBeh ?? [];
    $moreOpen = ($plainStructure ?? null) !== null || $beh !== [];
    $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
    $packWords = fn ($p) => $p->relationLoaded('packUnits')
        ? $p->packUnits->where('factor', '>', 1)->sortByDesc('factor')
            ->map(fn ($k) => $k->name . ' = ' . $num($k->factor) . ' ' . ($p->unit?->name ?: 'pieces'))->implode(' · ')
        : '';
    $structures = ['' => 'Any', 'simple' => 'Simple', 'variants' => 'With variants', 'combo' => 'Combo'];
    $answers = ['' => 'Any', 'yes' => 'Yes', 'no' => 'No'];
@endphp

<div class="card mb-3">
    <div class="card-body">
        <div class="row g-2 small text-muted mb-3" id="plain-concepts">
            <div class="col-md-4"><b class="text-dark">Structure</b> — how the item is built: Simple, With variants, or a Combo (a deal).</div>
            <div class="col-md-4"><b class="text-dark">Role</b> — what it is for the business: Sale item, Raw material, Packaging, Semi-finished, Finished good, Service.</div>
            <div class="col-md-4"><b class="text-dark">Behaviour</b> — what the system does with it: Bought, Sold, Stock counted, On the counter, Recipe-based.</div>
        </div>
        <form method="GET" action="{{ $indexUrl }}" id="plain-filters">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label for="prod-search" class="form-label">Search</label>
                    <input id="prod-search" type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="Name, SKU or barcode">
                </div>
                <div class="col-md-3">
                    <label for="filter-category" class="form-label">Category</label>
                    <select id="filter-category" name="category_id" class="form-select">
                        <option value="">All categories</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" @selected(request('category_id') == $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="filter-role" class="form-label">Role</label>
                    <select id="filter-role" name="role" class="form-select">
                        <option value="">All roles</option>
                        @foreach($roleOptions as $kind => $label)
                            <option value="{{ $kind }}" @selected(($plainRole ?? null) === $kind)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="filter-status" class="form-label">Status</label>
                    <select id="filter-status" name="status" class="form-select">
                        <option value="">All</option>
                        <option value="active" @selected(request('status') === 'active')>Active</option>
                        <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
                    </select>
                </div>
                <div class="col-md-1 d-grid">
                    <button class="btn btn-dark">Filter</button>
                </div>
            </div>

            <details class="mt-3" id="more-filters" @if($moreOpen) open @endif>
                <summary class="fw-semibold">More filters</summary>
                <p class="small text-muted mt-2 mb-2">Each behaviour is one question with three answers, so two filters can never contradict each other.</p>
                <div class="d-flex flex-wrap gap-4">
                    <fieldset>
                        <legend class="small fw-semibold mb-1" style="font-size:.8rem">Structure</legend>
                        <div class="btn-group btn-group-sm" role="group">
                            @foreach($structures as $value => $label)
                                <input type="radio" class="btn-check" name="structure" id="structure-{{ $value ?: 'any' }}" value="{{ $value }}" @checked(($plainStructure ?? '') === $value)>
                                <label class="btn btn-outline-secondary" for="structure-{{ $value ?: 'any' }}">{{ $label }}</label>
                            @endforeach
                        </div>
                    </fieldset>
                    @foreach($plainBehaviours as $key => $label)
                        <fieldset>
                            <legend class="small fw-semibold mb-1" style="font-size:.8rem">{{ $label }}</legend>
                            <div class="btn-group btn-group-sm" role="group">
                                @foreach($answers as $value => $answer)
                                    <input type="radio" class="btn-check" name="beh[{{ $key }}]" id="beh-{{ $key }}-{{ $value ?: 'any' }}" value="{{ $value }}" @checked(($beh[$key] ?? '') === $value)>
                                    <label class="btn btn-outline-secondary" for="beh-{{ $key }}-{{ $value ?: 'any' }}">{{ $answer }}</label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach
                </div>
                <a href="{{ $indexUrl }}" class="btn btn-sm btn-light mt-3">Clear all filters</a>
            </details>
        </form>
    </div>
</div>

@if(($plainDealCount ?? 0) > 0)
    @can('tenant.combos.index')
        <div class="alert alert-light border small py-2" id="plain-deals-note">
            Deals are listed under Structure "Combo": {{ $plainDealCount }} {{ $plainDealCount === 1 ? 'deal' : 'deals' }}.
            <a href="{{ $indexUrl . '?structure=combo' }}">Show deals</a>
        </div>
    @endcan
@endif

<div class="card">
    <div class="card-body table-responsive">
        @if($plainCombos)
            <table class="table align-middle" id="plain-deals">
                <caption class="visually-hidden">Deals</caption>
                <thead>
                    <tr><th scope="col">Deal</th><th scope="col">Structure</th><th scope="col">Category</th><th scope="col" class="text-end">Price</th><th scope="col">Status</th><th scope="col" class="text-end">Action</th></tr>
                </thead>
                <tbody>
                @forelse($plainCombos as $combo)
                    <tr>
                        <td><b>{{ $combo->name }}</b><div class="small text-muted"><code>{{ $combo->code }}</code></div></td>
                        <td>Combo<div class="small text-muted">a deal · {{ $combo->components_count }} {{ $combo->components_count === 1 ? 'item' : 'items' }}</div></td>
                        <td>{{ $combo->category?->name ?? '—' }}</td>
                        <td class="text-end">{{ number_format((float) $combo->price, 2) }}</td>
                        <td>@if($combo->status === 'active')<span class="badge bg-success">Active</span>@else<span class="badge bg-secondary">Inactive</span>@endif</td>
                        <td class="text-end">
                            @can('tenant.combos.edit')
                                <a href="{{ url('/combos/' . $combo->id . '/edit') }}" class="btn btn-sm btn-outline-primary">Open deal</a>
                                <div class="small text-muted">existing deal editor</div>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No deals found.</td></tr>
                @endforelse
                </tbody>
            </table>
            <p class="small text-muted mb-0">A deal is its own record: "Open deal" opens the existing deal editor. A deal is never copied into an ordinary product.</p>
            <div class="mt-3">{{ $plainCombos->links() }}</div>
        @else
            <table class="table align-middle" id="plain-products">
                <caption class="visually-hidden">Products list</caption>
                <thead>
                    <tr>
                        <th scope="col">Product</th>
                        <th scope="col">Structure</th>
                        <th scope="col">Role</th>
                        <th scope="col">Behaviour</th>
                        @if($plainPacksOn ?? false)<th scope="col">Packs</th>@endif
                        <th scope="col">Category</th>
                        <th scope="col" class="text-end">Sell price</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($products as $product)
                    <tr>
                        <td><a href="{{ url('/products/' . $product->id) }}"><b>{{ $product->name }}</b></a><div class="small text-muted"><code>{{ $product->sku }}</code></div></td>
                        <td>{{ $product->has_variants ? 'With variants' : 'Simple' }}</td>
                        <td><span class="badge bg-light text-dark border" data-role="{{ $product->product_kind ?: 'sale_item' }}">{{ $plainRoleNames[$product->product_kind ?: 'sale_item'] ?? ucfirst(str_replace('_', ' ', (string) $product->product_kind)) }}</span></td>
                        <td><span class="d-inline-flex flex-wrap gap-1">@include('tenant.products.partials.product-behaviour-badges')</span></td>
                        @if($plainPacksOn ?? false)<td class="small">{{ $packWords($product) ?: '—' }}</td>@endif
                        <td>{{ $product->category?->name ?? '—' }}</td>
                        <td class="text-end">{{ number_format((float) $product->default_selling_price, 2) }}</td>
                        <td>@if($product->status === 'active')<span class="badge bg-success">Active</span>@else<span class="badge bg-secondary">Inactive</span>@endif</td>
                        <td class="text-end text-nowrap">
                            @can('tenant.products.show')
                                <a href="{{ url('/products/' . $product->id) }}" class="btn btn-sm btn-light">View</a>
                            @endcan
                            @can('tenant.products.edit')
                                <a href="{{ url($base . '/' . $product->id . '/edit') }}" class="btn btn-sm btn-primary">Edit</a>
                            @endcan
                            @can('tenant.products.destroy')
                                <form method="POST" action="{{ url('/products/' . $product->id) }}" class="d-inline" onsubmit="return confirm('Delete this product?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ ($plainPacksOn ?? false) ? 9 : 8 }}" class="text-center text-muted py-4">No products found.</td></tr>
                @endforelse
                </tbody>
            </table>
            <p class="small text-muted mb-0">Nothing on this screen changes a product's role or its saved settings — it only shows them more clearly.</p>
            <div class="mt-3">{{ $products->links() }}</div>
        @endif
    </div>
</div>
