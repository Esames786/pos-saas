{{--
    PRODUCT-BOUNDARY-2 - product role / visibility badges.
    Expects: $product (App\Models\Tenant\Product).
    Optional: $showKind (default true), $badgeContext (catalog|manufacturing|full).
--}}
@php
    $showKind = $showKind ?? true;
    $badgeContext = $badgeContext ?? 'full';
    $showManufacturingBadges = in_array($badgeContext, ['manufacturing', 'full'], true);
@endphp
@if($showKind)
    <span class="badge {{ $product->productKindBadgeClass() }}">{{ $product->productKindLabel() }}</span>
@endif
@if($product->isPosVisible())
    <span class="badge bg-success">POS Visible</span>
@else
    <span class="badge bg-danger">Hidden from POS</span>
@endif
@if($product->is_sellable)
    <span class="badge bg-primary">Sellable</span>
@endif
@if($product->is_stock_tracked)
    <span class="badge bg-light text-dark border">Stock Tracked</span>
@endif
@if($product->inventory_consumption_method === 'recipe')
    <span class="badge bg-info text-dark">Recipe Item</span>
@endif
@if($product->product_type === 'service' || $product->product_kind === \App\Models\Tenant\Product::KIND_SERVICE)
    <span class="badge bg-secondary">Service Item</span>
@endif
@if($showManufacturingBadges && $product->can_be_bom_component)
    <span class="badge bg-warning text-dark">BOM Component</span>
@endif
@if($showManufacturingBadges && $product->can_be_bom_output)
    <span class="badge bg-info text-dark">BOM Output</span>
@endif
@if($showManufacturingBadges && $product->is_manufactured_finished_good)
    <span class="badge bg-primary">Manufactured FG</span>
@endif
