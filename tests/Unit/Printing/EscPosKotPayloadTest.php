<?php

namespace Tests\Unit\Printing;

use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesOrderLine;
use App\Services\Printing\EscPosPayloadService;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

class EscPosKotPayloadTest extends TestCase
{
    public function test_normal_and_addition_headings_use_batch_sequence(): void
    {
        $normal = $this->renderKot(['kot_event_type' => 'normal', 'kot_sequence_no' => 1]);
        $addition = $this->renderKot(['kot_event_type' => 'addition', 'kot_sequence_no' => 2]);

        $this->assertStringContainsString('*** KOT #1 ***', $normal);
        $this->assertStringContainsString('*** ADDITION KOT #2 ***', $addition);
        $this->assertStringContainsString('(R) CHICKEN BURGER', $addition);
    }

    public function test_duplicate_heading_has_sequence_and_copy_number(): void
    {
        $output = $this->renderKot([
            'is_reprint' => true,
            'kot_event_type' => 'duplicate',
            'kot_sequence_no' => 3,
            'copy_no' => 1,
        ]);

        $this->assertStringContainsString('*** DUPLICATE KOT #3 ***', $output);
        $this->assertStringContainsString('DUPLICATE 1', $output);
    }

    public function test_cancel_kot_uses_immutable_line_snapshot_quantity(): void
    {
        $output = $this->renderKot([
            'kot_event_type' => 'cancel',
            'kot_sequence_no' => 4,
            'line_snapshots' => [[
                'line_id' => 22,
                'line_kind' => 'component',
                'product_name' => 'Burger Patty',
                'variant_name' => null,
                'unit_code' => 'PCS',
                'quantity' => 2,
                'modifiers' => [],
                'kitchen_note' => 'Cancel from combo',
            ]],
            'line_quantities' => ['22' => 2],
        ]);

        $this->assertStringContainsString('*** CANCEL KOT #4 ***', $output);
        // PRINT-FORMAT-PARITY-1: single line, name left + clean qty right-aligned.
        // Piece units (PCS/EA) are suppressed — the number alone is the count.
        $this->assertMatchesRegularExpression('/BURGER PATTY +2$/m', $output);
        $this->assertStringNotContainsString('PCS', $output);
        $this->assertStringContainsString('NOTE: Cancel from combo', $output);
    }

    private function renderKot(array $payload): string
    {
        $sale = new SalesOrder([
            'sale_no' => 'HS-TEST-001',
            'order_type' => 'dine_in',
        ]);
        $line = new SalesOrderLine([
            'line_kind' => 'standard',
            'product_name' => 'Chicken Burger',
            'unit_code' => 'PCS',
            'quantity' => 1,
            'modifiers' => [],
        ]);
        $line->id = 11;
        $sale->setRelation('lines', new Collection([$line]));
        $sale->setRelation('restaurantTable', null);
        $sale->setRelation('restaurantWaiter', null);

        $job = new PrintJob(['payload' => array_merge([
            'line_ids' => [11],
            'line_quantities' => ['11' => 1],
        ], $payload)]);

        $method = new ReflectionMethod(EscPosPayloadService::class, 'kot');
        $method->setAccessible(true);

        return $method->invoke(new EscPosPayloadService(), $sale, $job);
    }
}
