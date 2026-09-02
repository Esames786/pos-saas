<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\HeldSaleController;
use App\Http\Controllers\Tenant\PrintJobController;
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
 * KOT-SENT-POOL-2 — a second helping added to a running bill must reach the kitchen.
 *
 * Saving a held order deletes and re-creates every line, so each new row has to be told how much
 * of it the kitchen already knows about. A line the POS can name by id takes its own stored
 * quantity; one it cannot name draws from a POOL keyed on product+kind+combo (KOT-SENT-POOL-1).
 *
 * The two halves never spoke to each other. A line taken by id did NOT draw its share out of the
 * pool, so the pool still held the full quantity when the next, unnamed line reached it — and the
 * second helping was born already sent. Delta zero: no KOT, no reminder, and the kitchen never
 * heard about food the customer is being charged for.
 *
 * Found live at Kashif Food on bill HS-20260902191935-749 — a second Singaporean Rice (Midnight)
 * added to a running check printed nothing. Five bills carried the same gap since 30 Aug.
 *
 * These tests drive the REAL controller, twice, exactly as recall does: first the hold, then the
 * save that carries the original lines back by id alongside a brand-new one.
 */
class KotSentPoolMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $userId;
    private int $branchId;
    private int $terminalId;
    private int $categoryId;
    private int $productId;
    private int $comboId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'print_jobs', 'kot_batch_lines', 'kot_batches',
            'sales_order_lines', 'sales_orders', 'shifts', 'terminals',
            'category_printer_mappings', 'printers',
            'combo_components', 'combos', 'products', 'categories', 'branches', 'users',
        ]);

        $this->userId = $this->makeUser(['employee_code' => 'KP' . Str::random(4)]);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');

        $this->branchId   = $this->makeBranch();
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->categoryId = $this->makeCategory(['name' => 'Rice', 'slug' => 'rice-' . Str::random(5)]);
        $this->productId  = $this->makeProduct($this->categoryId, [
            'name' => 'Singaporean Rice', 'default_selling_price' => 550,
        ]);

        $printerId = $this->makePrinter([
            'code' => 'KIT' . Str::random(3), 'print_role' => 'both',
            'branch_id' => $this->branchId, 'is_default' => 1,
        ]);
        DB::connection('tenant')->table('category_printer_mappings')->insert([
            'branch_id' => $this->branchId, 'category_id' => $this->categoryId, 'printer_id' => $printerId,
            'print_role' => 'kot', 'order_type' => 'all', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->comboId = DB::connection('tenant')->table('combos')->insertGetId([
            'branch_id' => $this->branchId, 'code' => 'MID' . Str::random(4),
            'name' => 'Singaporean Rice (Midnight)', 'price' => 550, 'sort_order' => 1,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('tenant')->table('combo_components')->insert([
            'combo_id' => $this->comboId, 'product_id' => $this->productId,
            'quantity' => 1, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(ShiftService::class)->open(
            Branch::on('tenant')->find($this->branchId),
            Terminal::on('tenant')->find($this->terminalId),
            $this->userId,
            0.0
        );
    }

    /* ── driving the real endpoint ───────────────────────────────────────── */

    /** One deal = a header carrying the money plus the component the kitchen cooks. */
    private function dealLines(string $suffix, ?int $headerId = null, ?int $componentId = null): array
    {
        return [
            [
                'product_id' => $this->productId, 'quantity' => 1, 'unit_price' => 550,
                'line_kind' => 'combo_header', 'combo_id' => $this->comboId,
                'line_name' => 'Singaporean Rice (Midnight)',
                'client_line_key' => 'h' . $suffix,
                'sales_order_line_id' => $headerId,
            ],
            [
                'product_id' => $this->productId, 'quantity' => 1, 'unit_price' => 0,
                'line_kind' => 'component', 'combo_id' => $this->comboId,
                'client_line_key' => 'c' . $suffix, 'parent_client_line_key' => 'h' . $suffix,
                'sales_order_line_id' => $componentId,
            ],
        ];
    }

    /** A plain, non-deal item — the ordinary line the counter punches all day. */
    private function plainLine(string $suffix, float $qty = 1, ?int $lineId = null): array
    {
        return [[
            'product_id' => $this->productId, 'quantity' => $qty, 'unit_price' => 550,
            'line_kind' => 'standard',
            'client_line_key' => 's' . $suffix,
            'sales_order_line_id' => $lineId,
        ]];
    }

    /**
     * POST /held-sales through the real controller; returns the decoded JSON.
     *
     * `$queueKot` mirrors what the POS does straight afterwards. A test that wants to see what the
     * SAVE decided must hold it back — once the KOT prints, the new line is legitimately sent too,
     * and the two states are indistinguishable.
     */
    private function save(array $lines, ?int $heldSaleId = null, bool $queueKot = true): array
    {
        $request = Request::create('/held-sales', 'POST', array_filter([
            'branch_id'     => $this->branchId,
            'terminal_id'   => $this->terminalId,
            'order_type'    => 'takeaway',
            'discount_type' => 'none',
            'held_sale_id'  => $heldSaleId,
            'lines'         => $lines,
        ], fn ($v) => $v !== null));
        $request->headers->set('Accept', 'application/json');

        $response = app()->call([app(HeldSaleController::class), 'store'], ['request' => $request]);
        $this->assertContains($response->getStatusCode(), [200, 201],
            'the save must succeed: ' . $response->getContent());

        $payload = json_decode($response->getContent(), true);

        // The POS saves, then asks for the KOT — two requests, exactly as the counter does it.
        if ($queueKot) {
            $this->queueKot((int) $payload['sale_id']);
        }

        return $payload;
    }

    /** POST /printing/jobs/kot/{sale} — the second half of what pressing Hold does. */
    private function queueKot(int $saleId): void
    {
        $request = Request::create('/printing/jobs/kot/' . $saleId, 'POST', [
            'terminal_id' => $this->terminalId,
        ]);
        $request->headers->set('Accept', 'application/json');

        $response = app()->call(
            [app(PrintJobController::class), 'queueKot'],
            ['request' => $request, 'salesOrder' => SalesOrder::on('tenant')->findOrFail($saleId)]
        );

        $this->assertSame(200, $response->getStatusCode(),
            'queueing the KOT must succeed: ' . $response->getContent());
    }

    /** What the kitchen was actually told, across every non-cancel KOT batch on this order. */
    private function kotQuantityFor(int $saleId, string $lineKind = 'component'): float
    {
        return (float) DB::connection('tenant')->table('kot_batch_lines as kl')
            ->join('kot_batches as b', 'b.id', '=', 'kl.kot_batch_id')
            ->where('b.sales_order_id', $saleId)
            ->where('b.event_type', 'not like', '%cancel%')
            ->where('kl.line_kind', $lineKind)
            ->sum('kl.quantity');
    }

    private function savedId(array $payload, string $clientKey): int
    {
        $row = collect($payload['lines'] ?? [])->firstWhere('client_line_key', $clientKey);
        $this->assertNotNull($row, "the save must return the id for {$clientKey}");

        return (int) $row['id'];
    }

    /* ── the bug ─────────────────────────────────────────────────────────── */

    public function test_a_second_helping_of_a_sent_deal_reaches_the_kitchen(): void
    {
        $first  = $this->save($this->dealLines('1'));
        $saleId = (int) $first['sale_id'];

        $this->assertEqualsWithDelta(1.0, $this->kotQuantityFor($saleId), 0.001,
            'the first deal goes to the kitchen');

        // Recall: the original two lines come back BY ID, and a second deal is added with none.
        // The KOT is held back so the SAVE's own decision is visible.
        $this->save(array_merge(
            $this->dealLines('1', $this->savedId($first, 'h1'), $this->savedId($first, 'c1')),
            $this->dealLines('2')
        ), $saleId, queueKot: false);

        $components = DB::connection('tenant')->table('sales_order_lines')
            ->where('sales_order_id', $saleId)->where('line_kind', 'component')
            ->orderBy('id')->get();

        $this->assertCount(2, $components, 'two deals, two components');
        $this->assertEqualsWithDelta(1.0, (float) $components->sum('kot_sent_quantity'), 0.001,
            'only ONE helping has been sent so far — the second must not inherit the first\'s sent '
            . 'quantity, or it is born already sent and the kitchen is never told');

        $this->queueKot($saleId);

        $this->assertEqualsWithDelta(2.0, $this->kotQuantityFor($saleId), 0.001,
            'the addition must print its own KOT: the customer is charged for two, the kitchen cooks two');
    }

    /** And it keeps working: a third helping added later prints too, exactly once. */
    public function test_a_third_helping_added_later_also_prints_exactly_once(): void
    {
        $first  = $this->save($this->dealLines('1'));
        $saleId = (int) $first['sale_id'];

        $second = $this->save(array_merge(
            $this->dealLines('1', $this->savedId($first, 'h1'), $this->savedId($first, 'c1')),
            $this->dealLines('2')
        ), $saleId);

        $this->save(array_merge(
            $this->dealLines('1', $this->savedId($second, 'h1'), $this->savedId($second, 'c1')),
            $this->dealLines('2', $this->savedId($second, 'h2'), $this->savedId($second, 'c2')),
            $this->dealLines('3')
        ), $saleId);

        $this->assertEqualsWithDelta(3.0, $this->kotQuantityFor($saleId), 0.001,
            'three helpings billed, three cooked — and the two already sent are not sent again');
    }

    /**
     * The other direction, and the reason the pool exists at all: saving again with NOTHING new
     * must not reprint what the kitchen already has.
     */
    public function test_saving_again_without_adding_anything_prints_nothing_new(): void
    {
        $first  = $this->save($this->dealLines('1'));
        $saleId = (int) $first['sale_id'];

        $this->save($this->dealLines('1', $this->savedId($first, 'h1'), $this->savedId($first, 'c1')), $saleId);

        $this->assertEqualsWithDelta(1.0, $this->kotQuantityFor($saleId), 0.001,
            're-saving an unchanged order must not send the food twice');
    }

    /* ── the ordinary day at the counter, which must not change ─────────── */

    /** The commonest thing there is: a plain item added to a running bill. */
    public function test_a_plain_item_added_to_a_running_bill_prints(): void
    {
        $first  = $this->save($this->plainLine('1'));
        $saleId = (int) $first['sale_id'];

        $this->save(array_merge(
            $this->plainLine('1', 1, $this->savedId($first, 's1')),
            $this->plainLine('2')
        ), $saleId);

        $this->assertEqualsWithDelta(2.0, $this->kotQuantityFor($saleId, 'standard'), 0.001,
            'two punched, two cooked');
    }

    /** Raising the quantity of something already sent must cook the DIFFERENCE, not all of it again. */
    public function test_raising_the_quantity_of_a_sent_item_prints_only_the_difference(): void
    {
        $first  = $this->save($this->plainLine('1', 2));
        $saleId = (int) $first['sale_id'];

        $this->assertEqualsWithDelta(2.0, $this->kotQuantityFor($saleId, 'standard'), 0.001);

        $this->save($this->plainLine('1', 3, $this->savedId($first, 's1')), $saleId);

        $this->assertEqualsWithDelta(3.0, $this->kotQuantityFor($saleId, 'standard'), 0.001,
            'the kitchen has now been told about three, having been told about two — not five');
    }

    /** A deal added to a bill that started with plain items is new food and must print. */
    public function test_a_deal_added_to_a_bill_of_plain_items_prints(): void
    {
        $first  = $this->save($this->plainLine('1'));
        $saleId = (int) $first['sale_id'];

        $this->save(array_merge(
            $this->plainLine('1', 1, $this->savedId($first, 's1')),
            $this->dealLines('1')
        ), $saleId);

        $this->assertEqualsWithDelta(1.0, $this->kotQuantityFor($saleId, 'standard'), 0.001,
            'the plain item was already sent and must not be sent again');
        $this->assertEqualsWithDelta(1.0, $this->kotQuantityFor($saleId, 'component'), 0.001,
            "the deal's component is new food and must reach the kitchen");
    }

    /**
     * BUG-014, restated: the SAME product standing alone and sitting inside a deal are two
     * different things to the kitchen. Neither may draw down the other's pool — which is exactly
     * what the key's four parts are for, and the draw-down must not blur them.
     */
    public function test_a_product_standalone_and_inside_a_deal_do_not_borrow_each_other(): void
    {
        $first  = $this->save($this->plainLine('1'));
        $saleId = (int) $first['sale_id'];

        // The plain line comes back by id; a deal on the SAME product arrives new.
        $this->save(array_merge(
            $this->plainLine('1', 1, $this->savedId($first, 's1')),
            $this->dealLines('1')
        ), $saleId);

        $lines = DB::connection('tenant')->table('sales_order_lines')
            ->where('sales_order_id', $saleId)->get()->keyBy('line_kind');

        $this->assertEqualsWithDelta(1.0, (float) $lines['standard']->kot_sent_quantity, 0.001);
        $this->assertEqualsWithDelta(1.0, (float) $lines['component']->kot_sent_quantity, 0.001,
            'the component was sent by its OWN KOT, not by inheriting the standalone line');
        $this->assertEqualsWithDelta(2.0,
            $this->kotQuantityFor($saleId, 'standard') + $this->kotQuantityFor($saleId, 'component'), 0.001,
            'one plain plate and one deal plate — two tickets of food in total');
    }

    /**
     * The outer guard the pool sits behind, pinned here because the fix leans on it: a line that
     * WAS sent cannot quietly leave the order. Resubmitting it without its id reads as removing
     * sent food, and that is refused unless a void with a reason comes with it.
     *
     * This is why draining the pool by a named line's WHOLE prior quantity is safe — nothing can
     * come back later claiming to be that same sent food.
     */
    public function test_sent_food_cannot_leave_the_order_unnamed_and_unexplained(): void
    {
        $first  = $this->save($this->dealLines('1'));
        $saleId = (int) $first['sale_id'];

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->save($this->dealLines('1'), $saleId, queueKot: false);
    }
}
