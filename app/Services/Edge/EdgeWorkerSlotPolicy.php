<?php

namespace App\Services\Edge;

use App\Support\EdgeProcessLiveness;
use Illuminate\Support\Carbon;

/**
 * EDGE 0.8.3 (F-G10) — the ONE ownership rule for a singleton worker slot (print worker, authority worker).
 *
 * Inputs: the current state row (state, worker_uuid, heartbeat_at, worker_pid, worker_process_token, worker_host),
 * the candidate's uuid, the staleness window. Output: take over or refuse, with a named reason and the OS liveness
 * verdict that led to it. Pure (the liveness probe is injectable) so the matrix is unit-testable without a DB:
 *
 *   row not running / no uuid / same uuid / no heartbeat ........ TAKE OVER  (slot free)
 *   other uuid, process ALIVE,  heartbeat fresh .................. REFUSE     (genuine duplicate start)
 *   other uuid, process ALIVE,  heartbeat stale .................. TAKE OVER  (wedged worker — it exits on its next beat)
 *   other uuid, process DEAD   (any heartbeat age) ............... TAKE OVER  (crash / fast reboot: the G10 trap)
 *   other uuid, liveness UNKNOWN, heartbeat fresh ................ REFUSE     (pre-0.8.3 rule, unchanged)
 *   other uuid, liveness UNKNOWN, heartbeat stale ................ TAKE OVER  (pre-0.8.3 rule, unchanged)
 *
 * IDENTITY BINDING (F-2b, found in the 0.8.1 ↔ 0.8.3 pointer rehearsal): a pre-0.8.3 runtime that takes the slot rewrites
 * worker_uuid/started_at/heartbeat_at but leaves the identity columns untouched, so a stale pid could be probed against a
 * LIVE older worker. The process token is therefore bound to the worker uuid that wrote it ("unix:<s>@<worker_uuid>"); an
 * identity whose token is not bound to the row's CURRENT uuid is UNKNOWN and the heartbeat rule applies.
 *
 * This row is never a lease or an authority: taking the slot over never rewrites job leases and never changes the
 * branch authority state — those keep their own expiry/handback contracts.
 */
final class EdgeWorkerSlotPolicy
{
    public const TAKEOVER_SLOT_FREE = 'slot_free';
    public const TAKEOVER_PROCESS_GONE = 'previous_process_gone';
    public const TAKEOVER_STALE_HEARTBEAT = 'stale_heartbeat';
    public const TAKEOVER_STALE_PROCESS_ALIVE = 'stale_heartbeat_process_alive';
    public const REFUSE_LIVE_DUPLICATE = 'live_worker_holds_slot';
    public const REFUSE_FRESH_HEARTBEAT_UNVERIFIED = 'fresh_heartbeat_liveness_unknown';

