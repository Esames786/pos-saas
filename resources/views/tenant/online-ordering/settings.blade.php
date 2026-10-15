@extends('layouts.app')

@section('title', 'Website Settings')

@section('content')
@php
    $hours = $settings->hours ?: \App\Models\Tenant\WebSetting::defaultHours();
    $routeVal = function ($branchId, $type, $field) use ($routes) {
        $r = $routes->get($branchId.'.'.$type);
        return old("routes.$branchId.$type.$field", $r?->{$field});
    };
@endphp

<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
    <div>
        <h1 class="mb-1">Website Settings</h1>
        <p class="text-muted mb-0">Your own ordering website: when it is open, which terminal rings for a new order, and what the customer is told on WhatsApp.</p>
    </div>
    @can('tenant.online-ordering.areas.index')
        <a href="{{ url('/online-ordering/areas') }}" class="btn btn-outline-primary"><i class="ti ti-map-pin me-1"></i>Delivery areas</a>
    @endcan
</div>

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ url('/online-ordering/settings') }}">
    @csrf
    @method('PUT')

    <div class="card mb-3">
        <div class="card-body">
            <h5 class="mb-3">Website</h5>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Taking orders</label>
                    <div class="form-check form-switch mt-2">
                        <input type="checkbox" value="1" name="is_enabled" id="is-enabled" class="form-check-input"
                               {{ old('is_enabled', $settings->is_enabled) ? 'checked' : '' }}>
                        <label class="form-check-label" for="is-enabled">Website is on</label>
                    </div>
                    <div class="form-text">Off = the website shows the menu but takes no orders.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="branch-mode">Which branch serves a customer</label>
                    <select name="branch_mode" id="branch-mode" class="form-select">
                        <option value="area" @selected(old('branch_mode', $settings->branch_mode) === 'area')>By delivery area</option>
                        <option value="single" @selected(old('branch_mode', $settings->branch_mode) === 'single')>One branch for everything</option>
                    </select>
                    <div class="form-text">By area: each delivery area picks its branch on the Delivery areas page.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="default-branch">Website branch</label>
                    <select name="default_branch_id" id="default-branch" class="form-select">
                        <option value="">— Choose —</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" @selected((int) old('default_branch_id', $settings->default_branch_id) === $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">Needed for "One branch for everything", and used for Pick-Up.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="contact-phone">Contact number for customers</label>
                    <input type="text" name="contact_phone" id="contact-phone" class="form-control" maxlength="40"
                           value="{{ old('contact_phone', $settings->contact_phone) }}" placeholder="0300 0000000">
                    <div class="form-text">Shown on the website and in WhatsApp messages.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="eta-delivery">Delivery time (default)</label>
                    <div class="input-group">
                        <input type="number" name="default_eta_minutes" id="eta-delivery" class="form-control" min="5" max="240"
                               value="{{ old('default_eta_minutes', $settings->default_eta_minutes) }}" required>
                        <span class="input-group-text">min</span>
                    </div>
                    <div class="form-text">An area's own time on the Delivery areas page wins.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="eta-pickup">Pick-Up ready in</label>
                    <div class="input-group">
                        <input type="number" name="pickup_eta_minutes" id="eta-pickup" class="form-control" min="5" max="240"
                               value="{{ old('pickup_eta_minutes', $settings->pickup_eta_minutes) }}" required>
                        <span class="input-group-text">min</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h5 class="mb-1">Opening hours</h5>
            <p class="text-muted small mb-3">Outside these hours the website says it is closed. A closing time earlier than the opening time means after midnight (12:00 → 04:00).</p>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-3" style="max-width: 640px;">
                    <thead><tr><th>Day</th><th>Opens</th><th>Closes</th><th>Closed all day</th></tr></thead>
                    <tbody>
                    @foreach(\App\Models\Tenant\WebSetting::DAYS as $day => $label)
                        @php $h = $hours[$day] ?? ['open' => null, 'close' => null, 'closed' => false]; @endphp
                        <tr>
                            <td>{{ $label }}</td>
                            <td><input type="time" name="hours[{{ $day }}][open]" class="form-control form-control-sm" value="{{ old("hours.$day.open", $h['open']) }}"></td>
                            <td><input type="time" name="hours[{{ $day }}][close]" class="form-control form-control-sm" value="{{ old("hours.$day.close", $h['close']) }}"></td>
                            <td><input type="checkbox" value="1" name="hours[{{ $day }}][closed]" class="form-check-input" {{ old("hours.$day.closed", $h['closed']) ? 'checked' : '' }}></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <label class="form-label" for="closed-message">Message when closed</label>
            <input type="text" name="closed_message" id="closed-message" class="form-control" maxlength="255" style="max-width: 640px;"
                   value="{{ old('closed_message', $settings->closed_message) }}" placeholder="Sorry, we are closed right now. We open at 12:00 PM.">
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h5 class="mb-1">Where website orders arrive</h5>
            <p class="text-muted small mb-3">A new order lands as a Draft on this terminal and rings there. If its shift is closed, the backup rings instead; if both are closed, the website stops taking that order type.</p>
            @forelse($branches as $b)
                @php $bt = $terminals->get($b->id, collect()); @endphp
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-3" style="max-width: 760px;">
                        <thead><tr><th style="width: 30%">{{ $b->name }}</th><th>Terminal</th><th>Backup terminal</th></tr></thead>
                        <tbody>
                        @foreach(\App\Models\Tenant\WebOrderRoute::ORDER_TYPES as $type => $typeLabel)
                            <tr>
                                <td>{{ $typeLabel }} orders</td>
                                <td>
                                    <select name="routes[{{ $b->id }}][{{ $type }}][terminal_id]" class="form-select form-select-sm">
                                        <option value="">{{ $type === 'takeaway' ? '— Same as Delivery —' : '— Choose —' }}</option>
                                        @foreach($bt as $t)
                                            <option value="{{ $t->id }}" @selected((int) $routeVal($b->id, $type, 'terminal_id') === $t->id)>{{ $t->name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="routes[{{ $b->id }}][{{ $type }}][backup_terminal_id]" class="form-select form-select-sm">
                                        <option value="">— None —</option>
                                        @foreach($bt as $t)
                                            <option value="{{ $t->id }}" @selected((int) $routeVal($b->id, $type, 'backup_terminal_id') === $t->id)>{{ $t->name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @empty
                <p class="text-muted mb-0">No active branch.</p>
            @endforelse
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h5 class="mb-1">If the customer does not confirm</h5>
            <p class="text-muted small mb-3">An order stays a Draft until the customer taps Confirm Order on WhatsApp, or your order taker calls and holds it.</p>
            <div class="row g-3 align-items-end" style="max-width: 640px;">
                <div class="col-md-6">
                    <div class="form-check form-switch">
                        <input type="checkbox" value="1" name="auto_cancel_enabled" id="auto-cancel" class="form-check-input"
                               {{ old('auto_cancel_enabled', $settings->auto_cancel_minutes !== null) ? 'checked' : '' }}>
                        <label class="form-check-label" for="auto-cancel">Cancel it automatically</label>
                    </div>
                    <div class="form-text">Off = it is never cancelled on its own.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="auto-cancel-minutes">after</label>
                    <div class="input-group">
                        <input type="number" name="auto_cancel_minutes" id="auto-cancel-minutes" class="form-control" min="5" max="240"
                               value="{{ old('auto_cancel_minutes', $settings->auto_cancel_minutes ?? 20) }}">
                        <span class="input-group-text">min</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h5 class="mb-1">WhatsApp messages to the customer</h5>
            <p class="text-muted small mb-3">Optional. Each message sent is charged per message on your monthly usage invoice.</p>
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="form-check form-switch">
                        <input type="checkbox" value="1" name="wa_notify_received" id="wa-received" class="form-check-input"
                               {{ old('wa_notify_received', $settings->wa_notify_received) ? 'checked' : '' }}>
                        <label class="form-check-label" for="wa-received">Order received + Confirm Order link</label>
                    </div>
                    <div class="form-text">Off = no confirm link; your order taker holds every order by phone.</div>
                </div>
                <div class="col-md-4">
                    <div class="form-check form-switch">
                        <input type="checkbox" value="1" name="wa_notify_confirmed" id="wa-confirmed" class="form-check-input"
                               {{ old('wa_notify_confirmed', $settings->wa_notify_confirmed) ? 'checked' : '' }}>
                        <label class="form-check-label" for="wa-confirmed">Order confirmed (held)</label>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-check form-switch">
                        <input type="checkbox" value="1" name="wa_notify_cancelled" id="wa-cancelled" class="form-check-input"
                               {{ old('wa_notify_cancelled', $settings->wa_notify_cancelled) ? 'checked' : '' }}>
                        <label class="form-check-label" for="wa-cancelled">Order cancelled</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @can('tenant.online-ordering.settings.update')
        <button class="btn btn-primary">Save website settings</button>
    @endcan
</form>

{{-- ONLINE-ORDERING-1: website ki shakl — logo, banner slides, footer, cart ka "Popular with your order". --}}
@php $banners = array_values((array) ($settings->banners ?? [])); @endphp
<form method="POST" action="{{ url('/online-ordering/settings') }}" enctype="multipart/form-data" class="mt-4" id="branding">
    @csrf
    @method('PUT')
    <input type="hidden" name="section" value="branding">
    <div class="card mb-3">
        <div class="card-body">
            <h5 class="mb-1">Website look</h5>
            <p class="text-muted small mb-3">Logo, banner slides and the footer of your ordering website. Pictures are made small automatically so the site stays fast.</p>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="logo">Logo</label>
                    @if($settings->logo_path)
                        <div class="d-flex align-items-center gap-3 mb-2">
                            <img src="{{ asset('storage/'.$settings->logo_path) }}" alt="Current logo" style="width:64px;height:64px;object-fit:cover;border-radius:50%">
                            <div class="form-check">
                                <input type="checkbox" value="1" name="remove_logo" id="remove-logo" class="form-check-input">
                                <label class="form-check-label" for="remove-logo">Remove</label>
                            </div>
                        </div>
                    @endif
                    <input type="file" name="logo" id="logo" class="form-control" accept="image/png,image/jpeg,image/webp">
                    <div class="form-text">Square picture. Also shown on items that have no photo.</div>
                </div>
                <div class="col-md-8">
                    <label class="form-label" for="banners">Banner slides <span class="text-muted">({{ count($banners) }} of {{ \App\Services\OnlineOrdering\WebsiteBranding::MAX_BANNERS }})</span></label>
                    @if($banners)
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            @foreach($banners as $i => $b)
                                <div class="border rounded p-1 text-center" style="width:180px">
                                    <img src="{{ asset('storage/'.($b['sm'] ?? $b['path'])) }}" alt="Banner {{ $i + 1 }}" style="width:100%;aspect-ratio:16/5;object-fit:cover;border-radius:4px">
                                    <div class="d-flex justify-content-between align-items-center mt-1 small">
                                        <span class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-secondary" name="move_banner" value="{{ $i }}:up" {{ $i === 0 ? 'disabled' : '' }} aria-label="Move left">&lsaquo;</button>
                                            <button class="btn btn-outline-secondary" name="move_banner" value="{{ $i }}:down" {{ $i === count($banners) - 1 ? 'disabled' : '' }} aria-label="Move right">&rsaquo;</button>
                                        </span>
                                        <label class="form-check-label"><input type="checkbox" class="form-check-input me-1" name="remove_banners[]" value="{{ $i }}">Remove</label>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <input type="file" name="banners[]" id="banners" class="form-control" accept="image/png,image/jpeg,image/webp" multiple>
                    <div class="form-text">Wide pictures (about 3.2 : 1, e.g. 1920 × 600). They slide on top of the menu.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="whatsapp-number">WhatsApp button number</label>
                    <input type="text" name="whatsapp_number" id="whatsapp-number" class="form-control" maxlength="40" value="{{ old('whatsapp_number', $settings->whatsapp_number) }}" placeholder="0321 0000000">
                    <div class="form-text">Green chat button on the website. Empty = no button.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="contact-email">Email</label>
                    <input type="email" name="contact_email" id="contact-email" class="form-control" maxlength="150" value="{{ old('contact_email', $settings->contact_email) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="upsell-category">"Popular with your order" in the cart</label>
                    <select name="upsell_category_id" id="upsell-category" class="form-select">
                        <option value="">— None —</option>
                        @foreach($categories as $c)
                            <option value="{{ $c->id }}" {{ (int) old('upsell_category_id', $settings->upsell_category_id) === $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">Items from this category are suggested in the cart, e.g. Raita &amp; Salad.</div>
                </div>
                <div class="col-md-12">
                    <div class="form-check form-switch">
                        <input type="checkbox" value="1" name="popular_auto" id="popular-auto" class="form-check-input" {{ old('popular_auto', $settings->popular_auto ?? true) ? 'checked' : '' }}>
                        <label class="form-check-label" for="popular-auto">"Popular Items" = your best sellers of the last 7 days (automatic)</label>
                    </div>
                    <div class="form-text">Off = the items you tick as "Popular" on the product and deal screens.</div>
                </div>
                <div class="col-md-12">
                    <label class="form-label" for="contact-address">Address (footer)</label>
                    <input type="text" name="contact_address" id="contact-address" class="form-control" maxlength="255" value="{{ old('contact_address', $settings->contact_address) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="facebook-url">Facebook page</label>
                    <input type="url" name="facebook_url" id="facebook-url" class="form-control" maxlength="255" value="{{ old('facebook_url', $settings->facebook_url) }}" placeholder="https://facebook.com/…">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="instagram-url">Instagram</label>
                    <input type="url" name="instagram_url" id="instagram-url" class="form-control" maxlength="255" value="{{ old('instagram_url', $settings->instagram_url) }}" placeholder="https://instagram.com/…">
                </div>
                <div class="col-md-12">
                    <label class="form-label" for="about-text">About text (footer, helps Google)</label>
                    <textarea name="about_text" id="about-text" class="form-control" rows="3" maxlength="2000">{{ old('about_text', $settings->about_text) }}</textarea>
                </div>
            </div>
        </div>
    </div>
    @can('tenant.online-ordering.settings.update')
        <button class="btn btn-primary">Save website look</button>
    @endcan
</form>
@endsection
