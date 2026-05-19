@extends('layouts.app')

@section('title', 'Bulk Import Products')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">Bulk Import Products</h1>
    <a href="{{ url('/products') }}" class="btn btn-light">Back</a>
</div>

@if(session('status'))
    <div class="alert alert-success" role="alert" aria-live="polite">{{ session('status') }}</div>
@endif

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header"><strong>Upload CSV File</strong></div>
            <div class="card-body">
                <form method="POST" action="{{ url('/products-bulk-import') }}" enctype="multipart/form-data" novalidate>
                    @csrf
                    <div class="mb-3">
                        <label for="csv_file" class="form-label required">CSV File</label>
                        <input id="csv_file" type="file" name="csv_file" accept=".csv,.txt"
                               class="form-control @error('csv_file') is-invalid @enderror" required>
                        @error('csv_file') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-help">Maximum file size: 4 MB.</div>
                    </div>

                    <button type="submit" class="btn btn-primary">Import Products</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><strong>CSV Format Guide</strong></div>
            <div class="card-body">
                <p class="mb-2">The CSV file must have these columns in the header row:</p>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <caption class="visually-hidden">Required CSV columns</caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Column</th>
                                <th scope="col">Required</th>
                                <th scope="col">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td><code>sku</code></td><td>Yes</td><td>Unique product code</td></tr>
                            <tr><td><code>name</code></td><td>Yes</td><td>Product name</td></tr>
                            <tr><td><code>category</code></td><td>Yes</td><td>Category name (auto-created)</td></tr>
                            <tr><td><code>unit</code></td><td>Yes</td><td>Unit code (auto-created)</td></tr>
                            <tr><td><code>product_type</code></td><td>Yes</td><td>simple / recipe / hybrid / service</td></tr>
                            <tr><td><code>purchase_price</code></td><td>Yes</td><td>Numeric value</td></tr>
                            <tr><td><code>selling_price</code></td><td>Yes</td><td>Numeric value</td></tr>
                            <tr><td><code>barcode</code></td><td>Yes</td><td>Leave blank to skip</td></tr>
                        </tbody>
                    </table>
                </div>

                <p class="mt-2 mb-1 fw-medium">Example row:</p>
                <pre class="bg-light p-2 rounded small">sku,name,category,unit,product_type,purchase_price,selling_price,barcode
COLA-500,Coca Cola 500ml,Beverages,PCS,simple,40,60,6001234567890</pre>

                <p class="text-muted small mb-0">Existing products (matched by SKU) will be updated. New SKUs will be created.</p>
            </div>
        </div>
    </div>
</div>
@endsection
