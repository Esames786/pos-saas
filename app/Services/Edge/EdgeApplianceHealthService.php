<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeLocalMeta;
use App\Models\Edge\EdgeSyncOutbox;
use App\Support\EdgeApplianceLayout;
use App\Support\EdgeLocalDatabase;
use App\Support\EdgeRuntime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * P4 §11 — the ONE operator/admin health report for the Branch Server (command `edge:local:health` and the
 * cashier-facing status page read the SAME report).
 *
 * Non-secret by construction: it names services, states, counts, watermarks, versions and paths — never a DB
 * password, device secret, recovery key, app key, token or card data. Every section is guarded so a broken DB or
 * an unbound box still yields a report (with the problem named) instead of a stack trace.
 */
class EdgeApplianceHealthService
{
    public const STATUS_STANDBY_READY = 'STANDBY_READY';
    public const STATUS_LOCAL_ACTIVE = 'LOCAL_ACTIVE';
    public const STATUS_TRANSITION = 'TRANSITION';
    public const STATUS_DEGRADED = 'DEGRADED';
    public const STATUS_NOT_BOUND = 'NOT_BOUND';

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly EdgeLocalPrintWorkerSupervisor $printWorker,
        private readonly EdgeLocalAuthorityWorkerSupervisor $authorityWorker,
    ) {
    }

    public function report(): array
    {
        $problems = [];
        $runtime = $this->runtime($problems);
        $db = $this->database($problems);
        $meta = $db['reachable'] ? $this->meta() : null;
        $binding = $this->binding($meta, $problems);
        $authority = $this->authority($meta, $problems);
        $freshness = $this->freshness($meta, $problems);
        $sync = $this->sync($meta, $problems);
        $workers = $this->workers($meta, $problems);
        $print = $this->print($meta, $problems);
        $backup = $this->backup($meta, $problems);
        $update = $this->update($problems);
        $gateway = $this->gateway($problems);
        $layout = EdgeApplianceLayout::describe();

        $status = $this->overall($meta, $authority, $problems);

        return [
            'status' => $status,
            'status_label' => $this->label($status),
            'generated_at' => now()->toIso8601String(),
            'problems' => array_values(array_unique($problems)),
            'runtime' => $runtime,
            'database' => $db,
            'binding' => $binding,
            'authority' => $authority,
            'freshness' => $freshness,
            'sync' => $sync,
            'workers' => $workers,
            'print' => $print,
            'backup' => $backup,
            'update' => $update,
            'gateway' => $gateway,
            'layout' => $layout,
            'print_architecture' => (array) config('edge.print_architecture'),
            'auto_failover_enabled' => false, // supervised takeover only (locked)
        ];
    }

    // ── sections ────────────────────────────────────────────────────────────

    private function runtime(array &$problems): array
    {
        $boot = [];
        try {
            $boot = EdgeRuntime::bootProblems();
        } catch (Throwable $e) {
            $boot = [$e->getMessage()];
        }
        foreach ($boot as $p) {
            $problems[] = 'Runtime: ' . $p;
        }
        $manifest = [];
        $path = base_path('edge-build-manifest.json');
        if (is_file($path)) {
            $manifest = json_decode((string) file_get_contents($path), true) ?: [];
        }

        return [
            'mode' => (string) config('app.role', 'cloud'),
            'edge_app_version' => (string) config('edge.app_version'),
            'config_schema' => (string) config('edge.config_schema'),
            'bootstrap_schema' => (string) config('edge.bootstrap_schema'),
            'php_version' => PHP_VERSION,
            'packaged_artifact' => EdgeRuntime::isPackagedEdgeArtifact(),
            'artifact_commit' => $manifest['git_commit'] ?? null,
            'artifact_build_mode' => $manifest['build_mode'] ?? null,
            'artifact_manifest_hash' => isset($manifest['manifest_hash']) ? substr((string) $manifest['manifest_hash'], 0, 16) . '…' : null,
            'boot_problems' => $boot,
            'log_channel' => (string) config('logging.default'),
            'session_driver' => (string) config('session.driver'),
        ];
    }

    private function database(array &$problems): array
    {
        $reachable = false;
        $version = null;
        $error = null;
        try {
            $version = (string) (DB::connection('tenant')->selectOne('select version() as v')->v ?? '');
            $reachable = true;
        } catch (Throwable $e) {
            $error = get_class($e);
            $problems[] = 'Local database unreachable.';
        }
        $schemaOk = null;
        if ($reachable) {
            try {
                $schemaOk = Schema::connection('tenant')->hasTable('edge_local_meta') && Schema::connection('tenant')->hasTable('edge_sync_outbox');
                if (! $schemaOk) {
                    $problems[] = 'Local database schema is not initialised (run edge:local:db-init).';
                }
            } catch (Throwable) {
                $schemaOk = false;
            }
        }

        return [
            'reachable' => $reachable,
            'host' => EdgeLocalDatabase::host(),
            'database' => EdgeLocalDatabase::database(),
            'server_version' => $version,
            'schema_present' => $schemaOk,
            'safe_target' => EdgeLocalDatabase::unsafeReason() === null,
            'error' => $error,
        ];
    }

    private function meta(): ?EdgeLocalMeta
    {
        try {
            return $this->context->tryCurrent();
        } catch (Throwable) {
            return null;
        }
    }

    private function binding(?EdgeLocalMeta $meta, array &$problems): array
    {
        if (! $meta) {
            $problems[] = 'Appliance is not bound to a branch yet (pair + bootstrap-pull).';

            return ['bound' => false];
        }
        $credentials = 0;
        try {
            $credentials = (int) DB::connection('tenant')->table('edge_local_user_credentials')
                ->where('branch_id', (int) $meta->branch_id)->where('activation_epoch', (int) $meta->activation_epoch)->where('status', 'active')->count();
        } catch (Throwable) {
        }
        if ($credentials === 0) {
            $problems[] = 'No enrolled local users (run edge:local:enroll).';
        }
        $deviceConfigured = trim((string) config('edge.sync.device_id', '')) !== '' && trim((string) config('edge.sync.device_secret', '')) !== '';
        if (! $deviceConfigured) {
            $problems[] = 'Device identity missing from the appliance configuration.';
        }

        return [
            'bound' => $meta->runtime_state === EdgeLocalMeta::STATE_BOOTSTRAPPED,
            'runtime_state' => (string) $meta->runtime_state,
            'tenant_code' => $meta->tenant_code,
            'tenant_id' => (int) $meta->tenant_id,
            'branch_id' => (int) $meta->branch_id,
            'device_uuid' => $meta->device_uuid,
            'device_identity_configured' => $deviceConfigured,
            'device_identity_matches' => $deviceConfigured && (string) config('edge.sync.device_id') === (string) $meta->device_uuid,
            'activation_epoch' => (int) $meta->activation_epoch,
            'bootstrap_schema' => $meta->bootstrap_schema,
            'config_schema_version' => $meta->config_schema_version,
            'schema_compatible' => (string) $meta->bootstrap_schema === (string) config('edge.bootstrap_schema') && (string) $meta->config_schema_version === (string) config('edge.config_schema'),
            'imported_at' => optional($meta->imported_at)->toIso8601String(),
            'enrolled_local_users' => $credentials,
        ];
    }

    private function authority(?EdgeLocalMeta $meta, array &$problems): array
    {
        if (! $meta) {
            return ['state' => null, 'connection_state' => null];
        }
        $lastAck = $meta->authority_last_ack_at ? Carbon::parse($meta->authority_last_ack_at) : null;
        $state = (string) ($meta->authority_state ?? 'standby');
        $conn = (string) ($meta->connection_state ?? 'unknown');
        if ($lastAck === null) {
            $problems[] = 'The Cloud has never acknowledged a heartbeat from this appliance.';
        }
        if (in_array($conn, ['unstable', 'lost'], true)) {
            $problems[] = 'Cloud connection is ' . $conn . '.';
        }
        $gates = [];
        try {
            $gates = app(EdgeAuthorityService::class)->gates();
        } catch (Throwable $e) {
            $gates = ['error' => get_class($e)];
        }
        $blockers = [];
        if ($state === 'local_active') {
            try {
                $blockers = array_column(app(EdgeHandbackOrchestrator::class)->assess()['blockers'] ?? [], 'code');
            } catch (Throwable) {
            }
        }

        return [
            'state' => $state,
            'state_reason' => $meta->authority_state_reason,
            'connection_state' => $conn,
            'connection_state_since' => optional($meta->connection_state_since)->toIso8601String(),
            'connection_state_reason' => $meta->connection_state_reason,
            'last_heartbeat_ack_at' => $lastAck?->toIso8601String(),
            'last_heartbeat_ack_age_seconds' => $lastAck ? max(0, (int) $lastAck->diffInSeconds(now())) : null,
            'last_heartbeat_failure_at' => optional($meta->authority_last_failure_at)->toIso8601String(),
            'consecutive_failures' => (int) ($meta->heartbeat_consecutive_failures ?? 0),
            'consecutive_acks' => (int) ($meta->heartbeat_consecutive_acks ?? 0),
            'cloud_holder_seen' => $meta->authority_cloud_holder_seen,
            'takeover_at' => optional($meta->authority_takeover_at)->toIso8601String(),
            'handed_back_at' => $meta->handed_back_at ?? null,
            'gates' => $gates,
            'handback_blockers' => $blockers,
            'require_confirmation' => (bool) config('edge.authority.require_confirmation', true),
            'auto_failover' => false,
        ];
    }

    private function freshness(?EdgeLocalMeta $meta, array &$problems): array
    {
        if (! $meta) {
            return [];
        }
        $out = [];
        try {
            $f = app(EdgeStandbyFreshnessService::class)->freshEnough();
            $out['config'] = ['ok' => (bool) ($f['config_ok'] ?? false), 'applied_revision' => (int) ($meta->last_applied_config_revision ?? 0), 'cloud_revision_seen' => $meta->standby_config_revision_seen !== null ? (int) $meta->standby_config_revision_seen : null, 'refreshed_at' => optional($meta->standby_config_refreshed_at)->toIso8601String()];
            $out['stock'] = ['ok' => (bool) ($f['stock_ok'] ?? false), 'watermark_seen' => $this->short($meta->standby_stock_watermark_seen), 'refreshed_at' => optional($meta->standby_stock_refreshed_at)->toIso8601String(), 'reasons' => (array) ($f['reasons'] ?? [])];
        } catch (Throwable $e) {
            $out['config'] = ['ok' => false, 'error' => get_class($e)];
            $out['stock'] = ['ok' => false, 'error' => get_class($e)];
        }
        foreach ([
            'returnable' => [EdgeReturnableSaleCacheService::class, 'returnable_cache_watermark', 'standby_returnable_watermark_seen', 'returnable_cache_refreshed_at'],
            'supplier_finance' => [EdgeSupplierFinanceCacheService::class, 'supplier_finance_cache_watermark', 'standby_supplier_finance_watermark_seen', 'supplier_finance_cache_refreshed_at'],
            'purchase_return' => [EdgePurchaseReturnCacheService::class, 'purchase_return_cache_watermark', 'standby_purchase_return_watermark_seen', 'purchase_return_cache_refreshed_at'],
        ] as $key => [$class, $wm, $seen, $at]) {
            try {
                $fr = app($class)->freshness();
                $out[$key] = ['ok' => (bool) ($fr['ok'] ?? false), 'watermark' => $this->short($meta->{$wm} ?? null), 'watermark_seen' => $this->short($meta->{$seen} ?? null), 'refreshed_at' => optional($meta->{$at})->toIso8601String(), 'reasons' => (array) ($fr['reasons'] ?? [])];
            } catch (Throwable $e) {
                $out[$key] = ['ok' => false, 'error' => get_class($e)];
            }
        }
        $stale = array_keys(array_filter($out, fn ($s) => ($s['ok'] ?? false) !== true));
        if ($stale !== [] && (string) ($meta->authority_state ?? 'standby') !== 'local_active') {
            $problems[] = 'Warm-standby cache not current: ' . implode(', ', $stale) . '.';
        }

        return $out;
    }

    private function sync(?EdgeLocalMeta $meta, array &$problems): array
    {
        if (! $meta) {
            return [];
        }
        try {
            $q = fn () => DB::connection('tenant')->table('edge_sync_outbox');
            $pending = (int) $q()->where('state', EdgeSyncOutbox::STATE_PENDING)->count();
            $leased = (int) $q()->where('state', EdgeSyncOutbox::STATE_LEASED)->count();
            $failed = (int) $q()->where('state', EdgeSyncOutbox::STATE_FAILED_PERMANENT)->count();
            $acked = (int) $q()->where('state', EdgeSyncOutbox::STATE_ACKNOWLEDGED)->count();
            $lastAck = $q()->whereNotNull('acknowledged_at')->max('acknowledged_at');
            $oldestPending = $q()->where('state', EdgeSyncOutbox::STATE_PENDING)->min('created_at');
            $byFamily = $q()->whereIn('state', [EdgeSyncOutbox::STATE_PENDING, EdgeSyncOutbox::STATE_LEASED, EdgeSyncOutbox::STATE_FAILED_PERMANENT])
                ->selectRaw('envelope_schema_version as family, state, count(*) as n')->groupBy('envelope_schema_version', 'state')->get()
                ->map(fn ($r) => ['family' => (string) $r->family, 'state' => (string) $r->state, 'count' => (int) $r->n])->all();
            $permanent = $q()->where('state', EdgeSyncOutbox::STATE_FAILED_PERMANENT)->orderByDesc('id')->limit(10)->get(['sale_uuid', 'envelope_schema_version', 'last_error', 'updated_at'])
                ->map(fn ($r) => ['uuid' => (string) $r->sale_uuid, 'family' => (string) $r->envelope_schema_version, 'error' => mb_substr((string) $r->last_error, 0, 160), 'at' => (string) $r->updated_at])->all();
            if ($failed > 0) {
                $problems[] = $failed . ' event(s) permanently refused by the Cloud — supervisor review required.';
            }

            return [
                'outbox_pending' => $pending, 'outbox_leased' => $leased, 'outbox_failed_permanent' => $failed, 'outbox_acknowledged' => $acked,
                'last_acknowledged_at' => $lastAck ? Carbon::parse($lastAck)->toIso8601String() : null,
                'oldest_pending_at' => $oldestPending ? Carbon::parse($oldestPending)->toIso8601String() : null,
                'reconcile_clean_at' => optional($meta->reconcile_clean_at)->toIso8601String(),
                'open_by_family' => $byFamily,
                'permanent_failures' => $permanent,
            ];
        } catch (Throwable $e) {
            return ['error' => get_class($e)];
        }
    }

    private function workers(?EdgeLocalMeta $meta, array &$problems): array
    {
        $print = ['state' => 'unknown'];
        $authority = ['installed' => null, 'running' => false];
        try {
            $print = $this->printWorker->health();
        } catch (Throwable $e) {
            $print = ['state' => 'error', 'error' => get_class($e)];
        }
        try {
            $authority = $this->authorityWorker->health();
        } catch (Throwable $e) {
            $authority = ['installed' => null, 'running' => false, 'error' => get_class($e)];
        }
        if ($meta && ($print['state'] ?? '') !== 'running') {
            $problems[] = 'Local print worker is ' . (string) ($print['state'] ?? 'unknown') . '.';
        }
        if ($meta && ! ($authority['running'] ?? false)) {
            $problems[] = 'Authority/heartbeat worker is not running.';
        }
        $plan = [];
        try {
            $plan = array_map(fn ($t) => ['task' => $t['name'], 'command' => $t['artisan_command'], 'kind' => $t['kind'], 'listen' => $t['listen'] ?? null], app(EdgeSupervisionPlan::class)->tasks(PHP_BINARY, base_path()));
            $plan[] = ['task' => EdgeSupervisionPlan::GATEWAY_TASK, 'command' => 'nginx (TLS gateway)', 'kind' => 'continuous', 'listen' => '0.0.0.0:' . (int) config('edge.gateway.https_port', 443)];
        } catch (Throwable) {
        }

        return [
            'print_worker' => $print,
            'authority_worker' => $authority,
            'web_backends' => array_map(fn ($i) => ['worker' => $i, 'listen' => (string) config('edge.web.bind', '127.0.0.1') . ':' . ((int) config('edge.web.port_base', 8090) + $i - 1), 'listening' => $this->portOpen((string) config('edge.web.bind', '127.0.0.1'), (int) config('edge.web.port_base', 8090) + $i - 1)], range(1, max(1, min(8, (int) config('edge.web.workers', 2))))),
            'planned_tasks' => $plan,
        ];
    }

    private function print(?EdgeLocalMeta $meta, array &$problems): array
    {
        if (! $meta) {
            return [];
        }
        try {
            $branchId = (int) $meta->branch_id;
            $printers = DB::connection('tenant')->table('printers')->where('is_active', 1)
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
                ->get(['id', 'name', 'printer_type', 'print_role', 'ip_address', 'port'])
                ->map(fn ($p) => ['id' => (int) $p->id, 'name' => (string) $p->name, 'type' => (string) $p->printer_type, 'role' => (string) $p->print_role, 'ip' => $p->ip_address, 'port' => $p->port !== null ? (int) $p->port : null,
                    'edge_capable' => $p->printer_type === 'network' && ! empty($p->ip_address)])->all();
            $jobs = [];
            if (Schema::connection('tenant')->hasTable('print_jobs')) {
                $jobs = DB::connection('tenant')->table('print_jobs')->where('branch_id', $branchId)->selectRaw('print_status, count(*) as n')->groupBy('print_status')->pluck('n', 'print_status')->map(fn ($n) => (int) $n)->all();
            }
            $usb = array_filter($printers, fn ($p) => $p['type'] === 'usb');
            if ($usb !== []) {
                $problems[] = count($usb) . ' USB printer(s) configured — USB printing is Online-only until the dual-mode agent exists.';
            }

            return ['printers' => $printers, 'jobs_by_status' => $jobs, 'network_printers_edge_direct' => count(array_filter($printers, fn ($p) => $p['edge_capable']))];
        } catch (Throwable $e) {
            return ['error' => get_class($e)];
        }
    }

    private function backup(?EdgeLocalMeta $meta, array &$problems): array
    {
        $configured = trim((string) config('edge.backup.recovery_key', '')) !== '';
        if (! $configured) {
            $problems[] = 'Backup recovery key not configured — backups cannot be sealed.';
        }
        $last = null;
        try {
            if ($meta && Schema::connection('tenant')->hasTable('edge_local_backups')) {
                $row = DB::connection('tenant')->table('edge_local_backups')->orderByDesc('id')->first();
                if ($row) {
                    $last = ['backup_uuid' => (string) $row->backup_uuid, 'created_at' => (string) $row->created_at, 'age_seconds' => max(0, (int) Carbon::parse($row->created_at)->diffInSeconds(now())), 'size_bytes' => (int) $row->size_bytes, 'status' => (string) $row->status, 'file_present' => is_file((string) $row->path)];
                    if ($last['age_seconds'] > 2 * 3600) {
                        $problems[] = 'Last backup is older than 2 hours.';
                    }
                } elseif ($meta->runtime_state === EdgeLocalMeta::STATE_BOOTSTRAPPED) {
                    $problems[] = 'No backup has been taken yet.';
                }
            }
        } catch (Throwable) {
        }

        return ['recovery_key_configured' => $configured, 'recovery_key_id' => (string) config('edge.backup.recovery_key_id', 'k1'), 'path' => (string) config('edge.backup.path'), 'path_writable' => is_dir((string) config('edge.backup.path')) ? is_writable((string) config('edge.backup.path')) : null, 'retention' => (int) config('edge.backup.retention', 24), 'last' => $last];
    }

    private function update(array &$problems): array
    {
        $root = (string) (config('edge.update.install_root') ?? '');
        $pointer = null;
        if ($root !== '' && is_file(rtrim($root, "/\\") . DIRECTORY_SEPARATOR . 'current')) {
            $pointer = trim((string) file_get_contents(rtrim($root, "/\\") . DIRECTORY_SEPARATOR . 'current'));
        }
        $publicKey = trim((string) config('edge.update.public_key', '')) !== '';
        if (! $publicKey) {
            $problems[] = 'Update verification key not configured — signed updates cannot be verified.';
        }
        $last = null;
        try {
            if (Schema::connection('tenant')->hasTable('edge_local_updates')) {
                $row = DB::connection('tenant')->table('edge_local_updates')->orderByDesc('id')->first();
                if ($row) {
                    $last = ['from' => $row->from_version, 'to' => $row->to_version, 'result' => (string) $row->result, 'failure_code' => $row->failure_code, 'rollback' => $row->rollback_result, 'completed_at' => (string) $row->completed_at];
                }
            }
        } catch (Throwable) {
        }

        return ['public_key_configured' => $publicKey, 'install_root' => $root !== '' ? $root : null, 'active_version_pointer' => $pointer, 'allow_downgrade' => (bool) config('edge.update.allow_downgrade', false), 'last' => $last];
    }

    private function gateway(array &$problems): array
    {
        $certs = EdgeApplianceLayout::certsDir();
        $cert = $certs ? $certs . DIRECTORY_SEPARATOR . 'server.crt' : null;
        $info = null;
        if ($cert && is_file($cert) && extension_loaded('openssl')) {
            $parsed = @openssl_x509_parse((string) file_get_contents($cert)) ?: [];
            if ($parsed !== []) {
                $validTo = isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null;
                $info = ['subject' => (string) ($parsed['subject']['CN'] ?? ''), 'san' => (string) ($parsed['extensions']['subjectAltName'] ?? ''), 'valid_to' => $validTo ? date('c', $validTo) : null, 'days_left' => $validTo ? (int) floor(($validTo - time()) / 86400) : null];
                if ($validTo && $validTo - time() < 30 * 86400) {
                    $problems[] = 'Gateway TLS certificate expires within 30 days.';
                }
            }
        }
        $conf = EdgeApplianceLayout::gatewayDir() ? EdgeApplianceLayout::gatewayDir() . DIRECTORY_SEPARATOR . 'nginx.conf' : null;

        return [
            'kind' => (string) config('edge.gateway.kind', 'nginx'),
            'https_port' => (int) config('edge.gateway.https_port', 443),
            'https_listening' => $this->portOpen('127.0.0.1', (int) config('edge.gateway.https_port', 443)),
            'config_present' => $conf !== null && is_file($conf),
            'certificate_present' => $cert !== null && is_file($cert),
            'certificate' => $info,
            'lan_hostname' => (string) config('edge.lan.hostname'),
            'lan_reserved_ip' => config('edge.lan.reserved_ip'),
        ];
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function overall(?EdgeLocalMeta $meta, array $authority, array $problems): string
    {
        if (! $meta || $meta->runtime_state !== EdgeLocalMeta::STATE_BOOTSTRAPPED) {
            return self::STATUS_NOT_BOUND;
        }
        $state = (string) ($authority['state'] ?? 'standby');
        if ($state === 'local_active') {
            return self::STATUS_LOCAL_ACTIVE;
        }
        if (in_array($state, ['preparing_local', 'handing_back', 'connection_restored'], true)) {
            return self::STATUS_TRANSITION;
        }

        return $problems === [] ? self::STATUS_STANDBY_READY : self::STATUS_DEGRADED;
    }

    private function label(string $status): string
    {
        return match ($status) {
            self::STATUS_STANDBY_READY => 'Ready as warm standby — Cloud is serving this branch',
            self::STATUS_LOCAL_ACTIVE => 'Local Mode active — this Branch Server is serving the branch',
            self::STATUS_TRANSITION => 'Changing over — please wait for the supervisor',
            self::STATUS_DEGRADED => 'Standby with issues — see problems',
            default => 'Not yet set up — installation incomplete',
        };
    }

    private function short(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = (string) $v;

        return strlen($s) > 20 ? substr($s, 0, 16) . '…' : $s;
    }

    private function portOpen(string $host, int $port): bool
    {
        $fp = @fsockopen($host === '0.0.0.0' ? '127.0.0.1' : $host, $port, $errno, $errstr, 0.25);
        if (is_resource($fp)) {
            fclose($fp);

            return true;
        }

        return false;
    }
}
