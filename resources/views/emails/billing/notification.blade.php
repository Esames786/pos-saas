<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <div style="max-width:560px;margin:0 auto;padding:24px;">
        <div style="background:#0f172a;color:#e9c869;padding:16px 24px;border-radius:8px 8px 0 0;font-weight:bold;font-size:18px;">
            {{ $brand }}
        </div>
        <div style="background:#ffffff;padding:24px;border-radius:0 0 8px 8px;border:1px solid #e5e7eb;border-top:none;">
            <h2 style="margin:0 0 16px;font-size:20px;color:#111827;">{{ $heading }}</h2>
            @foreach($lines as $line)
                <p style="margin:0 0 12px;font-size:15px;line-height:1.5;color:#374151;">{{ $line }}</p>
            @endforeach
            @if($ctaUrl && $ctaLabel)
                <p style="margin:24px 0 0;">
                    <a href="{{ $ctaUrl }}" style="background:#0f172a;color:#e9c869;text-decoration:none;padding:12px 22px;border-radius:6px;font-weight:bold;display:inline-block;">{{ $ctaLabel }}</a>
                </p>
            @endif
        </div>
        <p style="margin:16px 4px 0;font-size:12px;color:#9ca3af;">This is an automated billing message from {{ $brand }}.</p>
    </div>
</body>
</html>
