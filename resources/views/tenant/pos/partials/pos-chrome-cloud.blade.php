{{-- W-A — Online (Cloud) chrome for the shared POS layout: exactly the two includes layouts.app rendered, so the Online page
     keeps its (hidden-on-POS) header + sidebar — including the header's shop clock widget — byte-for-byte as before.
     Cloud only: never rendered by an Edge runtime (its PosRuntime names its own chrome view). --}}
@include('partials.header')
@include('partials.sidebar')
