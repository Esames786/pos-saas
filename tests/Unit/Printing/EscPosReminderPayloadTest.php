<?php

namespace Tests\Unit\Printing;

use App\Models\Tenant\PrintJob;
use App\Services\Printing\EscPosPayloadService;
use Tests\TestCase;

class EscPosReminderPayloadTest extends TestCase
{
    public function test_updated_reminder_is_complete_non_fiscal_and_marks_only_current_delta(): void
    {
        $output = $this->render([
            'heading' => 'UPDATED ORDER',
            'revision' => 2,
            'sale_no' => 'HS-100',
            'order_type' => 'dine_in',
            'order_time' => '2026-08-03T10:00:00+05:00',
            'updated_time' => '2026-08-03T10:10:00+05:00',
            'generated_at' => '2026-08-03T10:11:00+05:00',
            'subtotal' => 999,
            'tax_amount' => 99,
            'grand_total' => 1098,
            'payment_method' => 'cash',
            'layout' => [],
            'lines' => [
                ['line_id' => 1, 'product_name' => 'Burger', 'quantity' => 3, 'unit_code' => 'PCS', 'round_delta' => 2],
                ['line_id' => 2, 'product_name' => 'Drink', 'quantity' => 1, 'unit_code' => 'PCS', 'round_delta' => 0],
                ['line_id' => 3, 'product_name' => 'Fries', 'quantity' => 1, 'unit_code' => 'PCS', 'round_delta' => 1],
            ],
        ]);

        $this->assertStringContainsString('UPDATED ORDER', $output);
        $this->assertStringContainsString('REVISION 2', $output);
        // PRINT-FORMAT-PARITY-1: single line, name left + clean qty right-aligned.
        // Piece units (PCS/EA) are suppressed — the number alone is the count.
        $this->assertMatchesRegularExpression('/BURGER \(R \+2\) +3$/m', $output);
        $this->assertMatchesRegularExpression('/DRINK +1$/m', $output);
        $this->assertMatchesRegularExpression('/\(R\) FRIES +1$/m', $output);
        $this->assertStringNotContainsString('PCS', $output);
        $this->assertStringNotContainsString('999', $output);
        $this->assertStringNotContainsString('1098', $output);
        foreach (['SUBTOTAL', 'TAX', 'TOTAL', 'PAYMENT', 'BALANCE', 'CASH'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtoupper($output));
        }
    }

    public function test_timestamp_visibility_and_duplicate_copy_are_payload_bound(): void
    {
        $output = $this->render([
            'heading' => 'REMINDER',
            'revision' => 1,
            'copy_no' => 2,
            'is_reprint' => true,
            'sale_no' => 'HS-101',
            'order_type' => 'takeaway',
            'order_time' => '2026-08-03T10:00:00+05:00',
            'updated_time' => '2026-08-03T10:10:00+05:00',
            'generated_at' => '2026-08-03T10:11:00+05:00',
            'layout' => ['show_order_time' => false, 'show_updated_time' => true, 'show_print_time' => false],
            'lines' => [['line_id' => 1, 'product_name' => 'Tea', 'quantity' => 1, 'round_delta' => 1]],
        ]);

        $this->assertStringContainsString('DUPLICATE 2', $output);
        $this->assertStringNotContainsString('ORDER: 2026-08-03 10:00', $output);
        $this->assertStringContainsString('UPDATED: 2026-08-03 10:10', $output);
        $this->assertStringNotContainsString('PRINT: 2026-08-03 10:11', $output);
    }

    public function test_cancellation_reminder_has_cancelled_and_remaining_sections(): void
    {
        $output = $this->render([
            'heading' => 'CANCELLED / UPDATED ORDER',
            'event_type' => 'cancelled_updated_order',
            'revision' => 2,
            'order_type' => 'delivery',
            'layout' => [],
            'cancelled_lines' => [['product_name' => 'Burger', 'quantity' => 1]],
            'lines' => [['line_id' => 1, 'product_name' => 'Burger', 'quantity' => 2, 'round_delta' => 0]],
            'cancellation_audit' => [[
                'reason' => 'Customer changed mind',
                'requested_by' => 'Cashier',
                'approved_by' => 'Manager',
            ]],
        ]);

        $this->assertStringContainsString('CANCELLED:', $output);
        $this->assertStringContainsString('REMAINING ORDER:', $output);
        $this->assertStringContainsString('REASON: Customer changed mind', $output);
        $this->assertStringContainsString('APPROVED BY: Manager', $output);
    }

    private function render(array $payload): string
    {
        return (new EscPosPayloadService())->buildReminder(new PrintJob(['payload' => $payload]));
    }
}
