@php $rtl = app()->getLocale() === 'ar'; $end = $rtl ? 'left' : 'right'; @endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;background:#f4f6fb;font-family:Segoe UI,Arial,sans-serif;color:#1f2937;">
  <div style="max-width:560px;margin:0 auto;padding:24px;">
    <div style="background:linear-gradient(135deg,#16284a,#0f172a);color:#fff;border-radius:14px 14px 0 0;padding:24px 28px;">
      <div style="font-size:20px;font-weight:700;color:#e9c869;">{{ $brand }}</div>
      <div style="font-size:13px;color:#cbd5e1;margin-top:2px;">{{ __('Your workspace could not be created') }}</div>
    </div>
    <div style="background:#fff;border:1px solid #e5e7eb;border-top:0;border-radius:0 0 14px 14px;padding:28px;">
      <p style="margin:0 0 14px;">{{ __('Hello,') }}</p>
      <p style="margin:0 0 18px;">{{ __('We could not finish setting up the :brand workspace for :business. Nothing was charged, and the unfinished workspace has been removed, so you can sign up again with the same details.', ['brand' => $brand, 'business' => $businessName]) }}</p>

      <p style="text-align:center;margin:0 0 22px;">
        <a href="{{ $tryAgainUrl }}" style="display:inline-block;background:linear-gradient(135deg,#e9c869,#caa23f);color:#11203f;font-weight:700;text-decoration:none;padding:12px 26px;border-radius:9px;">{{ __('Try again') }}</a>
      </p>

      <p style="margin:0 0 8px;font-size:13px;color:#475569;">
        {{ __('If it happens again, reply to this email or write to') }} <a href="mailto:{{ $supportEmail }}" style="color:#a87f24;" dir="ltr">{{ $supportEmail }}</a> {{ __('and we will set it up for you.') }}
      </p>

      <hr style="border:0;border-top:1px solid #eef1f6;margin:20px 0;">
      <p style="margin:0;font-size:12px;color:#94a3b8;">&copy; {{ date('Y') }} {{ $brand }}.</p>
    </div>
  </div>
</body>
</html>
