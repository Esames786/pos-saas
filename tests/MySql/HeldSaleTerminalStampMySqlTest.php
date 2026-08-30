<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\HeldSaleController;
use App\Models\Tenant\Branch;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Sales\ShiftService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * HELD-SALE-TERMINAL-STAMP-1 — an order belongs to the counter that TOOK it, forever.
 *
 * The hold UPDATE mirrored the CREATE and wrote every posted field back, `terminal_id` among them.
 * So the counter that saved LAST became the order's owner: a floor order recalled and saved at a
 * counter moved into that counter's sales, its shift, and its daily closing — and its reprints and
 * cancellations followed it there. Nobody asked for that; it arrived with the original held-sale
 * write in May and contradicts RECALL-REPRINT-TERMINAL-1, which added a print-time terminal override
 * precisely so the sale row would never have to move.
 *
 * The terminal is a fact about the order (which counter took it), not a record of who last touched
 * it. Printing already has its own override for "route to where the operator is standing".
 */
class HeldSaleTerminalStampMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $userId;
    private int $branchId;
    private int $floorTerminal;
    private int $counterTerminal;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'print_jobs', 'kot_batch_lines', 'kot_batches', 'sales_order_lines', 'sales_orders',
            'shifts', 'terminal_user', 'terminals', 'products', 'categories', 'branches', 'users',
        ]);

        $this->userId = $this->makeUser(['employee_code' => 'TS' . Str::random(4)]);
        $user = User::on('tenant')->find($this->userId);
        $this->actingAs($user, 'tenant');
        Auth::shouldUse('tenant');

        $this->branchId = $this->makeBranch();
        $this->floorTerminal = $this->makeTerminal($this->branchId, ['code' => 'T4', 'name' => 'DTQ Floor']);
        $this->counterTerminal = $this->makeTerminal($this->branchId, ['code' => 'T2', 'name' => 'DTQ 1']);
        $this->productId = $this->makeProduct($this->makeCategory(['name' => 'Rice', 'slug' => 'rice']), ['name' => 'Singaporean Rice']);

        // Both counters trading — the order can be saved from either.
        foreach ([$this->floorTerminal, $this->counterTerminal] as $terminalId) {
            app(ShiftService::class)->open(
                Branch::on('tenant')->find($this->branchId),
                Terminal::on('tenant')->find($terminalId),
                $this->userId,
                0.0
            );
        }
    }

    /** Drive the real controller. */
    private function hold(array $body): SalesOrder
    {
        $request = Request::create('/held-sales', 'POST', array_merge([
            'branch_id' => $this->branchId,
            'discount_type' => 'none',
            'order_type' => 'takeaway',
        ], $body));
        $request->headers->set('Accept', 'application/json');

        $response = app()->call([app(HeldSaleController::class), 'store'], ['request' => $request]);
        $this->assertContains($response->getStatusCode(), [200, 201], 'hold should succeed: ' . $response->getContent());

        return SalesOrder::on('tenant')->where('status', 'held')->latest('id')->firstOrFail();
    }

    /** A new order is stamped with the counter that took it. */
    public function test_a_new_held_order_is_stamped_with_the_terminal_that_took_it(): void
    {
        $sale = $this->hold([
            'terminal_id' => $this->floorTerminal,
            'lines' => [['product_id' => $this->productId, 'quantity' => 1, 'unit_price' => 600]],
        ]);

        $this->assertSame($this->floorTerminal, (int) $sale->terminal_id);
    }

    /** THE FIX: recalling and saving it at another counter must NOT move the order there. */
    public function test_saving_a_recalled_order_at_another_counter_does_not_move_it(): void
    {
        $sale = $this->hold([
            'terminal_id' => $this->floorTerminal,
            'lines' => [['product_id' => $this->productId, 'quantity' => 1, 'unit_price' => 600]],
        ]);
        $this->assertSame($this->floorTerminal, (int) $sale->terminal_id);

        // The counter recalls it, adds a round, and saves — posting ITS OWN terminal, as the POS does.
        $this->hold([
            'held_sale_id' => $sale->id,
            'terminal_id' => $this->counterTerminal,
            'lines' => [
                ['product_id' => $this->productId, 'quantity' => 1, 'unit_price' => 600, 'sales_order_line_id' => $sale->lines()->first()->id],
                ['product_id' => $this->productId, 'quantity' => 2, 'unit_price' => 600],
            ],
        ]);

        $sale->refresh();
        $this->assertSame(
            $this->floorTerminal,
            (int) $sale->terminal_id,
            'the order still belongs to the counter that took it — cash, shift and closing stay there.'
        );
        $this->assertCount(2, $sale->lines()->get(), 'the save itself still worked — the round was added.');
    }

    /** The order's shift is likewise the one it was opened under, not the saver's. */
    public function test_the_orders_shift_does_not_move_either(): void
    {
        $sale = $this->hold([
            'terminal_id' => $this->floorTerminal,
            'lines' => [['product_id' => $this->productId, 'quantity' => 1, 'unit_price' => 600]],
        ]);
        $originalShift = (int) $sale->shift_id;
        $this->assertGreaterThan(0, $originalShift);

        $this->hold([
            'held_sale_id' => $sale->id,
            'terminal_id' => $this->counterTerminal,
            'lines' => [['product_id' => $this->productId, 'quantity' => 3, 'unit_price' => 600]],
        ]);

        $this->assertSame($originalShift, (int) $sale->refresh()->shift_id);
    }

    /** Branch is still writable — only the terminal was pinned, not the whole update. */
    public function test_other_fields_still_save_normally(): void
    {
        $sale = $this->hold([
            'terminal_id' => $this->floorTerminal,
            'order_type' => 'takeaway',
            'lines' => [['product_id' => $this->productId, 'quantity' => 1, 'unit_price' => 600]],
            'notes' => 'first round',
        ]);

        $this->hold([
            'held_sale_id' => $sale->id,
            'terminal_id' => $this->counterTerminal,
            'order_type' => 'takeaway',
            'lines' => [['product_id' => $this->productId, 'quantity' => 1, 'unit_price' => 600]],
            'notes' => 'changed to takeaway',
        ]);

        $sale->refresh();
        $this->assertSame('takeaway', $sale->order_type, 'order type still updates');
        $this->assertSame('changed to takeaway', $sale->notes, 'notes still update');
        $this->assertSame($this->floorTerminal, (int) $sale->terminal_id, 'but the terminal does not');
    }
}
