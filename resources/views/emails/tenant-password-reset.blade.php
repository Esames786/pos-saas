<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;background:#f4f6fb;font-family:Segoe UI,Arial,sans-serif;color:#1f2937;">
  <div style="max-width:560px;margin:0 auto;padding:24px;">
    <div style="background:linear-gradient(135deg,#16284a,#0f172a);color:#fff;border-radius:14px 14px 0 0;padding:24px 28px;">
      <div style="font-size:20px;font-weight:700;color:#e9c869;">{{ $brand }}</div>
      <div style="font-size:13px;color:#cbd5e1;margin-top:2px;">Password reset</div>
    </div>
    <div style="background:#fff;border:1px solid #e5e7eb;border-top:0;border-radius:0 0 14px 14px;padding:28px;">
      <p style="margin:0 0 16px;">We received a request to reset the password for your {{ $brand }} account.</p>

      <p style="text-align:center;margin:0 0 20px;">
        <a href="{{ $resetUrl }}" style="display:inline-block;background:linear-gradient(135deg,#e9c869,#caa23f);color:#11203f;font-weight:700;text-decoration:none;padding:12px 26px;border-radius:9px;">Reset password</a>
      </p>

      <p style="margin:0 0 8px;font-size:13px;color:#475569;">
        This link expires in {{ $expireMinutes }} minutes. If the button does not work,
        copy and paste this URL into your browser:
      </p>
      <p style="margin:0 0 16px;font-size:12px;word-break:break-all;"><a href="{{ $resetUrl }}" style="color:#a87f24;">{{ $resetUrl }}</a></p>

      <p style="margin:0;font-size:13px;color:#475569;">
        If you did not request a password reset, you can safely ignore this email —
        your password will not change.
      </p>

      <hr style="border:0;border-top:1px solid #eef1f6;margin:20px 0;">
      <p style="margin:0;font-size:12px;color:#94a3b8;">
        Need help? Contact <a href="mailto:{{ $supportEmail }}" style="color:#a87f24;">{{ $supportEmail }}</a>.<br>
        &copy; {{ date('Y') }} {{ $brand }}.
      </p>
    </div>
  </div>
</body>
</html>
