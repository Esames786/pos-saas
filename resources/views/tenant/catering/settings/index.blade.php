@extends('layouts.app')

@section('title', 'Catering Settings')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">Catering Settings</h1>
</div>

@include('tenant.catering.partials.tooltips')
@include('tenant.catering.partials.screen-impact', ['manages' => 'Reminder timing, document language and the default service charge.', 'managesUr' => 'یاد دہانی کا وقت، دستاویز کی زبان اور سروس چارج۔', 'emails' => 'Booking reminders — recorded, but only delivered once server SMTP is configured', 'reversible' => 'safe', 'note' => 'Reminder emails are recorded but are NOT delivered until an SMTP account is configured on the server.', 'noteUr' => 'ای میل ریکارڈ ہوتی ہے مگر SMTP ترتیب کے بغیر گاہک تک نہیں پہنچتی۔'])
@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ url('/catering/settings') }}">
    @csrf @method('PUT')
    <div class="card mb-3" style="max-width: 720px;">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Reminder Recipient Email</label>
                    <input type="email" name="reminder_recipient_email" class="form-control"
                           value="{{ old('reminder_recipient_email', $settings->reminder_recipient_email) }}">
                    <div class="form-text">Internal address that receives upcoming-event reminder emails.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Default Service Charge %</label>
                    <input type="number" step="0.01" min="0" max="100" name="default_service_charge_percent" class="form-control"
                           value="{{ old('default_service_charge_percent', $settings->default_service_charge_percent) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Print Language Profile</label>
                    <select name="print_language_profile" class="form-select">
                        <option value="en" @selected($settings->print_language_profile === 'en')>English</option>
                        <option value="ur" @selected($settings->print_language_profile === 'ur')>Urdu</option>
                        <option value="both" @selected($settings->print_language_profile === 'both')>English + Urdu</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Reminder Schedule</label>
                    @php $offsets = $settings->reminder_offsets ?? \App\Models\Tenant\CateringSetting::DEFAULT_REMINDER_OFFSETS; @endphp
                    @foreach(['d7' => '7 days before', 'd3' => '3 days before', 'd1' => '1 day before', 'same_day' => 'Same day'] as $key => $label)
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="reminder_offsets[]" value="{{ $key }}"
                                   id="offset-{{ $key }}" @checked(in_array($key, $offsets, true))>
                            <label class="form-check-label" for="offset-{{ $key }}">{{ $label }}</label>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary">Save Settings</button>
        </div>
    </div>
</form>
@endsection
