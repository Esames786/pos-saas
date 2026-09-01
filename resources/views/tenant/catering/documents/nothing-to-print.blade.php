{{-- KASHIF-KITCHEN-MATERIALS-1 — "there is nothing here to print", said plainly.

     These document pages open in a NEW TAB, so a redirect-with-error goes
     nowhere the operator can see and a framework error page tells them only
     that something broke. Nothing is broken: the bookings they picked simply
     have no released kitchen sheet yet. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }}</title>
<style>
    body { font-family: Arial, Helvetica, sans-serif; background: #f3f4f6; margin: 0; padding: 60px 20px; color: #111827; }
    .card { max-width: 560px; margin: 0 auto; background: #fff; border-radius: 10px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,.12); }
    h1 { font-size: 20px; margin: 0 0 12px; }
    p { line-height: 1.6; color: #374151; margin: 0 0 12px; }
    .refs { font-family: monospace; font-size: 13px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px 12px; color: #4b5563; }
    .hint { font-size: 13px; color: #6b7280; margin-top: 18px; }
</style>
</head>
<body>
    <div class="card">
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>
        @if(! empty($references))
            <div class="refs">{{ implode(', ', $references) }}</div>
        @endif
        <p class="hint">{{ $hint }}</p>
    </div>
</body>
</html>
