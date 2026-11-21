<?php

namespace App\Services\Edge\AutoTakeover;

use App\Http\Controllers\Edge\EdgeLocalAuthorityControlController;
use App\Services\Edge\EdgeAuthorityService;
use App\Services\Edge\EdgeBranchContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE 0.9 (stream B) — OPTIONAL AUTOMATIC OFFLINE TAKEOVER (default OFF; lead decisions 21 Nov 2026 §1–2).
 *
 * The automatic trigger calls the SAME EdgeAuthorityService::takeOver(true, 'auto-takeover', false, null) — no stale override —
 * under the SAME appliance-wide lock as the "Switch to Offline" button. It decides "take over" only when EVERY guard holds:
 *
 *   1. effective enabled = the Cloud branch setting (replicated `branches.edge_auto_takeover_enabled`) AND the appliance kill
 *      switch edge.authority.auto_takeover_allowed (env EDGE_AUTO_TAKEOVER_ALLOWED, default false);
 *   2. authority is STANDBY (an already-local appliance has nothing to do; handing back is never interrupted);
 *   3. SUSTAINED loss: ≥ 3 consecutive failed heartbeats AND no acknowledged heartbeat for ≥ after_seconds (Cloud setting,
 *      default 300, clamped 180..3600). Any acknowledged beat resets the timer (last ack moves) — flapping never accumulates;
 *   4. the last measured Cloud clock skew is KNOWN and |skew| ≤ 10 s (the lease margin assumes synced clocks);
 *   5. no handback in the last 15 minutes; at most one automatic attempt per 60 s;
 *   6. AUTHORITY_TAKEOVER_SAFE and every other takeover gate pass (EdgeAuthorityService::gates — the engine re-checks them
 *      under its own row lock anyway).
 *
 * The Cloud fences itself at lease expiry on its own clock; the appliance takes over only after last_ack + TTL + margin AND the
 * sustained window — never an overlap. Reconnect never hands back automatically (the engine keeps EDGE until a supervised
 * Return to Online). Every decision is recorded on edge_local_meta (status page) and audited as [edge-auto-takeover].
 */
class AutoTakeoverEvaluator
{
    public const MIN_FAILED_BEATS = 3;
    public const MAX_SKEW_SECONDS = 10;
    public const RETRY_SECONDS = 60;
    public const HANDBACK_QUIET_SECONDS = 900;
    public const DEFAULT_AFTER = 300;
    public const MIN_AFTER = 180;
    public const MAX_AFTER = 3600;
    public const BY = 'auto-takeover';

