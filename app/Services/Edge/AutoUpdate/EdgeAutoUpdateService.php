<?php

namespace App\Services\Edge\AutoUpdate;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * EDGE 0.9 (stream B) — ONE auto-update tick (task BingooEdgeUpdater → `edge:local:auto-update`, every 15 min):
 *
 *   check K6 (at most once per check interval, or at once when "Update now" is pending)
 *     → nothing newer: up_to_date
 *     → newer: download + extract + VERIFY (EdgeAutoUpdatePackageVerifier) once; a refused package is reported (K4
 *       update_failed) and not retried until the Cloud advertises different bytes
 *     → GATE (EdgeAutoUpdateGate): enabled + bound + authority STANDBY + outbox empty + (maintenance window OR Update now)
 *       — closed: waiting (the reasons are kept for the status page)
 *     → APPLY (EdgeAutoUpdateApplier: lock, re-check, D7 plan, snapshot when the schema changes, installer, health,
 *       rollback rule) → K4 updating / updated / update_failed / rolled_back
 *
 * Single instance (file lock). Never throws to the scheduler: every failure becomes a recorded, reported result.
 */
class EdgeAutoUpdateService
{
    public function __construct(
        private readonly EdgeDeviceCloudClient $cloud,
        private readonly EdgeAutoUpdateStager $stager,
        private readonly EdgeAutoUpdatePackageVerifier $verifier,
        private readonly EdgeAutoUpdateApplier $applier,
        private readonly EdgeAutoUpdateRuntimeProbe $runtime,
        private readonly EdgeAutoUpdateStateStore $store,
    ) {
    }

    /**
     * @param  array{check_only?:bool,force_check?:bool,now?:CarbonImmutable,performed_by?:string}  $opts
     * @return array{result:string,version?:?string,code?:?string,message:string,reasons?:array}
     */
    public function tick(array $opts = []): array
    {
        $now = $opts['now'] ?? CarbonImmutable::now();
        if (! EdgeAutoUpdateSettings::enabled()) {
            return $this->remember(['result' => 'disabled', 'message' => 'automatic updates are switched off (EDGE_AUTO_UPDATE_ENABLED=false)']);
        }
        if (! $this->store->acquireLock()) {
            return ['result' => 'busy', 'message' => 'another updater run is in progress'];
        }
        try {
            return $this->run($now, $opts);
        } catch (\Throwable $e) {
            Log::warning('[edge-auto-update] tick failed', ['error' => mb_substr($e->getMessage(), 0, 300)]);

            return $this->remember(['result' => 'error', 'code' => self::code($e), 'message' => mb_substr($e->getMessage(), 0, 300)]);
        } finally {
            $this->store->releaseLock();
        }
    }

