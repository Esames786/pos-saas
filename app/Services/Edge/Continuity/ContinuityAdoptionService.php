<?php

namespace App\Services\Edge\Continuity;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Product;
use App\Models\Tenant\RestaurantTable;
use App\Models\Tenant\RestaurantTableSession;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesOrderLine;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeAuthorityService;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Sales\ShiftService;
use App\Support\EdgeRuntime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * EDGE 0.9 CONTINUITY — ADOPT the mirrored live state at the first POS touch after a supervised Switch to Offline.
 *
 * LAZY + OUTSIDE the authority engine (EdgeAuthorityService is not touched): the shared cashier flow (shift badge, POS screen,
 * table board, held list, open table) calls ensureAdopted() while the appliance is LOCAL_ACTIVE. Once per local-authority
 * SEGMENT (edge_local_meta.authority_takeover_at) the whole branch is adopted in ONE transaction, each item in its own savepoint:
 *
 *   shifts        every mirrored OPEN shift whose terminal has no Edge shift yet → an Edge shift opened through the SHARED
 *                 ShiftService::open (terminal row lock) with opening cash = the mirrored expected cash, then stamped
 *                 continues_cloud_shift_uuid / continuity_segment / continuity_mirrored_at (UNIQUE per terminal + Cloud shift +
 *                 segment — the race guard) — the counter continues its online shift, nothing to open by hand;
 *   held bills    every ADOPTABLE mirrored held sale → an Edge held sale with the SAME sale_uuid / sale_no (adopted_from_order_uuid,
 *                 mirror revision + instant + fingerprint) on the continuation shift of its counter;
 *   tables        every mirrored open table session whose orders are ALL adoptable → an Edge session with the SAME session_uuid,
 *                 its orders as Edge held sales whose lines carry the Cloud line_uuid and, when the kitchen already has them,
 *                 kot_sent = 1 / kot_sent_quantity = qty — the shared KOT pipeline fires only the unsent delta, so a fired line
 *                 is never re-printed and a line added on the appliance fires normally.
 *
 * NOTHING here is a financial fact: no payment, no stock, no settlement, no outbox row. An adopted order becomes money only when
 * the cashier settles it on the appliance (the existing settle path), and its envelope then tells the Cloud to complete the
 * EXISTING order (EdgeSaleEnvelopeBuilder `adoption`, EdgeInboundSaleIngestionService merge). Every adoption — and every item
 * skipped, with its reason — is one `edge_mirror_adoptions` row (UNIQUE kind + Cloud identity + segment: idempotent).
 */
class ContinuityAdoptionService
{
    public const KIND_SHIFT = 'shift';
    public const KIND_HELD = 'held_sale';
    public const KIND_SESSION = 'table_session';
    public const KIND_TABLE_ORDER = 'table_order';

    public const STATUS_ADOPTED = 'adopted';
    public const STATUS_REFUSED_STALE = 'refused_stale';
    public const STATUS_REFUSED_UNTRUSTED = 'refused_untrusted';
    public const STATUS_NOTHING_MIRRORED = 'nothing_mirrored';

    private const REASON_NOTHING_MIRRORED = 'The online shift was not mirrored to this Branch Server before the switch — open a shift to continue.';

    /** Refresh errors that make the held revision untrustworthy (a transport failure during the outage is NOT one of them). */
    private const UNTRUSTED_ERRORS = ['LIVE_STATE_REVISION_MISMATCH', 'LIVE_STATE_OUT_OF_ORDER', 'LIVE_STATE_WRONG_BRANCH', 'LIVE_STATE_MALFORMED', 'LIVE_STATE_TOO_LARGE'];

