<?php

namespace Tests\Unit\Edge;

use PHPUnit\Framework\TestCase;

/**
 * Edge 0.9 CP6 (Windows Sandbox, release package, 0.8.5 -> 0.9.0 through the setup wizard): Update-EdgeAppliance.ps1 stopped
 * EVERY service, the authority/heartbeat worker included, before the dry-run, the pre-update backup, the staging and the
 * schema upgrade. The Cloud saw 136 s without a heartbeat (lease TTL 120 s), and the branch's Online POS was paused for ~16 s.
 * On a slow disk, the 0.8.x staging re-verify alone takes ~5 min.
 *
 * Static guard on the two scripts:
 *  - the update keeps the authority worker running (step 3 excludes it);
 *  - it stops that worker only after the update applied, right before the new runtime's services start;
 *  - the failure paths start what was stopped without touching it;
 *  - Register-EdgeServices' cooperative stops honour -Exclude.
 */
class EdgeUpdateKeepsHeartbeatTest extends TestCase
{
    private function script(string $name): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 3) . '/scripts/edge/' . $name));
    }

    public function test_step_3_stops_every_service_except_the_authority_worker(): void
    {
        $s = $this->script('Update-EdgeAppliance.ps1');
        $this->assertMatchesRegularExpression('/function Get-EdgeAuthorityTaskName\b/', $s);
        $this->assertStringContainsString("artisan_command -eq 'edge:local:authority-worker'", $s, 'the task is found in the service plan, not by a hard-coded name');

        $stop = strpos($s, "\$stopArgs = @{ Action = 'Stop'; PlanFile = \$planFile }");
        $exclude = strpos($s, "if (\$authorityTask) { \$stopArgs['Exclude'] = @(\$authorityTask) }");
        $this->assertNotFalse($stop, 'step 3 stops the services');
        $this->assertNotFalse($exclude, 'step 3 excludes the authority task');
        $this->assertGreaterThan($stop, $exclude);
        $this->assertLessThan(strpos($s, "& (Join-Path \$scriptDir 'Register-EdgeServices.ps1') @stopArgs"), $exclude, 'the exclusion is set before the Stop runs');
    }

    public function test_the_authority_worker_is_stopped_only_after_the_update_applied_and_before_the_new_services_start(): void
    {
        $s = $this->script('Update-EdgeAppliance.ps1');
        $apply = strpos($s, '& $php $launcher edge:local:update $UpdateFile (Join-Path $PackageRoot \'app\') "--expected-plan-hash=$ExpectedPlanHash"');
        $planRender = strpos($s, "\$planArgs = @('edge:local:service-plan'");
        $stopCall = strpos($s, "\nStop-EdgeAuthorityWorker\n");
        $start = strpos($s, "\$startArgs = @{ Action = \$(if (\$ReRegisterServices) { 'Register' } else { 'Start' }); PlanFile = \$planFile }");

        $this->assertNotFalse($apply);
        $this->assertNotFalse($planRender);
        $this->assertNotFalse($stopCall, 'the script stops the authority worker exactly once, at top level');
        $this->assertNotFalse($start);
        $this->assertSame(1, substr_count($s, "\nStop-EdgeAuthorityWorker\n"));
        $this->assertGreaterThan($apply, $stopCall, 'never before the update applied: the heartbeat must cover staging + schema upgrade');
        $this->assertGreaterThan($planRender, $stopCall, 'after the new plan is rendered (shortest gap)');
        $this->assertLessThan($start, $stopCall, 'before the new runtime services start (no second instance; IgnoreNew)');

        preg_match('/function Stop-EdgeAuthorityWorker\b.*?\n}\n/s', $s, $m);
        $fn = $m[0] ?? '';
        $this->assertStringContainsString('edge:local:authority-worker --stop', $fn, 'cooperative stop first');
        $this->assertStringContainsString('Stop-ScheduledTask -TaskName $authorityTask', $fn, 'then the task itself');
        $this->assertStringContainsString("\$ErrorActionPreference = 'Continue'", $fn, 'a stderr line of the cooperative stop is never fatal on PS 5.1');
    }

    public function test_failure_paths_restart_the_stopped_services_without_a_second_authority_instance(): void
    {
        $s = $this->script('Update-EdgeAppliance.ps1');
        $this->assertDoesNotMatchRegularExpression("/'Register-EdgeServices\\.ps1'\\) -Action Start -PlanFile \\\$planFile/", $s, 'every failure path goes through Restore-EdgeServices');
        $this->assertSame(4, preg_match_all('/^\s+Restore-EdgeServices$/m', $s), 'the four failure paths (dry-run refused, blocker, plan hash differs, apply failed)');

        preg_match('/function Restore-EdgeServices\b.*?\n}\n/s', $s, $m);
        $fn = $m[0] ?? '';
        $this->assertStringContainsString("\$restoreArgs['Exclude'] = @(\$authorityTask)", $fn);
        $this->assertStringContainsString("\$st.State -ne 'Running'", $fn, 'the authority task is started only if it is not running');
    }

    public function test_register_edge_services_cooperative_stops_honour_exclude(): void
    {
        $s = $this->script('Register-EdgeServices.ps1');
        preg_match("/    'Stop' \\{.*?\n    }\n/s", $s, $m);
        $stop = $m[0] ?? '';
        $this->assertNotSame('', $stop);
        $this->assertStringContainsString("if (& \$runsWorker 'edge:local:print-worker') {", $stop);
        $this->assertStringContainsString("if (& \$runsWorker 'edge:local:authority-worker') {", $stop);
        $guard = strpos($stop, "if (& \$runsWorker 'edge:local:authority-worker') {");
        $this->assertGreaterThan($guard, strpos($stop, 'edge:local:authority-worker --stop'), 'the cooperative authority stop sits inside the guard');
        // $runsWorker reads $tasks, the plan tasks AFTER -Exclude
        $this->assertStringContainsString('@($tasks | Where-Object { [string]$_.artisan_command -eq $cmd', $stop);
        $this->assertStringContainsString("\$tasks = @(\$plan.tasks | Where-Object { \$Exclude -notcontains [string]\$_.name })", $s);
    }
}
