<?php

namespace App\Services\Edge;

use App\Support\EdgeConsoleBoundary;
use App\Support\EdgeRuntime;
use RuntimeException;

/**
 * OFFLINE EDGE PRODUCTIZATION (J) + PACKAGING (P4) — the deterministic supervision plan for the Branch Server appliance.
 *
 * One source of truth for WHICH long-running / periodic processes the appliance runs and under WHAT policy,
 * so the Windows Scheduled Task installers (scripts/edge) and the contract tests agree. It emits data, not
 * side effects; the PowerShell scripts render it. Policy, locked:
 *
 *   - branch_server ONLY — a Cloud host supervises nothing here (fail closed);
 *   - every task runs an EDGE-ALLOWLISTED artisan command — a Cloud command can never be scheduled;
 *   - least privilege — a restricted service account, NON-elevated, NEVER SYSTEM;
 *   - one logical instance — the print worker via its singleton heartbeat row, the sync sender via the
 *     outbox SKIP-LOCKED lease, backup via its single-writer file lock, the authority worker via its DB
 *     singleton, each web backend via its own loopback listen port (so a duplicate task run is safe);
 *   - boot start + bounded restart, and a bounded DB-startup wait so a worker that boots before MariaDB
 *     retries instead of crash-looping;
 *   - no secret ever appears on a command line (the command is just php + artisan + the command name).
 *
 * P4 adds the WEB RUNTIME: N loopback PHP backends (edge:local:serve --worker=N, one task each) fronted by the
 * ONE non-artisan supervised process, the TLS gateway (nginx) that owns the LAN listener. The gateway is
 * described separately by gateway() so the artisan-only invariant of tasks() stays provable.
 */
class EdgeSupervisionPlan
{
    /** A heartbeat/behaviour the appliance's workers already implement; named here for the contract tests. */
    public const SINGLETON_HEARTBEAT = 'singleton_heartbeat';   // print worker (EdgeLocalPrintWorkerSupervisor)
    public const SINGLETON_OUTBOX_LEASE = 'outbox_lease';       // sync sender (SKIP LOCKED)
    public const SINGLETON_BACKUP_LOCK = 'backup_lock';         // backup (flock)
    public const SINGLETON_AUTHORITY_WORKER = 'authority_worker_heartbeat'; // Q authority worker (DB singleton + liveness heartbeat)
    public const SINGLETON_LISTEN_PORT = 'listen_port';         // P4 web backend / gateway: the OS grants a port to one process only

    public const GATEWAY_TASK = 'BingooEdgeGateway';
    public const WEB_TASK_PREFIX = 'BingooEdgeWeb';

    private const SERVICE_ACCOUNT = 'NT AUTHORITY\\LOCAL SERVICE';

