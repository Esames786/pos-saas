<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeSupervisionPlan;
use App\Services\Edge\EdgeWebBackendSupervisor;
use App\Support\EdgeApplianceLayout;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;

/**
 * P4 WINDOWS APPLIANCE — the loopback PHP web backend (one per supervised BingooEdgeWebN Scheduled Task).
 *
 *   php artisan edge:local:serve --worker=1
 *
 * Runs PHP's built-in web server for THIS runtime's public/ on 127.0.0.1:<port_base + worker - 1>. It binds
 * LOOPBACK ONLY by default: the LAN never reaches a backend directly — the TLS gateway (nginx, its own supervised
 * task) terminates HTTPS and proxies here, adding X-Forwarded-Proto so the app treats the request as secure.
 * The listen port is the singleton: the OS grants it to one process, so a duplicate task run exits at once.
 *
 * Supervision (F-FB4-1 hotfix, 0.8.1): the command is the backend's supervisor — see EdgeWebBackendSupervisor. On
 * Windows the child's stdout/stderr go to worker-specific, size-capped log files under the appliance data root (the P4
 * pipe relay deadlocked there after ~37 requests); the backend is probed over HTTP on its own port and a hung or exited
 * backend is restarted with back-off; the child is terminated when this process stops. The child inherits the appliance
 * env dir variable so it loads the SAME appliance.env. No secret ever appears on the command line.
 */
class EdgeLocalServeCommand extends Command
{
    protected $signature = 'edge:local:serve
        {--worker=1 : Backend index (1..N) — selects the loopback port}
        {--host= : Bind address (default edge.web.bind — loopback only; a LAN bind is refused unless EDGE_WEB_ALLOW_LAN_BIND=true)}
        {--port= : Override the port (default port_base + worker - 1)}
        {--once : Do not restart the backend after it exits or hangs (tests / diagnostics)}
        {--check : Print the resolved bind and exit without starting}';

    protected $description = 'Run and supervise one loopback PHP web backend for the Branch Server (fronted by the TLS gateway).';

    public function handle(EdgeSupervisionPlan $plan): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:serve only runs on a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }
        $problems = EdgeRuntime::bootProblems();
        if ($problems !== []) {
            $this->error('Refusing to serve: ' . implode(' ', $problems));

            return self::FAILURE;
        }
        $worker = max(1, (int) $this->option('worker'));
        $host = (string) ($this->option('host') ?: config('edge.web.bind', '127.0.0.1'));
        $port = (int) ($this->option('port') ?: $plan->webPort($worker));
        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true) && ! filter_var(env('EDGE_WEB_ALLOW_LAN_BIND', false), FILTER_VALIDATE_BOOL)) {
            $this->error("Refusing a non-loopback bind [{$host}]: the LAN listener is the TLS gateway. Plain HTTP is never the normal mode.");

            return self::FAILURE;
        }
        $docroot = public_path();
        $router = $docroot . DIRECTORY_SEPARATOR . 'index.php';
        if (! is_file($router)) {
            $this->error('public/index.php not found under ' . $docroot);

            return self::FAILURE;
        }
        $this->info("edge web backend #{$worker} → http://{$host}:{$port} (docroot {$docroot})");
        if ($this->option('check')) {
            return self::SUCCESS;
        }

        $env = array_merge(getenv() ?: [], [
            'EDGE_WEB_WORKER' => (string) $worker,
        ]);
        $envDir = (string) (getenv(EdgeApplianceLayout::ENV_DIR_VAR) ?: '');
        if ($envDir !== '') {
            $env[EdgeApplianceLayout::ENV_DIR_VAR] = $envDir; // the child loads the same appliance.env
        }
        $cmd = [PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', $host . ':' . $port, '-t', $docroot, $router];

        $supervisor = new EdgeWebBackendSupervisor(
            $worker,
            $host,
            $port,
            $cmd,
            $env,
            base_path(),
            EdgeApplianceLayout::logsDir(),
            fn (string $line) => $this->getOutput()->writeln($line),
        );

        $code = $supervisor->run((bool) $this->option('once'));
        if ($code !== 0) {
            $this->warn("web backend #{$worker} supervisor exited with code {$code}.");
        }

        return $code === 0 ? self::SUCCESS : self::FAILURE;
    }
}
