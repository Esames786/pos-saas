<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeSupervisionPlan;
use App\Support\EdgeApplianceLayout;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;

/**
 * P4 WINDOWS APPLIANCE — emit the deterministic Windows service plan for the installer (JSON).
 *
 *   php artisan edge:local:service-plan --php="C:\Program Files\Bingoo Edge\php\php.exe" --app-root="C:\Program Files\Bingoo Edge" \
 *       --data-root="C:\ProgramData\BingooEdge" --gateway="C:\Program Files\Bingoo Edge\gateway\nginx.exe" [--write-gateway-config]
 *
 * ONE source of truth: Register-EdgeServices.ps1 registers exactly what this prints (EdgeSupervisionPlan), so the
 * PowerShell never invents a task, a principal or an argument. Non-secret output. --write-gateway-config renders the
 * nginx configuration into <data-root>/gateway/nginx.conf (creating logs/temp dirs) so the gateway can start.
 */
class EdgeLocalServicePlanCommand extends Command
{
    protected $signature = 'edge:local:service-plan
        {--php= : PHP executable the tasks run (default: this PHP)}
        {--app-root= : The install root whose artisan launcher the tasks call (default: this app root)}
        {--data-root= : The appliance data root (default: the configured/derived data root)}
        {--gateway= : Path to the gateway executable (nginx.exe); omit to describe the gateway without a binary}
        {--write-gateway-config : Render <data-root>/gateway/nginx.conf now}
        {--json : Emit JSON (default when not a TTY)}';

    protected $description = 'Print the Windows service/task plan (and render the TLS gateway config) for the Branch Server.';

    public function handle(EdgeSupervisionPlan $plan): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:service-plan only runs on a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }
        $php = (string) ($this->option('php') ?: PHP_BINARY);
        $appRoot = rtrim((string) ($this->option('app-root') ?: base_path()), "/\\");
        $dataRoot = rtrim((string) ($this->option('data-root') ?: (EdgeApplianceLayout::dataRoot() ?? '')), "/\\");
        if ($dataRoot === '') {
            $this->error('A data root is required (--data-root or EDGE_DATA_ROOT).');

            return self::FAILURE;
        }
        $gatewayExe = (string) ($this->option('gateway') ?: ($dataRoot . DIRECTORY_SEPARATOR . 'gateway' . DIRECTORY_SEPARATOR . 'nginx.exe'));

        $out = [
            'edge_app_version' => (string) config('edge.app_version'),
            'php' => $php,
            'app_root' => $appRoot,
            'data_root' => $dataRoot,
            'tasks' => $plan->tasks($php, $appRoot),
            'gateway' => $plan->gateway($gatewayExe, $dataRoot),
            'gateway_binary_present' => is_file($gatewayExe),
            'web_workers' => $plan->webWorkers(),
            'invariants' => [
                'principal_never_system' => true,
                'non_elevated' => true,
                'artisan_tasks_edge_allowlisted_only' => true,
                'no_secret_on_command_line' => true,
                'one_logical_instance_per_worker' => true,
                'boot_start_bounded_restart' => true,
                'lan_listener_is_tls_gateway_only' => true,
            ],
        ];

        if ($this->option('write-gateway-config')) {
            $dir = $dataRoot . DIRECTORY_SEPARATOR . 'gateway';
            foreach ([$dir, $dir . DIRECTORY_SEPARATOR . 'logs', $dir . DIRECTORY_SEPARATOR . 'temp'] as $d) {
                if (! is_dir($d) && ! @mkdir($d, 0775, true) && ! is_dir($d)) {
                    $this->error('Cannot create ' . $d);

                    return self::FAILURE;
                }
            }
            // nginx on Windows needs its temp dirs to exist under the prefix.
            foreach (['client_body_temp', 'proxy_temp', 'fastcgi_temp', 'uwsgi_temp', 'scgi_temp'] as $t) {
                @mkdir($dir . DIRECTORY_SEPARATOR . $t, 0775, true);
            }
            file_put_contents($dir . DIRECTORY_SEPARATOR . 'nginx.conf', $plan->renderGatewayConfig($dataRoot, $appRoot));
            // nginx resolves a relative `include` against the prefix (-p) directory: ship the standard mime map beside the config.
            $mime = base_path('scripts/edge/appliance/mime.types');
            if (is_file($mime)) {
                copy($mime, $dir . DIRECTORY_SEPARATOR . 'mime.types');
            }
            $out['gateway_config_written'] = $dir . DIRECTORY_SEPARATOR . 'nginx.conf';
        }

        if ($this->option('json') || ! stream_isatty(STDOUT)) {
            $this->line(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $this->info('Bingoo Edge — Windows service plan (' . count($out['tasks']) . ' tasks + gateway)');
        $this->table(['task', 'process', 'command / listen', 'kind', 'principal'], collect($out['tasks'])->map(fn ($t) => [
            $t['name'], 'php artisan', $t['artisan_command'] . ($t['listen'] ? ' @ ' . $t['listen'] : ''), $t['kind'] . ($t['repeat_minutes'] ? " / {$t['repeat_minutes']}m" : ''), $t['principal'],
        ])->push([$out['gateway']['name'], 'nginx', 'TLS ' . $out['gateway']['listen'] . ' → web backends', 'continuous', $out['gateway']['principal']])->all());
        if (isset($out['gateway_config_written'])) {
            $this->info('Gateway config written: ' . $out['gateway_config_written']);
        }

        return self::SUCCESS;
    }
}
