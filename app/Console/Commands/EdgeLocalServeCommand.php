<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeSupervisionPlan;
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
 * Supervision: the command stays in the foreground for the life of the child server and exits with its code; the
 * Scheduled Task's restart policy relaunches it. A hard stop is safe — the web process holds no lease; MySQL rolls
 * back any open transaction. The child inherits the appliance env dir variable so it loads the SAME appliance.env.
 * No secret ever appears on the command line.
 */
class EdgeLocalServeCommand extends Command
{
    protected $signature = 'edge:local:serve
        {--worker=1 : Backend index (1..N) — selects the loopback port}
        {--host= : Bind address (default edge.web.bind — loopback only; a LAN bind is refused unless EDGE_WEB_ALLOW_LAN_BIND=true)}
        {--port= : Override the port (default port_base + worker - 1)}
        {--check : Print the resolved bind and exit without starting}';

    protected $description = 'Run one loopback PHP web backend for the Branch Server (fronted by the TLS gateway).';

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
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        if (! is_resource($proc)) {
            $this->error('Could not start the PHP web server process.');

            return self::FAILURE;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $code = null;
        while (true) {
            $status = proc_get_status($proc);
            $this->relay($pipes[1]);
            $this->relay($pipes[2]);
            if (! $status['running']) {
                $code = (int) $status['exitcode'];
                break;
            }
            usleep(200000);
        }
        $this->relay($pipes[1]);
        $this->relay($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $this->warn("web backend #{$worker} exited with code {$code}; the supervisor restarts it.");

        return $code === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function relay($pipe): void
    {
        $chunk = @stream_get_contents($pipe);
        if ($chunk !== false && $chunk !== '') {
            $this->getOutput()->write($chunk);
        }
    }
}
