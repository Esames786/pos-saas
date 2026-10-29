@extends('layouts.app')

@section('title', 'Website Settings')

@section('content')
@php
    $hours = $settings->hours ?: \App\Models\Tenant\WebSetting::defaultHours();
    $routeIds = function ($branchId, $type) use ($routes) {
        $old = old("routes.$branchId.$type.terminal_ids");
        if (is_array($old)) {
            return array_map('intval', $old);
        }
        $r = $routes->get($branchId.'.'.$type);

        return $r ? $r->ids() : [];
    };
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
            <p class="text-muted small mb-3">Tick one or more terminals. A new order rings on every ticked terminal and lands as a Draft on the first one (in this order) whose shift is open. If none of them has an open shift, the website stops taking that order type.</p>
            {{-- WEB-PICKUP-SWITCH-1 (owner, 29 Oct): a restaurant may take only delivery on its website. --}}
            <div class="form-check form-switch mb-1">
                <input type="checkbox" value="1" name="pickup_enabled" id="pickup-enabled" class="form-check-input"
                       {{ old('pickup_enabled', $settings->pickupOn()) ? 'checked' : '' }}>
                <label class="form-check-label" for="pickup-enabled">Pick-Up on the website</label>
            </div>
            <div class="form-text mb-3">Off = customers can only order Delivery; the Pick-Up button is not shown.</div>
            @forelse($branches as $b)
                @php $bt = $terminals->get($b->id, collect()); @endphp
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-3" style="max-width: 760px;">
                        <thead><tr><th style="width: 30%">{{ $b->name }}</th><th>Terminals</th></tr></thead>
                        <tbody>
                        @foreach(\App\Models\Tenant\WebOrderRoute::ORDER_TYPES as $type => $typeLabel)
                            <tr>
                                <td>{{ $typeLabel }} orders</td>
                                <td>
                                    @php $picked = $routeIds($b->id, $type); @endphp
                                    <input type="hidden" name="routes[{{ $b->id }}][{{ $type }}][terminal_ids][]" value="">
                                    <div class="d-flex flex-wrap gap-2">
                                        @forelse($bt as $t)
                                            <input type="checkbox" class="btn-check" id="rt-{{ $b->id }}-{{ $type }}-{{ $t->id }}" name="routes[{{ $b->id }}][{{ $type }}][terminal_ids][]" value="{{ $t->id }}" autocomplete="off" @checked(in_array($t->id, $picked, true))>
                                            <label class="btn btn-sm btn-outline-primary" for="rt-{{ $b->id }}-{{ $type }}-{{ $t->id }}">{{ $t->name }}</label>
                                        @empty
                                            <span class="text-muted small">No active terminal in this branch.</span>
                                        @endforelse
                                    </div>
                                    @if($type === 'takeaway')
                                        <div class="form-text">None ticked = the same terminals as Delivery.</div>
                                    @endif
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

