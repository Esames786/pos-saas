@extends('layouts.app')

@section('title', $title)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">{{ $title }}</h1>
    <a href="{{ url('/tenants') }}" class="btn btn-light">Back to Tenants</a>
</div>

@php
    $okCount = collect($results)->filter(fn ($r) => ($r['status'] ?? '') === 'ok')->count();
    $total   = count($results);
@endphp
<div class="alert {{ $okCount === $total ? 'alert-success' : 'alert-warning' }}">
    <strong>{{ $okCount }}/{{ $total }}</strong> succeeded.
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    @foreach($columns as $col)
                        <th>{{ ucwords(str_replace('_', ' ', $col)) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
            @foreach($results as $row)
                <tr>
                    @foreach($columns as $col)
                        <td>
                            @php $val = $row[$col] ?? '—'; @endphp
                            @if($col === 'status')
                                @if($val === 'ok')
                                    <span class="badge bg-success-subtle text-success-emphasis">ok</span>
                                @elseif($val === 'skipped')
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis">skipped</span>
                                @else
                                    <span class="badge bg-danger-subtle text-danger-emphasis">{{ $val }}</span>
                                @endif
                            @else
                                <span class="small">{{ $val !== null && $val !== '' ? $val : '—' }}</span>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
