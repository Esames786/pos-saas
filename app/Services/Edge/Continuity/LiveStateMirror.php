<?php

namespace App\Services\Edge\Continuity;

use App\Services\Edge\EdgeAuthorityService;
use App\Services\Edge\EdgeBranchContext;
use App\Support\EdgeRuntime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * EDGE 0.9 CONTINUITY — the appliance's READ-ONLY mirror of the branch's live Cloud state (contract K1).
 *
 * apply()         validates a K1 package and REPLACES the whole mirror in ONE transaction (replace-by-revision): every row is
 *                 written with the package revision + one `mirrored_at` instant, the previous set is removed, the singleton
 *                 edge_mirror_meta records revision / generated_at / counts. A package for another branch, an out-of-order
 *                 package (older generated_at) or a package arriving while the appliance is the writer is REFUSED — the mirror
 *                 a takeover adopts can never move under the cashier.
 * refreshIfDue()  the ONE guarded call of the authority tick's STANDBY phase: rate-limited (edge.continuity.poll_seconds,
 *                 default 30 s), time-boxed by the client's bounded timeouts, NEVER throws (a failure is recorded on the
 *                 mirror meta and nothing else) and never touches edge_local_meta — heartbeat, lease and connection state are
 *                 not this class's business.
 * status()        what the health page / cashier banner shows: "live state mirrored up to HH:MM:SS".
 *
 * Nothing mirrored is a financial fact. ContinuityAdoptionService turns rows into Edge drafts after a supervised takeover;
 * the Cloud remains the truth for anything never rung on the appliance.
 */
class LiveStateMirror
{
    public const STATUS_OK = 'ok';

    /** Bounds on one package (a branch is a restaurant, not a warehouse) — a runaway payload is refused, never half-applied. */
    private const MAX_SHIFTS = 200;
    private const MAX_SESSIONS = 500;
    private const MAX_HELD = 500;
    private const MAX_LINES_PER_ORDER = 400;