    private function run(CarbonImmutable $now, array $opts): array
    {
        $current = (string) config('edge.app_version');
        $state = $this->store->read();
        $updateNow = $this->store->pendingUpdateNow();

        // 1. CHECK — at most once per interval, unless forced or an Update now is waiting.
        $release = $state['available'] ?? null;
        $lastCheck = isset($state['last_check_at']) ? CarbonImmutable::parse((string) $state['last_check_at']) : null;
        $due = ($opts['force_check'] ?? false) || $updateNow !== null || $release === null || $lastCheck === null
            || $lastCheck->addMinutes((int) config('edge.auto_update.check_interval_minutes', 15) - 1)->lessThanOrEqualTo($now);
        if ($due) {
            if (! $this->cloud->deviceConfigured()) {
                return $this->remember(['result' => 'not_paired', 'message' => 'the appliance has no device identity yet — nothing to check']);
            }
            try {
                $doc = $this->cloud->latestRelease($current);
            } catch (\Throwable $e) {
                $this->store->merge(['last_check_at' => $now->toIso8601String(), 'last_check' => ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 200)]]);

                return $this->remember(['result' => 'check_failed', 'code' => self::code($e), 'message' => mb_substr($e->getMessage(), 0, 300)]);
            }
            $release = ! empty($doc['available']) ? self::normaliseRelease($doc) : null;
            $this->store->merge(['last_check_at' => $now->toIso8601String(), 'last_check' => ['ok' => true], 'available' => $release]);
            if ($release !== null && ! empty($doc['update_now']) && $updateNow === null) {
                // The owner pressed "Update now" on the Cloud device row: same request as the status page button.
                $updateNow = $this->store->requestUpdateNow('cloud', 'cloud');
            }
        }
        if ($release === null || version_compare((string) $release['version'], $current, '<=')) {
            if ($updateNow !== null) {
                $this->store->clearUpdateNow();
            }

            return $this->remember(['result' => 'up_to_date', 'version' => $current, 'message' => 'Edge ' . $current . ' is the newest release']);
        }
        $version = (string) $release['version'];
        if (($opts['check_only'] ?? false)) {
            return $this->remember(['result' => 'available', 'version' => $version, 'message' => "Edge {$version} is available (check only)"]);
        }

        // 2. STAGE + VERIFY — once per advertised package; a refused package is not retried until the bytes change.
        $refused = $state['refused'] ?? null;
        if (is_array($refused) && ($refused['manifest_sha256'] ?? null) === $release['manifest_sha256']) {
            return $this->remember(['result' => 'refused', 'version' => $version, 'code' => $refused['code'] ?? null, 'message' => 'the advertised package was refused earlier: ' . ($refused['message'] ?? '')], false);
        }
        $work = EdgeAutoUpdateSettings::workDir();
        try {
            $root = $this->stager->stage($release, $work);
            $verified = $this->verifier->verify($root, $release, $current, (string) config('edge.config_schema'));
        } catch (\Throwable $e) {
            $code = self::code($e);
            $this->store->merge(['refused' => ['manifest_sha256' => $release['manifest_sha256'], 'version' => $version, 'code' => $code, 'message' => mb_substr($e->getMessage(), 0, 300), 'at' => $now->toIso8601String()], 'staged' => null]);
            // Never keep refused bytes around: drop the whole staging dir of this advertisement.
            $this->stager->removeTree($work . DIRECTORY_SEPARATOR . 'staging' . DIRECTORY_SEPARATOR . EdgeRuntimePointer::safe($version) . '-' . substr((string) $release['manifest_sha256'], 0, 8));
            $this->cloud->reportStatus('update_failed', $version, false, 'package refused: ' . mb_substr($e->getMessage(), 0, 300));

            return $this->remember(['result' => 'refused', 'version' => $version, 'code' => $code, 'message' => mb_substr($e->getMessage(), 0, 300)]);
        }
        $this->store->merge(['staged' => ['version' => $version, 'root' => $root, 'manifest_sha256' => $release['manifest_sha256'], 'verified_at' => $now->toIso8601String()]]);

        // 3. GATE.
        $window = EdgeMaintenanceWindow::fromArray($release['maintenance_window'] ?? null, EdgeMaintenanceWindow::fromConfig());
        $facts = $this->runtime->facts();
        $gate = EdgeAutoUpdateGate::decide($facts + [
            'enabled' => true,
            'inside_window' => $window->contains($now),
            'update_now' => $updateNow !== null,
            'window' => $window->describe(),
        ]);
        $this->store->merge(['gate' => $gate + ['at' => $now->toIso8601String(), 'window' => $window->toArray()]]);
        if (! $gate['allowed']) {
            return $this->remember(['result' => 'waiting', 'version' => $version, 'message' => 'Edge ' . $version . ' is verified and waiting: ' . implode('; ', array_column($gate['reasons'], 'detail')), 'reasons' => $gate['reasons']], false);
        }

        // 4. APPLY.
        $by = (string) ($opts['performed_by'] ?? ('auto-update:' . $gate['mode'] . ($updateNow !== null ? ' (requested by ' . ($updateNow['requested_by'] ?? '?') . ')' : '')));
        $this->cloud->reportStatus('updating', $version, true, 'applying ' . $current . ' → ' . $version . ' (' . $gate['mode'] . ')');
        $result = $this->applier->apply($root, $verified['package'], $release, $by);
        $phase = match ($result['result']) {
            EdgeAutoUpdateApplier::UPDATED => 'updated',
            EdgeAutoUpdateApplier::ROLLED_BACK => 'rolled_back',
            EdgeAutoUpdateApplier::REFUSED => null,
            default => 'update_failed',
        };
        if ($phase !== null) {
            $this->cloud->reportStatus($phase, $phase === 'updated' ? $version : $current, $phase === 'updated', (string) $result['message']);
        }
        if ($result['result'] !== EdgeAutoUpdateApplier::REFUSED) {
            $this->store->clearUpdateNow();
        }
        if ($result['result'] === EdgeAutoUpdateApplier::UPDATED) {
            $this->store->merge(['staged' => null, 'available' => null]);
            $this->stager->prune($work, null, (int) config('edge.auto_update.keep_staged', 2));
        } elseif ($result['result'] !== EdgeAutoUpdateApplier::REFUSED) {
            // A package that failed health is not re-applied automatically until the Cloud advertises different bytes.
            $this->store->merge(['refused' => ['manifest_sha256' => $release['manifest_sha256'], 'version' => $version, 'code' => $result['code'] ?? null, 'message' => mb_substr((string) $result['message'], 0, 300), 'at' => $now->toIso8601String()]]);
        }

        return $this->remember(['result' => $result['result'], 'version' => $version] + $result);
    }

    /** Status for the health page + `--status` (non-secret). */
    public function status(): array
    {
        $state = $this->store->read();
        $window = EdgeMaintenanceWindow::fromArray(($state['available'] ?? [])['maintenance_window'] ?? null, EdgeMaintenanceWindow::fromConfig());

        return [
            'enabled' => EdgeAutoUpdateSettings::enabled(),
            'current_version' => (string) config('edge.app_version'),
            'available' => $state['available'] ?? null,
            'staged' => $state['staged'] ?? null,
            'last_check_at' => $state['last_check_at'] ?? null,
            'last_check' => $state['last_check'] ?? null,
            'last_result' => $state['last_result'] ?? null,
            'gate' => $state['gate'] ?? null,
            'refused' => $state['refused'] ?? null,
            'update_now' => $this->store->pendingUpdateNow(),
            'window' => $window->toArray() + ['describe' => $window->describe()],
            'history' => array_slice((array) ($state['history'] ?? []), 0, 5),
        ];
    }

    public static function normaliseRelease(array $doc): array
    {
        return [
            'version' => (string) ($doc['version'] ?? ''),
            'manifest_sha256' => strtolower((string) ($doc['manifest_sha256'] ?? '')),
            'package_hash' => strtolower((string) ($doc['package_hash'] ?? '')),
            'signing_key_id' => strtolower((string) ($doc['signing_key_id'] ?? '')),
            'size_bytes' => isset($doc['size_bytes']) ? (int) $doc['size_bytes'] : null,
            'download_url' => (string) ($doc['download_url'] ?? ''),
            'min_from_version' => (string) ($doc['min_from_version'] ?? ''),
            'maintenance_window' => is_array($doc['maintenance_window'] ?? null) ? $doc['maintenance_window'] : null,
            'plan_hashes' => is_array($doc['plan_hashes'] ?? null) ? $doc['plan_hashes'] : null,
            'expected_plan_hash' => isset($doc['expected_plan_hash']) ? (string) $doc['expected_plan_hash'] : null,
        ];
    }

    private function remember(array $result, bool $record = true): array
    {
        $result['at'] = now()->toIso8601String();
        try {
            if ($record) {
                $this->store->recordResult(array_intersect_key($result, array_flip(['result', 'version', 'from_version', 'to_version', 'code', 'message', 'rollback', 'at'])));
            } else {
                $this->store->merge(['last_tick' => array_intersect_key($result, array_flip(['result', 'version', 'code', 'message', 'at']))]);
            }
        } catch (\Throwable) {
            // the state file is best effort; the tick result is still returned to the command
        }

        return $result;
    }

    private static function code(\Throwable $e): ?string
    {
        return preg_match('/^([A-Z_]+)[:( ]/', $e->getMessage(), $m) ? $m[1] : null;
    }
}
