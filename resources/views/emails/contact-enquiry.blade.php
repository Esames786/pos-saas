@php
    $rtl = app()->getLocale() === 'ar';
    $end = $rtl ? 'left' : 'right';
    $brand = config('saas.brand_name', 'Bingoo');
    $row = 'padding:8px 0;border-top:1px solid #eef1f6;';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;background:#f4f6fb;font-family:Segoe UI,Arial,sans-serif;color:#1f2937;">
  <div style="max-width:560px;margin:0 auto;padding:24px;">
    <div style="background:linear-gradient(135deg,#16284a,#0f172a);color:#fff;border-radius:14px 14px 0 0;padding:24px 28px;">
      <div style="font-size:20px;font-weight:700;color:#e9c869;">{{ $brand }}</div>
      <div style="font-size:13px;color:#cbd5e1;margin-top:2px;">{{ __('New enquiry from the website') }}</div>
    </div>
    <div style="background:#fff;border:1px solid #e5e7eb;border-top:0;border-radius:0 0 14px 14px;padding:28px;">

      {{-- Jawab dene ke liye sab kuch pehli nazar me: naam, number, email, mauzu. --}}
      <table style="width:100%;border-collapse:collapse;font-size:14px;margin:0 0 20px;">
        <tr><td style="padding:8px 0;color:#64748b;">{{ __('Name') }}</td>
            <td style="padding:8px 0;text-align:{{ $end }};font-weight:600;">{{ $enquiry->name }}</td></tr>
        <tr><td style="{{ $row }}color:#64748b;">{{ __('Email') }}</td>
            <td style="{{ $row }}text-align:{{ $end }};"><a href="mailto:{{ $enquiry->email }}" style="color:#a87f24;" dir="ltr">{{ $enquiry->email }}</a></td></tr>
        @if($enquiry->phone)
          <tr><td style="{{ $row }}color:#64748b;">{{ __('Phone') }}</td>
              <td style="{{ $row }}text-align:{{ $end }};" dir="ltr">{{ $enquiry->phone }}</td></tr>
        @endif
        @if($enquiry->business_name)
          <tr><td style="{{ $row }}color:#64748b;">{{ __('Business') }}</td>
              <td style="{{ $row }}text-align:{{ $end }};">{{ $enquiry->business_name }}</td></tr>
        @endif
        <tr><td style="{{ $row }}color:#64748b;">{{ __('Topic') }}</td>
            <td style="{{ $row }}text-align:{{ $end }};">{{ __($enquiry->topicLabel()) }}</td></tr>
        @if($enquiry->plan_hint)
          <tr><td style="{{ $row }}color:#64748b;">{{ __('Interested plan') }}</td>
              <td style="{{ $row }}text-align:{{ $end }};">{{ $enquiry->plan_hint }}</td></tr>
        @endif
        <tr><td style="{{ $row }}color:#64748b;">{{ __('Page language') }}</td>
            <td style="{{ $row }}text-align:{{ $end }};" dir="ltr">{{ $enquiry->locale }}</td></tr>
      </table>

      <div style="background:#f8faff;border:1px solid #e8eefc;border-radius:10px;padding:16px 18px;margin:0 0 18px;">
        <div style="font-size:12px;color:#64748b;margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px;">{{ __('Message') }}</div>
        <div style="font-size:14px;line-height:1.6;white-space:pre-wrap;">{{ $enquiry->message }}</div>
      </div>

      <p style="margin:0 0 8px;font-size:13px;color:#334155;">
        {{ __('Reply to this email and it goes straight back to them.') }}
      </p>

      <hr style="border:0;border-top:1px solid #eef1f6;margin:20px 0;">
      <p style="margin:0;font-size:12px;color:#94a3b8;">
        {{ __('Enquiry #:id · received :when', ['id' => $enquiry->id, 'when' => $enquiry->created_at?->format('d M Y, H:i')]) }}<br>
        {{ __('Saved in Bingoo Master → Contact Enquiries.') }}
      </p>
    </div>
  </div>
</body>
</html>
