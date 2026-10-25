{{-- PRODUCT-NAV-1: "Edit in product form" on a specialized setup list. It opens a new tab, so the list stays where it
     was; it shows only when ProductFormLink allows it (new product screen on, and the user may open product edit). --}}
@php
    $productFormUrl = \App\Support\ProductFormLink::to($productId ?? null, $tab);
@endphp
@if($productFormUrl)
    <a href="{{ $productFormUrl }}" target="_blank" rel="noopener" class="{{ $class ?? 'btn btn-sm btn-outline-primary' }}" data-edit-in-product
       title="Opens the product form in a new tab">Edit in product form</a>
@endif
