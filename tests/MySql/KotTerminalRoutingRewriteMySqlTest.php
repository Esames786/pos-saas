<?php

namespace Tests\MySql;

use App\Models\Tenant\SalesOrder;
use App\Services\Printing\KotTerminalRoutingRewriter;
use App\Services\Printing\PrintRoutingService;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * PHASE 4 — the order-type → terminal rewrite. Delivery + Dine-In sales already run on their own
 * terminals, so their KOTs must resolve to the SAME printers after the switch; only a multi-order-type
 * terminal (Takeaway/Quick Sale) changes — every order type on it now routes to that one counter.
 */
class KotTerminalRoutingRewriteMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private array $t = [];   // terminal ids by order type
    private array $p = [];   // printer ids by role
    private int $foodProduct;
    private int $dessertProduct;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'print_jobs', 'kot_batch_lines', 'kot_batches', 'sales_order_lines', 'sales_orders',
            'category_printer_mappings', 'printers', 'products', 'categories', 'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch();
        $this->t = [
            'delivery'   => $this->makeTerminal($this->branchId, ['name' => 'Delivery']),
            'takeaway'   => $this->makeTerminal($this->branchId, ['name' => 'Takeaway']),
            'dine_in'    => $this->makeTerminal($this->branchId, ['name' => 'Dine In']),
            'quick_sale' => $this->makeTerminal($this->branchId, ['name' => 'Quick Sale']),
        ];
        $this->p = [
            'delivery'   => $this->makePrinter(['code' => 'PD', 'name' => 'Delivery', 'print_role' => 'both', 'branch_id' => $this->branchId, 'is_default' => 1]),
            'takeaway'   => $this->makePrinter(['code' => 'PT', 'name' => 'Takeaway', 'print_role' => 'both', 'branch_id' => $this->branchId]),
            'dine_in'    => $this->makePrinter(['code' => 'PI', 'name' => 'Dine In', 'print_role' => 'both', 'branch_id' => $this->branchId]),
            'quick_sale' => $this->makePrinter(['code' => 'PQ', 'name' => 'Quick Sale', 'print_role' => 'both', 'branch_id' => $this->branchId]),
            'x'          => $this->makePrinter(['code' => 'PX', 'name' => 'XPrinter', 'print_role' => 'both', 'branch_id' => $this->branchId]),
        ];

        $food = $this->makeCategory(['name' => 'Biryani', 'slug' => 'biryani']);
        $dessert = $this->makeCategory(['name' => 'Desserts', 'slug' => 'desserts']);
        $this->foodProduct = $this->makeProduct($food, ['name' => 'Beef Biryani']);
        $this->dessertProduct = $this->makeProduct($dessert, ['name' => 'Ice Cream']);

        // Khatri's shape TODAY: order-type-keyed. Food → the order type's counter; Desserts → XPrinter.
        foreach (['delivery', 'takeaway', 'dine_in', 'quick_sale'] as $ot) {
            $this->map($ot, $food, $this->p[$ot]);
            $this->map($ot, $dessert, $this->p['x']);
        }
    }

    private function map(string $orderType, int $categoryId, int $printerId): void
    {
        DB::connection('tenant')->table('category_printer_mappings')->insert([
            'branch_id' => $this->branchId, 'terminal_id' => null, 'category_id' => $categoryId,
            'printer_id' => $printerId, 'print_role' => 'kot', 'order_type' => $orderType, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function kotPrinters(string $onTerminal, string $orderType, int $productId): array
    {
        $saleId = $this->makeSale($this->branchId, ['terminal_id' => $this->t[$onTerminal], 'order_type' => $orderType, 'status' => 'held']);
        $this->makeSaleLine($saleId, $productId, ['quantity' => 1]);

        return collect(app(PrintRoutingService::class)->kotRoutesForSale(SalesOrder::findOrFail($saleId)))
            ->pluck('printer.id')->filter()->map(fn ($i) => (int) $i)->sort()->values()->all();
    }

    public function test_delivery_and_dine_in_are_unchanged_while_a_shared_terminal_flips_to_its_counter(): void
    {
        // BEFORE — order-type routing.
        $this->assertSame([$this->p['dine_in']], $this->kotPrinters('dine_in', 'dine_in', $this->foodProduct));
        $this->assertSame([$this->p['delivery']], $this->kotPrinters('delivery', 'delivery', $this->foodProduct));
        // A quick_sale order rung on the TAKEAWAY terminal currently follows the order type → QuickSale printer.
        $this->assertSame([$this->p['quick_sale']], $this->kotPrinters('takeaway', 'quick_sale', $this->foodProduct));

        $result = app(KotTerminalRoutingRewriter::class)->rewrite($this->branchId);
        $this->assertSame(8, $result['converted'], 'all eight order-type rules convert');

        // Rows are now terminal-keyed with order_type "all".
        $this->assertSame(0, DB::connection('tenant')->table('category_printer_mappings')
            ->whereIn('order_type', ['delivery', 'takeaway', 'dine_in', 'quick_sale'])->count());
        $this->assertSame(8, DB::connection('tenant')->table('category_printer_mappings')
            ->where('order_type', 'all')->whereNotNull('terminal_id')->count());

        // AFTER — Delivery + Dine-In resolve to the SAME printers (their sales run on their terminals).
        $this->assertSame([$this->p['dine_in']], $this->kotPrinters('dine_in', 'dine_in', $this->foodProduct), 'dine-in printer unchanged');
        $this->assertSame([$this->p['delivery']], $this->kotPrinters('delivery', 'delivery', $this->foodProduct), 'delivery printer unchanged');
        // THE FLIP: a quick_sale order on the Takeaway terminal now prints to the TAKEAWAY counter.
        $this->assertSame([$this->p['takeaway']], $this->kotPrinters('takeaway', 'quick_sale', $this->foodProduct),
            'every order type on the takeaway terminal now routes to its counter');
        // Overflow still goes to the XPrinter, on any terminal.
        $this->assertSame([$this->p['x']], $this->kotPrinters('dine_in', 'dine_in', $this->dessertProduct));
    }

    public function test_the_rewrite_is_idempotent(): void
    {
        app(KotTerminalRoutingRewriter::class)->rewrite($this->branchId);
        $second = app(KotTerminalRoutingRewriter::class)->rewrite($this->branchId);

        $this->assertSame(0, $second['converted'], 'a second run converts nothing');
    }

    public function test_it_refuses_to_run_when_a_terminal_is_missing(): void
    {
        DB::connection('tenant')->table('terminals')->where('id', $this->t['quick_sale'])->update(['name' => 'Renamed Counter']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Quick Sale');
        app(KotTerminalRoutingRewriter::class)->rewrite($this->branchId);
    }
}