    /**
     * @return array<int,array<string,mixed>> the supervised artisan tasks (deterministic order).
     */
    public function tasks(string $phpPath, string $appRoot): array
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('SUPERVISION_NOT_BRANCH_SERVER: only a Branch Server supervises Edge workers.');
        }
        $appRoot = rtrim($appRoot, "/\\");
        $artisan = $appRoot . DIRECTORY_SEPARATOR . 'artisan';

        $tasks = [];
        // P4 — the web runtime: one loopback PHP backend per task; the gateway load-balances across them.
        for ($i = 1; $i <= $this->webWorkers(); $i++) {
            $tasks[] = $this->task(self::WEB_TASK_PREFIX . $i, 'edge:local:serve', $phpPath, $artisan, $appRoot, [
                'trigger' => 'at_startup', 'kind' => 'continuous', 'singleton' => self::SINGLETON_LISTEN_PORT,
                'extra_arguments' => '--worker=' . $i,
                'listen' => (string) config('edge.web.bind', '127.0.0.1') . ':' . $this->webPort($i),
            ]);
        }
        $tasks[] = $this->task('BingooEdgePrintWorker', 'edge:local:print-worker', $phpPath, $artisan, $appRoot, [
            'trigger' => 'at_startup', 'kind' => 'continuous', 'singleton' => self::SINGLETON_HEARTBEAT,
        ]);
        $tasks[] = $this->task('BingooEdgeSyncSender', 'edge:local:sync-send', $phpPath, $artisan, $appRoot, [
            'trigger' => 'at_startup', 'kind' => 'periodic', 'repeat_minutes' => 2, 'singleton' => self::SINGLETON_OUTBOX_LEASE,
        ]);
        // Q — the ONE authority worker: heartbeat at the configured interval, connection state machine, warm
        // standby freshness, outbox drain + reconciliation while local. Continuous, single instance (DB singleton
        // with liveness heartbeat), cooperative stop, secrets from config only.
        $tasks[] = $this->task('BingooEdgeAuthorityWorker', 'edge:local:authority-worker', $phpPath, $artisan, $appRoot, [
            'trigger' => 'at_startup', 'kind' => 'continuous', 'singleton' => self::SINGLETON_AUTHORITY_WORKER,
        ]);
        $tasks[] = $this->task('BingooEdgeBackup', 'edge:local:backup', $phpPath, $artisan, $appRoot, [
            'trigger' => 'at_startup', 'kind' => 'periodic', 'repeat_minutes' => 60, 'singleton' => self::SINGLETON_BACKUP_LOCK,
        ]);

        // Defence-in-depth: nothing but an Edge-allowlisted command may ever be scheduled on the appliance.
        foreach ($tasks as $t) {
            if (! EdgeConsoleBoundary::isAllowed($t['artisan_command'])) {
                throw new RuntimeException('SUPERVISION_COMMAND_DENIED: ' . $t['artisan_command'] . ' is not Edge-allowlisted.');
            }
        }

        return $tasks;
    }

    /**
     * P4 — the ONE non-artisan supervised process: the TLS gateway (nginx) owning the LAN listener. Same
     * least-privilege / boot-start / bounded-restart policy as the artisan tasks; its configuration file is
     * rendered by renderGatewayConfig() into the data root (never inside the versioned runtime).
     */
    public function gateway(string $gatewayExe, string $dataRoot): array
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('SUPERVISION_NOT_BRANCH_SERVER: only a Branch Server supervises Edge workers.');
        }
        $dataRoot = rtrim($dataRoot, "/\\");
        $prefix = $dataRoot . DIRECTORY_SEPARATOR . 'gateway';

        return [
            'name' => self::GATEWAY_TASK,
            'kind_of_process' => 'gateway',
            'executable' => $gatewayExe,
            // nginx: -p prefix (logs/temp under the data root), -c the rendered config. No secret on the command line —
            // the certificate/key are FILES referenced from the config, ACL-restricted to the service account.
            'arguments' => '-p "' . $prefix . '" -c "' . $prefix . DIRECTORY_SEPARATOR . 'nginx.conf"',
            'working_directory' => $prefix,
            'config_path' => $prefix . DIRECTORY_SEPARATOR . 'nginx.conf',
            'principal' => self::SERVICE_ACCOUNT,
            'run_level' => 'limited',
            'logon_type' => 'service_account',
            'trigger' => 'at_startup',
            'kind' => 'continuous',
            'restart_count' => 999,
            'restart_interval_minutes' => 1,
            'start_when_available' => true,
            'singleton' => self::SINGLETON_LISTEN_PORT,
            'listen' => '0.0.0.0:' . (int) config('edge.gateway.https_port', 443),
            'redirect_listen' => '0.0.0.0:' . (int) config('edge.gateway.http_port', 80),
            'stop' => 'signal',            // nginx -s quit (graceful: finishes in-flight requests)
            'stop_arguments' => '-p "' . $prefix . '" -c "' . $prefix . DIRECTORY_SEPARATOR . 'nginx.conf" -s quit',
        ];
    }

    /**
     * The gateway configuration (nginx). TLS on the LAN with the branch-CA server certificate (PEM files under
     * <dataRoot>/certs), HTTP → HTTPS redirect only, proxy to the loopback PHP backends (least_conn), the
     * forwarded-proto header so the app sees HTTPS, and generous upload/timeout limits for the POS pages.
     */
    public function renderGatewayConfig(string $dataRoot, string $appRoot): string
    {
        $dataRoot = rtrim(str_replace('\\', '/', $dataRoot), '/');
        $appRoot = rtrim(str_replace('\\', '/', $appRoot), '/');
        $bind = (string) config('edge.web.bind', '127.0.0.1');
        $upstreams = '';
        for ($i = 1; $i <= $this->webWorkers(); $i++) {
            $upstreams .= "        server {$bind}:{$this->webPort($i)} max_fails=3 fail_timeout=5s;\n";
        }
        $https = (int) config('edge.gateway.https_port', 443);
        $http = (int) config('edge.gateway.http_port', 80);
        $host = (string) config('edge.lan.hostname', 'bingoo-edge.local');

        return <<<NGINX
# Bingoo Edge — Branch Server TLS gateway (rendered by `edge:local:service-plan`; do not hand-edit — re-render).
# LAN listener: HTTPS {$https} with the branch-CA server certificate. HTTP {$http} only redirects.
# Upstream: the supervised loopback PHP backends (BingooEdgeWebN). No plain HTTP as normal mode.
worker_processes 1;
error_log logs/gateway-error.log warn;
pid       logs/gateway.pid;
events { worker_connections 512; }
http {
    include       mime.types;   # copied beside this file by edge:local:service-plan --write-gateway-config
    default_type  application/octet-stream;
    access_log    logs/gateway-access.log combined;
    sendfile      on;
    server_tokens off;
    client_max_body_size 32m;
    upstream bingoo_edge_web {
        least_conn;
{$upstreams}    }
    server {
        listen {$http};
        server_name {$host} _;
        return 301 https://\$host\$request_uri;
    }
    server {
        listen {$https} ssl http2;
        server_name {$host} _;
        ssl_certificate     "{$dataRoot}/certs/server.crt";
        ssl_certificate_key "{$dataRoot}/certs/server.key";
        ssl_protocols       TLSv1.2 TLSv1.3;
        ssl_prefer_server_ciphers on;
        add_header Strict-Transport-Security "max-age=31536000" always;
        location / {
            proxy_pass         http://bingoo_edge_web;
            proxy_http_version 1.1;
            proxy_set_header   Connection "";
            proxy_set_header   Host              \$host;
            proxy_set_header   X-Real-IP         \$remote_addr;
            proxy_set_header   X-Forwarded-For   \$proxy_add_x_forwarded_for;
            proxy_set_header   X-Forwarded-Proto https;
            proxy_set_header   X-Forwarded-Host  \$host;
            proxy_set_header   X-Forwarded-Port  {$https};
            proxy_read_timeout 120s;
            proxy_connect_timeout 5s;
            proxy_next_upstream error timeout http_502 http_503;
        }
    }
}
NGINX;
    }

    public function webWorkers(): int
    {
        return max(1, min(8, (int) config('edge.web.workers', 2)));
    }

    public function webPort(int $worker): int
    {
        return (int) config('edge.web.port_base', 8090) + max(1, $worker) - 1;
    }

    private function task(string $name, string $command, string $phpPath, string $artisan, string $appRoot, array $policy): array
    {
        $extra = isset($policy['extra_arguments']) && $policy['extra_arguments'] !== '' ? ' ' . $policy['extra_arguments'] : '';

        return [
            'name' => $name,
            'kind_of_process' => 'artisan',
            'artisan_command' => $command,
            'executable' => $phpPath,
            // The ONLY arguments are the artisan entrypoint + the command name (+ a non-secret worker index) — never a secret/credential.
            'arguments' => '"' . $artisan . '" ' . $command . $extra,
            'working_directory' => $appRoot,
            'principal' => self::SERVICE_ACCOUNT,
            'run_level' => 'limited',      // NON-elevated
            'logon_type' => 'service_account',
            'trigger' => $policy['trigger'],
            'kind' => $policy['kind'],
            'repeat_minutes' => $policy['repeat_minutes'] ?? null,
            'restart_count' => 999,
            'restart_interval_minutes' => 1,
            'start_when_available' => true,
            'singleton' => $policy['singleton'],
            'listen' => $policy['listen'] ?? null,
            'startup_db_retry' => true,     // bounded wait for MariaDB before giving up (task restarts)
            'stop' => 'cooperative',        // graceful stop first, never a hard kill that orphans a lease
        ];
    }
}
