{{--
    ONLINE-ORDERING-1 — the "Website" card of the product and deal forms (owner, 15 Oct: its own card at the
    bottom; the details open only when "Show on website" is ticked).
    Rendered only on a plan with Online Ordering. Nothing here changes the POS:
    POS name, price, photo and category stay as they are.
    Expects: $model (Product|Combo|null), $kind ('product'|'combo'),
             $websiteCategories, $websiteAddonGroups.
    Hidden details still post their values, so unticking never loses them.
--}}
@php
    $wm = $model;
    $selectedGroups = $kind === 'combo' && $wm && $wm->exists ? $wm->modifierGroups->pluck('id')->all() : [];
    $onWebsite = (bool) old('show_on_website', $wm?->show_on_website);
    $websiteErrors = $errors->hasAny(['website_price', 'website_card_name', 'website_size_label', 'website_category_id', 'website_tag', 'website_sort_order', 'website_image', 'website_description']);
@endphp
<input type="hidden" name="_website" value="1">
<div class="card border mt-4 mb-2" id="website-card">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <h5 class="mb-0"><i class="ti ti-world-www me-1"></i>Website</h5>
                <div class="form-text mt-0">Your online ordering website. The POS is not affected.</div>
            </div>
            <div class="form-check form-switch m-0">
                <input type="checkbox" value="1" name="show_on_website" id="show_on_website" class="form-check-input" {{ $onWebsite ? 'checked' : '' }}>
                <label class="form-check-label fw-semibold" for="show_on_website">Show on website</label>
            </div>
        </div>

        <div class="row g-3 mt-1" id="website-details" @if(! $onWebsite && ! $websiteErrors) hidden @endif>
            <div class="col-md-3">
                <label for="website_price" class="form-label">Website price</label>
                <input id="website_price" type="number" name="website_price" step="0.01" min="0"
                       value="{{ old('website_price', $wm?->website_price) }}"
                       class="form-control @error('website_price') is-invalid @enderror"
                       placeholder="{{ $kind === 'combo' ? 'Same as deal price' : 'Same as POS price' }}">
                @error('website_price') <div class="invalid-feedback">{{ $message }}</div> @enderror
                <div class="form-text">Leave empty to use the POS price.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="website_card_name">Card name</label>
                <input type="text" name="website_card_name" id="website_card_name" class="form-control" maxlength="190"
                       value="{{ old('website_card_name', $wm?->website_card_name) }}" placeholder="{{ $wm?->name ?: 'Same as name' }}">
                <div class="form-text">Items with the same card name become one card with sizes.</div>
            </div>
            {{-- Owner (15 Oct): a dropdown of the sizes already in use (+ common ones); "New size…" for anything else.
                 The text box keeps the name the form posts, so saving is unchanged. --}}
            @php
                $sizeNow = (string) old('website_size_label', $wm?->website_size_label);
                $sizeList = $websiteSizeLabels ?? [];
                $sizeOther = $sizeNow !== '' && ! in_array(mb_strtolower($sizeNow), array_map('mb_strtolower', $sizeList), true);
            @endphp
            <div class="col-md-2">
                <label class="form-label" for="website_size_pick">Size on card</label>
                <select id="website_size_pick" class="form-select" aria-describedby="website_size_help">
                    <option value="">— No size —</option>
                    @foreach($sizeList as $label)
                        <option value="{{ $label }}" @selected(! $sizeOther && mb_strtolower($label) === mb_strtolower($sizeNow))>{{ $label }}</option>
                    @endforeach
                    <option value="__new" @selected($sizeOther)>+ New size…</option>
                </select>
                <input type="text" name="website_size_label" id="website_size_label" class="form-control mt-1" maxlength="80"
                       value="{{ $sizeNow }}" placeholder="Type the new size" @if(! $sizeOther) hidden @endif>
                <div class="form-text" id="website_size_help">Shown on the card's size buttons.</div>
            </div>
            <script>
            (function () {
                'use strict';
                var pick = document.getElementById('website_size_pick'), box = document.getElementById('website_size_label');
                if (!pick || !box) { return; }
                pick.addEventListener('change', function () {
                    var other = pick.value === '__new';
                    box.hidden = !other;
                    box.value = other ? '' : pick.value;
                    if (other) { box.focus(); }
                });
            })();
            </script>
            <div class="col-md-4">
                <label class="form-label" for="website_category_id">Website category</label>
                <select name="website_category_id" id="website_category_id" class="form-select">
                    <option value="">— Same as POS category —</option>
                    @foreach($websiteCategories as $c)
                        <option value="{{ $c->id }}" @selected((int) old('website_category_id', $wm?->website_category_id) === $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
                <div class="form-text">Only where the website shows it. The POS category does not change.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="website_tag">Tag</label>
                <select name="website_tag" id="website_tag" class="form-select">
                    <option value="">— None —</option>
                    @foreach(\App\Models\Tenant\Product::WEBSITE_TAGS as $k => $label)
                        <option value="{{ $k }}" @selected(old('website_tag', $wm?->website_tag) === $k)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label d-block">&nbsp;</label>
                <div class="form-check mt-2">
                    <input type="checkbox" value="1" name="website_popular" id="website_popular" class="form-check-input"
                           {{ old('website_popular', $wm?->website_popular) ? 'checked' : '' }}>
                    <label class="form-check-label" for="website_popular">In "Popular Items"</label>
                </div>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="website_sort_order">Website order</label>
                <input type="number" min="0" name="website_sort_order" id="website_sort_order" class="form-control"
                       value="{{ old('website_sort_order', $wm?->website_sort_order) }}">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="website_image">Website photo</label>
                <input type="file" name="website_image" id="website_image" class="form-control" accept="image/jpeg,image/png,image/webp">
                @if($wm?->website_image_path)
                    <div class="d-flex align-items-center gap-2 mt-2">
                        <img src="{{ asset('storage/'.$wm->website_image_path) }}" alt="" style="width: 48px; height: 48px; object-fit: cover; border-radius: 6px;">
                        <label class="form-check mb-0"><input type="checkbox" value="1" name="remove_website_image" class="form-check-input"> <span class="form-check-label small">Remove</span></label>
                    </div>
                @endif
                <div class="form-text">Only for the website — the POS tile keeps its own photo.</div>
            </div>
            <div class="col-12">
                <label class="form-label" for="website_description">Website description</label>
                <textarea name="website_description" id="website_description" rows="2" class="form-control" maxlength="1000"
                          placeholder="e.g. 8 pcs spicy wings">{{ old('website_description', $wm?->website_description) }}</textarea>
            </div>
            @if($kind === 'combo')
                <input type="hidden" name="_website_addons" value="1">
                <div class="col-12">
                    <label class="form-label">Website add-ons</label>
                    @forelse($websiteAddonGroups as $g)
                        <div class="form-check form-check-inline">
                            <input type="checkbox" class="form-check-input" name="website_addon_groups[]" value="{{ $g->id }}" id="wag-{{ $g->id }}"
                                   {{ in_array($g->id, old('website_addon_groups', $selectedGroups)) ? 'checked' : '' }}>
                            <label class="form-check-label" for="wag-{{ $g->id }}">{{ $g->name }}
                                <span class="text-muted small">({{ \App\Models\Tenant\ModifierGroup::CHANNELS[$g->channel] ?? $g->channel }})</span></label>
                        </div>
                    @empty
                        <div class="text-muted small">No add-on group is set to show on the website. Create one under Modifier Groups with "Show on: Website only".</div>
                    @endforelse
                    <div class="form-text">Customers choose these on the website (e.g. which drink). The POS deal does not change.</div>
                </div>
            @endif
        </div>
    </div>
</div>
<script>
(function () {
    'use strict';
    var t = document.getElementById('show_on_website'), d = document.getElementById('website-details');
    if (!t || !d) { return; }
    t.addEventListener('change', function () { d.hidden = !t.checked; });
})();
</script>
