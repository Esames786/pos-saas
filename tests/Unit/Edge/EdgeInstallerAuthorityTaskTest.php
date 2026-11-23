<?php

namespace Tests\Unit\Edge;

use PHPUnit\Framework\TestCase;

/**
 * Edge 0.9 (CP5 run 13) — a fresh install must END with the authority/heartbeat worker running as its scheduled task.
 * Step 11 starts the tasks and step 12's one-off warm ticks run in the same second; when the ticks take the one worker
 * slot first, the task's worker exits cleanly (exit 0, so no Task Scheduler restart) and the branch had no heartbeat
 * until a reboot. Static guard on scripts/edge/Install-EdgeAppliance.ps1: after the ticks (and only when services were
 * registered) the installer starts the plan's authority-worker task again, ONLY that task, before step 13's health;
 * the step numbers the setup wizard parses stay 1..13 in order.
 */
class EdgeInstallerAuthorityTaskTest extends TestCase
{
    private function installer(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/scripts/edge/Install-EdgeAppliance.ps1');
    }

    public function test_the_authority_task_is_started_again_after_the_warm_sync_ticks_and_before_health(): void
    {
        $s = $this->installer();
        $ticks = strpos($s, "'--max-ticks=2'");
        $restart = strpos($s, 'if (-not $NoServices) { Start-EdgeAuthorityTask $planFile }');
        $health = strpos($s, "Step 13 'edge:local:health'");

        $this->assertNotFalse($ticks, 'step 12 runs the one-off warm ticks');
        $this->assertNotFalse($restart, 'the installer starts the authority task again when services were registered');
        $this->assertNotFalse($health);
        $this->assertGreaterThan($ticks, $restart, 'the restart comes AFTER the one-off ticks released the worker slot');
        $this->assertLessThan($health, $restart, 'the restart comes BEFORE the health step');
    }

    public function test_only_the_plans_authority_worker_task_is_started_and_it_must_stay_running(): void
    {
        $s = $this->installer();
        $this->assertMatchesRegularExpression('/function Start-EdgeAuthorityTask\b/', $s);
        preg_match('/function Start-EdgeAuthorityTask\b.*?\n}\n/s', str_replace("\r\n", "\n", $s), $m);
        $fn = $m[0] ?? '';

        $this->assertStringContainsString("artisan_command -eq 'edge:local:authority-worker'", $fn, 'the task is found in the service plan, not by a hard-coded name');
        $this->assertStringContainsString('Start-ScheduledTask -TaskName $name', $fn);
        $this->assertStringContainsString("-eq 'Running'", $fn, 'it waits for the task to be Running');
        // never Register-EdgeServices -Action Start: it would fire the backup / sync / updater tasks at once
        $this->assertDoesNotMatchRegularExpression("/Action\\s*=\\s*'Start'/", $s);
    }

    public function test_the_step_numbers_the_wizard_parses_are_unchanged(): void
    {
        preg_match_all('/^\s*Step (\d+) [\'"]/m', $this->installer(), $m);
        $steps = array_values(array_unique(array_map('intval', $m[1])));

        $this->assertSame(range(1, 13), $steps);
    }
}
