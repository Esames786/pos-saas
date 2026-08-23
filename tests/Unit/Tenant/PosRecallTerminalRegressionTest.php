<?php

namespace Tests\Unit\Tenant;

use Tests\TestCase;

/**
 * POS-RECALL-TERMINAL-1 — recalling a held sale must NOT change the operator's active terminal.
 *
 * The active terminal lives only in the DOM #terminal_id select (mirrored to localStorage on the
 * user's OWN change). recallHeldSale() used to overwrite it with the recalled order's terminal; because
 * that assignment is programmatic it never updated localStorage, so the hijacked terminal silently
 * leaked into the operator's NEXT new order (clearCart never resets #terminal_id). A multi-terminal
 * operator who recalled another terminal's order then punched every following sale under that terminal.
 *
 * The order instead adopts the operator's own terminal only when committed — Hold or Review & Pay both
 * stamp the POSTED terminal. This guard stops the hijack from creeping back in.
 */
class PosRecallTerminalRegressionTest extends TestCase
{
    private function pos(): string
    {
        return file_get_contents(resource_path('views/tenant/pos/index.blade.php'));
    }

    public function test_recall_does_not_overwrite_the_active_terminal(): void
    {
        $view = $this->pos();

        // The exact hijack — assigning the recalled sale's terminal onto the select — must be gone.
        $this->assertStringNotContainsString(
            'terminalEl.value = sale.terminal_id',
            $view,
            'recall must NOT set the active terminal from the recalled order — it silently leaks into the next new order'
        );
    }

    public function test_the_deliberate_intent_is_documented_in_place(): void
    {
        $view = $this->pos();

        $this->assertStringContainsString(
            'POS-RECALL-TERMINAL-1',
            $view,
            'the marker must remain so a future edit does not re-add the terminal hijack by accident'
        );
    }

    public function test_recall_still_restores_the_order_type(): void
    {
        $view = $this->pos();

        // Guard against over-deletion: recall must still sync the order TYPE (mode tab), which is a
        // legitimate restore and unrelated to the terminal hijack.
        $this->assertStringContainsString(
            'orderTypeEl.value = sale.order_type',
            $view,
            'recall must still restore the recalled order\'s order type'
        );
    }
}
