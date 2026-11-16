<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\PurchaseReturnController;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Product;
use App\Models\Tenant\PurchaseReturn;
use App\Services\Inventory\InventoryService;
use App\Services\Purchasing\PurchaseReturnService;
use App\Support\SubmissionKey;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\RacesWorkers;

/**
 * UB-06 (unified-business roadmap, W1) — a supplier return never sends back more than the receipt line brought in,
 * and the new screen's "Post return" is posted once.
 *
 *   - Two returns against one receipt line, posted at the same moment by two people (two OS processes queued behind a
 *     held lock on the line): the second waits, then counts the first — together never more than was received, the
 *     supplier credited once for what really went back.
 *   - "Post return" (save + post in one request) sent twice with the same form → one return; the same key with other
 *     lines → refused, nothing posted, a new key handed back.
 *   - The old screen (draft, then Post) without a key works exactly as before.
 */
class PurchaseReturnPostedOnceMySqlTest extends MySqlTenantTestCase
{
    use RacesWorkers;

    private int $branchId;

    private int $supplierId;

    private int $productId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'journal_lines', 'journal_entries', 'supplier_ledgers', 'supplier_credit_allocations',
            'purchase_return_lines', 'purchase_returns', 'purchase_bill_lines', 'purchase_bills',
            'goods_receipt_lines', 'goods_receipts', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'products', 'categories', 'units', 'suppliers', 'accounts', 'branches', 'users',
        ]);
        (new DefaultChartOfAccountsSeeder)->run();
        $c = DB::connection('tenant');
        $this->branchId = $c->table('branches')->insertGetId(['name' => 'Main', 'code' => 'MAIN', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $unit = $c->table('units')->insertGetId(['code' => 'PCS', 'name' => 'Piece', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $cat = $c->table('categories')->insertGetId(['name' => 'Goods', 'slug' => 'goods-' . Str::random(5), 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->productId = Product::create([
            'category_id' => $cat, 'unit_id' => $unit, 'sku' => 'RET-' . Str::upper(Str::random(5)), 'name' => 'Returnable Item',
            'slug' => 'returnable-' . Str::random(5), 'item_kind' => 'finished_good', 'inventory_consumption_method' => 'stock_item',
            'is_stock_tracked' => true, 'is_purchasable' => true, 'status' => 'active',
        ])->id;
        $this->supplierId = $c->table('suppliers')->insertGetId(['code' => 'SUP-' . Str::random(5), 'name' => 'Test Supplier', 'created_at' => now(), 'updated_at' => now()]);
        $this->userId = $c->table('users')->insertGetId(['name' => 'Store keeper', 'email' => 'sk' . uniqid() . '@test.local', 'password' => bcrypt('x'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        // 20 on hand: enough that branch stock alone would never stop an over-return of the 5 this receipt brought.
        app(InventoryService::class)->postIn(branch: Branch::findOrFail($this->branchId), product: Product::findOrFail($this->productId),
            variant: null, quantity: 20, unitCost: 40, movementType: 'opening_stock', referenceType: 'test');
        (new \ReflectionProperty(SubmissionKey::class, 'supported'))->setValue(null, []);
    }

    public function test_two_returns_on_one_receipt_line_at_the_same_moment_never_send_back_more_than_it_brought_in(): void
    {
        [, $grnLine] = $this->receiptOf(5);
        $a = $this->draft($grnLine, 4);
        $b = $this->draft($grnLine, 4);
        $stock = $this->stock();

        [$out, $queued] = $this->race('SELECT id FROM goods_receipt_lines WHERE id = ? FOR UPDATE', [$grnLine], [
            ['mode' => 'supplier_post', 'return_id' => $a->id, 'user_id' => $this->userId],
            ['mode' => 'supplier_post', 'return_id' => $b->id, 'user_id' => $this->userId],
        ]);

        $this->assertSame(2, $queued, 'both posts were in flight together, queued behind the receipt line: ' . implode(' | ', $out));
        $this->assertSame(1, $this->countOut($out, 'POSTED:'), 'one goes back: ' . implode(' | ', $out));
        $this->assertSame(1, $this->countOut($out, 'REFUSED:'), 'the other is refused: ' . implode(' | ', $out));
        $this->assertStringContainsString('only 1.000 returnable', $this->firstOut($out, 'REFUSED:'));
        $this->assertSame(1, PurchaseReturn::where('status', 'posted')->count());
        $this->assertEqualsWithDelta($stock - 4, $this->stock(), 0.001, 'stock left once');
        $this->assertSame(1, DB::table('supplier_ledgers')->where('entry_type', 'purchase_return')->count(), 'the supplier credited once');
    }

    public function test_post_return_on_the_new_screen_sent_twice_is_one_return(): void
    {
        [$grn, $grnLine] = $this->receiptOf(5);
        $stock = $this->stock();
        $key = SubmissionKey::fresh();
        $form = $this->newScreenForm($grn, $grnLine, 2, $key);

        $first = $this->postNewScreen($form);
        $this->assertTrue($first['ok'] ?? false, json_encode($first));
        $this->assertTrue($first['posted']);
        $this->assertNotEmpty($first['next_key'], 'a new key for the next return on the same screen');

        $again = $this->postNewScreen($form);
        $this->assertTrue($again['already'] ?? false, 'the same form again: ' . json_encode($again));
        $this->assertSame($first['id'], $again['id'], 'it gets the first return back');

        $other = $this->postNewScreen($this->newScreenForm($grn, $grnLine, 1, $key), 422);
        $this->assertStringContainsString('already posted as return', $other['message']);
        $this->assertNotEmpty($other['next_key'], 'refused, with a new key to post it deliberately');

        $this->assertSame(1, PurchaseReturn::count(), 'one return, not three');
        $this->assertEqualsWithDelta($stock - 2, $this->stock(), 0.001, 'stock left once');
        $this->assertSame($key, PurchaseReturn::value('submission_key'));
    }

    public function test_the_old_screen_draft_then_post_works_exactly_as_before(): void
    {
        [, $grnLine] = $this->receiptOf(5);
        $svc = app(PurchaseReturnService::class);
        $first = $this->draft($grnLine, 3);
        $second = $this->draft($grnLine, 3);          // both saved while nothing had gone back yet
        $svc->post($first, $this->userId);
        try {
            $svc->post($second, $this->userId);
            $this->fail('more than the receipt line brought in');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('only 2.000 returnable', $e->getMessage(), 'posting still checks what is left');
        }
        // W2-C: a new draft over what is left is refused as soon as it is saved, in the counter's words.
        try {
            $this->draft($grnLine, 3);
            $this->fail('a draft over what is left must be refused when it is saved');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('only 2 Piece can still be returned, not 3.', $e->getMessage());
        }
        $svc->post($this->draft($grnLine, 2), $this->userId);

        $this->assertSame(2, PurchaseReturn::where('status', 'posted')->count());
        $this->assertNull(PurchaseReturn::whereNotNull('submission_key')->value('id'), 'no key on the old screen');
    }

    public function test_a_return_number_already_taken_draws_another(): void
    {
        // The full suite caught it: three drafts in one second drew the same "PRET-<second>-<3 digits>". The clash is on
        // the index pret_no_unique — a name that does not say "return_no" — so it is recognised by the number itself.
        [, $grnLine] = $this->receiptOf(5);
        $taken = $this->draft($grnLine, 1)->return_no;
        $svc = new class($taken, app(InventoryService::class), app(\App\Services\Purchasing\PurchasingService::class)) extends PurchaseReturnService {
            private array $numbers;

            public function __construct(string $taken, InventoryService $i, \App\Services\Purchasing\PurchasingService $p)
            {
                parent::__construct($i, $p);
                $this->numbers = [$taken, 'PRET-TEST-FRESH-1'];
            }

            public function nextReturnNo(): string
            {
                return array_shift($this->numbers) ?? parent::nextReturnNo();
            }
        };

        $second = $svc->createDraft(['branch_id' => $this->branchId, 'supplier_id' => $this->supplierId, 'return_date' => now()->toDateString(), 'reason_code' => 'damaged'],
            [['product_id' => $this->productId, 'source_line_id' => $grnLine, 'quantity' => 1, 'unit_cost' => 40]], $this->userId);

        $this->assertSame('PRET-TEST-FRESH-1', $second->return_no, 'the taken number was skipped');
        $this->assertSame(2, PurchaseReturn::count());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{0:int, 1:int} goods receipt, its one line — $qty received at 40 */
    private function receiptOf(float $qty): array
    {
        $c = DB::connection('tenant');
        $grn = $c->table('goods_receipts')->insertGetId(['grn_no' => 'GRN-' . Str::upper(Str::random(8)), 'branch_id' => $this->branchId,
            'supplier_id' => $this->supplierId, 'receipt_date' => now()->toDateString(), 'status' => 'posted', 'created_at' => now(), 'updated_at' => now()]);
        $line = $c->table('goods_receipt_lines')->insertGetId(['goods_receipt_id' => $grn, 'product_id' => $this->productId,
            'quantity_received' => $qty, 'unit_cost' => 40, 'affects_stock' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return [$grn, $line];
    }

    private function draft(int $grnLine, float $qty): PurchaseReturn
    {
        return app(PurchaseReturnService::class)->createDraft(
            ['branch_id' => $this->branchId, 'supplier_id' => $this->supplierId, 'return_date' => now()->toDateString(), 'reason_code' => 'damaged'],
            [['product_id' => $this->productId, 'source_line_id' => $grnLine, 'quantity' => $qty, 'unit_cost' => 40]],
            $this->userId,
        );
    }

    private function newScreenForm(int $grn, int $grnLine, int $qty, string $key): array
    {
        return ['_entry_v2' => '1', '_post' => '1', 'submission_key' => $key, 'branch_id' => $this->branchId, 'supplier_id' => $this->supplierId,
            'goods_receipt_id' => $grn, 'return_date' => now()->toDateString(), 'reason_code' => 'damaged',
            'lines' => [['product_id' => $this->productId, 'source_line_id' => $grnLine, 'packs' => ['base' => (string) $qty], 'buy_pack' => 'base', 'price' => '40', 'reason_code' => 'damaged']]];
    }

    /** The real controller action, as the new screen calls it (JSON). */
    private function postNewScreen(array $form, int $status = 200): array
    {
        $user = \App\Models\Tenant\User::findOrFail($this->userId);
        auth('tenant')->setUser($user);
        auth()->shouldUse('tenant');
        $request = Request::create('/purchase-returns', 'POST', $form);
        $request->headers->set('Accept', 'application/json');
        $request->setLaravelSession(app('session')->driver());
        app()->instance('request', $request);
        $response = app()->call([app(PurchaseReturnController::class), 'store'], ['request' => $request]);
        $this->assertSame($status, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true);
    }

    private function stock(): float
    {
        return (float) DB::table('stock_balances')->where('product_id', $this->productId)->sum('quantity_on_hand');
    }
}
