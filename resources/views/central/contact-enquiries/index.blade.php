@extends('layouts.app')

@section('title', 'Contact Enquiries')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="mb-1">Contact Enquiries</h1>
        <p class="text-muted mb-0">Messages sent from the public <strong>Contact Sales</strong> form.</p>
    </div>
    <div class="text-end">
        <span class="badge bg-danger">{{ $newCount }} new</span>
        @if($unsentCount)
            {{-- Jo mehfooz to hui magar sales ko bheji na ja saki: yehi wo soorat hai jis ke liye
                 enquiry pehle DB me likhi jati hai aur phir email hoti hai. --}}
            <span class="badge bg-warning text-dark ms-1" title="Saved here, but the email to sales could not be queued">
                {{ $unsentCount }} not emailed
            </span>
        @endif
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/contact-enquiries') }}" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label mb-1">Search</label>
                <input type="text" name="q" value="{{ $q }}" class="form-control" placeholder="Name, email, phone, business or message">
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1">Status</label>
                <select name="status" class="form-select">
                    <option value="">All</option>
                    <option value="new" @selected($status === 'new')>New</option>
                    <option value="handled" @selected($status === 'handled')>Handled</option>
                </select>
            </div>
            <div class="col-md-5">
                <button class="btn btn-primary">Filter</button>
                <a href="{{ url('/contact-enquiries') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Who</th>
                        <th>Topic</th>
                        <th>Message</th>
                        <th>Received</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($enquiries as $enquiry)
                    <tr class="{{ $enquiry->isHandled() ? '' : 'table-warning' }}">
                        <td class="text-muted">{{ $enquiry->id }}</td>
                        <td>
                            <div class="fw-semibold">{{ $enquiry->name }}</div>
                            <div class="small">
                                <a href="mailto:{{ $enquiry->email }}" dir="ltr">{{ $enquiry->email }}</a>
                            </div>
                            @if($enquiry->phone)
                                <div class="small text-muted" dir="ltr">{{ $enquiry->phone }}</div>
                            @endif
                            @if($enquiry->business_name)
                                <div class="small text-muted">{{ $enquiry->business_name }}</div>
                            @endif
                        </td>
                        <td>
                            <span class="badge bg-secondary">{{ $enquiry->topicLabel() }}</span>
                            @if($enquiry->plan_hint)
                                <div class="small text-muted mt-1">plan: {{ $enquiry->plan_hint }}</div>
                            @endif
                            <div class="small text-muted mt-1" dir="ltr">{{ $enquiry->locale }}</div>
                        </td>
                        {{-- Poora message yahin — isi liye koi alag "show" safha nahi hai. --}}
                        <td style="max-width:420px;white-space:pre-wrap;">{{ $enquiry->message }}</td>
                        <td class="text-nowrap small">
                            {{ $enquiry->created_at?->format('d M Y H:i') }}
                            @if(! $enquiry->notified_at)
                                <div><span class="badge bg-warning text-dark mt-1">not emailed</span></div>
                            @endif
                            @if($enquiry->isHandled())
                                <div class="text-muted mt-1">
                                    handled {{ $enquiry->handled_at?->format('d M H:i') }}
                                    @if($enquiry->handled_by) by {{ $enquiry->handled_by }} @endif
                                </div>
                            @endif
                        </td>
                        <td class="text-end">
                            <form method="POST" action="{{ url('/contact-enquiries/' . $enquiry->id . '/handled') }}">
                                @csrf
                                <button class="btn btn-sm {{ $enquiry->isHandled() ? 'btn-outline-secondary' : 'btn-success' }}">
                                    {{ $enquiry->isHandled() ? 'Reopen' : 'Mark handled' }}
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No enquiries yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mt-3">{{ $enquiries->links() }}</div>
@endsection
