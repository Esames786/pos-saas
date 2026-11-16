<?php

namespace App\Support;

use App\Models\Tenant\SalesOrderLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DEAL-SPLIT-REPORT-1 — the ONE place that says whether a deal's money is reported under the items inside it.
 *
 * The owner gives a deal a part per item on the deal's own form (a platter of 900 = biryani 400 + BBQ 500).
 * At the moment of sale those parts are copied onto the bill's lines (`sales_order_lines.deal_share`), so a
 * later edit of the deal never moves an old report. The reports then file the deal's money under its items,
 * in proportion to those parts (a discounted deal splits in the same proportion), the deal's own row going away.
 *
 * Nothing on the bill changes: price, line_total, receipt, KOT, payment, GL and Net Sales are exactly as before.
 *
 * Off (the default, and for every tenant until the Master switch is turned on): nothing is written and every
 * report runs its old code, so no other tenant can see a difference.
 */
final class DealSplit
{
    /** @var array<string, bool> tenant database => the three columns exist */
    private static array $columns = [];

    /** On for this tenant — the Master switch AND the schema in place (deploy window: code before migration). */
    public static function active(): bool
    {
        return TenantFeatures::on(TenantFeatures::DEAL_SPLIT) && self::columnsReady();
    }

    public static function columnsReady(): bool
    {
        $db = (string) DB::connection('tenant')->getDatabaseName();

        return self::$columns[$db] ??= (function () {
            $schema = Schema::connection('tenant');

            return $schema->hasColumn('sales_order_lines', 'deal_share')
                && $schema->hasColumn('combo_components', 'report_amount')
                && $schema->hasColumn('combos', 'report_by_items');
        })();
    }

    /** Tests only: forget the schema answer (a test database is rebuilt between tests). */
    public static function flush(): void
    {
        self::$columns = [];
    }

    private static int $copying = 0;

    /**
     * Lines MOVED from one bill to another (Split Bill) keep exactly the parts they were sold with — or none —
     * instead of reading the deal's parts again: run the move inside this.
     *
     * @template T
     * @param  callable(): T  $move
     * @return T
     */
    public static function copying(callable $move): mixed
    {
        self::$copying++;
        try {
            return $move();
        } finally {
            self::$copying--;
        }
    }

    /**
     * Called when a sale line is created (SalesOrderLine::creating) — every write path (counter, held bill,
     * table, split bill, website) creates its lines through the model, so this is the one place.
     *
     * Writes `deal_share` ONLY when it has a value (switch on, deal split by items): a tenant with the switch
     * off never names the column, so a deploy that runs new code a second before the migration cannot fail a sale.
     */
    public static function stamp(SalesOrderLine $line): void
    {
        $kind = $line->line_kind ?? 'standard';
        if (self::$copying > 0 || ! in_array($kind, ['combo_header', 'component'], true) || ! $line->combo_id || $line->deal_share !== null) {
            return;
        }
        if (! self::active()) {
            return;
        }

        $parts = self::parts((int) $line->combo_id);
        if ($parts === null) {
            return;
        }

        if ($kind === 'combo_header') {
            $line->deal_share = $parts['total'];

            return;
        }

        $key = (int) $line->product_id . ':' . ($line->product_variant_id ? (int) $line->product_variant_id : '');
        $line->deal_share = $parts['items'][$key] ?? $parts['items'][(int) $line->product_id . ':*'] ?? 0;
    }

    /**
     * The deal's parts as they stand now: total of parts and the part per item (product:variant).
     * Null when the deal is not split by items or its parts add up to nothing.
     * An item listed twice in one deal gets the average of its rows on each of its lines, so the two lines
     * together still carry both parts.
     *
     * @return array{total: float, items: array<string, float>}|null
     */
    public static function parts(int $comboId): ?array
    {
        $conn = DB::connection('tenant');
        $combo = $conn->table('combos')->where('id', $comboId)->first(['id', 'report_by_items']);
        if (! $combo || ! (bool) $combo->report_by_items) {
            return null;
        }

        $rows = $conn->table('combo_components')->where('combo_id', $comboId)
            ->get(['product_id', 'product_variant_id', 'report_amount']);

        $total = round((float) $rows->sum(fn ($r) => (float) ($r->report_amount ?? 0)), 2);
        if ($total <= 0) {
            return null;
        }

        $items = [];
        foreach ($rows->groupBy(fn ($r) => (int) $r->product_id . ':' . ($r->product_variant_id ? (int) $r->product_variant_id : '')) as $key => $group) {
            $items[$key] = round($group->avg(fn ($r) => (float) ($r->report_amount ?? 0)), 2);
        }
        // A line whose variant differs from the deal's row (or a row without a variant) still finds its product.
        foreach ($rows->groupBy(fn ($r) => (int) $r->product_id) as $pid => $group) {
            $items[$pid . ':*'] = round($group->avg(fn ($r) => (float) ($r->report_amount ?? 0)), 2);
        }

        return ['total' => $total, 'items' => $items];
    }
}
