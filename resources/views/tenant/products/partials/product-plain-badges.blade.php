{{--
    PRODUCT-LIST-PLAIN-1 — where an item shows, in plain words (lists where the new product screen is on).
    Expects: $product. Same facts as product-role-badges, no jargon.
--}}
@if($product->is_sellable && $product->is_pos_visible)
    <span class="badge bg-success">{{ $product->status === 'active' ? 'On the counter' : 'On the counter when in use' }}</span>
@elseif($product->is_sellable)
    <span class="badge bg-warning text-dark" title="Sold inside deals, as an add-on or online — no button on the counter">Sold, no counter button</span>
@else
    <span class="badge bg-light text-dark border">Not sold — used inside</span>
@endif
@if($product->is_stock_tracked)
    <span class="badge bg-light text-dark border">Stock counted</span>
@endif
@if($product->is_purchasable)
    <span class="badge bg-light text-dark border">Bought</span>
@endif
@if($product->inventory_consumption_method === 'recipe')
    <span class="badge bg-info text-dark">Recipe</span>
@endif
