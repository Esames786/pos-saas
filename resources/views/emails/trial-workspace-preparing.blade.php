<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;background:#f4f6fb;font-family:Segoe UI,Arial,sans-serif;color:#1f2937;">
  <div style="max-width:560px;margin:0 auto;padding:24px;">
    <div style="background:linear-gradient(135deg,#16284a,#0f172a);color:#fff;border-radius:14px 14px 0 0;padding:24px 28px;">
      <div style="font-size:20px;font-weight:700;color:#e9c869;">{{ $brand }}</div>
      <div style="font-size:13px;color:#cbd5e1;margin-top:2px;">Your workspace is being set up</div>
    </div>
    <div style="background:#fff;border:1px solid #e5e7eb;border-top:0;border-radius:0 0 14px 14px;padding:28px;">
      <p style="margin:0 0 14px;">Hello,</p>
      <p style="margin:0 0 18px;">Thank you for starting a <strong>{{ $brand }}</strong> trial. We are now setting up the workspace for
        <strong>{{ $businessName }}</strong>. This usually takes a minute or two.</p>

      <table style="width:100%;border-collapse:collapse;font-size:14px;margin:0 0 20px;">
        <tr><td style="padding:8px 0;color:#64748b;">Your address</td>
            <td style="padding:8px 0;text-align:right;">{{ $workspaceAddress }}</td></tr>
        <tr><td style="padding:8px 0;color:#64748b;border-top:1px solid #eef1f6;">Owner email</td>
            <td style="padding:8px 0;text-align:right;border-top:1px solid #eef1f6;">{{ $ownerEmail }}</td></tr>
      </table>

      <p style="margin:0 0 8px;font-size:14px;color:#334155;">
        We will send your login link in a second email as soon as the workspace is ready.
        You can close the signup page; nothing else is needed from you.
      </p>

      <hr style="border:0;border-top:1px solid #eef1f6;margin:20px 0;">
      <p style="margin:0;font-size:12px;color:#94a3b8;">
        Did not sign up? You can ignore this email, or tell us at <a href="mailto:{{ $supportEmail }}" style="color:#a87f24;">{{ $supportEmail }}</a>.<br>
        &copy; {{ date('Y') }} {{ $brand }}.
      </p>
    </div>
  </div>
</body>
</html>
