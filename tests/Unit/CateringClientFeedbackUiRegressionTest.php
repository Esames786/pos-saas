<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Browser-only client fixes need a source contract as well as MySQL domain
 * proof. These assertions prevent the exact state/unit/time regressions from
 * disappearing during a later Blade refactor.
 */
class CateringClientFeedbackUiRegressionTest extends TestCase
{
    public function test_customer_search_and_service_time_keep_the_operator_contract(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/tenant/catering/events/partials/event-form-support.blade.php'
        );

        $this->assertIsString($source);
        $this->assertStringContainsString('templateResult', $source);
        $this->assertStringContainsString('templateSelection', $source);
        $this->assertStringContainsString('parseDate: function (text)', $source);
        $this->assertStringContainsString('(AM|PM)', $source);
        $this->assertStringContainsString("dateFormat: 'H:i'", $source,
            'the friendly AM/PM input must still submit canonical 24-hour time');
    }

    public function test_punch_uses_additive_supply_and_clears_instruction_state(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/tenant/catering/events/show.blade.php'
        );

        $this->assertIsString($source);
        $this->assertStringContainsString('pm-own', $source);
        $this->assertStringContainsString('pm-cust', $source);
        $this->assertStringContainsString('pm-total', $source);
        $this->assertStringContainsString('clearPunchInstructions()', $source);
        $this->assertStringContainsString('loadPunchInstructions(row, idx)', $source);
        $this->assertStringContainsString("unitCode: p.unit_code || '—'", $source);
        $this->assertStringNotContainsString("unitCode: (p.mats", $source);
    }
}
