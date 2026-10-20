<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Product;
use App\Models\Tenant\SalesOrder;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\SalesReturnService;
use App\Support\AlreadySubmitted;
use App\Support\SubmissionKey;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\MySql\Support\TenantFixtures;

/**
 * UB-04 / UB-06 (unified-business roadmap, W1) — a return is posted once, never returns more than was sold, and
 * expired or unchecked medicine never goes back on the shelf by itself.
 *
 *   - The same form posted twice (double click, retry, Back + Submit) makes ONE return: stock, refund, ledger and
 *     journal move once; the second post gets the first return back. The same key with other items is refused.
 *   - A form without a key (opened before this release) posts exactly as before; so does every post while the
 *     column is missing on a tenant (schema first).
 *   - Genuine concurrency, two OS processes queued behind a held lock: one form posted twice at once → one return
 *     (with a bill: the sale's lock; without: the unique key); two people returning one bill at once → never more
 *     than was sold.
 *   - Without a bill, an item with an expiry is "Thrown away" unless the cashier says shelf; an expired batch can
 *     never be restocked (with or without a bill).
 */
class SalesReturnPostedOnceMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'cash_bank_account_transactions', 'journal_lines', 'journal_entries', 'sales_ledgers',
            'sales_return_lines', 'sales_returns', 'sale_payments', 'sales_order_lines',
            'sales_orders', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'products', 'categories', 'accounts', 'branches', 'users',
        ]);
        (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder())->run();
        $this->branchId = $this->makeBranch();
        $this->userId = $this->makeUser();
        $this->resetKeyCache();
    }

    public function test_the_same_bill_form_posted_twice_makes_one_return_and_moves_stock_once(): void
    {
        [$p, $sale, $line] = $this->soldThree();
        $before = $this->stock($p);
        $key = SubmissionKey::fresh();
        $hash = SubmissionKey::hash(['line' => $line, 'qty' => 1]);

        $first = $this->postBill($sale, [['sales_order_line_id' => $line, 'quantity' => 1]], $key, $hash);
        try {
            $this->postBill($sale, [['sales_order_line_id' => $line, 'quantity' => 1]], $key, $hash);
            $this->fail('the second post of the same form must not post');
        } catch (AlreadySubmitted $e) {
            $this->assertTrue($e->same, 'same form, same items');
            $this->assertSame($first->id, $e->record->getKey(), 'it gets the first return back');
        }

        $this->assertSame(1, DB::table('sales_returns')->count(), 'one return');
        $this->assertEqualsWithDelta($before + 1, $this->stock($p), 0.001, 'stock came back once');
        $this->assertEqualsWithDelta(1, (float) DB::table('sales_order_lines')->where('id', $line)->value('returned_quantity'), 0.001);
        $this->assertSame(1, DB::table('sales_ledgers')->where('entry_type', 'sale_return')->count(), 'one ledger row');
        $this->assertSame(1, DB::table('journal_entries')->where('source_type', 'sales_return')->distinct()->count('source_id'), 'journaled once');
        $this->assertSame($key, DB::table('sales_returns')->value('submission_key'));
    }

    public function test_the_same_key_with_other_items_is_refused_and_nothing_moves(): void
    {
        [$p, $sale, $line] = $this->soldThree();
        $key = SubmissionKey::fresh();
        $this->postBill($sale, [['sales_order_line_id' => $line, 'quantity' => 1]], $key, SubmissionKey::hash(['qty' => 1]));
        $stock = $this->stock($p);

        try {
            $this->postBill($sale, [['sales_order_line_id' => $line, 'quantity' => 2]], $key, SubmissionKey::hash(['qty' => 2]));
            $this->fail('a reused key with other items must be refused');
        } catch (AlreadySubmitted $e) {
            $this->assertFalse($e->same, 'other items');
        }

        $this->assertSame(1, DB::table('sales_returns')->count());
        $this->assertEqualsWithDelta($stock, $this->stock($p), 0.001, 'nothing moved');
        $this->assertEqualsWithDelta(1, (float) DB::table('sales_order_lines')->where('id', $line)->value('returned_quantity'), 0.001);
    }

    public function test_without_a_key_two_posts_are_two_returns_as_before_but_never_more_than_was_sold(): void
    {
        [$p, $sale, $line] = $this->soldThree();
        $before = $this->stock($p);

        $this->postBill($sale, [['sales_order_line_id' => $line, 'quantity' => 2]]);
        $second = $this->postBill($sale, [['sales_order_line_id' => $line, 'quantity' => 2]]);
        $this->assertEqualsWithDelta(1, (float) DB::table('sales_return_lines')->where('sales_return_id', $second->id)->value('quantity'), 0.001,
            'the second is capped at what was left — exactly as before');
        try {
            $this->postBill($sale, [['sales_order_line_id' => $line, 'quantity' => 1]]);
            $this->fail('nothing is left to return');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('returnable', $e->getMessage());
        }

        $this->assertSame(2, DB::table('sales_returns')->count(), 'two returns, as before');
        $this->assertNull(DB::table('sales_returns')->value('submission_key'), 'no key stored');
        $this->assertEqualsWithDelta($before + 3, $this->stock($p), 0.001, 'three came back — what was sold');
    }

    public function test_a_return_without_a_bill_posted_twice_is_one_return(): void
    {
        $p = $this->stocked('Loose Soap');
        $before = $this->stock($p);
        $key = SubmissionKey::fresh();
        $hash = SubmissionKey::hash(['p' => $p, 'qty' => 2]);
        $line = [['product_id' => $p, 'quantity' => 2, 'unit_price' => 100, 'restock' => 1]];

        $first = $this->postNoBill($line, $key, $hash);
        try {
            $this->postNoBill($line, $key, $hash);
            $this->fail('the second post of the same form must not post');
        } catch (AlreadySubmitted $e) {
            $this->assertTrue($e->same);
            $this->assertSame($first->id, $e->record->getKey());
        }

        $this->assertSame(1, DB::table('sales_returns')->count());
        $this->assertEqualsWithDelta($before + 2, $this->stock($p), 0.001, 'stock came back once');
        $this->assertSame(1, DB::table('sales_ledgers')->where('entry_type', 'sale_return')->count());
    }

    public function test_while_the_column_is_missing_a_key_is_ignored_and_the_return_posts_as_before(): void
    {
        [$p, $sale, $line] = $this->soldThree();
        $migration = require database_path('migrations/tenant/2026_10_21_000001_add_submission_key_to_sales_returns.php');
        DB::connection('tenant')->statement('ALTER TABLE `sales_returns` DROP INDEX `sales_returns_submission_key_unique`, DROP COLUMN `submission_key`, DROP COLUMN `submission_hash`');
        $this->resetKeyCache();
        try {
            $return = $this->postBill($sale, [['sales_order_line_id' => $line, 'quantity' => 1]], SubmissionKey::fresh(), 'x');
            $this->assertSame(1, DB::table('sales_returns')->where('id', $return->id)->count(), 'posted as before');
        } finally {
            DB::setDefaultConnection('tenant');
            $migration->up();
            $this->resetKeyCache();
        }
        $this->assertTrue(SubmissionKey::supported('sales_returns'), 'the migration puts it back');
    }

    public function test_one_bill_form_posted_twice_at_the_same_moment_makes_one_return(): void
    {
        [$p, $sale, $line] = $this->soldThree();
        $before = $this->stock($p);
        $job = ['mode' => 'bill', 'sale_id' => $sale, 'branch_id' => $this->branchId, 'user_id' => $this->userId,
            'lines' => [['sales_order_line_id' => $line, 'quantity' => 1]], 'key' => SubmissionKey::fresh(), 'hash' => SubmissionKey::hash(['qty' => 1])];

        [$out, $queued] = $this->race('SELECT id FROM sales_orders WHERE id = ? FOR UPDATE', [$sale], [$job, $job]);

        $this->assertSame(2, $queued, 'both posts were in flight together, queued behind the sale: ' . implode(' | ', $out));
        $this->assertSame(1, $this->countOut($out, 'POSTED:'), 'one posts: ' . implode(' | ', $out));
        $this->assertSame(1, $this->countOut($out, 'ALREADY:'), 'the other finds it: ' . implode(' | ', $out));
        $this->assertStringEndsWith(':same', $this->firstOut($out, 'ALREADY:'));
        $this->assertSame(1, DB::table('sales_returns')->count());
        $this->assertEqualsWithDelta($before + 1, $this->stock($p), 0.001, 'stock came back once');
    }

    public function test_one_form_without_a_bill_posted_twice_at_the_same_moment_makes_one_return(): void
    {
        $p = $this->stocked('Loose Soap');
        $before = $this->stock($p);
        $balanceKey = (string) DB::table('stock_balances')->where('product_id', $p)->value('balance_key');
        $job = ['mode' => 'no_bill', 'branch_id' => $this->branchId, 'user_id' => $this->userId,
            'lines' => [['product_id' => $p, 'quantity' => 2, 'unit_price' => 100, 'restock' => 1]],
            'key' => SubmissionKey::fresh(), 'hash' => SubmissionKey::hash(['qty' => 2])];

        // The first post inserts its return, then waits for the stock row; the second waits on the first's key.
        [$out, $queued] = $this->race('SELECT id FROM stock_balances WHERE balance_key = ? FOR UPDATE', [$balanceKey], [$job, $job]);

        $this->assertSame(2, $queued, 'both posts were in flight together: ' . implode(' | ', $out));
        $this->assertSame(1, $this->countOut($out, 'POSTED:'), 'one posts: ' . implode(' | ', $out));
        $this->assertSame(1, $this->countOut($out, 'ALREADY:'), 'the other is stopped by the key: ' . implode(' | ', $out));
        $this->assertSame(1, DB::table('sales_returns')->count());
        $this->assertEqualsWithDelta($before + 2, $this->stock($p), 0.001, 'stock came back once');
        $this->assertSame(1, DB::table('sales_ledgers')->where('entry_type', 'sale_return')->count());
    }

    public function test_two_people_returning_one_bill_at_the_same_moment_never_return_more_than_was_sold(): void
    {
        [$p, $sale, $line] = $this->soldThree();
        $before = $this->stock($p);
        $job = fn () => ['mode' => 'bill', 'sale_id' => $sale, 'branch_id' => $this->branchId, 'user_id' => $this->userId,
            'lines' => [['sales_order_line_id' => $line, 'quantity' => 2]], 'key' => SubmissionKey::fresh(), 'hash' => SubmissionKey::hash(['qty' => 2])];

        [$out, $queued] = $this->race('SELECT id FROM sales_orders WHERE id = ? FOR UPDATE', [$sale], [$job(), $job()]);

        $this->assertSame(2, $queued, 'both were in flight together: ' . implode(' | ', $out));
        $this->assertSame(2, $this->countOut($out, 'POSTED:'), 'two different returns: ' . implode(' | ', $out));
        $this->assertEqualsWithDelta(3, (float) DB::table('sales_return_lines')->sum('quantity'), 0.001, 'each asked for 2 of 3 — together never more than 3');
        $this->assertEqualsWithDelta(3, (float) DB::table('sales_order_lines')->where('id', $line)->value('returned_quantity'), 0.001);
        $this->assertEqualsWithDelta($before + 3, $this->stock($p), 0.001);
    }

    public function test_a_medicine_back_without_a_bill_is_thrown_away_unless_the_cashier_says_shelf(): void
    {
        $syrup = $this->stocked('Cough Syrup', ['has_expiry' => 1], 'SYR1', now()->addYear()->toDateString());
        $soap = $this->stocked('Soap Bar');
        [$syrupBefore, $soapBefore] = [$this->stock($syrup), $this->stock($soap)];

        $return = $this->postNoBill([
            ['product_id' => $syrup, 'quantity' => 1, 'unit_price' => 100],     // the form did not say: unchecked medicine
            ['product_id' => $soap, 'quantity' => 1, 'unit_price' => 100],      // an item with no expiry: as before
        ]);

        $lines = DB::table('sales_return_lines')->where('sales_return_id', $return->id)->get()->keyBy('product_id');
        $this->assertSame(0, (int) $lines[$syrup]->restocked, 'medicine: thrown away unless the cashier says shelf');
        $this->assertSame(1, (int) $lines[$soap]->restocked, 'no expiry: back on the shelf, as before');
        $this->assertEqualsWithDelta($syrupBefore, $this->stock($syrup), 0.001);
        $this->assertEqualsWithDelta($soapBefore + 1, $this->stock($soap), 0.001);
    }

    public function test_expired_stock_never_goes_back_on_the_shelf_with_or_without_a_bill(): void
    {
        $old = now()->subDays(3)->toDateString();
        $syrup = $this->stocked('Old Syrup', ['has_expiry' => 1], 'OLD1', $old);
        $oldBatch = (int) DB::table('inventory_batches')->where('product_id', $syrup)->where('batch_no', 'OLD1')->value('id');
        $stock = $this->stock($syrup);
        $count = DB::table('sales_returns')->count();

        foreach ([
            'a picked expired batch' => ['inventory_batch_id' => $oldBatch],
            'a typed batch whose expiry has passed' => ['batch_no' => 'TYPED1', 'expiry' => now()->subMonths(2)->format('m/y')],
        ] as $what => $batch) {
            try {
                $this->postNoBill([['product_id' => $syrup, 'quantity' => 1, 'unit_price' => 100, 'restock' => 1] + $batch]);
                $this->fail($what . ' must be refused');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('expired', $e->getMessage(), $what);
                $this->assertStringContainsString('Thrown away', $e->getMessage());
            }
        }
        $this->postNoBill([['product_id' => $syrup, 'quantity' => 1, 'unit_price' => 100, 'restock' => 0, 'inventory_batch_id' => $oldBatch]]);

        // With a bill: the line left from the batch that has since expired.
        $sale = $this->makeSale($this->branchId, ['created_by_user_id' => $this->userId, 'subtotal' => 100, 'grand_total' => 100, 'paid_amount' => 100]);
        $line = $this->makeSaleLine($sale, $syrup, ['quantity' => 1, 'unit_price' => 100, 'line_total' => 100, 'unit_cost' => 40, 'cost_total' => 40, 'inventory_batch_id' => $oldBatch]);
        try {
            $this->postBill($sale, [['sales_order_line_id' => $line, 'quantity' => 1, 'restock' => 1]]);
            $this->fail('restocking a sale line into its expired batch must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('OLD1 expired', $e->getMessage());
        }
        $this->assertSame($count + 1, DB::table('sales_returns')->count(), 'only the thrown-away return posted');
        $this->postBill($sale, [['sales_order_line_id' => $line, 'quantity' => 1, 'restock' => 0]]);
        $this->assertEqualsWithDelta($stock, $this->stock($syrup), 0.001, 'nothing expired came back into stock');
    }

    public function test_a_return_number_already_taken_draws_another_instead_of_failing(): void
    {
        // Two returns in the same second used to pick the same "SR-<second>-<3 digits>" about once in 900 — the second
        // one failed with a 500. Force the clash: the first number drawn is one that already exists.
        [$p, $sale, $line] = $this->soldThree();
        $taken = $this->postBill($sale, [['sales_order_line_id' => $line, 'quantity' => 1]])->return_no;
        $this->app->instance(\App\Services\Sales\SalesService::class, new class($taken, app(InventoryService::class), app(\App\Services\Kitchen\RecipeConsumptionService::class)) extends \App\Services\Sales\SalesService {
            private array $numbers;

            public function __construct(string $taken, InventoryService $i, \App\Services\Kitchen\RecipeConsumptionService $r)
            {
                parent::__construct($i, $r);
                $this->numbers = [$taken, 'SR-TEST-FRESH-1'];
            }

            public function nextReturnNo(): string
            {
                return array_shift($this->numbers) ?? parent::nextReturnNo();
            }
        });
        $this->app->forgetInstance(SalesReturnService::class);

        $second = $this->postBill($sale, [['sales_order_line_id' => $line, 'quantity' => 1]], SubmissionKey::fresh(), 'h');

        $this->assertSame('SR-TEST-FRESH-1', $second->return_no, 'the taken number was skipped');
        $this->assertSame(2, DB::table('sales_returns')->count());
        $this->assertEqualsWithDelta(2, (float) DB::table('sales_order_lines')->where('id', $line)->value('returned_quantity'), 0.001, 'the line counted once');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{0:int, 1:int, 2:int} product, sale, its line — 3 sold at 100, cost 40 */
    private function soldThree(): array
    {
        $p = $this->stocked('Sealed Item');
        $sale = $this->makeSale($this->branchId, ['created_by_user_id' => $this->userId, 'subtotal' => 300, 'grand_total' => 300, 'paid_amount' => 300]);
        $line = $this->makeSaleLine($sale, $p, ['quantity' => 3, 'unit_price' => 100, 'line_total' => 300, 'unit_cost' => 40, 'cost_total' => 120]);

        return [$p, $sale, $line];
    }

    private function stocked(string $name, array $attrs = [], ?string $batchNo = null, ?string $expiry = null): int
    {
        $p = $this->makeProduct($this->makeCategory(), ['name' => $name, 'is_stock_tracked' => 1, 'is_sellable' => 1,
            'inventory_consumption_method' => 'stock_item', 'default_selling_price' => 100] + $attrs);
        app(InventoryService::class)->postIn(
            branch: Branch::findOrFail($this->branchId), product: Product::findOrFail($p), variant: null,
            quantity: 50, unitCost: 40, movementType: 'opening_stock', referenceType: 'test', batchNo: $batchNo, expiryDate: $expiry,
        );

        return $p;
    }

    private function postBill(int $sale, array $lines, ?string $key = null, ?string $hash = null)
    {
        return app(SalesReturnService::class)->processReturn(
            SalesOrder::findOrFail($sale), $lines, 'test', 'cash', null, $this->userId,
            submissionKey: $key, submissionHash: $hash,
        );
    }

    private function postNoBill(array $lines, ?string $key = null, ?string $hash = null)
    {
        return app(SalesReturnService::class)->processNoBillReturn(
            Branch::findOrFail($this->branchId), $lines, 'test', 'cash', null, $this->userId,
            submissionKey: $key, submissionHash: $hash,
        );
    }

    private function stock(int $productId): float
    {
        return (float) DB::table('stock_balances')->where('product_id', $productId)->sum('quantity_on_hand');
    }

    private function resetKeyCache(): void
    {
        (new \ReflectionProperty(SubmissionKey::class, 'supported'))->setValue(null, []);
    }

    /**
     * Hold a row lock, start every job as its own OS process, wait until all of them are queued behind a lock in this
     * database (proof they are in flight together), then let go and collect what each one says.
     *
     * @return array{0: list<string>, 1: int} outputs, how many were seen waiting at once
     */
    private function race(string $lockSql, array $bindings, array $jobs): array
    {
        $locker = $this->independentTenantPdo();
        $locker->beginTransaction();
        $st = $locker->prepare($lockSql);
        $st->execute($bindings);
        $st->fetchAll();

        $env = array_merge(getenv() ?: [], ['EDGE_TEST_TENANT_DB' => $this->tenantDb, 'APP_ENV' => 'testing']);
        $procs = $pipes = $files = [];
        foreach ($jobs as $i => $job) {
            $files[$i] = tempnam(sys_get_temp_dir(), 'srw');
            file_put_contents($files[$i], json_encode($job));
            $procs[$i] = proc_open([PHP_BINARY, base_path('tests/MySql/Support/sales_return_worker.php'), $files[$i]],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], base_path(), $env);
        }

        $watcher = $this->independentTenantPdo();
        $waiting = $watcher->prepare("SELECT COUNT(*) FROM information_schema.innodb_trx t
            JOIN information_schema.processlist p ON p.id = t.trx_mysql_thread_id
            WHERE t.trx_state = 'LOCK WAIT' AND p.db = ?");
        $queued = 0;
        $deadline = microtime(true) + 40;
        while (microtime(true) < $deadline) {
            $waiting->execute([$this->tenantDb]);
            $queued = max($queued, (int) $waiting->fetchColumn());
            if ($queued >= count($jobs)) {
                break;
            }
            usleep(100_000);
        }
        $locker->commit();

        $out = [];
        foreach (array_keys($jobs) as $i) {
            $out[$i] = trim(stream_get_contents($pipes[$i][1]) . ' ' . stream_get_contents($pipes[$i][2]));
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            proc_close($procs[$i]);
            @unlink($files[$i]);
        }

        return [$out, $queued];
    }

    private function countOut(array $out, string $prefix): int
    {
        return count(array_filter($out, fn ($o) => str_starts_with($o, $prefix)));
    }

    private function firstOut(array $out, string $prefix): string
    {
        return (string) collect($out)->first(fn ($o) => str_starts_with($o, $prefix));
    }
}