    /** Order types an adopted held bill may carry (the Branch Server's held-sale types). */
    private const HELD_ORDER_TYPES = ['quick_sale', 'takeaway', 'dine_in', 'delivery'];

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly LiveStateClient $client,
    ) {
    }

    /**
     * The tick hook. Never throws; returns a short machine string for the tick's `work` report.
     */
    public function refreshIfDue(): string
    {
        try {
            if (! EdgeRuntime::isBranchServer()) {
                return 'skipped:not-branch-server';
            }
            if ($this->client->url() === '') {
                return 'skipped:not-provisioned';
            }
            $meta = $this->context->current();
            if (! $meta || (string) ($meta->authority_state ?? EdgeAuthorityService::STANDBY) !== EdgeAuthorityService::STANDBY) {
                return 'skipped:not-standby';
            }
            $mirror = $this->metaRow();
            $poll = max(5, (int) config('edge.continuity.poll_seconds', 30));
            if ($mirror && $mirror->last_attempt_at && Carbon::parse($mirror->last_attempt_at)->gt(now()->subSeconds($poll))) {
                return 'skipped:not-due';
            }
            $this->touchMeta(['last_attempt_at' => now()]);

            $package = $this->client->fetch($mirror?->revision);
            if (! empty($package['unchanged'])) {
                if ($mirror && (string) $mirror->revision === (string) $package['revision']) {
                    $this->touchMeta(['mirrored_at' => now(), 'last_success_at' => now(), 'last_error' => null]);

                    return 'unchanged:' . $package['revision'];
                }

                throw new RuntimeException('LIVE_STATE_REVISION_MISMATCH: the Cloud answered unchanged for a revision this appliance does not hold.');
            }
            $applied = $this->apply($package);

            return 'applied:' . $applied['revision'];
        } catch (Throwable $e) {
            try {
                $this->touchMeta(['last_error' => mb_substr($e->getMessage(), 0, 500)]);
            } catch (Throwable $ignored) {
                // the mirror store itself is unavailable (schema not migrated yet) — nothing to record, nothing to do
            }
            Log::info('[edge-continuity] live-state refresh failed', ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return 'error:' . mb_substr($e->getMessage(), 0, 160);
        }
    }

    /**
     * Apply a full K1 package atomically.
     *
     * @param  array<string,mixed>  $package
     * @return array{revision:string, shifts:int, table_sessions:int, table_orders:int, held_sales:int, not_adoptable:int}
     */
    public function apply(array $package): array
    {
        $meta = $this->context->requireCurrent();
        $branchId = (int) $meta->branch_id;
        if ((string) ($meta->authority_state ?? EdgeAuthorityService::STANDBY) !== EdgeAuthorityService::STANDBY) {
            throw new RuntimeException('LIVE_STATE_NOT_STANDBY: the mirror is frozen while this appliance is (or is becoming) the branch writer.');
        }
        if ((int) ($package['branch_id'] ?? 0) !== $branchId) {
            throw new RuntimeException('LIVE_STATE_WRONG_BRANCH: the live-state package is not for this branch.');
        }
        $revision = trim((string) ($package['revision'] ?? ''));
        if ($revision === '' || mb_strlen($revision) > 128) {
            throw new RuntimeException('LIVE_STATE_MALFORMED: the package revision is missing or too long.');
        }
        $shifts = array_values((array) ($package['shifts'] ?? []));
        $sessions = array_values((array) ($package['table_sessions'] ?? []));
        $held = array_values((array) ($package['held_sales'] ?? []));
        if (count($shifts) > self::MAX_SHIFTS || count($sessions) > self::MAX_SESSIONS || count($held) > self::MAX_HELD) {
            throw new RuntimeException('LIVE_STATE_TOO_LARGE: the package exceeds the mirror bounds.');
        }
        $generatedAt = isset($package['generated_at']) && $package['generated_at'] !== '' ? Carbon::parse((string) $package['generated_at']) : null;
        $now = now();

        // Validate + normalise EVERYTHING before the first write (a malformed row refuses the package; nothing half-applies).
        $shiftRows = array_map(fn ($s) => $this->shiftRow((array) $s, $revision, $now), $shifts);
        $sessionRows = [];
        $orderRows = [];
        $lineRows = [];
        foreach ($sessions as $s) {
            $s = (array) $s;
            $sessionRows[] = $this->sessionRow($s, $revision, $now);
            foreach ((array) ($s['orders'] ?? []) as $o) {
                [$order, $lines] = $this->orderRows((array) $o, 'table', $revision, $now, (string) $s['session_uuid']);
                $orderRows[] = $order;
                array_push($lineRows, ...$lines);
            }
        }
        $heldRows = [];
        foreach ($held as $h) {
            [$order, $lines] = $this->orderRows((array) $h, 'held', $revision, $now, null);
            $heldRows[] = $order;
            array_push($lineRows, ...$lines);
        }
        $this->assertUnique(array_column($shiftRows, 'shift_uuid'), 'shift_uuid');
        $this->assertUnique(array_column($sessionRows, 'session_uuid'), 'session_uuid');
        $this->assertUnique(array_merge(array_column($orderRows, 'order_uuid'), array_column($heldRows, 'order_uuid')), 'order_uuid');

        DB::connection('tenant')->transaction(function () use ($branchId, $revision, $generatedAt, $now, $shiftRows, $sessionRows, $orderRows, $heldRows, $lineRows) {
            $conn = DB::connection('tenant');
            $current = $conn->table('edge_mirror_meta')->where('singleton_guard', 1)->lockForUpdate()->first();
            // Re-check the authority INSIDE the transaction: a takeover committed after the pre-check freezes the mirror.
            $authority = (string) ($conn->table('edge_local_meta')->value('authority_state') ?? EdgeAuthorityService::STANDBY);
            if ($authority !== EdgeAuthorityService::STANDBY) {
                throw new RuntimeException('LIVE_STATE_NOT_STANDBY: the mirror is frozen while this appliance is (or is becoming) the branch writer.');
            }
            if ($current && $current->generated_at && $generatedAt && $generatedAt->lt(Carbon::parse($current->generated_at))) {
                throw new RuntimeException('LIVE_STATE_OUT_OF_ORDER: the package is older than the mirror already held.');
            }
            foreach (['edge_mirror_order_lines', 'edge_mirror_held_sales', 'edge_mirror_table_orders', 'edge_mirror_table_sessions', 'edge_mirror_shifts'] as $table) {
                $conn->table($table)->delete();
            }
            foreach ([
                'edge_mirror_shifts' => $shiftRows, 'edge_mirror_table_sessions' => $sessionRows, 'edge_mirror_table_orders' => $orderRows,
                'edge_mirror_held_sales' => $heldRows, 'edge_mirror_order_lines' => $lineRows,
            ] as $table => $rows) {
                foreach (array_chunk($rows, 200) as $chunk) {
                    $conn->table($table)->insert($chunk);
                }
            }
            $fields = [
                'branch_id' => $branchId, 'revision' => $revision, 'generated_at' => $generatedAt, 'mirrored_at' => $now,
                'last_success_at' => $now, 'last_error' => null,
                'shifts_count' => count($shiftRows), 'table_sessions_count' => count($sessionRows), 'held_sales_count' => count($heldRows),
                'updated_at' => $now,
            ];
            if ($current) {
                $conn->table('edge_mirror_meta')->where('id', $current->id)->update($fields);
            } else {
                $conn->table('edge_mirror_meta')->insert($fields + ['singleton_guard' => 1, 'created_at' => $now]);
            }
        });

        return [
            'revision' => $revision, 'shifts' => count($shiftRows), 'table_sessions' => count($sessionRows), 'table_orders' => count($orderRows),
            'held_sales' => count($heldRows),
            'not_adoptable' => count(array_filter(array_merge($orderRows, $heldRows), fn ($r) => ! $r['adoptable'])),
        ];
    }

    /** @return array{available:bool, revision:?string, mirrored_at:?string, mirrored_at_display:?string, age_seconds:?int, shifts:int, table_sessions:int, held_sales:int, last_error:?string} */
    public function status(): array
    {
        $row = null;
        try {
            $row = $this->metaRow();
        } catch (Throwable $e) {
            $row = null;
        }
        $at = $row?->mirrored_at ? Carbon::parse($row->mirrored_at) : null;

        return [
            'available' => $at !== null,
            'revision' => $row?->revision,
            'mirrored_at' => $at?->toIso8601String(),
            'mirrored_at_display' => $at ? self::display($at) : null,
            'age_seconds' => $at ? max(0, (int) $at->diffInSeconds(now(), true)) : null,
            'shifts' => (int) ($row->shifts_count ?? 0),
            'table_sessions' => (int) ($row->table_sessions_count ?? 0),
            'held_sales' => (int) ($row->held_sales_count ?? 0),
            'last_error' => $row?->last_error,
        ];
    }

    /** "HH:MM:SS" in the branch's business timezone (the shop clock the cashier reads). */
    public static function display(Carbon|string|null $instant): ?string
    {
        if ($instant === null || $instant === '') {
            return null;
        }
        $at = $instant instanceof Carbon ? $instant->copy() : Carbon::parse($instant);
        try {
            $branch = \App\Models\Tenant\Branch::on('tenant')->find((int) app(EdgeBranchContext::class)->current()?->branch_id);
            $tz = $branch ? app(\App\Support\TenantClock::class)->businessTimezone($branch) : config('app.timezone', 'UTC');
        } catch (Throwable $e) {
            $tz = config('app.timezone', 'UTC');
        }

        return $at->setTimezone($tz ?: 'UTC')->format('H:i:s');
    }

    // ── normalisation ───────────────────────────────────────────────────────────────────────────────────────────────

    private function shiftRow(array $s, string $revision, Carbon $now): array
    {
        $uuid = $this->ulid($s['shift_uuid'] ?? null, 'shift_uuid');
        $running = (array) ($s['running'] ?? []);
        $terminalId = (int) ($s['terminal_id'] ?? 0);
        if ($terminalId <= 0) {
            throw new RuntimeException('LIVE_STATE_MALFORMED: a mirrored shift has no terminal_id.');
        }

        return [
            'shift_uuid' => $uuid,
            'terminal_id' => $terminalId,
            'status' => mb_substr((string) ($s['status'] ?? 'open'), 0, 20),
            'business_date' => isset($s['business_date']) && $s['business_date'] ? Carbon::parse((string) $s['business_date'])->toDateString() : null,
            'opened_at' => $this->instant($s['opened_at'] ?? null),
            'opened_by_user_id' => isset($s['opened_by_user_id']) ? (int) $s['opened_by_user_id'] : null,
            'opening_cash' => $this->money($s['opening_cash'] ?? 0),
            'cash_sales' => $this->money($running['cash_sales'] ?? 0),
            'card_sales' => $this->money($running['card_sales'] ?? 0),
            'refunds' => $this->money($running['refunds'] ?? 0),
            'cash_in' => $this->money($running['cash_in'] ?? 0),
            'cash_out' => $this->money($running['cash_out'] ?? 0),
            'expected_cash' => $this->money($running['expected_cash'] ?? ($s['opening_cash'] ?? 0)),
            'revision' => $revision, 'mirrored_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ];
    }

    private function sessionRow(array $s, string $revision, Carbon $now): array
    {
        $tableId = (int) ($s['table_id'] ?? 0);
        if ($tableId <= 0) {
            throw new RuntimeException('LIVE_STATE_MALFORMED: a mirrored table session has no table_id.');
        }

        return [
            'session_uuid' => $this->ulid($s['session_uuid'] ?? null, 'session_uuid'),
            'table_id' => $tableId,
            'session_no' => isset($s['session_no']) ? mb_substr((string) $s['session_no'], 0, 100) : null,
            'opened_at' => $this->instant($s['opened_at'] ?? null),
            'covers' => isset($s['covers']) ? max(0, (int) $s['covers']) : null,
            'waiter_user_id' => isset($s['waiter_user_id']) ? (int) $s['waiter_user_id'] : (isset($s['restaurant_waiter_id']) ? (int) $s['restaurant_waiter_id'] : null),
            'revision' => $revision, 'mirrored_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ];
    }

    /**
     * One order (table order or held sale) + its lines. ADOPTABLE only when the Branch Server can take it over EXACTLY: unpaid,
     * every line a plain product line (no deal rows — K1 carries no deal structure yet), coherent money, at least one line.
     *
     * @return array{0: array<string,mixed>, 1: list<array<string,mixed>>}
     */
    private function orderRows(array $o, string $source, string $revision, Carbon $now, ?string $sessionUuid): array
    {
        $uuid = $this->ulid($o['order_uuid'] ?? null, 'order_uuid');
        $lines = array_values((array) ($o['lines'] ?? []));
        if (count($lines) > self::MAX_LINES_PER_ORDER) {
            throw new RuntimeException('LIVE_STATE_TOO_LARGE: a mirrored order has too many lines.');
        }
        $payments = array_values((array) ($o['payments'] ?? []));
        $paid = 0.0;
        foreach ($payments as $p) {
            $paid += (float) ($p['amount'] ?? 0);
        }
        $lineRows = [];
        $reason = null;
        $sum = 0.0;
        $lineDiscounts = 0.0;
        $lineTaxes = 0.0;
        foreach ($lines as $i => $l) {
            $l = (array) $l;
            $kind = (string) ($l['line_kind'] ?? 'standard');
            if ($kind !== 'standard' || ! empty($l['combo_id'])) {
                $reason ??= 'DEAL_LINES_NOT_MIRRORED';
            }
            $qty = round((float) ($l['qty'] ?? 0), 3);
            $price = round((float) ($l['unit_price'] ?? 0), 2);
            if ($qty <= 0) {
                $reason ??= 'LINE_QUANTITY_INVALID';
            }
            $sum += $qty * $price;
            $lineDiscounts += (float) ($l['discount_amount'] ?? 0);
            $lineTaxes += (float) ($l['tax_amount'] ?? 0);
            $lineRows[] = [
                'order_uuid' => $uuid,
                'source' => $source,
                'line_uuid' => $this->ulid($l['line_uuid'] ?? null, 'line_uuid'),
                'position' => $i,
                'line_kind' => mb_substr($kind, 0, 20),
                'product_id' => (int) ($l['product_id'] ?? 0),
                'variant_id' => isset($l['variant_id']) && $l['variant_id'] !== null ? (int) $l['variant_id'] : null,
                'product_name' => isset($l['product_name']) ? mb_substr((string) $l['product_name'], 0, 255) : null,
                'qty' => $qty,
                'unit_price' => $price,
                'discount_amount' => $this->money($l['discount_amount'] ?? 0),
                'tax_amount' => $this->money($l['tax_amount'] ?? 0),
                'notes' => isset($l['notes']) && $l['notes'] !== '' ? mb_substr((string) $l['notes'], 0, 1000) : null,
                'modifiers' => json_encode(is_array($l['modifiers'] ?? null) ? $l['modifiers'] : [], JSON_UNESCAPED_UNICODE),
                'kot_state' => isset($l['kot_state']) ? mb_substr((string) $l['kot_state'], 0, 20) : null,
                'kot_batch_uuid' => isset($l['kot_batch_uuid']) && $l['kot_batch_uuid'] !== '' ? mb_substr((string) $l['kot_batch_uuid'], 0, 36) : null,
                'revision' => $revision, 'created_at' => $now, 'updated_at' => $now,
            ];
            if ((int) ($l['product_id'] ?? 0) <= 0) {
                throw new RuntimeException('LIVE_STATE_MALFORMED: a mirrored line has no product_id.');
            }
        }
        $this->assertUnique(array_column($lineRows, 'line_uuid'), 'line_uuid');

        $total = round((float) ($o['total'] ?? 0), 2);
        $subtotal = array_key_exists('subtotal', $o) ? round((float) $o['subtotal'], 2) : round($sum, 2);
        $discount = array_key_exists('discount', $o) ? round((float) $o['discount'], 2) : round($lineDiscounts, 2);
        $tax = array_key_exists('tax', $o) ? round((float) $o['tax'], 2) : round($lineTaxes, 2);
        $service = round((float) ($o['service_charge'] ?? 0), 2);
        if ($lines === []) {
            $reason ??= 'NO_LINES';
        }
        if ($paid > 0.0049) {
            $reason ??= 'PAID_IN_PART_ONLINE';
        }
        if (abs(($subtotal - $discount + $tax + $service) - $total) > 0.011) {
            $reason ??= 'MONEY_INCOHERENT';
        }
        $orderType = $source === 'table' ? 'dine_in' : (string) ($o['order_type'] ?? 'takeaway');
        if ($source === 'held' && ! in_array($orderType, self::HELD_ORDER_TYPES, true)) {
            $reason ??= 'ORDER_TYPE_UNSUPPORTED';
        }

        $row = [
            'order_uuid' => $uuid,
            'order_no' => isset($o['order_no']) ? mb_substr((string) $o['order_no'], 0, 100) : null,
            'shift_uuid' => isset($o['shift_uuid']) && $o['shift_uuid'] ? $this->ulid($o['shift_uuid'], 'shift_uuid') : null,
            'terminal_id' => isset($o['terminal_id']) ? (int) $o['terminal_id'] : null,
            'subtotal' => $subtotal, 'discount' => $discount, 'tax' => $tax, 'service_charge' => $service, 'total' => $total,
            'paid_total' => round($paid, 2),
            'adoptable' => $reason === null,
            'not_adoptable_reason' => $reason,
            'fingerprint' => ContinuityFingerprint::fromMirror($o),
            'revision' => $revision, 'mirrored_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ];
        if ($source === 'table') {
            $row += ['session_uuid' => (string) $sessionUuid, 'status' => mb_substr((string) ($o['status'] ?? 'open'), 0, 30),
                'payments' => json_encode($payments, JSON_UNESCAPED_UNICODE)];
        } else {
            $row += ['order_type' => $orderType, 'held_at' => $this->instant($o['held_at'] ?? null),
                'customer_id' => isset($o['customer_id']) && $o['customer_id'] !== null ? (int) $o['customer_id'] : null];
        }

        return [$row, $lineRows];
    }

    private function ulid(mixed $value, string $field): string
    {
        $v = is_scalar($value) ? trim((string) $value) : '';
        if (! \App\Support\Edge\EdgeIdentity::isValid($v, \App\Support\Edge\EdgeIdentity::FORMAT_ULID)) {
            throw new RuntimeException("LIVE_STATE_MALFORMED: {$field} is not a canonical identity.");
        }

        return strtoupper($v);
    }

    private function money(mixed $v): float
    {
        return round((float) $v, 2);
    }

    private function instant(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return Carbon::parse((string) $v)->setTimezone(config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    }

    private function assertUnique(array $values, string $field): void
    {
        if (count($values) !== count(array_unique($values))) {
            throw new RuntimeException("LIVE_STATE_MALFORMED: duplicate {$field} in the package.");
        }
    }

    private function metaRow(): ?object
    {
        return DB::connection('tenant')->table('edge_mirror_meta')->where('singleton_guard', 1)->first();
    }

    private function touchMeta(array $fields): void
    {
        $conn = DB::connection('tenant');
        $fields['updated_at'] = now();
        if ($conn->table('edge_mirror_meta')->where('singleton_guard', 1)->exists()) {
            $conn->table('edge_mirror_meta')->where('singleton_guard', 1)->update($fields);
        } else {
            $conn->table('edge_mirror_meta')->insertOrIgnore($fields + ['singleton_guard' => 1, 'created_at' => now()]);
        }
    }
}
