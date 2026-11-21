<?php

namespace App\Services\Edge\AutoUpdate;

use App\Support\EdgeApplianceLayout;
use RuntimeException;

/**
 * EDGE 0.9 (stream B) — stop / start the supervised appliance tasks around the runtime switch, EXCEPT the updater's own task.
 *
 * mode 'tasks' (an installed appliance): renders the service plan with the runtime that is about to run
 * (`edge:local:service-plan --write-gateway-config --json` through that runtime's own artisan) and drives the tasks with that
 * runtime's own Register-EdgeServices.ps1 `-Action Stop|Start -Exclude BingooEdgeUpdater -KeepEnabled` (cooperative worker
 * stop first, nginx -s quit, then Stop-ScheduledTask; Start = Start-ScheduledTask; no task is (re)registered, disabled or
 * given another principal — that stays an elevated wizard step). mode 'none': a dev tree / tests — nothing is driven.
 */
class EdgeAutoUpdateServiceControl
{
    public const UPDATER_TASK = 'BingooEdgeUpdater';

    public function __construct(private readonly EdgeApplianceRunner $runner)
    {
    }

    public function mode(): string
    {
        return (string) config('edge.auto_update.service_control', 'tasks') === 'tasks' && EdgeAutoUpdateSettings::installRoot() !== null ? 'tasks' : 'none';
    }

    /** Cooperative stop of every task but the updater, using the scripts of $runtimeDir (the runtime currently active). */
    public function stop(string $runtimeDir): void
    {
        if ($this->mode() !== 'tasks') {
            return;
        }
        $this->drive('Stop', $runtimeDir);
    }

    /** Re-render the plan with $runtimeDir (the runtime that will run) and start every task but the updater. */
    public function start(string $runtimeDir): void
    {
        if ($this->mode() !== 'tasks') {
            return;
        }
        $this->renderPlan($runtimeDir);
        $this->drive('Start', $runtimeDir);
    }

    private function renderPlan(string $runtimeDir): void
    {
        $installRoot = (string) EdgeAutoUpdateSettings::installRoot();
        $dataRoot = (string) EdgeApplianceLayout::dataRoot();
        $layout = json_decode((string) @file_get_contents($installRoot . DIRECTORY_SEPARATOR . EdgeApplianceLayout::LAYOUT_FILE), true) ?: [];
        $args = ['edge:local:service-plan', '--php=' . $this->runner->php(), '--app-root=' . $installRoot, '--data-root=' . $dataRoot, '--write-gateway-config', '--json'];
        if (! empty($layout['gateway'])) {
            $args[] = '--gateway=' . (string) $layout['gateway'];
        }
        [$code, $out] = $this->runner->artisan($args, 120, $runtimeDir);
        $plan = EdgeApplianceRunner::lastJson($out);
        if ($code !== 0 || ! is_array($plan)) {
            throw new RuntimeException('AUTO_UPDATE_SERVICE_PLAN_FAILED: the runtime could not render its service plan (exit ' . $code . ').');
        }
        $file = $this->planFile();
        file_put_contents($file, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function drive(string $action, string $runtimeDir): void
    {
        $script = rtrim($runtimeDir, "/\\") . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'edge' . DIRECTORY_SEPARATOR . 'Register-EdgeServices.ps1';
        if (! is_file($script)) {
            throw new RuntimeException("AUTO_UPDATE_SERVICE_CONTROL: {$script} not found.");
        }
        $cmd = ['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $script,
            '-Action', $action, '-PlanFile', $this->planFile(), '-Exclude', self::UPDATER_TASK, '-KeepEnabled'];
        [$code, $out, $err] = $this->runner->run($cmd, 300, $runtimeDir);
        if ($code !== 0) {
            throw new RuntimeException("AUTO_UPDATE_SERVICE_CONTROL: Register-EdgeServices -Action {$action} exited {$code}: " . mb_substr(trim($err !== '' ? $err : $out), 0, 300));
        }
    }

    private function planFile(): string
    {
        $dir = EdgeApplianceLayout::gatewayDir();
        if ($dir === null) {
            throw new RuntimeException('AUTO_UPDATE_SERVICE_CONTROL: no appliance data root (gateway dir) is configured.');
        }

        return $dir . DIRECTORY_SEPARATOR . 'service-plan.json';
    }
}