    public const TAKE_OVER = 'take_over';
    public const WAIT = 'wait';
    public const REFUSED = 'refused';
    public const DISABLED = 'disabled';
    public const NOOP = 'noop';

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly EdgeAuthorityService $authority,
    ) {
    }

    /** The effective setting: Cloud branch setting AND appliance kill switch. @return array{cloud_enabled:bool,allowed:bool,enabled:bool,after_seconds:int} */
    public function settings(): array
    {
        $cloud = false;
        $after = self::DEFAULT_AFTER;
        try {
            $branchId = $this->context->boundBranchId();
            if ($branchId !== null && Schema::connection('tenant')->hasColumn('branches', 'edge_auto_takeover_enabled')) {
                $row = DB::connection('tenant')->table('branches')->where('id', $branchId)->first();
                $cloud = (bool) ($row->edge_auto_takeover_enabled ?? false);
                if (isset($row->edge_auto_takeover_after_seconds) && $row->edge_auto_takeover_after_seconds !== null) {
                    $after = (int) $row->edge_auto_takeover_after_seconds;
                }
            }
        } catch (\Throwable) {
            $cloud = false; // unreadable = OFF (fail closed)
        }
        $allowed = (bool) config('edge.authority.auto_takeover_allowed', false);

        return [
            'cloud_enabled' => $cloud,
            'allowed' => $allowed,
            'enabled' => $cloud && $allowed,
            'after_seconds' => max(self::MIN_AFTER, min(self::MAX_AFTER, $after)),
        ];
    }

    /**
     * The decision, with every reason (pure: reads only, never writes, never calls the Cloud).
     *
     * @return array{decision:string,reasons:array<int,array{code:string,detail:string}>,settings:array,facts:array}
     */
    public function evaluate(?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $settings = $this->settings();
        $out = fn (string $decision, array $reasons, array $facts = []) => ['decision' => $decision, 'reasons' => $reasons, 'settings' => $settings, 'facts' => $facts];
        if (! $settings['enabled']) {
            $r = [];
            if (! $settings['cloud_enabled']) {
                $r[] = ['code' => 'CLOUD_SETTING_OFF', 'detail' => 'Automatic Offline Takeover is off for this branch on the Cloud'];
            }
            if (! $settings['allowed']) {
                $r[] = ['code' => 'APPLIANCE_NOT_ALLOWED', 'detail' => 'this Branch Server does not allow automatic takeover (EDGE_AUTO_TAKEOVER_ALLOWED=false)'];
            }

            return $out(self::DISABLED, $r);
        }
        $meta = $this->context->current();
        if ($meta === null) {
            return $out(self::REFUSED, [['code' => 'NOT_BOUND', 'detail' => 'the appliance is not bound']]);
        }
        $state = (string) ($meta->authority_state ?? EdgeAuthorityService::STANDBY);
        if ($state !== EdgeAuthorityService::STANDBY) {
            return $out(self::NOOP, [['code' => 'AUTHORITY_NOT_STANDBY', 'detail' => 'authority is ' . $state . ' — nothing to take over']]);
        }
        $failures = (int) ($meta->heartbeat_consecutive_failures ?? 0);
        $lastAck = $meta->authority_last_ack_at ? CarbonImmutable::parse($meta->authority_last_ack_at) : null;
        $lostFor = $lastAck ? max(0, $now->getTimestamp() - $lastAck->getTimestamp()) : null;
        $skew = $meta->getAttribute('authority_cloud_skew_seconds');
        $facts = ['consecutive_failures' => $failures, 'lost_for_seconds' => $lostFor, 'cloud_skew_seconds' => $skew === null ? null : (int) $skew];

        $wait = [];
        if ($lastAck === null) {
            return $out(self::REFUSED, [['code' => 'NEVER_ACKNOWLEDGED', 'detail' => 'the Cloud never acknowledged a heartbeat — no lease to lapse']], $facts);
        }
        if ($failures < self::MIN_FAILED_BEATS) {
            $wait[] = ['code' => 'CONNECTION_NOT_LOST', 'detail' => "{$failures} consecutive failed heartbeat(s), " . self::MIN_FAILED_BEATS . ' needed'];
        }
        if ($lostFor < $settings['after_seconds']) {
            $wait[] = ['code' => 'SUSTAINED_LOSS_NOT_REACHED', 'detail' => "no Cloud acknowledgement for {$lostFor} s of {$settings['after_seconds']} s"];
        }
        if ($wait !== []) {
            return $out(self::WAIT, $wait, $facts);
        }

        $refused = [];
        if ($skew === null) {
            $refused[] = ['code' => 'CLOCK_SKEW_UNKNOWN', 'detail' => 'the Cloud clock offset was never measured'];
        } elseif (abs((int) $skew) > self::MAX_SKEW_SECONDS) {
            $refused[] = ['code' => 'CLOCK_SKEW_TOO_LARGE', 'detail' => 'this PC is ' . (int) $skew . ' s off the Cloud clock (max ' . self::MAX_SKEW_SECONDS . ' s) — fix Windows time'];
        }
        $handback = $this->lastHandbackAt();
        if ($handback !== null && $now->getTimestamp() - $handback->getTimestamp() < self::HANDBACK_QUIET_SECONDS) {
            $refused[] = ['code' => 'RECENT_HANDBACK', 'detail' => 'returned to Online at ' . $handback->toIso8601String() . ' — no automatic takeover within 15 minutes'];
        }
        $attempt = $meta->getAttribute('auto_takeover_last_attempt_at');
        if ($attempt !== null && $now->getTimestamp() - CarbonImmutable::parse($attempt)->getTimestamp() < self::RETRY_SECONDS) {
            $refused[] = ['code' => 'RATE_LIMITED', 'detail' => 'one automatic attempt per ' . self::RETRY_SECONDS . ' s'];
        }
        try {
            $gates = $this->authority->gates();
        } catch (\Throwable $e) {
            $gates = ['GATES_UNREADABLE' => false];
        }
        $failed = array_keys(array_filter($gates, fn ($ok) => ! $ok));
        if ($failed !== []) {
            $refused[] = ['code' => 'GATES_BLOCKED', 'detail' => 'takeover gates blocked: ' . implode(', ', $failed)];
        }
        $facts['gates'] = $gates;

        return $refused === [] ? $out(self::TAKE_OVER, [], $facts) : $out(self::REFUSED, $refused, $facts);
    }

    /**
     * Evaluate and, when the decision is take_over, call the EXISTING engine under the EXISTING lock. Records the decision on
     * edge_local_meta and audits it ([edge-auto-takeover]) whenever it changes or an attempt is made. Never throws.
     *
     * @return array{decision:string,reasons:array,result:?string,message:?string}
     */
    public function evaluateAndAct(): array
    {
        $lock = Cache::lock(EdgeLocalAuthorityControlController::LOCK, 120);
        if (! $lock->get()) {
            return ['decision' => 'busy', 'reasons' => [['code' => 'AUTHORITY_CHANGE_IN_PROGRESS', 'detail' => 'another authority change holds the lock']], 'result' => null, 'message' => null];
        }
        try {
            $d = $this->evaluate();
            if ($d['decision'] !== self::TAKE_OVER) {
                $this->record($d['decision'], $d['reasons'], false);

                return $d + ['result' => null, 'message' => null];
            }
            $this->record(self::TAKE_OVER, [], true);
            try {
                $r = $this->authority->takeOver(true, self::BY, false, null);
                $result = ! empty($r['already']) ? 'already' : 'taken_over';
                $message = $result === 'already' ? 'already Local Mode' : 'Local Mode active — automatic takeover after sustained Cloud loss';
                $this->record($result, [], false, true);

                return $d + ['result' => $result, 'message' => $message];
            } catch (\Throwable $e) {
                $reasons = [['code' => 'ENGINE_REFUSED', 'detail' => mb_substr($e->getMessage(), 0, 300)]];
                $this->record(self::REFUSED, $reasons, false, true);

                return ['decision' => self::REFUSED, 'reasons' => $reasons, 'settings' => $d['settings'], 'facts' => $d['facts'], 'result' => 'refused', 'message' => $e->getMessage()];
            }
        } catch (\Throwable $e) {
            Log::warning('[edge-auto-takeover] evaluation failed', ['error' => mb_substr($e->getMessage(), 0, 300)]);

            return ['decision' => 'error', 'reasons' => [['code' => 'EVALUATION_FAILED', 'detail' => mb_substr($e->getMessage(), 0, 200)]], 'result' => null, 'message' => null];
        } finally {
            $lock->release();
        }
    }

    /**
     * Hook for the heartbeat ack (lead wires it into EdgeAuthorityService::heartbeat after an accepted ack):
     * skew = Cloud server_time − this appliance's clock at receipt. Never throws.
     */
    public function recordCloudSkew(?string $serverTime, ?CarbonInterface $localNow = null): ?int
    {
        if ($serverTime === null || trim($serverTime) === '') {
            return null;
        }
        try {
            $skew = CarbonImmutable::parse($serverTime)->getTimestamp() - ($localNow ?? now())->getTimestamp();
            if ($this->hasColumns()) {
                DB::connection('tenant')->table('edge_local_meta')->where('singleton_guard', 1)->update([
                    'authority_cloud_skew_seconds' => max(-2147483648, min(2147483647, $skew)),
                    'authority_cloud_skew_at' => now(),
                ]);
            }

            return $skew;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Non-secret status for the health report / status page. */
    public function status(): array
    {
        $s = $this->settings();
        $meta = null;
        try {
            $meta = $this->context->current();
        } catch (\Throwable) {
        }

        return $s + [
            'last_decision' => $meta?->getAttribute('auto_takeover_last_decision'),
            'last_reasons' => $meta?->getAttribute('auto_takeover_last_reasons'),
            'last_decision_at' => $meta?->getAttribute('auto_takeover_last_at') ? CarbonImmutable::parse($meta->getAttribute('auto_takeover_last_at'))->toIso8601String() : null,
            'cloud_skew_seconds' => $meta?->getAttribute('authority_cloud_skew_seconds') !== null ? (int) $meta->getAttribute('authority_cloud_skew_seconds') : null,
        ];
    }

    private function lastHandbackAt(): ?CarbonImmutable
    {
        try {
            $at = DB::connection('tenant')->table('edge_local_connection_transitions')
                ->where('from_state', 'handing_back')->max('occurred_at');

            return $at ? CarbonImmutable::parse($at) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function record(string $decision, array $reasons, bool $attempt, bool $force = false): void
    {
        if (! $this->hasColumns()) {
            return;
        }
        $text = mb_substr(implode('; ', array_map(fn ($r) => $r['code'] . ': ' . $r['detail'], $reasons)), 0, 500);
        try {
            $row = DB::connection('tenant')->table('edge_local_meta')->where('singleton_guard', 1)->first(['auto_takeover_last_decision', 'auto_takeover_last_reasons']);
            $changed = $row === null || (string) $row->auto_takeover_last_decision !== $decision || (string) $row->auto_takeover_last_reasons !== $text;
            $update = ['auto_takeover_last_decision' => $decision, 'auto_takeover_last_reasons' => $text, 'auto_takeover_last_at' => now()];
            if ($attempt) {
                $update['auto_takeover_last_attempt_at'] = now();
            }
            DB::connection('tenant')->table('edge_local_meta')->where('singleton_guard', 1)->update($update);
            if ($changed || $attempt || $force) {
                Log::info('[edge-auto-takeover]', ['decision' => $decision, 'reasons' => $text, 'attempt' => $attempt]);
            }
            app()->forgetInstance(EdgeBranchContext::class);
        } catch (\Throwable $e) {
            Log::warning('[edge-auto-takeover] could not record the decision', ['error' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }

    private function hasColumns(): bool
    {
        try {
            return Schema::connection('tenant')->hasColumn('edge_local_meta', 'auto_takeover_last_decision');
        } catch (\Throwable) {
            return false;
        }
    }
}
