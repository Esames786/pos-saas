<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\RestaurantFloor;
use App\Models\Tenant\RestaurantTable;
use App\Models\Tenant\RestaurantTableSession;
use App\Models\Tenant\RestaurantWaiter;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Terminal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RestaurantTableSessionController extends Controller
{
    public function board(Request $request)
    {
        $this->assertDineInAllowed();
        $scope = app(\App\Services\Security\UserDataScope::class);
        $branches = $scope->branchesForPos(auth('tenant')->user());
        abort_if($branches->isEmpty(), 403, 'Your account has no active branch assignment.');

        $requestedBranchId = (int) $request->input('branch_id', 0);
        $selectedBranchId = $branches->contains('id', $requestedBranchId)
            ? $requestedBranchId
            : (int) $branches->first()->id;

        $floors = RestaurantFloor::with([
            'tables' => fn ($q) => $q->orderBy('sort_order'),
            'tables.openSession.waiter',
            'tables.openSession.salesOrders',
        ])
            ->where('branch_id', $selectedBranchId)
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->get();

        $waiters = RestaurantWaiter::where(function ($q) use ($selectedBranchId) {
            $q->where('branch_id', $selectedBranchId)->orWhereNull('branch_id');
        })->where('status', 'active')->orderBy('name')->get();

        // SHIFT-POS-INTEGRATION-CLOSURE-1: opening a table binds to a specific terminal's shift, so
        // the board must let the host pick the terminal they are working from.
        $terminals = $scope->terminalsForPos(auth('tenant')->user(), [$selectedBranchId]);

        return view('tenant.restaurant.board', compact('floors', 'waiters', 'branches', 'selectedBranchId', 'terminals'));
    }

    public function open(Request $request, RestaurantTable $restaurantTable)
    {
        $this->assertDineInAllowed();
        app(\App\Services\Security\UserDataScope::class)->assertPosSelection(
            auth('tenant')->user(),
            (int) $restaurantTable->branch_id,
            $request->filled('terminal_id') ? (int) $request->input('terminal_id') : null,
        );
        // BRANCH-OPERATING-MODE-1: table/session mutations belong to the Branch Server for active Local POS branches.
        app(\App\Services\Edge\BranchOperatingModeService::class)
            ->assertSaleMutationAllowed(\App\Models\Tenant\Branch::findOrFail($restaurantTable->branch_id));

        $data = $request->validate([
            'terminal_id'          => 'required|exists:terminals,id',
            'restaurant_waiter_id' => 'nullable|exists:restaurant_waiters,id',
            'guest_count'          => 'required|integer|min:1|max:100',
            'notes'                => 'nullable|string|max:255',
        ]);

        if (!empty($data['restaurant_waiter_id'])) {
            $waiter = RestaurantWaiter::find($data['restaurant_waiter_id']);
            if ($waiter && $waiter->branch_id && (int) $waiter->branch_id !== (int) $restaurantTable->branch_id) {
                if ($request->expectsJson()) {
                    return response()->json(['message' => 'Selected waiter does not belong to this branch.'], 422);
                }
                return back()->withErrors(['restaurant_waiter_id' => 'Selected waiter does not belong to this branch.']);
            }
        }

        // SHIFT-POS-INTEGRATION-CLOSURE-1: shifts are PER-TERMINAL and a branch may have several open
        // terminal shifts, so a table must be bound to the EXACT terminal that opened it — never an
        // arbitrary branch shift (that would mis-attribute cash / close-blocking / sync identity).
        // The terminal must be active and belong to this table's branch.
        $terminal = Terminal::where('id', $data['terminal_id'])
            ->where('branch_id', $restaurantTable->branch_id)
            ->where('status', 'active')
            ->first();
        if (! $terminal) {
            $msg = 'Select an active terminal in this branch before opening a table.';
            if ($request->expectsJson()) {
                return response()->json(['message' => $msg, 'code' => 'INVALID_TERMINAL'], 422);
            }
            return back()->withErrors(['terminal_id' => $msg]);
        }

        $sessionNo = 'TS-' . now()->format('YmdHis') . '-' . random_int(100, 999);

        try {
            DB::connection('tenant')->transaction(function () use ($restaurantTable, $data, $sessionNo, $terminal) {
                // Opening a table is NEW commercial state: it requires the EXACT terminal's open shift
                // and binds to it (opened_shift_id + business_date) so it is visible to that terminal's
                // shift-close guard and carries the correct shift identity. Lock the shift FIRST
                // (consistent order), then the table row.
                $shift = app(\App\Services\Sales\ShiftService::class)->lockOpenShiftForTerminal($terminal);

                $table = RestaurantTable::lockForUpdate()->findOrFail($restaurantTable->id);

                if ($table->openSession()->exists()) {
                    throw new \RuntimeException('Table already has an open session.');
                }

                RestaurantTableSession::create([
                    'session_no'           => $sessionNo,
                    'branch_id'            => $table->branch_id,
                    'restaurant_table_id'  => $table->id,
                    'restaurant_waiter_id' => $data['restaurant_waiter_id'] ?? null,
                    // TABLE-RESERVATION-2b: carry any reservation's customer onto the session (read from
                    // the locked row BEFORE the clear below) so the POS pre-attaches it to the first order.
                    'customer_id'          => $table->reserved_customer_id,
                    'customer_name'        => $table->reserved_name,
                    'customer_phone'       => $table->reserved_phone,
                    'opened_by_user_id'    => Auth::id(),
                    'opened_shift_id'      => $shift->id,
                    'business_date'        => $shift->business_date->toDateString(),
                    'guest_count'          => $data['guest_count'],
                    'status'               => 'open',
                    'opened_at'            => now(),
                    'notes'                => $data['notes'] ?? null,
                ]);

                // TABLE-RESERVATION-1: opening consumes any reservation — clear its fields so nothing lingers.
                $table->update([
                    'status'               => 'occupied',
                    'reserved_customer_id' => null, 'reserved_name' => null, 'reserved_phone' => null,
                    'reserved_for'         => null, 'reservation_note' => null,
                    'reserved_by_user_id'  => null, 'reserved_at' => null,
                ]);
            });
        } catch (\App\Exceptions\ShiftException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'code' => 'NO_OPEN_SHIFT'], 422);
            }
            return back()->withErrors(['table' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            return back()->withErrors(['table' => $e->getMessage()]);
        }

        if ($request->expectsJson()) {
            $session = RestaurantTableSession::with(['table', 'waiter'])
                ->where('session_no', $sessionNo)
                ->where('restaurant_table_id', $restaurantTable->id)
                ->first();
            return response()->json([
                'ok'         => true,
                'session_id' => $session?->id,
                'branch_id'  => (int) $restaurantTable->branch_id,
                // Full detail lets the POS paint the session bar + board in place (no reload).
                'session'    => $session ? [
                    'id'          => (int) $session->id,
                    'session_no'  => $session->session_no,
                    'table_id'    => (int) $restaurantTable->id,
                    'table_no'    => $restaurantTable->table_no,
                    'waiter_name' => $session->waiter?->name,
                    'guest_count' => $session->guest_count,
                    'status'      => $session->status,
                    'branch_id'   => (int) $session->branch_id,
                    // TABLE-RESERVATION-2b: let the POS pre-attach the carried-over reservation customer.
                    'customer_id'    => $session->customer_id,
                    'customer_name'  => $session->customer_name,
                    'customer_phone' => $session->customer_phone,
                ] : null,
            ]);
        }

        return redirect(url('/restaurant/board?branch_id=' . $restaurantTable->branch_id))
            ->with('status', "Table {$restaurantTable->table_no} session opened.");
    }

    public function billRequested(RestaurantTableSession $restaurantTableSession)
    {
        $this->assertDineInAllowed();
        $this->assertSessionAccess($restaurantTableSession);
        app(\App\Services\Edge\BranchOperatingModeService::class)
            ->assertSaleMutationAllowed(\App\Models\Tenant\Branch::findOrFail($restaurantTableSession->branch_id));

        if (!in_array($restaurantTableSession->status, ['open', 'bill_requested'], true)) {
            if (request()->expectsJson()) {
                return response()->json(['message' => 'Session is not open.'], 422);
            }
            return back()->withErrors(['session' => 'Session is not open.']);
        }

        $restaurantTableSession->update(['status' => 'bill_requested']);
        $restaurantTableSession->table->update(['status' => 'bill_requested']);

        if (request()->expectsJson()) {
            return response()->json([
                'ok'      => true,
                'status'  => 'bill_requested',
                'message' => 'Bill requested for table ' . $restaurantTableSession->table->table_no . '.',
            ]);
        }

        return back()->with('status', 'Bill requested for table ' . $restaurantTableSession->table->table_no . '.');
    }

    public function close(Request $request, RestaurantTableSession $restaurantTableSession)
    {
        $this->assertDineInAllowed();
        $this->assertSessionAccess($restaurantTableSession);
        app(\App\Services\Edge\BranchOperatingModeService::class)
            ->assertSaleMutationAllowed(\App\Models\Tenant\Branch::findOrFail($restaurantTableSession->branch_id));

        $closeType     = $request->input('status', 'closed');
        $sessionStatus = $closeType === 'cancelled' ? 'cancelled' : 'closed';

        // TABLE-CLOSE-EMPTY-1 — decide under a LOCK, not on a stale read.
        //
        // The POS board can now offer Close on a session that carries nothing, but that button was
        // drawn when the board last rendered. Between the render and the click, another counter can
        // punch an order onto the same table — and the old code read the orders outside any lock, so
        // two requests could both see "nothing here" and a live check could be closed out from under
        // the counter working on it.
        //
        // The session row is locked first, then its status and its orders are read again inside that
        // lock. A punch arriving at the same moment serialises behind it and is seen.
        $failure = DB::connection('tenant')->transaction(function () use ($restaurantTableSession, $sessionStatus) {
            $session = RestaurantTableSession::whereKey($restaurantTableSession->id)
                ->lockForUpdate()
                ->first();

            if (! $session || in_array($session->status, ['closed', 'cancelled'], true)) {
                return 'This table session is already closed or cancelled.';
            }

            // Re-read INSIDE the lock. This is the check the button depends on.
            $openSales = SalesOrder::where('restaurant_table_session_id', $session->id)
                ->whereIn('status', ['draft', 'held'])
                ->lockForUpdate()
                ->exists();

            if ($openSales) {
                return 'This table has an open order now. Cancel or complete it through POS before closing.';
            }

            $session->update([
                'status'            => $sessionStatus,
                'closed_by_user_id' => Auth::id(),
                'closed_at'         => now(),
            ]);
            $session->table?->update(['status' => 'available']);

            return null;
        });

        $msg = $sessionStatus === 'cancelled' ? 'Session cancelled.' : 'Session closed as paid.';

        // The POS board calls this with fetch(); a redirect would look like success to it and the
        // cashier would watch the table stay occupied with no reason given.
        if ($request->expectsJson()) {
            return $failure
                ? response()->json(['ok' => false, 'message' => $failure], 422)
                : response()->json(['ok' => true, 'message' => $msg]);
        }

        return $failure
            ? back()->withErrors(['session' => $failure])
            : redirect(url('/restaurant/board'))->with('status', $msg);
    }

    public function show(RestaurantTableSession $restaurantTableSession)
    {
        $this->assertDineInAllowed();
        $this->assertSessionAccess($restaurantTableSession);
        $restaurantTableSession->load([
            'branch',
            'table.floor',
            'waiter',
            'openedBy',
            'salesOrders.lines.product',
        ]);

        return view('tenant.restaurant.sessions.show', compact('restaurantTableSession'));
    }

    public function billPreview(RestaurantTableSession $restaurantTableSession)
    {
        $this->assertDineInAllowed();
        $this->assertSessionAccess($restaurantTableSession);
        $restaurantTableSession->load([
            'branch',
            'table.floor.tables',
            'waiter',
            'salesOrders' => function ($query) {
                $query->whereIn('status', ['held', 'paid'])
                    ->with(['lines.product', 'lines.variant', 'payments.method', 'branch', 'shift']);
            },
        ]);

        // Render the bill preview using the branch's configured RECEIPT layout so the
        // preview + its print match the actual receipt look (header/footer/font/paper),
        // instead of generic admin styling.
        $layout = \App\Models\Tenant\ReceiptLayoutSetting::where('branch_id', $restaurantTableSession->branch_id)
            ->where('document_type', 'receipt')
            ->first();

        if (request()->expectsJson()) {
            return response()->json([
                'ok' => true,
                'html' => view('tenant.pos.partials.table-bill-preview', [
                    'session' => $restaurantTableSession,
                    'layout'  => $layout,
                ])->render(),
            ]);
        }

        return view('tenant.restaurant.table-sessions.bill-preview', [
            'session' => $restaurantTableSession,
            'layout'  => $layout,
        ]);
    }

    public function move(Request $request, RestaurantTableSession $restaurantTableSession)
    {
        $this->assertDineInAllowed();
        $this->assertSessionAccess($restaurantTableSession);
        app(\App\Services\Edge\BranchOperatingModeService::class)
            ->assertSaleMutationAllowed(\App\Models\Tenant\Branch::findOrFail($restaurantTableSession->branch_id));

        $data = $request->validate([
            'target_table_id' => ['required', 'exists:restaurant_tables,id'],
        ]);

        if (!in_array($restaurantTableSession->status, ['open', 'bill_requested'], true)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Only open table sessions can be moved.'], 422);
            }
            return back()->withErrors(['session' => 'Only open table sessions can be moved.']);
        }

        $targetTable = RestaurantTable::where('branch_id', $restaurantTableSession->branch_id)
            ->where('id', $data['target_table_id'])
            ->firstOrFail();

        if ((int) $targetTable->id === (int) $restaurantTableSession->restaurant_table_id) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Please select a different target table.'], 422);
            }
            return back()->withErrors(['table' => 'Please select a different target table.']);
        }

        $targetHasOpenSession = RestaurantTableSession::where('restaurant_table_id', $targetTable->id)
            ->whereIn('status', ['open', 'bill_requested'])
            ->exists();

        if ($targetHasOpenSession || !in_array($targetTable->status, ['available', 'cleaning'], true)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Target table is not available.'], 422);
            }
            return back()->withErrors(['table' => 'Target table is not available.']);
        }

        try {
            DB::connection('tenant')->transaction(function () use ($restaurantTableSession, $targetTable) {
                $sessionLocked = RestaurantTableSession::whereKey($restaurantTableSession->id)
                    ->lockForUpdate()
                    ->first();

                if (!$sessionLocked || !in_array($sessionLocked->status, ['open', 'bill_requested'], true)) {
                    throw new \RuntimeException('The table session is no longer open. Refresh and try again.');
                }

                $tableIds = collect([$sessionLocked->restaurant_table_id, $targetTable->id])->sort()->values();
                $tables = RestaurantTable::whereIn('id', $tableIds)->lockForUpdate()->get()->keyBy('id');
                $sourceTableLocked = $tables->get($sessionLocked->restaurant_table_id);
                $targetTableLocked = $tables->get($targetTable->id);

                if (!$sourceTableLocked || !$targetTableLocked
                    || (int) $sourceTableLocked->branch_id !== (int) $sessionLocked->branch_id
                    || (int) $targetTableLocked->branch_id !== (int) $sessionLocked->branch_id) {
                    throw new \RuntimeException('The source or target table is no longer valid.');
                }

                $targetHasSession = RestaurantTableSession::where('restaurant_table_id', $targetTableLocked->id)
                    ->whereIn('status', ['open', 'bill_requested'])
                    ->lockForUpdate()
                    ->exists();

                if ($targetHasSession || !in_array($targetTableLocked->status, ['available', 'cleaning'], true)) {
                    throw new \RuntimeException('Target table is no longer available.');
                }

                $sessionLocked->update([
                    'restaurant_table_id' => $targetTableLocked->id,
                ]);

                SalesOrder::where('restaurant_table_session_id', $sessionLocked->id)
                    ->update([
                        'restaurant_floor_id' => $targetTableLocked->restaurant_floor_id,
                        'restaurant_table_id' => $targetTableLocked->id,
                    ]);

                $sourceTableLocked?->update(['status' => 'available']);

                $targetTableLocked->update([
                    'status' => $sessionLocked->status === 'bill_requested'
                        ? 'bill_requested'
                        : 'occupied',
                ]);
            }, 3);
        } catch (\RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            return back()->withErrors(['table' => $e->getMessage()]);
        } catch (\Throwable $e) {
            report($e);
            if ($request->expectsJson()) {
                return response()->json(['message' => 'The table state changed while moving. Refresh and try again.'], 409);
            }
            return back()->withErrors(['table' => 'The table state changed while moving. Refresh and try again.']);
        }

        $restaurantTableSession->refresh()->load(['table', 'waiter', 'salesOrders']);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'Table moved successfully.',
                'session' => $this->sessionPayload($restaurantTableSession),
            ]);
        }

        return back()->with('status', 'Table moved successfully.');
    }

    public function merge(Request $request, RestaurantTableSession $restaurantTableSession)
    {
        $this->assertDineInAllowed();
        $this->assertSessionAccess($restaurantTableSession);
        app(\App\Services\Edge\BranchOperatingModeService::class)
            ->assertSaleMutationAllowed(\App\Models\Tenant\Branch::findOrFail($restaurantTableSession->branch_id));

        $data = $request->validate([
            'target_session_id' => ['required', 'exists:restaurant_table_sessions,id'],
        ]);

        if (!in_array($restaurantTableSession->status, ['open', 'bill_requested'], true)) {
            return back()->withErrors(['session' => 'Only open table sessions can be merged.']);
        }

        if ((int) $restaurantTableSession->id === (int) $data['target_session_id']) {
            return back()->withErrors(['session' => 'Source and target sessions cannot be the same.']);
        }

        $targetSession = RestaurantTableSession::with(['table'])
            ->where('branch_id', $restaurantTableSession->branch_id)
            ->whereIn('status', ['open', 'bill_requested'])
            ->findOrFail($data['target_session_id']);

        try {
            DB::connection('tenant')->transaction(function () use ($restaurantTableSession, $targetSession) {
                $sessionIds = collect([$restaurantTableSession->id, $targetSession->id])->sort()->values();
                $sessions = RestaurantTableSession::whereIn('id', $sessionIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $source = $sessions->get($restaurantTableSession->id);
                $target = $sessions->get($targetSession->id);

                if (!$source || !$target
                    || (int) $source->branch_id !== (int) $target->branch_id
                    || !in_array($source->status, ['open', 'bill_requested'], true)
                    || !in_array($target->status, ['open', 'bill_requested'], true)) {
                    throw new \RuntimeException('One of the table sessions is no longer available for merge.');
                }

                $tableIds = collect([$source->restaurant_table_id, $target->restaurant_table_id])->sort()->values();
                $tables = RestaurantTable::whereIn('id', $tableIds)->lockForUpdate()->get()->keyBy('id');
                $sourceTable = $tables->get($source->restaurant_table_id);
                $targetTable = $tables->get($target->restaurant_table_id);

                if (!$sourceTable || !$targetTable || (int) $sourceTable->branch_id !== (int) $targetTable->branch_id) {
                    throw new \RuntimeException('Source and destination tables are invalid.');
                }
                if ((int) $sourceTable->id === (int) $targetTable->id
                    || !in_array($sourceTable->status, ['occupied', 'bill_requested'], true)
                    || !in_array($targetTable->status, ['occupied', 'bill_requested'], true)) {
                    throw new \RuntimeException('One of the tables is no longer in an active mergeable state.');
                }

                $activeSessionsByTable = RestaurantTableSession::whereIn('restaurant_table_id', [$sourceTable->id, $targetTable->id])
                    ->whereIn('status', ['open', 'bill_requested'])
                    ->lockForUpdate()
                    ->get(['id', 'restaurant_table_id'])
                    ->groupBy('restaurant_table_id');
                $activeSourceSessions = $activeSessionsByTable->get($sourceTable->id, collect());
                $activeTargetSessions = $activeSessionsByTable->get($targetTable->id, collect());

                if ($activeSourceSessions->count() !== 1 || (int) $activeSourceSessions->first()->id !== (int) $source->id) {
                    throw new \RuntimeException('The source table session changed. Refresh and try again.');
                }
                if ($activeTargetSessions->count() !== 1 || (int) $activeTargetSessions->first()->id !== (int) $target->id) {
                    throw new \RuntimeException('The destination table session changed. Refresh and try again.');
                }

                $activeSales = SalesOrder::where('restaurant_table_session_id', $source->id)
                    ->whereIn('status', ['held', 'draft'])
                    ->lockForUpdate()
                    ->get();

                if ($activeSales->isEmpty()) {
                    throw new \RuntimeException('The source table has no active held order to merge.');
                }

                // Paid fiscal history intentionally remains attached to the original session/table.
                SalesOrder::whereIn('id', $activeSales->pluck('id'))->update([
                    'restaurant_floor_id'         => $targetTable->restaurant_floor_id,
                    'restaurant_table_id'          => $targetTable->id,
                    'restaurant_table_session_id'  => $target->id,
                    'restaurant_waiter_id'         => $target->restaurant_waiter_id,
                ]);

                $billWasRequested = in_array('bill_requested', [$source->status, $target->status], true);
                $source->update([
                    'status'            => 'cancelled',
                    'closed_at'         => now(),
                    'closed_by_user_id' => auth('tenant')->id(),
                    'notes'             => trim(($source->notes ? $source->notes . ' | ' : '') . 'Active check merged into session ' . $target->session_no . '; paid history retained.'),
                ]);

                $mergedStatus = $billWasRequested ? 'bill_requested' : 'open';
                if ($target->status !== $mergedStatus) {
                    $target->update(['status' => $mergedStatus]);
                }
                $sourceTable->update(['status' => 'available']);
                $targetTable->update([
                    'status' => $mergedStatus === 'bill_requested' ? 'bill_requested' : 'occupied',
                ]);
            }, 3);
        } catch (\RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            return back()->withErrors(['session' => $e->getMessage()]);
        } catch (\Throwable $e) {
            report($e);
            if ($request->expectsJson()) {
                return response()->json(['message' => 'The table state changed while merging. Refresh and try again.'], 409);
            }
            return back()->withErrors(['session' => 'The table state changed while merging. Refresh and try again.']);
        }

        $targetSession->refresh()->load(['table', 'waiter', 'salesOrders']);
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'Table sessions merged successfully.',
                'session' => $this->sessionPayload($targetSession),
            ]);
        }

        return back()->with('status', 'Table sessions merged successfully.');
    }

    private function sessionPayload(RestaurantTableSession $session): array
    {
        $session->loadMissing(['table', 'waiter', 'salesOrders']);

        return [
            'id' => (int) $session->id,
            'session_no' => $session->session_no,
            'table_id' => (int) $session->restaurant_table_id,
            'table_no' => $session->table?->table_no,
            'waiter_name' => $session->waiter?->name,
            'guest_count' => (int) $session->guest_count,
            'status' => $session->status,
            'branch_id' => (int) $session->branch_id,
            'open_check' => (float) $session->salesOrders->where('status', 'held')->sum('grand_total'),
        ];
    }

    private function assertDineInAllowed(): void
    {
        abort_unless(
            auth('tenant')->user()?->allowsOrderType('dine_in'),
            403,
            'Your account is not allowed to use Dine In orders.'
        );
    }

    private function assertSessionAccess(RestaurantTableSession $session): void
    {
        app(\App\Services\Security\UserDataScope::class)->assertPosSelection(
            auth('tenant')->user(),
            (int) $session->branch_id,
            null,
        );
    }
}
