<?php

namespace Tests\Unit\Tenant;

use Tests\TestCase;

class PrintAgentKeepAwakeRegressionTest extends TestCase
{
    private function agent(): string
    {
        return file_get_contents(base_path('tools/print-agent/print-agent.js'));
    }

    public function test_keep_awake_and_print_poll_are_mutually_exclusive(): void
    {
        $code = $this->agent();

        $this->assertStringContainsString('let keepAwakeRunning = false;', $code);
        $this->assertStringContainsString('if (ticking || keepAwakeRunning || knownPrinters.size === 0)', $code);
        $this->assertStringContainsString('if (ticking || keepAwakeRunning)', $code);
        $this->assertStringContainsString('finally {', $code);
        $this->assertStringContainsString('keepAwakeRunning = false;', $code);
    }

    public function test_heartbeat_replaces_stale_printer_destinations(): void
    {
        $code = $this->agent();

        $this->assertStringContainsString('syncKnownPrinters((res.json && res.json.printers) || []);', $code);
        $this->assertStringContainsString('knownPrinters.clear();', $code);
    }
}
