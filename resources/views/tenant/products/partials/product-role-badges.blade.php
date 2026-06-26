{{--
    PRODUCT-BOUNDARY-2 — product role / visibility badges.
    Expects: $product (App\Models\Tenant\Product). Optional: $showKind (default true).
--}}
@php $showKind = $showKind ?? true; @endphp
@if($showKind)
    <span class="badge {{ $product->productKindBadgeClass() }}">{{ $product->productKindLabel() }}</span>
@endif
@if($product->isPosVisible())
    <span class="badge bg-success">POS Visible</span>
@else
    <span class="badge bg-danger">Hidden from POS</span>
@endif
@if($product->can_be_bom_component)
    <span class="badge bg-warning text-dark">BOM Component</span>
@endif
@if($product->can_be_bom_output)
    <span class="badge bg-info text-dark">BOM Output</span>
@endif
@if($product->is_manufactured_finished_good)
    <span class="badge bg-primary">Manufactured FG</span>
@endif
