{{--
    W3/W4 S1 — what the system does with an item, one badge per behaviour that is true. Expects: $product.
    "On the counter" is the rule the till uses (Product::isPosVisible: active, sold, shown on the counter).
--}}
@if($product->is_purchasable)
    <span class="badge bg-light text-dark border">Bought</span>
@endif
@if($product->is_sellable)
    <span class="badge bg-light text-dark border">Sold</span>
@endif
@if($product->is_stock_tracked)
    <span class="badge bg-light text-dark border">Stock counted</span>
@endif
@if($product->isPosVisible())
    <span class="badge bg-success">On the counter</span>
@endif
@if($product->inventory_consumption_method === 'recipe')
    <span class="badge bg-info text-dark">Recipe-based</span>
@endif
