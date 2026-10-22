<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductVariant;
use App\Models\Tenant\StockLedger;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\MySql\Support\RacesWorkers;

/**
 * UB-W2 evidence for the candidate (df524466): stock with no variant and stock on the product's default variant are
 * one stock for a stock-out — and nothing else changes. Through the real InventoryService, on the test's own database:
 *
 *  - a real second variant (Large) is never taken for plain stock, and plain stock is never taken for it;
 *  - one branch's stock never serves another branch;
 *  - FEFO and expiry run across both kinds of row: nearest expiry first, expired never sold, the picked batch first;
 *  - every leg is written against the row it left, at that row's own cost; balances and the stock book agree;
 *  - a receipt reversal (GRN void) still takes back only from the row it filled;
 *  - two sales at the same moment never take the same stock twice, and no row goes below zero.
 */
class PlainStockVariantsMySqlTest extends MySqlTenantTestCase
{
    use RacesWorkers;

    private Branch $a;
    private Branch $b;
    private Product $sugar;
    private ProductVariant $plain;   // the default variant
    private ProductVariant $large;   // a real second variant

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['stock_ledgers', 'stock_balances', 'inventory_batches', 'product_variants', 'products', 'categories', 'units', 'branches']);
        $c = DB::connection('tenant');
        $this->a = Branch::create(['name' => 'Branch A', 'code' => 'BRA', 'status' => 'active']);
        $this->b = Branch::create(['name' => 'Branch B', 'code' => 'BRB', 'status' => 'active']);
        $kg = $c->table('units')->insertGetId(['code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now()]);
        $cat = $c->table('categories')->insertGetId(['name' => 'Grocery', 'slug' => 'grocery-' . Str::random(5), 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->sugar = Product::create(['category_id' => $cat, 'unit_id' => $kg, 'sku' => 'PSV-' . Str::upper(Str::random(5)), 'name' => 'Plain Sugar',
            'slug' => 'plain-sugar-' . Str::random(5), 'item_kind' => 'finished_good', 'inventory_consumption_method' => 'stock_item',
            'is_stock_tracked' => true, 'status' => 'active', 'default_purchase_price' => 140]);
        $this->plain = ProductVariant::create(['product_id' => $this->sugar->id, 'sku' => $this->sugar->sku, 'name' => 'Sugar', 'is_default' => true, 'is_active' => true]);
        $this->large = ProductVariant::create(['product_id' => $this->sugar->id, 'sku' => $this->sugar->sku . '-L', 'name' => 'Sugar 5 kg bag', 'is_default' => false, 'is_active' => true]);
        $this->sugar = $this->sugar->fresh();
    }

    public function test_a_real_second_variant_is_never_taken_for_plain_stock_nor_plain_stock_for_it(): void
    {
        $this->in($this->a, $this->large, 5, 600);
        $this->assertRefused(fn () => $this->out($this->a, null, 1), 'Insufficient stock');
        $this->assertSame(5.0, $this->onHand($this->a, $this->large), 'the Large bags are untouched');

        $this->in($this->a, null, 2, 140);
        $this->in($this->a, $this->plain, 3, 150);
        $this->assertRefused(fn () => $this->out($this->a, $this->plain, 6), 'Insufficient stock');   // plain = 2 + 3, never the Large 5
        $this->assertSame([2.0, 3.0, 5.0], [$this->onHand($this->a, null), $this->onHand($this->a, $this->plain), $this->onHand($this->a, $this->large)],
            'a refused stock-out moves nothing');

        $this->out($this->a, $this->plain, 5);
        $this->assertSame([0.0, 0.0, 5.0], [$this->onHand($this->a, null), $this->onHand($this->a, $this->plain), $this->onHand($this->a, $this->large)]);
        $this->out($this->a, $this->large, 2);
        $this->assertSame(3.0, $this->onHand($this->a, $this->large), 'Large still sells from its own rows only');
    }

    public function test_one_branch_never_serves_another(): void
    {
        $this->in($this->a, null, 2, 140);
        $this->in($this->a, $this->plain, 2, 150);
        $this->in($this->b, $this->plain, 10, 150);

        $this->out($this->a, null, 3);
        $this->assertEqualsWithDelta(1, $this->branchTotal($this->a), 0.0001);
        $this->assertEqualsWithDelta(10, $this->branchTotal($this->b), 0.0001, 'Branch B untouched');
        $this->assertRefused(fn () => $this->out($this->a, $this->plain, 2), 'Insufficient stock');
        $this->assertEqualsWithDelta(10, $this->branchTotal($this->b), 0.0001, 'and never borrowed from');

        // Branch B holding the nearest expiry must not even be looked at: Branch A sells from its own later batch.
        // (The balance key would also stop B's stock leaving, so this is what proves the branch filter itself.)
        $this->in($this->a, $this->plain, 5, 150, 'A-LATER', now()->addMonths(6)->toDateString());
        $this->in($this->b, $this->plain, 5, 150, 'B-SOONER', now()->addMonth()->toDateString());
        $legs = $this->out($this->a, null, 3);
        $this->assertSame(['A-LATER' => 3.0], $this->byBatch($legs), 'A sells from its own batch');
        $this->assertSame(5.0, $this->batchLeft('B-SOONER'), 'B\'s sooner batch is untouched');
    }

    public function test_fefo_and_expiry_run_across_both_kinds_of_row(): void
    {
        $this->in($this->a, null, 4, 140, 'E-OLD', now()->subMonth()->endOfMonth()->toDateString());   // expired, no variant
        $this->in($this->a, $this->plain, 3, 150, 'J-SOON', now()->addMonth()->endOfMonth()->toDateString());
        $this->in($this->a, null, 5, 145, 'M-LATER', now()->addMonths(3)->endOfMonth()->toDateString());

        $legs = $this->out($this->a, $this->plain, 5, skipExpired: true);
        $this->assertSame(['J-SOON' => 3.0, 'M-LATER' => 2.0], $this->byBatch($legs), 'nearest expiry first, across both rows; the expired batch never sold');
        $this->assertSame(4.0, $this->batchLeft('E-OLD'));

        $this->assertRefused(fn () => $this->out($this->a, null, 7, skipExpired: true), 'only 3 Kilogram left that is not expired (4 Kilogram more has expired');
        $this->assertSame(3.0, $this->batchLeft('M-LATER'), 'the refusal moved nothing');

        $picked = (int) DB::connection('tenant')->table('inventory_batches')->where('batch_no', 'M-LATER')->value('id');
        $this->in($this->a, $this->plain, 2, 150, 'J2-SOONER', now()->addDays(10)->toDateString());
        $legs = $this->out($this->a, null, 1, skipExpired: true, preferBatchId: $picked);
        $this->assertSame(['M-LATER' => 1.0], $this->byBatch($legs), 'the batch the cashier picked goes first, whichever row it sits on');
    }

    public function test_each_leg_is_written_against_the_row_it_left_at_that_rows_cost(): void
    {
        $this->in($this->a, null, 5, 140);
        $this->in($this->a, $this->plain, 5, 150);
        $legs = $this->out($this->a, null, 7);

        $this->assertEqualsWithDelta(7, collect($legs)->sum('quantity'), 0.0001);
        foreach ($legs as $leg) {
            $row = $this->row($this->a, $leg->product_variant_id ? $this->plain : null);
            $this->assertEqualsWithDelta((float) $row->average_cost, (float) $leg->unit_cost, 0.0001, 'a leg costs what its own row holds');
            $this->assertEqualsWithDelta((float) $row->quantity_on_hand, (float) $leg->balance_after, 0.0001, 'and its balance-after is that row\'s');
        }
        $this->assertContains(round((float) collect($legs)->sum('total_cost'), 2), [5 * 140 + 2 * 150.0, 2 * 140 + 5 * 150.0], 'cost of goods = what the rows held');
        foreach ([null, $this->plain] as $v) {
            $this->assertEqualsWithDelta($this->onHand($this->a, $v), $this->bookSays($this->a, $v), 0.0001, 'each balance row = its own stock-book lines');
        }
        $this->assertSame(0, DB::connection('tenant')->table('stock_balances')->where('quantity_on_hand', '<', 0)->count());
    }

    public function test_a_receipt_reversal_still_takes_back_only_from_the_row_it_filled(): void
    {
        $r1 = $this->in($this->a, null, 5, 140, 'R1', now()->addMonth()->toDateString());
        $r2 = $this->in($this->a, $this->plain, 5, 150, 'R2', now()->addMonths(6)->toDateString());
        $this->out($this->a, $this->plain, 3);   // FEFO: from R1's row (no variant)
        $this->assertSame([2.0, 5.0], [$this->batchLeft('R1'), $this->batchLeft('R2')]);

        app(InventoryService::class)->reverseReceiptEntry($r2, 'VOID-R2');
        $this->assertSame([2.0, 0.0], [$this->batchLeft('R1'), $this->batchLeft('R2')], 'R2 came back out of its own row, R1 untouched');

        $this->assertRefused(fn () => app(InventoryService::class)->reverseReceiptEntry($r1, 'VOID-R1'), '');
        $this->assertSame(2.0, $this->batchLeft('R1'), 'R1 cannot be taken back once sold from — refused, nothing moved');
    }

    public function test_two_sales_at_the_same_moment_never_take_the_same_stock_twice(): void
    {
        $this->in($this->a, null, 5, 140);
        $this->in($this->a, $this->plain, 5, 150);
        $job = ['mode' => 'stock_out', 'branch_id' => $this->a->id, 'product_id' => $this->sugar->id, 'variant_id' => null, 'quantity' => 6];

        [$out, $queued] = $this->race('SELECT id FROM stock_balances WHERE product_id = ? FOR UPDATE', [$this->sugar->id], [$job, $job]);

        $this->assertSame(2, $queued, 'both were in flight together: ' . implode(' | ', $out));
        $this->assertSame(1, $this->countOut($out, 'POSTED:'), 'one sale takes 6: ' . implode(' | ', $out));
        $this->assertSame(1, $this->countOut($out, 'REFUSED:'), 'the other finds 4 left and is refused: ' . implode(' | ', $out));
        $this->assertEqualsWithDelta(4, $this->branchTotal($this->a), 0.0001, '10 − 6, never 10 − 12');
        $this->assertSame(0, DB::connection('tenant')->table('stock_balances')->where('quantity_on_hand', '<', 0)->count(), 'no row below zero');
        $this->assertEqualsWithDelta(6, (float) DB::connection('tenant')->table('stock_ledgers')->where('direction', 'out')->sum('quantity'), 0.0001,
            'the stock book shows 6 out, once');
    }

    /* ── helpers ─────────────────────────────────────────────────────────── */

    private function in(Branch $branch, ?ProductVariant $variant, float $qty, float $cost, ?string $batch = null, ?string $expiry = null): StockLedger
    {
        return app(InventoryService::class)->postIn(branch: $branch, product: $this->sugar, variant: $variant, quantity: $qty, unitCost: $cost,
            movementType: 'purchase', referenceType: 'test', batchNo: $batch, expiryDate: $expiry);
    }

    private function out(Branch $branch, ?ProductVariant $variant, float $qty, bool $skipExpired = false, ?int $preferBatchId = null): array
    {
        return app(InventoryService::class)->postOutFefo(branch: $branch, product: $this->sugar, variant: $variant, quantity: $qty,
            movementType: 'sale', referenceType: 'test', skipExpired: $skipExpired, preferBatchId: $preferBatchId);
    }

    private function assertRefused(callable $do, string $says): void
    {
        try {
            $do();
            $this->fail('expected a refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($says, $e->getMessage());
        }
    }

    private function onHand(Branch $branch, ?ProductVariant $variant): float
    {
        return (float) DB::connection('tenant')->table('stock_balances')->where('branch_id', $branch->id)->where('product_id', $this->sugar->id)
            ->where(fn ($q) => $variant ? $q->where('product_variant_id', $variant->id) : $q->whereNull('product_variant_id'))->sum('quantity_on_hand');
    }

    private function row(Branch $branch, ?ProductVariant $variant)
    {
        return DB::connection('tenant')->table('stock_balances')->where('branch_id', $branch->id)->where('product_id', $this->sugar->id)
            ->where(fn ($q) => $variant ? $q->where('product_variant_id', $variant->id) : $q->whereNull('product_variant_id'))->whereNull('inventory_batch_id')->first();
    }

    private function bookSays(Branch $branch, ?ProductVariant $variant): float
    {
        return (float) DB::connection('tenant')->table('stock_ledgers')->where('branch_id', $branch->id)->where('product_id', $this->sugar->id)
            ->where(fn ($q) => $variant ? $q->where('product_variant_id', $variant->id) : $q->whereNull('product_variant_id'))
            ->sum(DB::raw("CASE WHEN direction = 'in' THEN quantity ELSE -quantity END"));
    }

    private function branchTotal(Branch $branch): float
    {
        return (float) DB::connection('tenant')->table('stock_balances')->where('branch_id', $branch->id)->where('product_id', $this->sugar->id)->sum('quantity_on_hand');
    }

    private function batchLeft(string $batchNo): float
    {
        $ids = DB::connection('tenant')->table('inventory_batches')->where('batch_no', $batchNo)->pluck('id');

        return (float) DB::connection('tenant')->table('stock_balances')->whereIn('inventory_batch_id', $ids)->sum('quantity_on_hand');
    }

    /** @return array<string, float> batch number => quantity taken */
    private function byBatch(array $legs): array
    {
        $out = [];
        foreach ($legs as $leg) {
            $no = (string) DB::connection('tenant')->table('inventory_batches')->where('id', $leg->inventory_batch_id)->value('batch_no');
            $out[$no] = ($out[$no] ?? 0.0) + (float) $leg->quantity;
        }

        return $out;
    }
}
