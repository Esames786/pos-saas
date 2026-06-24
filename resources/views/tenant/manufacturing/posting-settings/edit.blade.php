@extends('layouts.app')

@section('title', 'Edit Manufacturing Posting Settings')

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>Edit Posting Settings</h4>
                <h6>Map manufacturing posting accounts &amp; inventory policy</h6>
            </div>
        </div>

        <div class="alert alert-warning d-flex align-items-start gap-2">
            <i class="ti ti-alert-triangle fs-18 mt-1"></i>
            <div>
                <strong>Enabling these settings does not post anything yet.</strong> This is Phase A
                (configuration only). Posting (journal entries, stock movements, COGS) will be added in a later
                phase. Mappings are validated and stored; nothing is posted on save.
            </div>
        </div>

        @if($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ url('/manufacturing/posting-settings') }}">
            @csrf
            @method('PUT')

            <div class="card mb-3">
                <div class="card-body">
                    <div class="form-check form-switch">
                        <input type="hidden" name="is_enabled" value="0">
                        <input class="form-check-input" type="checkbox" role="switch" id="is_enabled" name="is_enabled" value="1"
                               {{ old('is_enabled', $setting->is_enabled) ? 'checked' : '' }}>
                        <label class="form-check-label" for="is_enabled">
                            <strong>Enable manufacturing posting settings</strong>
                            <span class="d-block text-muted small">Can only be enabled when all required (*) accounts are mapped. Enabling still posts nothing in this phase.</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h6 class="mb-0">Account mappings</h6></div>
                <div class="card-body">
                    <div class="row g-3">
                        @foreach($fields as $field => $meta)
                            <div class="col-md-6">
                                <label class="form-label mb-1">
                                    {{ $meta['label'] }}
                                    @if($meta['required'])<span class="text-danger">*</span>@else<span class="text-muted small">(optional)</span>@endif
                                </label>
                                <select name="{{ $field }}" class="select form-select @error($field) is-invalid @enderror">
                                    <option value="">— Not set —</option>
                                    @foreach($accounts->where('type', $meta['type']) as $acc)
                                        <option value="{{ $acc->id }}" {{ (string) old($field, $setting->{$field}) === (string) $acc->id ? 'selected' : '' }}>
                                            {{ $acc->code }} — {{ $acc->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="form-text">Must be a(n) {{ $meta['type'] }} account.</div>
                                @error($field)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h6 class="mb-0">Inventory policy</h6></div>
                <div class="card-body row g-3">
                    <div class="col-md-4">
                        <label class="form-label mb-1">Negative stock policy</label>
                        <select name="negative_stock_policy" class="form-select">
                            <option value="block" {{ old('negative_stock_policy', $setting->negative_stock_policy) === 'block' ? 'selected' : '' }}>Block (do not allow negative stock)</option>
                        </select>
                        <div class="form-text">Phase A: only “block” is supported.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label mb-1">Costing method</label>
                        <select name="costing_method" class="form-select">
                            <option value="moving_average" {{ old('costing_method', $setting->costing_method) === 'moving_average' ? 'selected' : '' }}>Moving weighted average</option>
                        </select>
                        <div class="form-text">Phase A: matches existing inventory costing.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label mb-1">Finished-goods cost source</label>
                        <select name="fg_cost_source" class="form-select">
                            <option value="wip_actual" {{ old('fg_cost_source', $setting->fg_cost_source) === 'wip_actual' ? 'selected' : '' }}>WIP actual cost</option>
                        </select>
                        <div class="form-text">Phase A: FG valued at accumulated WIP cost.</div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-body">
                    <label class="form-label mb-1">Notes</label>
                    <textarea name="notes" rows="2" class="form-control" maxlength="2000">{{ old('notes', $setting->notes) }}</textarea>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Save Settings</button>
                <a href="{{ url('/manufacturing/posting-settings') }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
@endsection
