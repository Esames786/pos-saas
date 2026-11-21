<?php

namespace App\Services\Edge\AutoUpdate;

/**
 * EDGE 0.9 (stream B) — THE rule for when the appliance may apply a verified update by itself. Pure decision, no I/O.
 *
 * Applies ONLY when ALL hold:
 *   1. auto-update is enabled and the appliance is bound (paired + bootstrapped);
 *   2. the selling authority is STANDBY — the Cloud sells. Never in Local Mode, never while handing back;
 *   3. the sync outbox has nothing pending and nothing in flight (no unsynced sale/return/finance event is ever at risk);
 *   4. the moment is inside the owner's maintenance window, OR an explicit "Update now" was requested.
 * "Update now" bypasses ONLY the window — never rules 1–3. The engine's lease/gates are not consulted or changed here:
 * an update never changes who sells.
 */
final class EdgeAutoUpdateGate
{
    public const DISABLED = 'AUTO_UPDATE_DISABLED';
    public const NOT_BOUND = 'NOT_BOUND';
    public const AUTHORITY_NOT_STANDBY = 'AUTHORITY_NOT_STANDBY';
    public const OUTBOX_NOT_EMPTY = 'OUTBOX_NOT_EMPTY';
    public const OUTSIDE_WINDOW = 'OUTSIDE_MAINTENANCE_WINDOW';

    /**
     * @param  array{enabled:bool,bound:bool,authority_state:string,outbox_pending:int,outbox_in_flight:int,inside_window:bool,update_now:bool,window?:string}  $c
     * @return array{allowed:bool,mode:?string,reasons:array<int,array{code:string,detail:string}>}
     */
    public static function decide(array $c): array
    {
        $reasons = [];
        if (! ($c['enabled'] ?? false)) {
            $reasons[] = ['code' => self::DISABLED, 'detail' => 'automatic updates are switched off on this appliance'];
        }
        if (! ($c['bound'] ?? false)) {
            $reasons[] = ['code' => self::NOT_BOUND, 'detail' => 'the appliance is not paired / bootstrapped'];
        }
        $state = (string) ($c['authority_state'] ?? '');
        if ($state !== 'standby') {
            $reasons[] = ['code' => self::AUTHORITY_NOT_STANDBY, 'detail' => 'authority is ' . ($state === '' ? 'unknown' : $state) . ' — an update runs only while the Cloud sells (standby)'];
        }
        $pending = max(0, (int) ($c['outbox_pending'] ?? 0));
        $inFlight = max(0, (int) ($c['outbox_in_flight'] ?? 0));
        if ($pending + $inFlight > 0) {
            $reasons[] = ['code' => self::OUTBOX_NOT_EMPTY, 'detail' => "sync outbox not empty (pending {$pending}, in flight {$inFlight})"];
        }
        $updateNow = (bool) ($c['update_now'] ?? false);
        if (! $updateNow && ! ($c['inside_window'] ?? false)) {
            $reasons[] = ['code' => self::OUTSIDE_WINDOW, 'detail' => 'outside the maintenance window ' . (string) ($c['window'] ?? '') . ' and no "Update now" requested'];
        }

        return [
            'allowed' => $reasons === [],
            'mode' => $reasons === [] ? ($updateNow ? 'update_now' : 'maintenance_window') : null,
            'reasons' => $reasons,
        ];
    }

    /** The safety part only (rules 1–3) — re-checked inside the apply lock and before a rollback that touches the DB. */
    public static function safe(array $c): bool
    {
        $r = self::decide(array_merge($c, ['update_now' => true]));

        return $r['allowed'];
    }
}
