<?php

namespace Tests\Feature\Edge;

use App\Services\Edge\AutoUpdate\EdgeAutoUpdateApplier;
use App\Services\Edge\AutoUpdate\EdgeAutoUpdateGate;
use App\Services\Edge\AutoUpdate\EdgeAutoUpdateHealthProbe;
use App\Services\Edge\AutoUpdate\EdgeAutoUpdateInstaller;
use App\Services\Edge\AutoUpdate\EdgeAutoUpdatePackageVerifier;
use App\Services\Edge\AutoUpdate\EdgeAutoUpdateRuntimeProbe;
use App\Services\Edge\AutoUpdate\EdgeAutoUpdateService;
use App\Services\Edge\AutoUpdate\EdgeAutoUpdateServiceControl;
use App\Services\Edge\AutoUpdate\EdgeAutoUpdateStager;
use App\Services\Edge\AutoUpdate\EdgeAutoUpdateStateStore;
use App\Services\Edge\AutoUpdate\EdgeApplianceRunner;
use App\Services\Edge\AutoUpdate\EdgeDatabaseSnapshot;
use App\Services\Edge\AutoUpdate\EdgeDeviceCloudClient;
use App\Services\Edge\AutoUpdate\EdgeMaintenanceWindow;
use App\Services\Edge\AutoUpdate\EdgeRuntimePointer;
use App\Services\Edge\EdgeBackupService;
use App\Services\Edge\EdgeUpdatePackageService;
use App\Services\Edge\EdgeUpdateVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * EDGE 0.9 (stream B) — the auto-update GATING MATRIX and the K6 ROLLBACK RULE, with fakes (no DB, no network, no tasks).
 *
 * Gate: applies only when standby AND outbox empty AND (inside the maintenance window OR "Update now"); "Update now" bypasses
 * only the window. Verification failures are refused, reported (K4 update_failed) and not retried for the same bytes.
 * Rollback: pointer only when the plan had no schema change; with a schema change, a full pre-update snapshot is required
 * (no snapshot → no update) and restored on a failed health check; never rolled back once the branch left standby.
 */
