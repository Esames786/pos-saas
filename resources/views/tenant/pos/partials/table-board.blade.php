{{--
    Live Table Board tiles.

    Single source of truth for the dine-in board, used both on first page load
    (POSController@index) and when refreshTableBoard() re-renders it in place via
    the GET /api/pos/table-board endpoint — so opening/continuing/selecting a table
    never needs a full page reload.

    Expects: $floors, $selectedBranchId, $tableSession (nullable — drives selection).
--}}
@if($floors->count() > 1)
    <div class="category-strip mb-3" id="floor-tab-strip">
        <button type="button" class="category-pill active" data-floor-tab="">All Floors</button>
        @foreach($floors as $floor)
            <button type="button" class="category-pill" data-floor-tab="{{ $floor->id }}">{{ $floor->name }}</button>
        @endforeach
    </div>
@endif

@forelse($floors as $floor)
    <div data-floor-panel="{{ $floor->id }}">
    <div class="mb-3">
        <h3 class="h6 mb-2">{{ $floor->name }}</h3>
        <div class="restaurant-board-grid">
            @foreach($floor->tables->sortBy('sort_order') as $table)
                @php
                    $session      = $table->openSession;
                    $sessionTotal = $session ? $session->salesOrders->sum('grand_total') : 0;
                @endphp
                @php $isSelectedSession = $session && $tableSession && (int) $session->id === (int) $tableSession->id; @endphp
                <div class="restaurant-table-tile {{ $table->status }} {{ $isSelectedSession ? 'selected' : '' }}">
                    <div class="d-flex justify-content-between gap-2 mb-2">
                        <div>
                            <div class="fw-bold">{{ $table->table_no }}</div>
                            <div class="small text-muted">{{ $table->capacity }} seats</div>
                        </div>
                        <span class="status-chip">{{ str_replace('_', ' ', ucfirst($table->status)) }}</span>
                    </div>

                    @if($session)
                        <div class="small mb-2">
                            <div><strong>Session:</strong> {{ $session->session_no }}</div>
                            <div class="d-flex align-items-center gap-1"><i class="ti ti-user small text-muted"></i>{{ $session->waiter?->name ?? 'No waiter' }}</div>
                            <div><strong>Total:</strong> {{ number_format($sessionTotal, 2) }}</div>
                        </div>
                        <a href="{{ url('/pos?table_session_id=' . $session->id . '&mode=dine_in&branch_id=' . $selectedBranchId) }}"
                           class="btn btn-sm {{ $isSelectedSession ? 'btn-success' : 'btn-primary' }} w-100 mb-1"
                           data-table-session-select="1"
                           data-session-id="{{ $session->id }}"
                           data-branch-id="{{ $selectedBranchId }}">
                            {{ $isSelectedSession ? 'Selected / Continue' : 'Continue Table' }}
                        </a>
                        @can('tenant.restaurant.table-sessions.bill-preview')
                            <a href="{{ url('/restaurant/table-sessions/' . $session->id . '/bill-preview') }}"
                               target="_blank" rel="noopener"
                               class="btn btn-sm btn-dark w-100 mb-1">Bill Preview</a>
                        @endcan
                        @php $firstHeld = $session->salesOrders->where('status', 'held')->first(); @endphp
                        @if($firstHeld)
                            @can('tenant.sales-orders.split-bill')
                                <a href="{{ url('/sales-orders/' . $firstHeld->id . '/split-bill') }}"
                                   target="_blank" rel="noopener"
                                   class="btn btn-sm btn-warning w-100 mb-1">Split Bill</a>
                            @endcan
                            <a href="{{ url('/held-sales?table_session_id=' . $session->id) }}"
                               target="_blank" rel="noopener"
                               class="btn btn-sm btn-outline-dark w-100">Held Orders</a>
                        @endif
                    @else
                        @can('tenant.restaurant.table-sessions.open')
                            <button type="button" class="btn btn-sm btn-success w-100"
                                data-bs-toggle="modal" data-bs-target="#openTableModal"
                                data-table-id="{{ $table->id }}" data-table-no="{{ $table->table_no }}">
                                Open Table
                            </button>
                        @else
                            <span class="text-muted small">Available</span>
                        @endcan
                    @endif
                </div>
            @endforeach
        </div>
    </div>
    </div>{{-- /data-floor-panel --}}
@empty
    <div class="alert alert-info" role="status">No active floors/tables found for this branch.</div>
@endforelse
