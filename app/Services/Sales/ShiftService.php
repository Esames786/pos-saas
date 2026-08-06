<?php

namespace App\Services\Sales;

use App\Exceptions\ShiftException;
use App\Models\Tenant\Branch;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use App\Support\TenantClock;
use Illuminate\Support\Facades\DB;

/**
 * SHIFT-TIMEZONE-BUSINESS-DATE-1 — the ONE canonical shift authority.
 *
 * Cloud POS and the future Offline Edge Branch Server both call this service so the invariant
 * is identical everywhere:
 *   - One open shift per terminal (row-locked open).
 *   - business_date + timezone frozen at open (business timezone = branch -> Asia/Karachi).
 *   - POS operational work requires an open shift (universal — NOT gated by terminals.requires_shift).
 *   - A shift cannot close while it still owns unresolved operational work.
 */
class ShiftService
{
    public function __construct(private readonly TenantClock $clock)
    {
    }

    /**
     * Open a shift for a terminal. Serializes concurrent opens by row-locking the terminal, then
     * re-checking under the lock, so two requests can never both open a shift for one terminal.
     * Freezes business_date + timezone_name at open (immutable thereafter).
     */
    public function open(Branch $branch, Terminal $terminal, int $userId, float $openingCash, ?string $notes = null): Shift
    {
        return DB::connection('tenant')->transaction(function () use ($branch, $terminal, $userId, $openingCash, $notes) {
            $locked = Terminal::where('id', $terminal->id)
                ->where('branch_id', $branch->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->activeShiftForTerminal($locked)) {
                throw new ShiftException('This terminal already has an open shift.');
            }

            $tz = $this->clock->businessTimezone($branch);

            return Shift::create([
                'branch_id'         => $branch->id,
                'terminal_id'       => $locked->id,
                'opened_by_user_id' => $userId,
                'opening_cash'      => $openingCash,
                'expected_cash'     => $openingCash,
                'status'            => 'open',
                'opened_at'         => now(),
                'business_date'     => $this->clock->businessDateForOpening($tz),
                'timezone_name'     => $tz,
                'opening_notes'     => $notes,
            ]);
        });
    }

    /** The current open shift for a terminal, or null. */
    public function activeShiftForTerminal(?Terminal $terminal): ?Shift
    {
        if (! $terminal) {
            return null;
        }

        return Shift::where('terminal_id', $terminal->id)
            ->where('status', 'open')
            ->latest('id')
            ->first();
    }

    /**
     * Universal POS enforcement: an open shift is REQUIRED to transact on a terminal. This
     * deliberately ignores terminals.requires_shift (kept only for backward compat) so no
     * terminal can bypass the rule online or offline. Throws a controlled ShiftException.
     */
    public function assertOpenShift(?Terminal $terminal): Shift
    {
        if (! $terminal) {
            throw new ShiftException('Select a terminal and open a shift before selling.');
        }

        $shift = $this->activeShiftForTerminal($terminal);

        if (! $shift) {
            throw new ShiftException('No open shift on this terminal. Open a shift to start selling.');
        }

        return $shift;
    }

    /**
     * Operational work still owned by a shift that must be settled before it can close:
     * its held (unpaid) sales, and its open/bill-requested restaurant table sessions.
     * @return array{held_sales: array<int,string>, open_tables: array<int,string>}
     */
    public function unresolvedWork(Shift $shift): array
    {
        $heldSales = SalesOrder::where('shift_id', $shift->id)
            ->where('status', 'held')
            ->pluck('sale_no', 'id')
            ->all();

        $openTables = [];
        if (\Illuminate\Support\Facades\Schema::connection('tenant')->hasColumn('restaurant_table_sessions', 'opened_shift_id')) {
            $openTables = \App\Models\Tenant\RestaurantTableSession::where('opened_shift_id', $shift->id)
                ->whereIn('status', ['open', 'bill_requested'])
                ->with('table')
                ->get()
                ->mapWithKeys(fn ($s) => [$s->id => 'Table ' . ($s->table?->table_no ?? $s->session_no)])
                ->all();
        }

        return ['held_sales' => $heldSales, 'open_tables' => $openTables];
    }

    /** True if the shift can be closed (no unresolved operational work). */
    public function canClose(Shift $shift): bool
    {
        $work = $this->unresolvedWork($shift);

        return empty($work['held_sales']) && empty($work['open_tables']);
    }
}
