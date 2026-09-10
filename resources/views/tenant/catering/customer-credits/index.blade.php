@extends('layouts.app')

@section('title', 'Money Owed to Customers')

@section('content')
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
        <h4 class="mb-1">Money Owed to Customers</h4>
        <p class="text-muted mb-0 fs-13">
            Bookings holding money that belongs to the customer — taken beyond the bill, or left behind
            when a booking was cancelled. Cancelling never refunds, so this money stays on the ledger
            until somebody hands it back.
        </p>
    </div>
    <a href="{{ url('/catering/events') }}" class="btn btn-light">Back to bookings</a>
</div>

@include("tenant.catering.partials.screen-impact", [
    "manages" => "Nothing. This screen only asks a question nobody else asks.",
    "managesUr" => "کچھ نہیں — یہ صرف وہ سوال پوچھتی ہے جو کوئی اور نہیں پوچھتا۔",
    "finance" => "Read-only. Refunding happens on the booking itself, with its own reason and its own document",
    "stock" => "Never",
    "prints" => "Nothing",
    "reversible" => "safe",
    "note" => "The figures come from the same position() every booking screen uses — nothing is recalculated here, so this list can never disagree with the booking it points at.",
    "noteUr" => "یہ اعداد وہی ہیں جو بکنگ کی اپنی سکرین دکھاتی ہے — یہاں کچھ دوبارہ شمار نہیں ہوتا۔",
])

@if($rows->isEmpty())
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="ti ti-mood-check fs-1 text-success d-block mb-2"></i>
            <h5 class="mb-1">Nothing is owed back</h5>
            <p class="text-muted fs-13 mb-0">
                No booking is holding money that belongs to a customer.
            </p>
        </div>
    </div>
@else
    <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <i class="ti ti-alert-triangle me-1"></i>
            <strong>{{ $rows->count() }}</strong>
            {{ \Illuminate\Support\Str::plural('booking', $rows->count()) }}
            {{ $rows->count() === 1 ? 'is' : 'are' }} holding customer money.
        </div>
        <div class="fs-5 fw-semibold">{{ number_format($total, 2) }}</div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Booking</th>
                        <th>Customer</th>
                        <th>Status</th>
                        <th class="text-end">Owed back</th>
                        {{-- The age is the column that matters. A liability three
                             months old is a different problem from this morning's. --}}
                        <th class="text-end">Waiting</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        @php($event = $row['event'])
                        <tr>
                            <td>
                                <span class="fw-semibold">{{ $event->event_no }}</span>
                                <span class="d-block text-muted fs-12">
                                    {{ $event->event_date?->format('d M Y') }}
                                </span>
                            </td>
                            <td>
                                {{ $event->customer_name }}
                                @if($event->customer_phone)
                                    <span class="d-block text-muted fs-12">{{ $event->customer_phone }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge bg-{{ $event->isCancelled() ? 'danger' : 'secondary' }}-transparent">
                                    {{ ucfirst(str_replace('_', ' ', $event->status)) }}
                                </span>
                                @if($event->isCancelled() && $event->cancel_reason)
                                    <span class="d-block text-muted fs-12">{{ \Illuminate\Support\Str::limit($event->cancel_reason, 40) }}</span>
                                @endif
                            </td>
                            <td class="text-end fw-semibold">{{ number_format($row['credit'], 2) }}</td>
                            <td class="text-end">
                                <span class="{{ $row['days'] >= 30 ? 'text-danger fw-semibold' : 'text-muted' }}">
                                    {{ $row['days'] }} {{ \Illuminate\Support\Str::plural('day', $row['days']) }}
                                </span>
                            </td>
                            <td class="text-end">
                                <a href="{{ url('/catering/events/' . $event->id) }}" class="btn btn-sm btn-light">
                                    Open &amp; refund
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
