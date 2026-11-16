@php
    $rtl = app()->getLocale() === 'ar';
    $website = str_replace(['https://', 'http://'], '', (string) config('saas.contact.website', 'bingoopos.com'));
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;background:#f4f6fb;font-family:Segoe UI,Arial,sans-serif;color:#1f2937;">
  <div style="max-width:560px;margin:0 auto;padding:24px;">
    <div style="background:linear-gradient(135deg,#16284a,#0f172a);color:#fff;border-radius:14px 14px 0 0;padding:24px 28px;">
      <div style="font-size:20px;font-weight:700;color:#e9c869;">{{ $brand }}</div>
      <div style="font-size:13px;color:#cbd5e1;margin-top:2px;">{{ __('Thanks for getting in touch') }}</div>
    </div>
    <div style="background:#fff;border:1px solid #e5e7eb;border-top:0;border-radius:0 0 14px 14px;padding:28px;">
      <p style="margin:0 0 14px;">{{ __('Hello :name,', ['name' => $name]) }}</p>
      <p style="margin:0 0 18px;">
        {{ __('We have received your message and someone from our sales team will get back to you shortly.') }}
      </p>

      {{-- Intezaar ke bajaye banda abhi khud dekh sakta hai — demo link aur number saath. --}}
      <p style="margin:0 0 8px;font-size:14px;color:#334155;">
        {{ __('In the meantime you can open a live demo yourself at') }}
        <a href="https://{{ $website }}/demos" style="color:#a87f24;" dir="ltr">{{ $website }}/demos</a>.
      </p>
      @if($phone)
        <p style="margin:0 0 8px;font-size:14px;color:#334155;">
          {{ __('If it is urgent, WhatsApp us on') }} <strong dir="ltr">{{ $phone }}</strong>.
        </p>
      @endif

      <hr style="border:0;border-top:1px solid #eef1f6;margin:20px 0;">
      <p style="margin:0;font-size:12px;color:#94a3b8;">
        {{ __('You are receiving this because a message was sent from our website using this address. If that was not you, you can ignore this email.') }}<br>
        &copy; {{ date('Y') }} {{ $brand }}.
      </p>
    </div>
  </div>
</body>
</html>
