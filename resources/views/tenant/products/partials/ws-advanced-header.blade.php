{{-- Compact advanced fields for Recipe/BOM. Collapsing never disables or clears inputs. --}}
@php
    $fields = $section === 'recipe' ? [
        'name' => ['Recipe name', 'text', 190], 'doc_no' => ['Document number', 'text', 100],
        'recipe_no' => ['Recipe number', 'text', 100], 'revision_no' => ['Revision', 'number', null],
        'review_date' => ['Review date', 'date', null], 'overhead_percent' => ['Overhead (%)', 'number', null],
    ] : [
        'bom_no' => ['BOM number', 'text', 50], 'name' => ['BOM name', 'text', 255],
        'version' => ['Version', 'text', 50], 'effective_from' => ['Effective from', 'date', null],
    ];
    $value = fn ($key) => $fromDb ? ($state[$key] ?? '') : old($section . '.' . $key, $state[$key] ?? '');
@endphp
<details class="ws-advanced mb-3" @if($messages->isNotEmpty()) open @endif>
    <summary>Advanced settings <span class="help">— status, references and notes</span></summary>
    <div class="row g-3 mt-0">
        @foreach($fields as $key => [$label, $type, $length])
            <div class="col-sm-6 col-lg-4">
                <label class="form-label" for="{{ $section }}-{{ $key }}">{{ $label }}</label>
                <input id="{{ $section }}-{{ $key }}" name="{{ $section }}[{{ $key }}]" type="{{ $type }}"
                    class="form-control" value="{{ $value($key) }}"
                    @if($length) maxlength="{{ $length }}" @endif
                    @if($key === 'revision_no') min="1" step="1" @endif
                    @if($key === 'overhead_percent') min="0" max="1000" step="0.0001" @endif
                    @if($key === 'name' && empty($state['name'])) placeholder="Uses the product name when creating" @endif
                    @if($key === 'bom_no' && empty($state['bom_no'])) placeholder="Assigned when saved" @endif>
            </div>
        @endforeach
        <div class="col-sm-6 col-lg-4">
            @if($section === 'recipe')
                <label class="form-label" for="recipe-active">Recipe status</label>
                <select id="recipe-active" class="form-select" name="recipe[is_active]">
                    <option value="1" @selected((bool) $value('is_active'))>Active</option>
                    <option value="0" @selected(! (bool) $value('is_active'))>Inactive</option>
                </select>
            @else
                <label class="form-label" for="bom-status">BOM status</label>
                <select id="bom-status" class="form-select" name="bom[status]">
                    @foreach(\App\Models\Tenant\ManufacturingBom::STATUSES as $status)
                        <option value="{{ $status }}" @selected($value('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            @endif
            <div class="help">Making this active switches off other active {{ $section === 'recipe' ? 'recipes' : 'BOMs' }} for this product.</div>
        </div>
        <div class="col-12">
            <label class="form-label" for="{{ $section }}-notes">Notes</label>
            <textarea id="{{ $section }}-notes" class="form-control" rows="2" name="{{ $section }}[notes]" @if($section === 'bom') maxlength="3000" @endif>{{ $value('notes') }}</textarea>
        </div>
    </div>
</details>
