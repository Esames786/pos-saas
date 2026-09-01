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
                    $effectiveStatus = $session
                        ? ($session->status === 'bill_requested' ? 'bill_requested' : 'occupied')
                        : $table->status;
                @endphp
                @php $isSelectedSession = $session && $tableSession && (int) $session->id === (int) $tableSession->id; @endphp
                <div class="restaurant-table-tile {{ $effectiveStatus }} {{ $isSelectedSession ? 'selected' : '' }}">
                    <div class="d-flex justify-content-between gap-2 mb-2">
                        <div>
                            <div class="fw-bold">{{ $table->table_no }}</div>
                            <div class="small text-muted">{{ $table->capacity }} seats</div>
                        </div>
                        <span class="status-chip">{{ str_replace('_', ' ', ucfirst($effectiveStatus)) }}</span>
                    </div>

                    @if($session)
                        <div class="small mb-2">
                            <div><strong>Session:</strong> {{ $session->session_no }}</div>
                            <div class="d-flex align-items-center gap-1"><i class="ti ti-user small text-muted"></i>{{ $session->waiter?->name ?? 'No waiter' }}</div>
                            <div><strong>Total:</strong> {{ number_format($sessionTotal, 2) }}</div>
                        </div>
                        <button type="button"
                           class="btn btn-sm {{ $isSelectedSession ? 'btn-success' : 'btn-primary' }} w-100 mb-1"
                           data-table-session-select="1"
                           data-session-id="{{ $session->id }}"
                           data-branch-id="{{ $selectedBranchId }}"
                           data-fallback-href="{{ url('/pos?table_session_id=' . $session->id . '&mode=dine_in&branch_id=' . $selectedBranchId) }}">
                            {{ $isSelectedSession ? 'Selected / Continue' : 'Continue Table' }}
                        </button>
                        @can('tenant.restaurant.table-sessions.bill-preview')
                            {{-- HIDDEN by the owner's request (31 Aug): the Table Workspace card was
                                 crowded and this duplicates the Bill / Preview available once the
                                 table is continued. Hidden in the UI ONLY — the permission is
                                 deliberately left in place, so nobody's access was changed and this
                                 comes back by deleting the d-none below. --}}
                            <button type="button" class="btn btn-sm btn-dark w-100 mb-1 d-none"
                                    data-table-bill-preview="{{ $session->id }}">Bill Preview</button>
                        @endcan
                        {{-- TABLE-CLOSE-EMPTY-1 — free a table that was opened by mistake.
                             A session with nothing on it had no way out of this screen: the card
                             offers Continue and Move only, and the close action lives on the
                             standalone restaurant board. So a cashier who opened the wrong table
                             left it "Occupied" until someone went looking, or the branch closed —
                             table 1 sat like that from 13:18 on 31 Aug.
                             Only shown while the session carries NO order at all. The moment
                             anything is punched this disappears, and the controller refuses a close
                             over an open order regardless of what this template does. --}}
                        @php $hasAnyOrder = $session->salesOrders->isNotEmpty(); @endphp
                        @if(! $hasAnyOrder)
                            @can('tenant.restaurant.table-sessions.close')
                                <button type="button" class="btn btn-sm btn-outline-danger w-100 mb-1"
                                        data-table-close="{{ $session->id }}"
                                        data-table-no="{{ $table->table_no }}">Close Table</button>
                            @endcan
                        @endif
                        @php $firstHeld = $session->salesOrders->where('status', 'held')->first(); @endphp
                        @if($firstHeld)
                            @can('tenant.sales-orders.split-bill')
                                <button type="button" class="btn btn-sm btn-warning w-100 mb-1"
                                        data-table-split="{{ $session->id }}">Split Bill</button>
                            @endcan
                            <button type="button" class="btn btn-sm btn-outline-dark w-100"
                                    data-table-held-orders="{{ $session->id }}">Held Orders</button>
                        @endif
                        <div class="d-flex gap-1 mt-1">
                            @can('tenant.restaurant.table-sessions.move')
                                <button type="button" class="btn btn-sm btn-outline-secondary flex-fill"
                                        data-table-move="{{ $session->id }}" data-source-table-id="{{ $table->id }}">Move</button>
                            @endcan
                        </div>
                    @elseif($table->status === 'reserved')
                        {{-- TABLE-RESERVATION-1: reserved (no session yet) — who + when + actions. --}}
                        <div class="small mb-2">
                            <strong>{{ $table->reserved_name ?: 'Reserved' }}</strong>
                            @if($table->reserved_phone)<br><span class="text-muted">{{ $table->reserved_phone }}</span>@endif
                            @if($table->reserved_for)<br><span class="text-muted">{{ $table->reserved_for->format('d-M h:i A') }}</span>@endif
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100 mb-1"
                                data-reservation-details="{{ $table->id }}">Details</button>
                        @can('tenant.restaurant.table-sessions.open')
                            <button type="button" class="btn btn-sm btn-success w-100 mb-1"
                                data-open-table="1"
                                data-table-id="{{ $table->id }}" data-table-no="{{ $table->table_no }}"
                                data-reserved-customer-id="{{ $table->reserved_customer_id }}">
                                Open Table
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger w-100"
                                    data-table-unreserve="{{ $table->id }}">Cancel Reservation</button>
                        @endcan
                    @else
                        @can('tenant.restaurant.table-sessions.open')
                            <button type="button" class="btn btn-sm btn-success w-100 mb-1"
                                data-open-table="1"
                                data-table-id="{{ $table->id }}" data-table-no="{{ $table->table_no }}">
                                Open Table
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary w-100"
                                    data-table-reserve="{{ $table->id }}" data-table-no="{{ $table->table_no }}">
                                <i class="ti ti-calendar-plus me-1"></i>Reserve
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
