{{-- PNL-DETAIL-1 — one part of the statement as ProfitLossReport laid it out: heads bold, children indented, each cost /
     expense account followed by its detail lines (grey, adding up to it). $less = the sales-discount part: "Less:" and
     the amount in brackets. --}}
@forelse($lines as $l)
    {{-- An account row reads as it always has ("code — name", amount); heads are bold, depth indents. --}}
    <tr class="{{ $l['kind'] === 'head' ? 'fw-semibold' : '' }}" data-kind="{{ $l['kind'] }}" data-code="{{ $l['code'] }}">
        <td style="padding-left: {{ 1.5 + $l['depth'] * 1.1 }}rem" class="{{ ($less ?? false) ? 'text-muted' : '' }}">{{ ($less ?? false) ? 'Less: ' : '' }}{{ $l['code'] }} — {{ $l['name'] }}</td><td class="text-end{{ ($less ?? false) ? ' text-muted' : '' }}">{{ ($less ?? false) ? '(' . number_format($l['amount'], 2) . ')' : number_format($l['amount'], 2) }}</td>
    </tr>
    @foreach($l['detail'] as $d)
        <tr class="pl-part small text-muted" data-part-of="{{ $l['code'] }}">
            <td style="padding-left: {{ 2.6 + $l['depth'] * 1.1 }}rem">{{ $d['label'] }}</td>
            <td class="text-end">{{ number_format($d['amount'], 2) }}</td>
        </tr>
    @endforeach
@empty
    @if(! ($less ?? false))
        <tr><td class="ps-4 text-muted">{{ $empty }}</td><td class="text-end">0.00</td></tr>
    @endif
@endforelse
