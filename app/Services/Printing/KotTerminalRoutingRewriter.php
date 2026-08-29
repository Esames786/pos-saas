<?php

namespace App\Services\Printing;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PHASE 4 — convert a branch's order-type-keyed KOT/reminder routing to TERMINAL-keyed, so a terminal
 * that carries several order types prints to its own counter (Phase 3 resolver honours it).
 *
 * Each rule with a concrete order type is pointed at the terminal that serves that order type
 * (Delivery → the "Delivery" terminal, Dine In → "Dine In", …) and its order type is relaxed to
 * "all" — the terminal now decides. Idempotent (only untouched, terminal-less rules convert) and
 * reversible (down() restores order_type from the terminal name). Delivery + Dine-In sales already
 * carry those terminal ids, so their KOTs resolve to the SAME printers as before.
 */
class KotTerminalRoutingRewriter
{
    /** Order type → the terminal NAME that serves it (matched case-insensitively within the branch). */
    public const ORDER_TYPE_TERMINAL = [
        'delivery'   => 'Delivery',
        'dine_in'    => 'Dine In',
        'takeaway'   => 'Takeaway',
        'quick_sale' => 'Quick Sale',
    ];

    /**
     * @return array{terminal_map: array<string,int>, pending: int, converted: int}
     */
    public function rewrite(int $branchId, bool $dryRun = false): array
    {
        $db = DB::connection('tenant');

        // Resolve each order type's terminal id BY NAME (branch-scoped). Abort if any is missing —
        // silently skipping would leave that order type routing to the wrong / no printer.
        $byName = $db->table('terminals')
            ->where('branch_id', $branchId)
            ->get(['id', 'name'])
            ->keyBy(fn ($t) => mb_strtolower(trim($t->name)));

        $map = [];
        foreach (self::ORDER_TYPE_TERMINAL as $orderType => $terminalName) {
            $key = mb_strtolower($terminalName);
            if (! $byName->has($key)) {
                throw new RuntimeException("Terminal '{$terminalName}' not found for branch {$branchId} — cannot rewrite KOT routing safely.");
            }
            $map[$orderType] = (int) $byName->get($key)->id;
        }

        // Only convert concrete-order-type rules that have NOT been pinned to a terminal yet — so a
        // re-run (or an already-migrated branch) is a no-op.
        $pending = $db->table('category_printer_mappings')
            ->where('branch_id', $branchId)
            ->whereIn('order_type', array_keys($map))
            ->whereNull('terminal_id')
            ->get(['id', 'order_type']);

        $converted = 0;
        if (! $dryRun) {
            foreach ($pending as $row) {
                $db->table('category_printer_mappings')->where('id', $row->id)->update([
                    'terminal_id' => $map[$row->order_type],
                    'order_type'  => 'all',
                    'updated_at'  => now(),
                ]);
                $converted++;
            }
        }

        return ['terminal_map' => $map, 'pending' => $pending->count(), 'converted' => $converted];
    }
}
