@extends('layouts.app')

@section('title', 'Journal Entries')

@php $statusBadge = ['draft' => 'bg-secondary', 'posted' => 'bg-success', 'void' => 'bg-danger']; @endphp

@section('content')

@if($eventNotFound ?? false)
    {{-- Said out loud, because an empty table looks exactly like a filter that worked. --}}
    <div class="alert alert-warning">No event <strong>{{ $filters['event_no'] }}</strong> found.</div>
@endif

        <div class="page-header">
            <div class="page-title">
                <h4>Journal Entries</h4>
                <h6>Double-entry general ledger journals</h6>
            </div>
            <div class="page-btn d-flex gap-2">
                <form method="GET" class="d-inline">
                    @foreach($filters as $k => $val)
                        @if(is_array($val))
                            @foreach($val as $item)
                                @if($item !== null && $item !== '')<input type="hidden" name="{{ $k }}[]" value="{{ $item }}">@endif
                            @endforeach
                        @else
                            @if($val !== null && $val !== '')<input type="hidden" name="{{ $k }}" value="{{ $val }}">@endif
                        @endif
                    @endforeach
                    <button type="submit" name="export_csv" value="1" class="btn btn-outline-success btn-sm"><i class="ti ti-download me-1"></i>Entries CSV</button>
                </form>
                <form method="GET" class="d-inline">
                    @foreach($filters as $k => $val)
                        @if(is_array($val))
                            @foreach($val as $item)
                                @if($item !== null && $item !== '')<input type="hidden" name="{{ $k }}[]" value="{{ $item }}">@endif
                            @endforeach
                        @else
                            @if($val !== null && $val !== '')<input type="hidden" name="{{ $k }}" value="{{ $val }}">@endif
                        @endif
                    @endforeach
                    <button type="submit" name="export_csv" value="lines" class="btn btn-outline-success btn-sm"><i class="ti ti-download me-1"></i>Lines CSV</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-sm-2">
                        <label class="form-label mb-1">From</label>
                        <input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}">
                    </div>
                    <div class="col-sm-2">
                        <label class="form-label mb-1">To</label>
                        <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
                    </div>
                    {{-- JOURNAL-SOURCE-MULTI-1: a tick-list, not a one-at-a-time dropdown. Reading
                         "what did the suppliers cost us" meant supplier payments AND purchase bills,
                         and the old control could only ever answer half of that. Nothing ticked
                         still means every source. --}}
                    @php $chosenSources = (array) ($filters['source_type'] ?? []); @endphp
                    <div class="col-sm-3">
                        <label class="form-label mb-1">
                            Source
                            <span class="text-muted small">
                                @if($chosenSources) ({{ count($chosenSources) }} chosen) @else (all) @endif
                            </span>
                        </label>
                        <div class="border rounded p-2" style="max-height:150px; overflow-y:auto;">
                            @forelse($sourceTypes as $st)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="source_type[]"
                                           value="{{ $st }}" id="src-{{ $loop->index }}"
                                           @checked(in_array($st, $chosenSources, true))>
                                    <label class="form-check-label small" for="src-{{ $loop->index }}">
                                        {{ str_replace('_', ' ', $st) }}
                                    </label>
                                </div>
                            @empty
                                <div class="text-muted small">No sources yet.</div>
                            @endforelse
                        </div>
                        <div class="form-text">Tick none for all sources.</div>
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label mb-1">Search</label>
                        <input type="text" name="q" class="form-control" placeholder="Entry / source / description" value="{{ $filters['q'] ?? '' }}">
                    </div>
                    {{-- JOURNAL-EVENT-REF-1: EXACT event number. The number is the key; a partial
                         like 0002 would match dozens of events. --}}
                    <div class="col-sm-2">
                        <label class="form-label mb-1">Event #</label>
                        <input type="text" name="event_no" class="form-control"
                               placeholder="EV-…" value="{{ $filters['event_no'] ?? '' }}">
                        <div class="form-text">Exact number.</div>
                    </div>
                    <div class="col-sm-2">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card table-list-card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table datanew">
                        <caption class="visually-hidden">Journal entries</caption>
                        <thead class="thead-light">
                            <tr>
                                <th scope="col">Entry #</th>
                                <th scope="col">Date</th>
                                <th scope="col">Source</th>
                                <th scope="col">Source #</th>
                                <th scope="col">Description</th>
                                <th scope="col" class="text-end">Debit</th>
                                <th scope="col" class="text-end">Credit</th>
                                <th scope="col">Status</th>
                                <th scope="col"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($entries as $e)
                            <tr>
                                <td><a href="{{ url('/finance/journal-entries/' . $e->id) }}" class="fw-semibold">{{ $e->entry_no }}</a>@if($e->is_reversal)<span class="badge bg-warning text-dark ms-1">Reversal</span>@endif</td>
                                <td>{{ optional($e->entry_date)->format('Y-m-d') }}</td>
                                <td>{{ str_replace('_', ' ', $e->source_type ?? '—') }}</td>
                                <td class="text-muted">
                                    {{ $e->source_no ?: '—' }}
                                    {{-- JOURNAL-EVENT-REF-1: the event under the source no, because a
                                         receipt's Source # is a ULID and says nothing to a person. --}}
                                    @if($ev = ($eventFor[$e->id] ?? null))
                                        <div class="small">
                                            @can('tenant.catering.events.show')
                                                <a href="{{ url('/catering/events/' . $ev['event_id']) }}">{{ $ev['event_no'] }}</a>
                                            @else
                                                {{ $ev['event_no'] }}
                                            @endcan
                                            @if($ev['customer_name'])
                                                <span class="text-muted">· {{ $ev['customer_name'] }}</span>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                <td class="text-muted">{{ $e->description }}</td>
                                <td class="text-end">{{ number_format((float) $e->total_debit, 2) }}</td>
                                <td class="text-end">{{ number_format((float) $e->total_credit, 2) }}</td>
                                <td><span class="badge {{ $statusBadge[$e->status] ?? 'bg-secondary' }}">{{ ucfirst($e->status) }}</span></td>
                                <td><a href="{{ url('/finance/journal-entries/' . $e->id) }}" class="btn btn-sm btn-outline-secondary"><i class="ti ti-eye"></i></a></td>
                            </tr>
                            @empty
                            <tr><td colspan="9" class="text-center text-muted py-4">No journal entries found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
@endsection
