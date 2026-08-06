<?php

namespace App\Services\Sales;

use App\Exceptions\ShiftException;
use App\Models\Tenant\Branch;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use App\Support\TenantClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
                // Stable cross-system identity (the future Edge sale envelope carries THIS, never the
                // local auto-increment id). Immutable once set; generated at open.
                'shift_uuid'        => (string) Str::ulid(),
                'opening_notes'     => $notes,
            ]);
        });
    }

    /**
     * SHIFT-BRANCH-UX-1: bulk-open shifts for several terminals of ONE branch in a single manager
     * action (branch opens → all its terminals go live at once). Each terminal is still opened via
     * the canonical per-terminal open() (row-locked, freezes business_date/timezone/shift_uuid), so
     * every invariant holds. Terminals that already have an open shift, or are not active in this
     * branch, are SKIPPED (with a reason) — never a hard failure.
     *
     * @param array<int> $terminalIds
     * @param array<int,float> $openingCashByTerminal per-terminal override; else $defaultOpeningCash
     * @return array{opened: array<int,Shift>, skipped: array<int,string>}
     */
    public function openMany(Branch $branch, array $terminalIds, int $userId, float $defaultOpeningCash, array $openingCashByTerminal = [], ?string $notes = null): array
    {
        $opened = [];
        $skipped = [];

        foreach (array_unique(array_map('intval', $terminalIds)) as $tid) {
            $terminal = Terminal::where('id', $tid)->where('branch_id', $branch->id)->where('status', 'active')->first();
            if (! $terminal) {
                $skipped[$tid] = 'Terminal #' . $tid . ' is not an active terminal in this branch';
                continue;
            }
            if ($this->activeShiftForTerminal($terminal)) {
                $skipped[$tid] = $terminal->name . ' already has an open shift';
                continue;
            }
            try {
                $cash = array_key_exists($tid, $openingCashByTerminal) ? (float) $openingCashByTerminal[$tid] : $defaultOpeningCash;
                $opened[$tid] = $this->open($branch, $terminal, $userId, $cash, $notes);
            } catch (ShiftException $e) {
                // open() re-checks under lock; a concurrent open just becomes a skip, not a 500.
                $skipped[$tid] = $terminal->name . ': ' . $e->getMessage();
            }
        }

        return ['opened' => $opened, 'skipped' => $skipped];
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
     *
     * NOTE: this is a NON-locking read — safe for a pre-flight check or a read-only badge. Any
     * write path that then persists commercial state MUST use lockOpenShiftForTerminal() INSIDE
     * its own transaction so a concurrent close cannot slip the shift shut after the check (TOCTOU).
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
     * SHIFT-TIMEZONE-BUSINESS-DATE-HARDEN-1: the atomic open-shift assertion. Call this INSIDE the
     * write transaction that persists the mutation. It row-locks the open shift (FOR UPDATE) and
     * re-validates status under the lock, so a concurrent close (which locks the same row) is
     * serialized against it — the shift can never be closed out from under an in-flight sale/hold,
     * and a mutation can never commit against an already-closed shift.
     *
     * Lock ordering: acquire this shift lock FIRST in every write path (before locking held orders
     * or table sessions) so all POS mutations take locks in a consistent order (shift -> record).
     */
    public function lockOpenShiftForTerminal(?Terminal $terminal): Shift
    {
        if (! $terminal) {
            throw new ShiftException('Select a terminal and open a shift before selling.');
        }

        $shift = Shift::where('terminal_id', $terminal->id)
            ->where('status', 'open')
            ->lockForUpdate()
            ->latest('id')
            ->first();

        if (! $shift) {
            throw new ShiftException('No open shift on this terminal. Open a shift to start selling.');
        }

        return $shift;
    }

    /**
     * Branch-level open-shift lock for operations that are not terminal-scoped (e.g. opening a
     * restaurant table from the floor board). Locks the latest open shift in the branch and returns
     * it; throws if the branch has no open shift. Same TOCTOU protection as the terminal variant.
     */
    public function lockOpenShiftForBranch(int $branchId): Shift
    {
        $shift = Shift::where('branch_id', $branchId)
            ->where('status', 'open')
            ->lockForUpdate()
            ->latest('id')
            ->first();

        if (! $shift) {
            throw new ShiftException('No open shift in this branch. Open a shift before opening tables.');
        }

        return $shift;
    }

    /**
     * The close-side of the same lock. Call INSIDE the close transaction: row-locks the shift, then
     * (still holding the lock) asserts it is open and has no unresolved operational work. Because a
     * new sale/hold locks the same row via lockOpenShiftForTerminal, this deterministically orders
     * close against in-flight mutations. Throws ShiftException (never 500) on a blocked close.
     */
    public function assertClosableUnderLock(Shift $shift): Shift
    {
        $locked = Shift::where('id', $shift->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== 'open') {
            throw new ShiftException('This shift is already closed.');
        }

        // CRITICAL: use LOCKING (current) reads here. Under REPEATABLE READ, the non-locking
        // snapshot is fixed at the transaction's first read and would MISS a held/table row a racing
        // sale/hold committed while we were blocked on the shift lock. lockForUpdate forces a current
        // read so a just-committed unresolved row is always seen and correctly blocks the close.
        $work = $this->unresolvedWork($locked, true);
        if (! empty($work['held_sales']) || ! empty($work['open_tables'])) {
            throw new ShiftException($this->describeUnresolvedWork($work));
        }

        return $locked;
    }

    /** Human-readable summary of what is blocking a shift close. */
    public function describeUnresolvedWork(array $work): string
    {
        $bits = [];
        if (! empty($work['held_sales'])) {
            $bits[] = count($work['held_sales']) . ' held order(s): ' . implode(', ', $work['held_sales']);
        }
        if (! empty($work['open_tables'])) {
            $bits[] = count($work['open_tables']) . ' open table(s): ' . implode(', ', $work['open_tables']);
        }

        return 'Settle all open work before closing this shift — ' . implode('; ', $bits) . '.';
    }

    /**
     * Operational work still owned by a shift that must be settled before it can close: its held
     * (unpaid) sales — which includes unpaid SPLIT children, since a split creates a held order that
     * inherits the parent's shift_id — and its open/bill-requested restaurant table sessions.
     *
     * DRAFT is deliberately NOT counted: it is a transient in-transaction state during direct-pay
     * checkout only (SalesOrderController@store creates 'draft' then finalizePaidSale flips it to
     * 'paid' before commit, or the whole transaction rolls back). No committed row ever survives as
     * 'draft', and an in-flight checkout locks the same shift row (lockOpenShiftForTerminal), so it
     * cannot straddle a close. (Proven by test_direct_pay_..._real_path asserting the committed
     * status is 'paid'.)
     *
     * $lockForUpdate makes these CURRENT reads (used by the atomic close guard so it can never miss
     * a row a racing operation just committed). Left false for read-only display callers.
     *
     * @return array{held_sales: array<int,string>, open_tables: array<int,string>}
     */
    public function unresolvedWork(Shift $shift, bool $lockForUpdate = false): array
    {
        $heldSales = SalesOrder::where('shift_id', $shift->id)
            ->where('status', 'held')
            ->when($lockForUpdate, fn ($q) => $q->lockForUpdate())
            ->pluck('sale_no', 'id')
            ->all();

        $openTables = [];
        if (\Illuminate\Support\Facades\Schema::connection('tenant')->hasColumn('restaurant_table_sessions', 'opened_shift_id')) {
            $openTables = \App\Models\Tenant\RestaurantTableSession::where('opened_shift_id', $shift->id)
                ->whereIn('status', ['open', 'bill_requested'])
                ->when($lockForUpdate, fn ($q) => $q->lockForUpdate())
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