class EdgeAutoUpdateGatingTest extends TestCase
{
    private string $work;
    private string $installRoot;
    /** @var array<int,string> */
    public array $calls = [];
    public array $facts = ['bound' => true, 'authority_state' => 'standby', 'outbox_pending' => 0, 'outbox_in_flight' => 0];
    public array $release;
    public ?RuntimeException $verifyFailure = null;
    public ?array $applierResult = null;
    public array $reports = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-au-' . Str::lower(Str::random(8));
        $this->installRoot = $this->work . DIRECTORY_SEPARATOR . 'runtime';
        @mkdir($this->installRoot . '/versions/0.9.0-edge', 0775, true);
        file_put_contents($this->installRoot . '/current', '0.9.0-edge');
        config([
            'edge.app_version' => '0.9.0-edge',
            'edge.auto_update.enabled' => true,
            'edge.auto_update.work_dir' => $this->work . DIRECTORY_SEPARATOR . 'updates',
            'edge.auto_update.window_start' => '03:00',
            'edge.auto_update.window_end' => '05:00',
            'edge.auto_update.window_tz' => 'Asia/Karachi',
            'edge.auto_update.service_control' => 'none',
            'edge.auto_update.health_wait_seconds' => 10,
            'edge.update.install_root' => $this->installRoot,
            'cache.default' => 'array',
        ]);
        $this->release = [
            'available' => true, 'version' => '0.9.1-edge', 'manifest_sha256' => str_repeat('a', 64), 'package_hash' => str_repeat('b', 64),
            'signing_key_id' => 'a2eaf8a3d3e66a48', 'size_bytes' => 10, 'download_url' => 'https://bingoopos.com/dl/x', 'min_from_version' => '0.9.0-edge',
        ];
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->work);
        parent::tearDown();
    }

    // ── the pure gate ─────────────────────────────────────────────────────────────────────────

    public function test_the_gate_matrix(): void
    {
        $base = ['enabled' => true, 'bound' => true, 'authority_state' => 'standby', 'outbox_pending' => 0, 'outbox_in_flight' => 0, 'inside_window' => true, 'update_now' => false, 'window' => '03:00–05:00'];
        $this->assertSame(['allowed' => true, 'mode' => 'maintenance_window', 'reasons' => []], EdgeAutoUpdateGate::decide($base));

        $cases = [
            'local mode never updates' => [['authority_state' => 'local_active'], EdgeAutoUpdateGate::AUTHORITY_NOT_STANDBY],
            'handing back never updates' => [['authority_state' => 'handing_back'], EdgeAutoUpdateGate::AUTHORITY_NOT_STANDBY],
            'update now never bypasses local mode' => [['authority_state' => 'local_active', 'update_now' => true], EdgeAutoUpdateGate::AUTHORITY_NOT_STANDBY],
            'pending outbox' => [['outbox_pending' => 1], EdgeAutoUpdateGate::OUTBOX_NOT_EMPTY],
            'in-flight outbox' => [['outbox_in_flight' => 2], EdgeAutoUpdateGate::OUTBOX_NOT_EMPTY],
            'update now never bypasses the outbox' => [['outbox_pending' => 3, 'update_now' => true], EdgeAutoUpdateGate::OUTBOX_NOT_EMPTY],
            'outside the window' => [['inside_window' => false], EdgeAutoUpdateGate::OUTSIDE_WINDOW],
            'not bound' => [['bound' => false], EdgeAutoUpdateGate::NOT_BOUND],
            'disabled' => [['enabled' => false], EdgeAutoUpdateGate::DISABLED],
        ];
        foreach ($cases as $label => [$patch, $code]) {
            $r = EdgeAutoUpdateGate::decide(array_merge($base, $patch));
            $this->assertFalse($r['allowed'], $label);
            $this->assertContains($code, array_column($r['reasons'], 'code'), $label);
        }
        $now = EdgeAutoUpdateGate::decide(array_merge($base, ['inside_window' => false, 'update_now' => true]));
        $this->assertTrue($now['allowed'], 'Update now bypasses ONLY the window');
        $this->assertSame('update_now', $now['mode']);
        $this->assertFalse(EdgeAutoUpdateGate::safe(array_merge($base, ['authority_state' => 'local_active'])));
        $this->assertTrue(EdgeAutoUpdateGate::safe(array_merge($base, ['inside_window' => false])), 'safe() ignores the window (re-check inside the lock)');
    }

    public function test_the_maintenance_window_wraps_midnight_honours_the_timezone_and_fails_closed(): void
    {
        $w = new EdgeMaintenanceWindow('03:00', '05:00', 'Asia/Karachi');
        $this->assertTrue($w->contains(CarbonImmutable::parse('2026-11-22 03:00', 'Asia/Karachi')));
        $this->assertTrue($w->contains(CarbonImmutable::parse('2026-11-21 22:30', 'UTC')), '22:30 UTC = 03:30 PKT');
        $this->assertFalse($w->contains(CarbonImmutable::parse('2026-11-22 05:00', 'Asia/Karachi')), 'half-open: the end minute is outside');
        $this->assertFalse($w->contains(CarbonImmutable::parse('2026-11-22 12:00', 'Asia/Karachi')));
        $wrap = new EdgeMaintenanceWindow('23:30', '01:00', 'Asia/Karachi');
        $this->assertTrue($wrap->contains(CarbonImmutable::parse('2026-11-22 23:45', 'Asia/Karachi')));
        $this->assertTrue($wrap->contains(CarbonImmutable::parse('2026-11-22 00:30', 'Asia/Karachi')));
        $this->assertFalse($wrap->contains(CarbonImmutable::parse('2026-11-22 01:00', 'Asia/Karachi')));
        foreach ([['25:00', '05:00', null], ['03:00', '03:00', null], ['03:00', '05:00', 'Mars/Base']] as [$s, $e, $tz]) {
            $bad = new EdgeMaintenanceWindow($s, $e, $tz);
            $this->assertFalse($bad->valid());
            $this->assertFalse($bad->contains(CarbonImmutable::parse('2026-11-22 03:30', 'Asia/Karachi')), 'an invalid window contains no minute');
        }
        $fallback = new EdgeMaintenanceWindow('03:00', '05:00', 'Asia/Karachi');
        $this->assertSame('01:00', EdgeMaintenanceWindow::fromArray(['start' => '01:00', 'end' => '02:00', 'tz' => 'Asia/Karachi'], $fallback)->start, 'a well-formed K6 window overrides');
        $this->assertSame('03:00', EdgeMaintenanceWindow::fromArray(['start' => 'x', 'end' => '02:00'], $fallback)->start, 'a malformed K6 window is ignored');
    }

    // ── the tick (service) with fakes ─────────────────────────────────────────────────────────

    public function test_nothing_newer_is_up_to_date_and_never_downloads(): void
    {
        $this->release['version'] = '0.9.0-edge';
        $r = $this->service()->tick(['now' => $this->inWindow()]);
        $this->assertSame('up_to_date', $r['result']);
        $this->assertNotContains('stage', $this->calls);
        $this->assertNotContains('apply', $this->calls);
    }

    public function test_a_verified_release_waits_outside_the_window_and_applies_inside_it(): void
    {
        $r = $this->service()->tick(['now' => $this->outsideWindow()]);
        $this->assertSame('waiting', $r['result']);
        $this->assertContains(EdgeAutoUpdateGate::OUTSIDE_WINDOW, array_column($r['reasons'], 'code'));
        $this->assertContains('verify', $this->calls, 'the package is verified while waiting (ready for the window)');
        $this->assertNotContains('apply', $this->calls);

        $this->calls = [];
        $r = $this->service()->tick(['now' => $this->inWindow()]);
        $this->assertSame('updated', $r['result']);
        $this->assertContains('apply', $this->calls);
        $this->assertSame(['updating', 'updated'], array_column($this->reports, 'phase'), 'K4 phases');
    }

    public function test_local_mode_or_a_non_empty_outbox_blocks_even_inside_the_window_and_on_update_now(): void
    {
        foreach ([['authority_state' => 'local_active'], ['authority_state' => 'handing_back'], ['outbox_pending' => 2], ['outbox_in_flight' => 1]] as $patch) {
            $this->facts = array_merge(['bound' => true, 'authority_state' => 'standby', 'outbox_pending' => 0, 'outbox_in_flight' => 0], $patch);
            $this->calls = [];
            $this->store()->requestUpdateNow('owner (O1)', 'status_page');
            $r = $this->service()->tick(['now' => $this->inWindow()]);
            $this->assertSame('waiting', $r['result'], json_encode($patch));
            $this->assertNotContains('apply', $this->calls, json_encode($patch));
            $this->assertNotNull($this->store()->pendingUpdateNow(), 'the request stays pending until the branch is safe');
        }
    }

    public function test_update_now_applies_outside_the_window_and_is_consumed(): void
    {
        $this->store()->requestUpdateNow('owner (O1)', 'status_page');
        $r = $this->service()->tick(['now' => $this->outsideWindow()]);
        $this->assertSame('updated', $r['result']);
        $this->assertNull($this->store()->pendingUpdateNow(), 'the Update now request is consumed by the applied update');
    }

    public function test_the_cloud_update_now_flag_is_the_same_request(): void
    {
        $this->release['update_now'] = true;
        $r = $this->service()->tick(['now' => $this->outsideWindow()]);
        $this->assertSame('updated', $r['result']);
    }

    public function test_a_signature_or_hash_failure_is_refused_reported_and_not_retried_for_the_same_bytes(): void
    {
        $this->verifyFailure = new RuntimeException('UPDATE_SIGNATURE_INVALID: the package signature is not valid.');
        $r = $this->service()->tick(['now' => $this->inWindow()]);
        $this->assertSame('refused', $r['result']);
        $this->assertSame('UPDATE_SIGNATURE_INVALID', $r['code']);
        $this->assertNotContains('apply', $this->calls);
        $this->assertSame('update_failed', $this->reports[0]['phase']);
        $this->assertFalse($this->reports[0]['ok']);

        // Same advertised bytes: no second download / verify.
        $this->calls = [];
        $this->verifyFailure = null;
        $r = $this->service()->tick(['now' => $this->inWindow(), 'force_check' => true]);
        $this->assertSame('refused', $r['result']);
        $this->assertNotContains('stage', $this->calls);

        // The Cloud republishes different bytes: verified again and applied.
        $this->release['manifest_sha256'] = str_repeat('c', 64);
        $r = $this->service()->tick(['now' => $this->inWindow(), 'force_check' => true]);
        $this->assertSame('updated', $r['result']);
    }

    public function test_disabled_and_unpaired_appliances_do_nothing(): void
    {
        config(['edge.auto_update.enabled' => false]);
        $this->assertSame('disabled', $this->service()->tick(['now' => $this->inWindow()])['result']);
        config(['edge.auto_update.enabled' => true]);
        $this->assertSame('not_paired', $this->service(paired: false)->tick(['now' => $this->inWindow()])['result']);
        $this->assertSame([], array_intersect(['stage', 'verify', 'apply'], $this->calls));
    }

    public function test_a_failed_apply_is_reported_and_the_same_package_is_not_reapplied(): void
    {
        $this->applierResult = ['result' => EdgeAutoUpdateApplier::ROLLED_BACK, 'code' => 'AUTO_UPDATE_HEALTH_FAILED', 'rollback' => 'reverted_runtime', 'message' => 'rolled back', 'from_version' => '0.9.0-edge', 'to_version' => '0.9.1-edge', 'schema_change' => false];
        $r = $this->service()->tick(['now' => $this->inWindow()]);
        $this->assertSame('rolled_back', $r['result']);
        $this->assertSame(['updating', 'rolled_back'], array_column($this->reports, 'phase'));
        $this->calls = [];
        $this->assertSame('refused', $this->service()->tick(['now' => $this->inWindow()])['result']);
        $this->assertNotContains('apply', $this->calls, 'a package that failed health is not re-applied until the Cloud advertises other bytes');
    }

    // ── the applier: K6 rollback rule ────────────────────────────────────────────────────────

    public function test_no_schema_change_healthy_update_needs_no_snapshot(): void
    {
        [$applier, $log] = $this->applier(schemaChange: false, healthy: true);
        $r = $applier->apply($this->work, $this->signed(), $this->release, 'test');
        $this->assertSame('updated', $r['result'], $r['message']);
        $this->assertFalse($r['schema_change']);
        $this->assertNotContains('snapshot', $log->calls);
        $this->assertSame('0.9.1-edge', EdgeRuntimePointer::current($this->installRoot));
        $this->assertSame(['stop:0.9.0-edge', 'start:0.9.1-edge'], array_values(array_filter($log->calls, fn ($c) => str_starts_with($c, 'stop') || str_starts_with($c, 'start'))));
    }

    public function test_no_schema_change_unhealthy_update_rolls_back_the_pointer_only(): void
    {
        [$applier, $log] = $this->applier(schemaChange: false, healthy: false);
        $r = $applier->apply($this->work, $this->signed(), $this->release, 'test');
        $this->assertSame('rolled_back', $r['result']);
        $this->assertSame('reverted_runtime', $r['rollback']);
        $this->assertNotContains('restore', $log->calls, 'no schema change → pointer rollback only');
        $this->assertSame('0.9.0-edge', EdgeRuntimePointer::current($this->installRoot));
        $this->assertSame(['stop:0.9.0-edge', 'start:0.9.1-edge', 'stop:0.9.1-edge', 'start:0.9.0-edge'], $this->serviceCalls($log), 'stop new → pointer back → start previous');
    }

    public function test_a_schema_change_without_an_approved_plan_hash_waits_and_installs_nothing(): void
    {
        [$applier, $log] = $this->applier(schemaChange: true, healthy: true);
        $r = $applier->apply($this->work, $this->signed(), $this->release, 'test');
        $this->assertSame('refused', $r['result']);
        $this->assertSame('UPDATE_MIGRATION_PLAN_UNCONFIRMED', $r['code']);
        $this->assertNotContains('install', $log->calls);
        $this->assertNotContains('snapshot', $log->calls);
        $this->assertSame('0.9.0-edge', EdgeRuntimePointer::current($this->installRoot));
    }

    public function test_a_schema_change_with_a_mismatched_plan_hash_is_refused(): void
    {
        [$applier, $log] = $this->applier(schemaChange: true, healthy: true);
        $r = $applier->apply($this->work, $this->signed(), $this->release + ['plan_hashes' => ['0.9.0-edge' => 'sha256:other']], 'test');
        $this->assertSame('UPDATE_MIGRATION_PLAN_MISMATCH', $r['code']);
        $this->assertNotContains('install', $log->calls);
    }

    public function test_a_schema_change_takes_a_snapshot_first_and_restores_it_when_health_fails(): void
    {
        [$applier, $log] = $this->applier(schemaChange: true, healthy: false);
        $r = $applier->apply($this->work, $this->signed(), $this->release + ['plan_hashes' => ['0.9.0-edge' => 'sha256:plan']], 'test');
        $this->assertSame('rolled_back', $r['result'], $r['message']);
        $this->assertSame('restored_snapshot', $r['rollback']);
        $i = array_search('snapshot', $log->calls, true);
        $this->assertNotFalse($i);
        $this->assertLessThan(array_search('install', $log->calls, true), $i, 'backup BEFORE the update');
        $this->assertContains('restore', $log->calls);
        $this->assertLessThan(array_search('start:0.9.0-edge', $log->calls, true), array_search('restore', $log->calls, true), 'restore before the previous runtime starts');
        $this->assertSame('0.9.0-edge', EdgeRuntimePointer::current($this->installRoot));
    }

    public function test_a_schema_change_whose_snapshot_fails_is_refused_before_any_mutation(): void
    {
        [$applier, $log] = $this->applier(schemaChange: true, healthy: true, snapshotFails: true);
        $r = $applier->apply($this->work, $this->signed(), $this->release + ['plan_hashes' => ['0.9.0-edge' => 'sha256:plan']], 'test');
        $this->assertSame('refused', $r['result']);
        $this->assertSame('UPDATE_PREUPDATE_SNAPSHOT_FAILED', $r['code']);
        $this->assertNotContains('install', $log->calls);
        $this->assertNotContains('stop:0.9.0-edge', $log->calls);
    }

    public function test_a_schema_upgrade_failure_restores_the_snapshot_and_restarts_the_previous_runtime(): void
    {
        [$applier, $log] = $this->applier(schemaChange: true, healthy: true, installThrows: 'UPDATE_SCHEMA_UPGRADE_FAILED (rollback=reverted_runtime): boom');
        $r = $applier->apply($this->work, $this->signed(), $this->release + ['plan_hashes' => ['0.9.0-edge' => 'sha256:plan']], 'test');
        $this->assertSame('rolled_back', $r['result']);
        $this->assertSame('restored_snapshot', $r['rollback']);
        $this->assertContains('restore', $log->calls);
        $this->assertSame('start:0.9.0-edge', $this->serviceCalls($log)[count($this->serviceCalls($log)) - 1]);
    }

    public function test_an_unhealthy_update_is_not_rolled_back_once_the_branch_left_standby(): void
    {
        [$applier, $log] = $this->applier(schemaChange: true, healthy: false, standbyAfterInstall: false);
        $r = $applier->apply($this->work, $this->signed(), $this->release + ['plan_hashes' => ['0.9.0-edge' => 'sha256:plan']], 'test');
        $this->assertSame('update_failed', $r['result']);
        $this->assertSame('AUTO_UPDATE_HEALTH_FAILED_NOT_ROLLED_BACK', $r['code']);
        $this->assertNotContains('restore', $log->calls, 'a selling branch is never restored underneath the cashier');
        $this->assertSame('0.9.1-edge', EdgeRuntimePointer::current($this->installRoot));
    }

    public function test_the_gate_is_rechecked_inside_the_lock(): void
    {
        [$applier, $log] = $this->applier(schemaChange: false, healthy: true, standbyAtStart: false);
        $r = $applier->apply($this->work, $this->signed(), $this->release, 'test');
        $this->assertSame('refused', $r['result']);
        $this->assertSame('AUTO_UPDATE_GATE_CHANGED', $r['code']);
        $this->assertSame([], $log->calls);
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────

    private function inWindow(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-22 03:30', 'Asia/Karachi');
    }

    private function outsideWindow(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-22 14:00', 'Asia/Karachi');
    }

    private function signed(): array
    {
        return ['payload' => ['edge_app_version' => '0.9.1-edge', 'artifact_manifest_hash' => 'x'], 'signature' => 'sig'];
    }

    private function store(): EdgeAutoUpdateStateStore
    {
        return new EdgeAutoUpdateStateStore($this->work . DIRECTORY_SEPARATOR . 'updates');
    }

    private function service(bool $paired = true): EdgeAutoUpdateService
    {
        $t = $this;
        $cloud = new class($t, $paired) extends EdgeDeviceCloudClient {
            public function __construct(private $t, private bool $paired) {}
            public function deviceConfigured(): bool { return $this->paired; }
            public function latestRelease(string $currentVersion): array { $this->t->calls[] = 'check'; return $this->t->release; }
            public function reportStatus(string $phase, string $version, bool $ok, string $message = ''): array { $this->t->reports[] = compact('phase', 'version', 'ok'); return ['sent' => true]; }
        };
        $stager = new class($t) extends EdgeAutoUpdateStager {
            public function __construct(private $t) {}
            public function stage(array $release, string $workDir): string { $this->t->calls[] = 'stage'; $d = $workDir . '/staging/pkg'; @mkdir($d, 0775, true); return $d; }
            public function prune(string $workDir, ?string $keepDir, int $keep): void {}
        };
        $verifier = new class($t) extends EdgeAutoUpdatePackageVerifier {
            public function __construct(private $t) {}
            public function verify(string $packageRoot, array $release, string $currentVersion, string $currentSchema): array
            {
                $this->t->calls[] = 'verify';
                if ($this->t->verifyFailure) { throw $this->t->verifyFailure; }
                return ['package' => ['payload' => ['edge_app_version' => $release['version']], 'signature' => 's'], 'manifest' => [], 'update_file' => 'u', 'version' => $release['version'], 'signing_key_id' => 'k'];
            }
        };
        $applier = new class($t) extends EdgeAutoUpdateApplier {
            public function __construct(private $t) {}
            public function apply(string $packageRoot, array $signedPackage, array $release, string $performedBy): array
            {
                $this->t->calls[] = 'apply';
                return $this->t->applierResult ?? ['result' => self::UPDATED, 'code' => null, 'message' => 'ok', 'from_version' => '0.9.0-edge', 'to_version' => $release['version'], 'schema_change' => false, 'rollback' => 'none'];
            }
        };

        return new EdgeAutoUpdateService($cloud, $stager, $verifier, $applier, $this->runtimeProbe(fn () => $this->facts), $this->store());
    }

    private function runtimeProbe(callable $facts): EdgeAutoUpdateRuntimeProbe
    {
        return new class($facts) extends EdgeAutoUpdateRuntimeProbe {
            public function __construct(private $f) {}
            public function facts(): array { return ($this->f)(); }
        };
    }

    /** @return array{0:EdgeAutoUpdateApplier,1:object} */
    private function applier(bool $schemaChange, bool $healthy, bool $snapshotFails = false, ?string $installThrows = null, bool $standbyAtStart = true, bool $standbyAfterInstall = true): array
    {
        $log = new class { public array $calls = []; public bool $installed = false; };
        $root = $this->installRoot;
        $installer = new class(app(EdgeUpdateVerifier::class), app(EdgeUpdatePackageService::class), app(EdgeBackupService::class), $log, $schemaChange, $installThrows, $root) extends EdgeAutoUpdateInstaller {
            public function __construct($v, $p, $b, private $log, private bool $schema, private ?string $throws, private string $root) { parent::__construct($v, $p, $b); }
            public function dryRun(array $package, string $stagedArtifactDir, string $performedBy): array
            {
                $this->log->calls[] = 'dry-run';
                $plan = $this->schema
                    ? ['entries' => [['migration' => 'm1']], 'run' => ['m1'], 'skip' => [], 'substitute' => [], 'blockers' => [], 'verdict' => 'OK']
                    : ['entries' => [], 'run' => [], 'skip' => [], 'substitute' => [], 'blockers' => [], 'verdict' => 'OK'];
                return ['result' => 'dry_run', 'verdict' => 'OK', 'plan' => $plan, 'plan_hash' => $this->schema ? 'sha256:plan' : 'sha256:empty', 'from_version' => '0.9.0-edge', 'to_version' => '0.9.1-edge', 'schema_before' => 'x'];
            }
            public function install(array $package, string $stagedArtifactDir, string $performedBy, ?string $expectedPlanHash = null): array
            {
                $this->log->calls[] = 'install';
                $this->stopWorkers();
                if ($this->throws !== null) {
                    throw new RuntimeException($this->throws); // the real installer reverts the pointer itself on a schema failure
                }
                @mkdir($this->root . '/versions/0.9.1-edge', 0775, true);
                file_put_contents($this->root . '/current', '0.9.1-edge');
                $this->log->installed = true;
                $this->startWorkers();
                return ['result' => 'applied'];
            }
        };
        $services = new class($log) extends EdgeAutoUpdateServiceControl {
            public function __construct(private $log) {}
            public function stop(string $runtimeDir): void { $this->log->calls[] = 'stop:' . basename($runtimeDir); }
            public function start(string $runtimeDir): void { $this->log->calls[] = 'start:' . basename($runtimeDir); }
        };
        $health = new class($log, $healthy) extends EdgeAutoUpdateHealthProbe {
            public function __construct(private $log, private bool $healthy) { parent::__construct(new EdgeApplianceRunner()); }
            public function probe(): array { return ['status' => 'STANDBY_READY', 'problems' => []]; }
            public function waitHealthy(array $before, string $expectedVersion, int $timeoutSeconds): array
            {
                $this->log->calls[] = 'health:' . $expectedVersion;
                $ok = $this->healthy || $expectedVersion === '0.9.0-edge';
                return ['ok' => $ok, 'reasons' => $ok ? [] : ['new problem: web backend not listening'], 'report' => []];
            }
        };
        $snapshot = new class($log, $snapshotFails) extends EdgeDatabaseSnapshot {
            public function __construct(private $log, private bool $fails) {}
            public function snapshot(string $file): array { if ($this->fails) { throw new RuntimeException('AUTO_UPDATE_SNAPSHOT_FAILED: disk full'); } $this->log->calls[] = 'snapshot'; @mkdir(dirname($file), 0775, true); file_put_contents($file, 'x'); return ['file' => $file, 'tables' => 1, 'rows' => 1, 'bytes' => 1, 'database' => 'edge_local_x']; }
            public function restore(string $file): array { $this->log->calls[] = 'restore'; return ['tables' => 1, 'rows' => 1]; }
        };
        $probe = $this->runtimeProbe(function () use ($log, $standbyAtStart, $standbyAfterInstall) {
            $standby = $log->installed ? $standbyAfterInstall : $standbyAtStart;
            return ['bound' => true, 'authority_state' => $standby ? 'standby' : 'local_active', 'outbox_pending' => 0, 'outbox_in_flight' => 0];
        });

        return [new EdgeAutoUpdateApplier($installer, $services, $health, $probe, $snapshot), $log];
    }

    private function serviceCalls(object $log): array
    {
        return array_values(array_filter($log->calls, fn ($c) => str_starts_with($c, 'stop:') || str_starts_with($c, 'start:')));
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
