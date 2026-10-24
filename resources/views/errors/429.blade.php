{{--
    CONTACT-FORM-1 — what a rate-limited visitor sees.

    Laravel ka apna 429 ek khaali angrezi jumla hota hai. Jo banda sach me form bhar raha tha (aur
    sirf jaldi me do baar daba baitha) usay rasta milna chahiye, na ke ek band darwaza — is liye
    yahan email aur WhatsApp mojood hain.

    JAAN BOOJH KAR standalone hai, kisi layout par nahi:
      - ye safha HAR domain par chal sakta hai (central site, tenant subdomain, edge), aur `layouts.public`
        ka marketing chrome ek logged-in tenant user ko ulta confuse karta
      - error safha kisi view composer ya controller ke bheje hue variable par taka nahi hona chahiye;
        toota hua error safha asal kharabi se bhi bura hota hai
    Sirf HTML ke liye — JSON maangne wali request ko Laravel khud JSON deta hai.
--}}
@php
    $contact = config('saas.contact', []);
    $brand = config('saas.brand_name', 'Bingoo');
    $phone = trim((string) ($contact['phone'] ?? ''));
    $hasPhone = $phone !== '' && ! str_contains($phone, 'X');
    $sales = $contact['sales_email'] ?? 'sales@bingoopos.com';
    $rtl = app()->getLocale() === 'ar';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Too many attempts') }} &middot; {{ $brand }}</title>
    <style>
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
               background:#f4f6fb; color:#1f2937; font-family:"Segoe UI",Arial,sans-serif; padding:24px; }
        .card { max-width:520px; width:100%; background:#fff; border:1px solid #e5e7eb;
                border-radius:16px; padding:40px 32px; text-align:center;
                box-shadow:0 10px 30px rgba(15,23,42,.06); }
        .mark { font-size:22px; font-weight:800; color:#a87f24; margin-bottom:20px; }
        h1 { font-size:1.5rem; margin:0 0 10px; }
        p { margin:0 0 14px; color:#64748b; line-height:1.6; }
        a.link { color:#a87f24; font-weight:600; text-decoration:none; }
        .btn { display:inline-block; margin-top:18px; padding:11px 22px; border-radius:10px;
               background:#16284a; color:#fff; text-decoration:none; font-weight:600; }
    </style>
</head>
<body>
    <div class="card">
        <div class="mark">{{ $brand }}</div>
        <h1>{{ __('Too many attempts') }}</h1>
        <p>{{ __('You have sent a few requests in a short time. Please wait a little and try again.') }}</p>
        <p>
            {{ __('In a hurry? Write to us directly:') }}<br>
            <a class="link" href="mailto:{{ $sales }}" dir="ltr">{{ $sales }}</a>
            @if($hasPhone)
                <br><span dir="ltr"><strong>{{ $phone }}</strong></span>
            @endif
        </p>
        <a class="btn" href="{{ url('/') }}">{{ __('Back to home') }}</a>
    </div>
</body>
</html>
