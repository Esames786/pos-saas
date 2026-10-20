{{-- MASTER-NEW-SCREENS-1: small pictures of newer screens; a click opens the full picture in a new tab.
     $shots: list of ['url' => …, 'caption' => …, 'switch' => label or null]; $title optional; $small = narrower. --}}
@if(! empty($shots))
    <div class="mu-shots {{ ! empty($small) ? 'is-small' : '' }}">
        @if(! empty($title))
            <div class="mu-shots-title">{{ $title }}</div>
        @endif
        @foreach($shots as $shot)
            <a href="{{ $shot['url'] }}" target="_blank" rel="noopener" title="{{ $shot['caption'] }}{{ ! empty($shot['switch']) ? ' — switch: ' . $shot['switch'] : '' }}">
                <img src="{{ $shot['url'] }}" alt="{{ $shot['caption'] }}" loading="lazy">
                <span>{{ $shot['caption'] }}</span>
                @if(! empty($shot['switch']))
                    <em>Switch: {{ $shot['switch'] }}</em>
                @endif
            </a>
        @endforeach
    </div>
@endif