    /** Cloud KOT states that mean "the kitchen does NOT have this line yet" — anything else (incl. unknown) counts as fired. */
    private const NOT_FIRED_STATES = ['pending', 'unsent', 'not_sent', 'draft'];

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly ShiftService $shifts,
    ) {
    }

    /** The current local-authority segment (UTC takeover instant), or null when the appliance is not the branch writer. */
    public function segment(): ?string
    {
        if (! EdgeRuntime::isBranchServer()) {
            return null;
        }
        $meta = $this->context->current();
        if (! $meta || (string) ($meta->authority_state ?? '') !== EdgeAuthorityService::LOCAL_ACTIVE || ! $meta->authority_takeover_at) {
            return null;
        }

        return Carbon::parse($meta->authority_takeover_at)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /** Controller-facing wrapper: adoption is best-effort and must NEVER break the POS request that triggered it. */
    public function ensureAdoptedQuietly(?User $user, ?int $preferredTerminalId = null): ?array
    {
        if (! $user) {
            return null;
        }
        try {
            return $this->ensureAdopted($user, $preferredTerminalId);
        } catch (Throwable $e) {
            Log::warning('[edge-continuity] adoption deferred', ['error' => mb_substr($e->getMessage(), 0, 300)]);

            return ['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 300)];
        }
    }

    /**
     * Adopt the mirrored state for the current segment (idempotent, race-safe, atomic per item).
     *
     * @return array{status:string, segment?:string, shifts?:int, held_sales?:int, table_sessions?:int, table_orders?:int, skipped?:list<array{kind:string,uuid:string,reason:string}>}
     */
    public function ensureAdopted(User $user, ?int $preferredTerminalId = null): array
    {
        $segment = $this->segment();
        if ($segment === null) {
            return ['status' => 'skipped:not-local'];
        }
        $conn = DB::connection('tenant');
        $fast = $conn->table('edge_mirror_meta')->where('singleton_guard', 1)->first();
        if ($fast && (string) $fast->adopted_segment === $segment) {
            return ['status' => 'already', 'segment' => $segment];
        }

        return $conn->transaction(function () use ($user, $preferredTerminalId, $segment, $conn) {
            $mirror = $conn->table('edge_mirror_meta')->where('singleton_guard', 1)->lockForUpdate()->first();
            if (! $mirror) {
                // Never mirrored (pre-0.9 Cloud, or the first poll never succeeded): nothing to adopt — the counter opens a shift as before.
                $conn->table('edge_mirror_meta')->insertOrIgnore(['singleton_guard' => 1, 'adopted_segment' => $segment, 'adopted_at' => now(),
                    'adoption_status' => self::STATUS_NOTHING_MIRRORED, 'adoption_reason' => self::REASON_NOTHING_MIRRORED, 'adoption_takeover_by' => $this->takeover()['by'], 'created_at' => now(), 'updated_at' => now()]);

                return ['status' => self::STATUS_NOTHING_MIRRORED, 'segment' => $segment, 'reason' => self::REASON_NOTHING_MIRRORED];
            }
            if ((string) $mirror->adopted_segment === $segment) {
                return ['status' => 'already', 'segment' => $segment];
            }
            $meta = $this->context->requireCurrent();
            // OWNER (CP1): STALE OR UNTRUSTED MIRRORED STATE FAILS CLOSED — nothing is adopted for this segment, the counter gets
            // the normal "open shift" flow, and the reason is recorded for the cashier banner and the audit log.
            if ($refusal = $this->freshnessRefusal($mirror, $meta)) {
                $conn->table('edge_mirror_meta')->where('id', $mirror->id)->update([
                    'adopted_segment' => $segment, 'adopted_at' => now(), 'adoption_status' => $refusal['status'],
                    'adoption_reason' => mb_substr($refusal['reason'], 0, 500), 'adoption_takeover_by' => $this->takeover()['by'], 'updated_at' => now(),
                ]);
                Log::warning('[edge-continuity] adoption refused', ['segment' => $segment, 'takeover' => $this->takeover()] + $refusal);

                return ['status' => $refusal['status'], 'segment' => $segment, 'reason' => $refusal['reason']];
            }
            $branch = Branch::on('tenant')->findOrFail((int) $meta->branch_id);
            $summary = ['status' => 'adopted', 'segment' => $segment, 'shifts' => 0, 'held_sales' => 0, 'table_sessions' => 0, 'table_orders' => 0, 'skipped' => []];
            if ($mirror->branch_id !== null && (int) $mirror->branch_id !== (int) $branch->id) {
                throw new RuntimeException('CONTINUITY_WRONG_BRANCH: the mirror belongs to another branch.');
            }
            $ctx = ['mirrored_at' => $mirror->mirrored_at, 'revision' => $mirror->revision, 'segment' => $segment, 'user' => $user,
                'preferred_terminal_id' => $preferredTerminalId, 'branch' => $branch, 'epoch' => (int) $meta->activation_epoch, 'takeover' => $this->takeover()];

            foreach ($conn->table('edge_mirror_shifts')->where('status', 'open')->orderBy('terminal_id')->get() as $ms) {
                $this->item($summary, self::KIND_SHIFT, (string) $ms->shift_uuid, $ctx, fn () => $this->adoptShift($ms, $ctx), 'shifts');
            }
            foreach ($conn->table('edge_mirror_held_sales')->orderBy('id')->get() as $mh) {
                if (! $mh->adoptable) {
                    $this->record(self::KIND_HELD, (string) $mh->order_uuid, $ctx, 'skipped', (string) $mh->not_adoptable_reason, null, null, (string) $mh->fingerprint);
                    $summary['skipped'][] = ['kind' => self::KIND_HELD, 'uuid' => (string) $mh->order_uuid, 'reason' => (string) $mh->not_adoptable_reason];
                    continue;
                }
                $this->item($summary, self::KIND_HELD, (string) $mh->order_uuid, $ctx, fn () => $this->adoptHeldSale($mh, $ctx), 'held_sales');
            }
            foreach ($conn->table('edge_mirror_table_sessions')->orderBy('table_id')->get() as $msess) {
                $orders = $conn->table('edge_mirror_table_orders')->where('session_uuid', $msess->session_uuid)->orderBy('id')->get();
                $blocked = $orders->first(fn ($o) => ! $o->adoptable);
                if ($blocked) {
                    // The WHOLE table stays with the Cloud: adopting half a table would split one bill across two truths.
                    $reason = 'ORDER_NOT_ADOPTABLE:' . (string) $blocked->not_adoptable_reason;
                    $this->record(self::KIND_SESSION, (string) $msess->session_uuid, $ctx, 'skipped', $reason, null, null, null);
                    $summary['skipped'][] = ['kind' => self::KIND_SESSION, 'uuid' => (string) $msess->session_uuid, 'reason' => $reason];
                    continue;
                }
                $adopted = $this->item($summary, self::KIND_SESSION, (string) $msess->session_uuid, $ctx, fn () => $this->adoptTableSession($msess, $orders, $ctx), 'table_sessions');
                if ($adopted) {
                    $summary['table_orders'] += $orders->count();
                }
            }

            $conn->table('edge_mirror_meta')->where('id', $mirror->id)->update(['adopted_segment' => $segment, 'adopted_at' => now(),
                'adoption_status' => self::STATUS_ADOPTED, 'adoption_reason' => null, 'adoption_takeover_by' => $ctx['takeover']['by'], 'updated_at' => now()]);
            Log::info('[edge-continuity] adopted mirrored live state', array_diff_key($summary, ['skipped' => true]) + ['skipped' => count($summary['skipped'])]);

            return $summary;
        }, 3);
    }

    /**
     * WHO took over this segment — the SAME EdgeAuthorityService::takeOver records it for the supervised button (the supervisor) and
     * for the optional automatic takeover (by = 'auto-takeover'). Adoption itself never depends on it (identical behaviour, same
     * freshness gate); it is audit only.
     *
     * @return array{by:?string, mode:string}
     */
    public function takeover(): array
    {
        $meta = $this->context->current();
        $raw = $meta?->authority_takeover_freshness;
        $facts = is_array($raw) ? $raw : json_decode((string) ($raw ?? ''), true);
        $by = is_array($facts) && isset($facts['confirmed_by']) && $facts['confirmed_by'] !== '' ? (string) $facts['confirmed_by'] : null;
        if ($by === null && preg_match('/confirmed by ([^;]+)/', (string) ($meta?->authority_state_reason ?? ''), $m)) {
            $by = trim($m[1]);
        }
        $by = $by !== null ? mb_substr($by, 0, 100) : null;

        return ['by' => $by, 'mode' => $by !== null && str_starts_with(strtolower($by), 'auto') ? 'auto' : 'manual'];
    }

    /**
     * OWNER (CP1) — the mirror may be adopted only when it is provably CURRENT and TRUSTED:
     *   - it was applied (or confirmed unchanged) no more than edge.continuity.max_mirror_age_seconds (default 120) before the
     *     LAST ACKNOWLEDGED HEARTBEAT, i.e. the last instant the Cloud was seen. (Measured against the last ack, not against the
     *     takeover: a takeover is by construction ≥ TTL + skew after it, and the Cloud cannot change the branch after its lease
     *     lapsed; whatever changed in that window is caught by the Cloud's fingerprint check at merge.)
     *   - no refresh since the last success failed with an INTEGRITY verdict (revision mismatch, out-of-order, wrong branch,
     *     malformed, too large) — a transport failure during the outage is expected and does not taint the held revision;
     *   - it carries a revision and belongs to this branch; the last Cloud contact time is known.
     *
     * @return array{status:string, reason:string}|null  null = adoptable
     */
    public function freshnessRefusal(object $mirror, object $meta): ?array
    {
        $max = max(1, (int) config('edge.continuity.max_mirror_age_seconds', 120));
        if (! $mirror->mirrored_at || ! $mirror->revision) {
            return ['status' => self::STATUS_REFUSED_UNTRUSTED, 'reason' => 'Online shift not continued: no complete mirrored state is held on this Branch Server. Open a shift to continue.'];
        }
        if ($mirror->branch_id !== null && (int) $mirror->branch_id !== (int) $meta->branch_id) {
            return ['status' => self::STATUS_REFUSED_UNTRUSTED, 'reason' => 'Online shift not continued: the mirrored state belongs to another branch. Open a shift to continue.'];
        }
        $lastError = (string) ($mirror->last_error ?? '');
        $failedSinceSuccess = $lastError !== '' && $mirror->last_attempt_at
            && (! $mirror->last_success_at || Carbon::parse($mirror->last_attempt_at)->gt(Carbon::parse($mirror->last_success_at)));
        if ($failedSinceSuccess) {
            foreach (self::UNTRUSTED_ERRORS as $code) {
                if (str_starts_with($lastError, $code)) {
                    return ['status' => self::STATUS_REFUSED_UNTRUSTED, 'reason' => "Online shift not continued: the last mirror refresh could not be trusted ({$code}). Open a shift to continue."];
                }
            }
        }
        if (! $meta->authority_last_ack_at) {
            return ['status' => self::STATUS_REFUSED_STALE, 'reason' => 'Online shift not continued: the last contact with the Online service is unknown, so the mirrored state cannot be proven current. Open a shift to continue.'];
        }
        $mirroredAt = Carbon::parse($mirror->mirrored_at);
        $lastAck = Carbon::parse($meta->authority_last_ack_at);
        $age = $mirroredAt->lt($lastAck) ? (int) $mirroredAt->diffInSeconds($lastAck, true) : 0;
        if ($age > $max) {
            $display = LiveStateMirror::display($mirroredAt) ?? '?';

            return ['status' => self::STATUS_REFUSED_STALE, 'reason' => "Online shift not continued: the mirrored state ({$display}) is {$age} s older than the last contact with the Online service (limit {$max} s). Open a shift to continue."];
        }

        return null;
    }

    /** The adoption verdict of the CURRENT segment for the cashier (null when not local or not decided yet). */
    public function verdict(): ?array
    {
        $segment = $this->segment();
        if ($segment === null) {
            return null;
        }
        try {
            $row = DB::connection('tenant')->table('edge_mirror_meta')->where('singleton_guard', 1)->first();
        } catch (Throwable $e) {
            return null;   // the cashier badge never fails on the continuity store
        }
        if (! $row || (string) $row->adopted_segment !== $segment) {
            return null;
        }

        return ['status' => (string) $row->adoption_status, 'reason' => $row->adoption_reason];
    }

    /**
     * Handback preparation (owner: "adopted-but-untouched drafts are discarded at handback — the Cloud copy remains the truth"):
     * every adopted order still held, unpaid, with no kitchen ticket raised on the appliance and its lines exactly as mirrored is
     * removed (it never became an Edge fact — no outbox row, no print), and an adopted table session left without orders is
     * released. Anything the cashier touched stays and must be settled or cancelled on the appliance (the handback gates are
     * unchanged). Wiring (route / health-page button / orchestrator pre-step) belongs to the lead.
     *
     * @return array{released_orders:int, released_sessions:int, kept:int}
     */
    public function releaseUntouched(): array
    {
        $out = ['released_orders' => 0, 'released_sessions' => 0, 'kept' => 0];
        $conn = DB::connection('tenant');
        $conn->transaction(function () use ($conn, &$out) {
            $rows = $conn->table('edge_mirror_adoptions')->whereIn('kind', [self::KIND_HELD, self::KIND_TABLE_ORDER])->where('status', 'adopted')->lockForUpdate()->get();
            foreach ($rows as $a) {
                $sale = SalesOrder::on('tenant')->where('id', (int) $a->local_id)->lockForUpdate()->first();
                if (! $sale || (string) $sale->status !== 'held' || (string) $sale->adopted_from_order_uuid !== (string) $a->source_uuid) {
                    $out['kept']++;
                    continue;
                }
                $lines = $sale->lines()->lockForUpdate()->get();
                $kots = $conn->table('kot_batches')->where('sales_order_id', $sale->id)->count();
                $untouched = $sale->payments()->count() === 0 && $kots === 0
                    && hash_equals((string) $a->fingerprint, ContinuityFingerprint::fromOrder($sale, $lines));
                if (! $untouched) {
                    $out['kept']++;
                    continue;
                }
                $sale->lines()->delete();
                $conn->table('sales_orders')->where('id', $sale->id)->delete();
                $conn->table('edge_mirror_adoptions')->where('id', $a->id)->update(['status' => 'released', 'reason' => 'untouched at handback', 'updated_at' => now()]);
                $out['released_orders']++;
            }
            $sessions = $conn->table('edge_mirror_adoptions')->where('kind', self::KIND_SESSION)->where('status', 'adopted')->lockForUpdate()->get();
            foreach ($sessions as $a) {
                $session = RestaurantTableSession::on('tenant')->where('id', (int) $a->local_id)->lockForUpdate()->first();
                if (! $session || ! in_array((string) $session->status, ['open', 'bill_requested'], true)) {
                    continue;
                }
                if (SalesOrder::on('tenant')->where('restaurant_table_session_id', $session->id)->exists()) {
                    $out['kept']++;
                    continue;
                }
                $conn->table('restaurant_table_sessions')->where('id', $session->id)->delete();
                RestaurantTable::on('tenant')->where('id', $session->restaurant_table_id)->update(['status' => 'available']);
                $conn->table('edge_mirror_adoptions')->where('id', $a->id)->update(['status' => 'released', 'reason' => 'untouched at handback', 'updated_at' => now()]);
                $out['released_sessions']++;
            }
        });

        return $out;
    }

    // ── cashier-facing facts (data-driven, identical DOM on both runtimes: the Cloud never sends these blocks) ─────────────

    /** The shift-badge `continuity` block for an open Edge shift that continues an online shift, else null. */
    public function continuityFor(?Shift $shift): ?array
    {
        $uuid = $shift?->getAttribute('continues_cloud_shift_uuid');
        if (! $shift || ! $uuid) {
            return null;
        }
        $at = $shift->getAttribute('continuity_mirrored_at');
        $display = LiveStateMirror::display($at);

        return [
            'cloud_shift_uuid' => (string) $uuid,
            'mirrored_at' => $at ? Carbon::parse($at)->toIso8601String() : null,
            'mirrored_at_display' => $display,
            'banner' => 'Continuing online shift' . ($display ? " (mirrored {$display})" : ''),
        ];
    }

    /** The `mirrored` block of a held / open order view for an adopted order, else null. */
    public static function mirroredFor(SalesOrder $sale): ?array
    {
        $uuid = $sale->getAttribute('adopted_from_order_uuid');
        if (! $uuid) {
            return null;
        }
        $at = $sale->getAttribute('adopted_mirrored_at');
        $display = LiveStateMirror::display($at);

        return [
            'order_uuid' => (string) $uuid,
            'mirrored_at' => $at ? Carbon::parse($at)->toIso8601String() : null,
            'mirrored_at_display' => $display,
            'badge' => 'Mirrored draft' . ($display ? " · up to {$display}" : ''),
        ];
    }

    // ── adoption of one item ─────────────────────────────────────────────────────────────────────────────────────────

    /** One item inside its own savepoint; a failure is recorded as `skipped` with its reason and never aborts the others. */
    private function item(array &$summary, string $kind, string $uuid, array $ctx, callable $fn, string $counter): bool
    {
        if (DB::connection('tenant')->table('edge_mirror_adoptions')->where('kind', $kind)->where('source_uuid', $uuid)->where('segment', $ctx['segment'])->exists()) {
            return false;
        }
        try {
            [$localId, $terminalId, $fingerprint] = DB::connection('tenant')->transaction(fn () => $fn());
            $this->record($kind, $uuid, $ctx, 'adopted', null, $localId, $terminalId, $fingerprint);
            $summary[$counter]++;

            return true;
        } catch (ContinuitySkip $e) {
            $this->record($kind, $uuid, $ctx, 'skipped', $e->getMessage(), $e->localId, null, null);
            $summary['skipped'][] = ['kind' => $kind, 'uuid' => $uuid, 'reason' => $e->getMessage()];
        } catch (Throwable $e) {
            $reason = 'ERROR:' . mb_substr($e->getMessage(), 0, 200);
            $this->record($kind, $uuid, $ctx, 'skipped', $reason, null, null, null);
            $summary['skipped'][] = ['kind' => $kind, 'uuid' => $uuid, 'reason' => $reason];
        }

        return false;
    }

    private function record(string $kind, string $uuid, array $ctx, string $status, ?string $reason, ?int $localId, ?int $terminalId, ?string $fingerprint): void
    {
        DB::connection('tenant')->table('edge_mirror_adoptions')->insertOrIgnore([
            'kind' => $kind, 'source_uuid' => $uuid, 'segment' => $ctx['segment'], 'status' => $status,
            'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null, 'terminal_id' => $terminalId, 'local_id' => $localId,
            'user_id' => (int) $ctx['user']->id, 'mirror_revision' => $ctx['revision'], 'mirrored_at' => $ctx['mirrored_at'],
            'fingerprint' => $fingerprint, 'adopted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            'takeover_by' => $ctx['takeover']['by'] ?? null, 'takeover_mode' => $ctx['takeover']['mode'] ?? null,
        ]);
    }

    /** @return array{0:int,1:int,2:null} */
    private function adoptShift(object $ms, array $ctx): array
    {
        $branch = $ctx['branch'];
        $terminal = Terminal::on('tenant')->where('id', (int) $ms->terminal_id)->where('branch_id', $branch->id)->where('status', 'active')->first();
        if (! $terminal) {
            throw new ContinuitySkip('TERMINAL_UNKNOWN');
        }
        $open = Shift::on('tenant')->where('terminal_id', $terminal->id)->where('status', 'open')->latest('id')->first();
        if ($open) {
            // The counter already opened an Edge shift by hand (pre-0.9 habit or a fresh drawer): never a second open shift.
            throw new ContinuitySkip('TERMINAL_ALREADY_OPEN', (int) $open->id);
        }
        $opener = $ms->opened_by_user_id && User::on('tenant')->whereKey((int) $ms->opened_by_user_id)->exists()
            ? (int) $ms->opened_by_user_id : (int) $ctx['user']->id;
        $display = LiveStateMirror::display($ctx['mirrored_at']);
        $shift = $this->shifts->open($branch, $terminal, $opener, (float) $ms->expected_cash,
            'Continues the online shift' . ($display ? " (mirrored {$display})" : '') . ' after Switch to Offline.');
        $fill = [
            'continues_cloud_shift_uuid' => (string) $ms->shift_uuid,
            'continuity_segment' => $ctx['segment'],
            'continuity_mirrored_at' => $ctx['mirrored_at'],
        ];
        if ($ms->business_date) {
            // One shift, one day: the continuation keeps the online shift's frozen operating date.
            $fill['business_date'] = Carbon::parse($ms->business_date)->toDateString();
        }
        $shift->forceFill($fill)->save();

        return [(int) $shift->id, (int) $terminal->id, null];
    }

    /** @return array{0:int,1:int,2:string} */
    private function adoptHeldSale(object $mh, array $ctx): array
    {
        if (SalesOrder::on('tenant')->where('sale_uuid', (string) $mh->order_uuid)->exists()) {
            throw new ContinuitySkip('ALREADY_LOCAL');
        }
        $branch = $ctx['branch'];
        $terminal = $mh->terminal_id ? Terminal::on('tenant')->where('id', (int) $mh->terminal_id)->where('branch_id', $branch->id)->where('status', 'active')->first() : null;
        $shift = $this->shiftFor($mh->shift_uuid, $terminal?->id, $ctx, false);
        if (! $shift) {
            throw new ContinuitySkip('NO_OPEN_SHIFT');
        }
        $sale = $this->createAdoptedSale($mh, (string) ($mh->order_type ?: 'takeaway'), $shift, null, $ctx);

        return [(int) $sale->id, (int) $shift->terminal_id, (string) $mh->fingerprint];
    }

    /** @return array{0:int,1:int,2:null} */
    private function adoptTableSession(object $msess, $orders, array $ctx): array
    {
        $branch = $ctx['branch'];
        $table = RestaurantTable::on('tenant')->where('id', (int) $msess->table_id)->where('branch_id', $branch->id)
            ->where('status', '!=', 'inactive')->lockForUpdate()->first();
        if (! $table) {
            throw new ContinuitySkip('TABLE_UNKNOWN');
        }
        if (RestaurantTableSession::on('tenant')->where('session_uuid', (string) $msess->session_uuid)->exists()) {
            throw new ContinuitySkip('ALREADY_LOCAL');
        }
        if (RestaurantTableSession::on('tenant')->where('restaurant_table_id', $table->id)->whereIn('status', ['open', 'bill_requested'])->lockForUpdate()->exists()) {
            throw new ContinuitySkip('TABLE_ALREADY_OPEN');
        }
        $firstShiftUuid = $orders->first()?->shift_uuid;
        $shift = $this->shiftFor($firstShiftUuid, null, $ctx, true);
        if (! $shift) {
            throw new ContinuitySkip('NO_OPEN_SHIFT');
        }
        $waiterId = $msess->waiter_user_id && \App\Models\Tenant\RestaurantWaiter::on('tenant')->whereKey((int) $msess->waiter_user_id)
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id))->exists() ? (int) $msess->waiter_user_id : null;

        $session = new RestaurantTableSession([
            'session_no' => $msess->session_no ?: 'TS-' . $branch->id . '-' . $msess->session_uuid,
            'branch_id' => $branch->id,
            'restaurant_table_id' => $table->id,
            'restaurant_waiter_id' => $waiterId,
            'opened_by_user_id' => (int) $ctx['user']->id,
            'opened_shift_id' => $shift->id,
            'business_date' => $shift->business_date?->toDateString(),
            'guest_count' => max(1, (int) ($msess->covers ?: 1)),
            'status' => 'open',
            'opened_at' => $msess->opened_at ?: now(),
        ]);
        $session->session_uuid = (string) $msess->session_uuid;   // the Cloud's identity — this IS that session
        $session->forceFill(['adopted_from_session_uuid' => (string) $msess->session_uuid]);
        $session->save();
        $table->update(['status' => 'occupied']);

        foreach ($orders as $mo) {
            if (SalesOrder::on('tenant')->where('sale_uuid', (string) $mo->order_uuid)->exists()) {
                throw new ContinuitySkip('ALREADY_LOCAL');   // rolls the whole table back (savepoint): never half a table
            }
            $orderShift = $this->shiftFor($mo->shift_uuid, null, $ctx, true) ?? $shift;
            $sale = $this->createAdoptedSale($mo, 'dine_in', $orderShift, $session->setRelation('table', $table), $ctx);
            $this->record(self::KIND_TABLE_ORDER, (string) $mo->order_uuid, $ctx, 'adopted', null, (int) $sale->id, (int) $orderShift->terminal_id, (string) $mo->fingerprint);
        }

        return [(int) $session->id, (int) $shift->terminal_id, null];
    }

    /**
     * The Edge shift an adopted order belongs to: the continuation of its Cloud shift in this segment; else the open shift of its
     * counter / of the operator's counter; else (tables only) any open shift of the branch.
     */
    private function shiftFor(?string $cloudShiftUuid, ?int $terminalId, array $ctx, bool $anyInBranch): ?Shift
    {
        $base = fn () => Shift::on('tenant')->where('branch_id', $ctx['branch']->id)->where('status', 'open');
        if ($cloudShiftUuid) {
            $s = $base()->where('continues_cloud_shift_uuid', $cloudShiftUuid)->where('continuity_segment', $ctx['segment'])->latest('id')->first();
            if ($s) {
                return $s;
            }
        }
        foreach (array_filter([$terminalId, $ctx['preferred_terminal_id']]) as $tid) {
            $s = $base()->where('terminal_id', (int) $tid)->latest('id')->first();
            if ($s) {
                return $s;
            }
        }

        return $anyInBranch ? $base()->orderBy('terminal_id')->first() : null;
    }

    /** The Edge held sale for one mirrored order — same identity, same money, lines marked fired where the kitchen has them. */
    private function createAdoptedSale(object $mo, string $orderType, Shift $shift, ?RestaurantTableSession $session, array $ctx): SalesOrder
    {
        $conn = DB::connection('tenant');
        $lines = $conn->table('edge_mirror_order_lines')->where('order_uuid', (string) $mo->order_uuid)->orderBy('position')->get();
        if ($lines->isEmpty()) {
            throw new ContinuitySkip('NO_LINES');
        }
        $products = Product::on('tenant')->with('unit')->whereIn('id', $lines->pluck('product_id')->unique()->all())->get()->keyBy('id');
        foreach ($lines as $l) {
            if (! $products->has((int) $l->product_id)) {
                throw new ContinuitySkip('PRODUCT_UNKNOWN');
            }
        }
        $branchId = (int) $ctx['branch']->id;
        $sale = new SalesOrder([
            'sale_no' => $mo->order_no ?: 'SO-' . $branchId . '-' . $shift->terminal_id . '-' . $mo->order_uuid,
            'branch_id' => $branchId,
            'terminal_id' => (int) $shift->terminal_id,
            'shift_id' => (int) $shift->id,
            'business_date' => $session?->business_date?->toDateString() ?? $shift->business_date?->toDateString(),
            'order_source' => 'pos',
            'order_type' => $orderType,
            'sale_date' => now(),
            'subtotal' => (float) $mo->subtotal,
            'discount_type' => 'none',
            'discount_value' => 0,
            'discount_amount' => (float) $mo->discount,
            'tax_amount' => (float) $mo->tax,
            'service_charge_amount' => (float) $mo->service_charge,
            'delivery_charge_amount' => 0,
            'tip_amount' => 0,
            'grand_total' => (float) $mo->total,
            'paid_amount' => 0, 'change_amount' => 0,
            'status' => 'held',
            'is_draft' => false,
            'created_by_user_id' => (int) $ctx['user']->id,
            'customer_id' => isset($mo->customer_id) && $mo->customer_id && \App\Models\Tenant\Customer::on('tenant')->whereKey((int) $mo->customer_id)->exists() ? (int) $mo->customer_id : null,
            'restaurant_table_session_id' => $session?->id,
            'restaurant_table_id' => $session?->restaurant_table_id,
            'restaurant_floor_id' => $session?->table?->restaurant_floor_id,
            'restaurant_waiter_id' => $session?->restaurant_waiter_id,
            'inventory_posted' => false,
            'edge_sync_state' => 'pending',
            'edge_activation_epoch' => (int) $ctx['epoch'],
        ]);
        $sale->sale_uuid = (string) $mo->order_uuid;   // the Cloud's identity — this IS that order (the merge completes it, never a second one)
        $sale->forceFill([
            'adopted_from_order_uuid' => (string) $mo->order_uuid,
            'adopted_mirror_revision' => (string) $mo->revision,
            'adopted_mirrored_at' => $mo->mirrored_at,
            'adopted_cloud_fingerprint' => (string) $mo->fingerprint,
        ]);
        $sale->save();

        foreach ($lines as $l) {
            $product = $products->get((int) $l->product_id);
            $variant = $l->variant_id ? \App\Models\Tenant\ProductVariant::on('tenant')->where('product_id', $product->id)->find((int) $l->variant_id) : null;
            if ($l->variant_id && ! $variant) {
                throw new ContinuitySkip('VARIANT_UNKNOWN');
            }
            $qty = (float) $l->qty;
            $price = (float) $l->unit_price;
            $fired = ! in_array(strtolower((string) ($l->kot_state ?? '')), self::NOT_FIRED_STATES, true) || $l->kot_state === null;
            $row = new SalesOrderLine([
                'sales_order_id' => $sale->id,
                'product_id' => (int) $product->id,
                'product_name' => $l->product_name ?: $product->name,
                'product_variant_id' => $variant?->id,
                'variant_name' => $variant?->name,
                'unit_code' => $product->unit?->code,
                'kitchen_note' => $l->notes,
                'line_kind' => 'standard',
                'quantity' => $qty, 'unit_price' => $price, 'unit_cost' => 0, 'cost_total' => 0,
                'discount_amount' => (float) $l->discount_amount, 'tax_amount' => (float) $l->tax_amount,
                'line_total' => $qty * $price - (float) $l->discount_amount + (float) $l->tax_amount,
                'modifiers' => ($m = json_decode((string) $l->modifiers, true)) ? $m : null,
                'kot_sent' => $fired,
                'kot_sent_quantity' => $fired ? $qty : 0,
            ]);
            $row->line_uuid = (string) $l->line_uuid;   // the Cloud line identity (kept until a revision re-creates the row)
            $row->save();
        }

        return $sale;
    }
}
