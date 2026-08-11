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

        $this->assertStringContainsString('syncKnownPrinters(res.json.printers);', $code);
        $this->assertStringContainsString('knownPrinters.clear();', $code);
    }

    /**
     * The list is replaced only when the server actually SENT one. Coercing a missing key to []
     * would let a single response without it silently empty the list, and keep-awake would then
     * quietly do nothing for the rest of the day with no error anywhere.
     */
    public function test_a_response_without_a_printer_list_does_not_wipe_the_known_printers(): void
    {
        $this->assertStringContainsString('Array.isArray(res.json.printers)', $this->agent());
    }

    /**
     * A print tick defers while keep-awake runs, so every millisecond spent poking is a millisecond
     * a queued KOT sits still. Poking serially made that block the SUM of every printer's timeout —
     * a second station switched off would delay the tickets of the one that was on.
     */
    public function test_pokes_run_in_parallel_and_give_up_quickly(): void
    {
        $code = $this->agent();

        $this->assertStringContainsString('await Promise.all(', $code);
        $this->assertMatchesRegularExpression('/const POKE_TIMEOUT_MS = (\d+);/', $code);
        preg_match('/const POKE_TIMEOUT_MS = (\d+);/', $code, $m);
        $this->assertLessThan(
            2000,
            (int) $m[1],
            'A poke that outlives a poll interval turns keep-awake into the thing that delays tickets.'
        );
        $this->assertStringContainsString('socket.setTimeout(POKE_TIMEOUT_MS);', $code);
    }
}