{{-- ONLINE-ORDERING-1: SEO + website pages, the restaurant's own (owner: "sab dynamic, tenant ke against"). --}}
@php
    $faqRows = old('faqs', $settings->faqList());
    $faqRows = $faqRows ?: [['category' => '', 'q' => '', 'a' => '']];
@endphp
<form method="POST" action="{{ url('/online-ordering/settings') }}" class="mt-4" id="seo">
    @csrf
    @method('PUT')
    <input type="hidden" name="section" value="seo">
    <div class="card mb-3">
        <div class="card-body">
            <h5 class="mb-1">SEO &amp; pages</h5>
            <p class="text-muted small mb-3">What Google shows for your website, and your Privacy Policy and FAQs pages. Empty page = no page and no link in the footer.</p>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="seo-title">Google title</label>
                    <input type="text" name="seo_title" id="seo-title" class="form-control" maxlength="120" value="{{ old('seo_title', $settings->seo_title) }}" placeholder="{{ app('tenant')->business_name }} — Order Online">
                    <div class="form-text">About 50–60 letters. Empty = “{{ app('tenant')->business_name }} — Order Online”.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="seo-keywords">Keywords</label>
                    <input type="text" name="seo_keywords" id="seo-keywords" class="form-control" maxlength="300" value="{{ old('seo_keywords', $settings->seo_keywords) }}" placeholder="biryani, bbq, karachi, food delivery">
                </div>
                <div class="col-md-12">
                    <label class="form-label" for="seo-description">Google description</label>
                    <textarea name="seo_description" id="seo-description" class="form-control" rows="2" maxlength="300" placeholder="Order online from {{ app('tenant')->business_name }}. Delivery and pick-up.">{{ old('seo_description', $settings->seo_description) }}</textarea>
                    <div class="form-text">About 150 letters. Also shown when your link is shared on WhatsApp or Facebook.</div>
                </div>
                <div class="col-md-12">
                    <label class="form-label" for="privacy-policy">Privacy Policy</label>
                    <textarea name="privacy_policy" id="privacy-policy" class="form-control js-rich" data-rich="full" rows="8" maxlength="40000">{{ old('privacy_policy', \App\Http\Controllers\Website\WebsitePagesController::html((string) $settings->privacy_policy)) }}</textarea>
                    <div class="form-text">Headings, bold, lists and links — shown on your website exactly like this.</div>
                </div>
                <div class="col-md-12">
                    <label class="form-label">FAQs</label>
                    <div id="faq-rows" class="d-grid gap-2">
                        @foreach($faqRows as $i => $f)
                            <div class="border rounded p-2 js-faq">
                                <div class="row g-2">
                                    <div class="col-md-3"><input type="text" class="form-control form-control-sm" name="faqs[{{ $i }}][category]" maxlength="60" value="{{ $f['category'] ?? '' }}" placeholder="Group, e.g. Delivery" aria-label="Group"></div>
                                    <div class="col-md-8"><input type="text" class="form-control form-control-sm" name="faqs[{{ $i }}][q]" maxlength="200" value="{{ $f['q'] ?? '' }}" placeholder="Question" aria-label="Question"></div>
                                    <div class="col-md-1 text-end"><button type="button" class="btn btn-sm btn-outline-danger js-faq-del" aria-label="Remove question"><i class="ti ti-trash"></i></button></div>
                                    <div class="col-md-12"><textarea class="form-control form-control-sm js-rich" data-rich="mini" name="faqs[{{ $i }}][a]" rows="2" maxlength="4000" placeholder="Answer" aria-label="Answer">{{ \App\Http\Controllers\Website\WebsitePagesController::html((string) ($f['a'] ?? '')) }}</textarea></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <template id="faq-template">
                        <div class="border rounded p-2 js-faq">
                            <div class="row g-2">
                                <div class="col-md-3"><input type="text" class="form-control form-control-sm" name="faqs[__I__][category]" maxlength="60" placeholder="Group, e.g. Delivery" aria-label="Group"></div>
                                <div class="col-md-8"><input type="text" class="form-control form-control-sm" name="faqs[__I__][q]" maxlength="200" placeholder="Question" aria-label="Question"></div>
                                <div class="col-md-1 text-end"><button type="button" class="btn btn-sm btn-outline-danger js-faq-del" aria-label="Remove question"><i class="ti ti-trash"></i></button></div>
                                <div class="col-md-12"><textarea class="form-control form-control-sm js-rich" data-rich="mini" name="faqs[__I__][a]" rows="2" maxlength="4000" placeholder="Answer" aria-label="Answer"></textarea></div>
                            </div>
                        </div>
                    </template>
                    <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="faq-add"><i class="ti ti-plus me-1"></i>Add question</button>
                </div>
            </div>
        </div>
    </div>
    @can('tenant.online-ordering.settings.update')
        <button class="btn btn-primary">Save SEO &amp; pages</button>
    @endcan
</form>
{{-- Rich text (owner: "rich text editable"): Quill turns each .js-rich textarea into an editor and writes the HTML
     back into it. Without Quill (offline) the plain textarea still works; the server cleans whatever arrives. --}}
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.snow.min.css">
<style>
    #seo .ql-container { font-family: inherit; font-size: 14px; }
    #seo .rich-full .ql-editor { min-height: 220px; }
    #seo .rich-mini .ql-editor { min-height: 70px; }
    #seo .ql-toolbar.ql-snow { border-radius: .375rem .375rem 0 0; }
    #seo .ql-container.ql-snow { border-radius: 0 0 .375rem .375rem; }
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.min.js"></script>
<script>
(function () {
    'use strict';
    var form = document.getElementById('seo');
    var box = document.getElementById('faq-rows'), add = document.getElementById('faq-add'), tpl = document.getElementById('faq-template');
    if (!form || !box || !add || !tpl) { return; }
    var TOOLBARS = {
        full: [[{ header: [2, 3, false] }], ['bold', 'italic', 'underline'], [{ list: 'ordered' }, { list: 'bullet' }], ['link', 'blockquote'], ['clean']],
        mini: [['bold', 'italic', 'underline'], [{ list: 'ordered' }, { list: 'bullet' }], ['link'], ['clean']]
    };
    function rich(ta) {
        if (!window.Quill || ta.dataset.ready) { return; }
        ta.dataset.ready = '1';
        var kind = ta.dataset.rich === 'full' ? 'full' : 'mini';
        var wrap = document.createElement('div');
        wrap.className = 'rich-' + kind;
        var ed = document.createElement('div');
        wrap.appendChild(ed);
        ta.parentNode.insertBefore(wrap, ta);
        ta.hidden = true;
        var q = new Quill(ed, { theme: 'snow', placeholder: ta.getAttribute('placeholder') || '', modules: { toolbar: TOOLBARS[kind] } });
        if (ta.value.trim()) { q.clipboard.dangerouslyPasteHTML(ta.value); }
        var sync = function () { ta.value = q.getText().trim() ? q.root.innerHTML : ''; };
        q.on('text-change', sync);
        sync();
    }
    form.querySelectorAll('.js-rich').forEach(rich);

    var next = box.querySelectorAll('.js-faq').length;
    add.addEventListener('click', function () {
        var holder = document.createElement('div');
        holder.innerHTML = tpl.innerHTML.replace(/__I__/g, String(next++));
        var row = holder.firstElementChild;
        box.appendChild(row);
        row.querySelectorAll('.js-rich').forEach(rich);
        row.querySelector('input[name$="[q]"]').focus();
    });
    box.addEventListener('click', function (e) {
        var del = e.target.closest('.js-faq-del');
        if (!del) { return; }
        var row = del.closest('.js-faq');
        if (box.querySelectorAll('.js-faq').length > 1) { row.remove(); return; }
        row.querySelectorAll('input').forEach(function (el) { el.value = ''; });
        row.querySelectorAll('.ql-editor').forEach(function (el) { el.innerHTML = ''; });
        row.querySelectorAll('textarea').forEach(function (el) { el.value = ''; });
    });
})();
</script>
@endsection