    /**
     * @param  array<string,mixed>|object  $row  the singleton row (array or stdClass/Model with the same keys)
     * @param  null|callable(?int,?string,?string): array  $liveness  test seam (defaults to EdgeProcessLiveness::check)
     * @return array{takeover: bool, reason: string, heartbeat_age_seconds: ?int, liveness: ?array}
     */
    public static function decide(array|object $row, string $workerUuid, int $staleSeconds, ?callable $liveness = null): array
    {
        $get = fn (string $k) => is_array($row) ? ($row[$k] ?? null) : ($row->{$k} ?? null);
        $state = (string) $get('state');
        $otherUuid = $get('worker_uuid');
        $hb = $get('heartbeat_at');
        $hbAt = $hb instanceof \DateTimeInterface ? Carbon::instance($hb) : ($hb !== null && $hb !== '' ? Carbon::parse((string) $hb) : null);
        $age = $hbAt !== null ? (int) abs(now()->diffInSeconds($hbAt)) : null;

        if ($state !== 'running' || $otherUuid === null || $otherUuid === '' || $otherUuid === $workerUuid || $hbAt === null) {
            return ['takeover' => true, 'reason' => self::TAKEOVER_SLOT_FREE, 'heartbeat_age_seconds' => $age, 'liveness' => null];
        }

        $fresh = $hbAt->gt(now()->subSeconds($staleSeconds));
        $pid = $get('worker_pid');
        $check = self::livenessFor($pid !== null ? (int) $pid : null, $get('worker_process_token'), $get('worker_host'), (string) $otherUuid, $liveness);
        $status = (string) ($check['status'] ?? EdgeProcessLiveness::UNKNOWN);

        if ($status === EdgeProcessLiveness::DEAD) {
            return ['takeover' => true, 'reason' => self::TAKEOVER_PROCESS_GONE, 'heartbeat_age_seconds' => $age, 'liveness' => $check];
        }
        if ($status === EdgeProcessLiveness::ALIVE) {
            return $fresh
                ? ['takeover' => false, 'reason' => self::REFUSE_LIVE_DUPLICATE, 'heartbeat_age_seconds' => $age, 'liveness' => $check]
                : ['takeover' => true, 'reason' => self::TAKEOVER_STALE_PROCESS_ALIVE, 'heartbeat_age_seconds' => $age, 'liveness' => $check];
        }

        return $fresh
            ? ['takeover' => false, 'reason' => self::REFUSE_FRESH_HEARTBEAT_UNVERIFIED, 'heartbeat_age_seconds' => $age, 'liveness' => $check]
            : ['takeover' => true, 'reason' => self::TAKEOVER_STALE_HEARTBEAT, 'heartbeat_age_seconds' => $age, 'liveness' => $check];
    }

    /**
     * The OS liveness of a recorded identity, AFTER the binding check: an identity whose token is not bound to the row's
     * current worker uuid (written by an older runtime, or never written) is UNKNOWN without touching the OS.
     *
     * @param  null|callable(?int,?string,?string): array  $liveness  test seam
     */
    public static function livenessFor(?int $pid, ?string $token, ?string $host, ?string $rowWorkerUuid, ?callable $liveness = null): array
    {
        if ($pid !== null && ! self::identityBoundTo($token, $rowWorkerUuid)) {
            return ['status' => EdgeProcessLiveness::UNKNOWN, 'reason' => 'recorded process identity is not bound to the current worker uuid (written by an older runtime or missing) - heartbeat rule applies', 'observed_token' => null, 'method' => null];
        }

        return ($liveness ?? [EdgeProcessLiveness::class, 'check'])($pid, $token, $host);
    }

    /** TRUE when the token carries the "@<worker_uuid>" binding of the row's current worker. */
    public static function identityBoundTo(?string $token, ?string $workerUuid): bool
    {
        return is_string($token) && $workerUuid !== null && $workerUuid !== '' && str_ends_with($token, '@' . $workerUuid);
    }

    /** One operator-readable line for logs/stdout (non-secret: uuids, pids, ages). */
    public static function describe(array $decision, ?string $otherUuid = null): string
    {
        $live = $decision['liveness']['reason'] ?? 'liveness not consulted';
        $age = $decision['heartbeat_age_seconds'];

        return ($decision['takeover'] ? 'taking over the worker slot' : 'refusing to start')
            . ' [' . $decision['reason'] . ']'
            . ($otherUuid ? " previous worker {$otherUuid}" : '')
            . ($age !== null ? " heartbeat {$age}s old" : '')
            . '; ' . $live;
    }

    /** The columns a worker records next to its heartbeat so a later start can verify the process (token bound to the uuid). */
    public static function identityColumns(array $identity, string $workerUuid): array
    {
        return [
            'worker_pid' => isset($identity['pid']) ? (int) $identity['pid'] : null,
            'worker_process_token' => mb_substr((string) ($identity['token'] ?? ''), 0, 24) . '@' . $workerUuid,
            'worker_host' => isset($identity['host']) ? mb_substr((string) $identity['host'], 0, 64) : null,
        ];
    }
}
