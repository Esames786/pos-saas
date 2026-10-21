<?php

namespace Tests\MySql;

use App\Models\Master\Tenant;
use App\Models\Tenant\InventoryBatch;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductPackUnit;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesOrderLine;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Inventory\InventoryService;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\CounterPacks;
use Database\Seeders\Demos\PharmacyDemoSeeder;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Spatie\Permission\PermissionRegistrar;

/**
 * MEDICAL-COUNTER-1 — the medical-store counter on /pos (Master switch "Pack & batch counter").
 *
 * The promises, through the real routes on the pharmacy demo store: a Strip sells as 10 tablets out of
 * stock and the bill line says "2 Strip"; an expired batch is never sold — and when only expired stock is
 * left the sale is refused and nothing moves; the batch the cashier picked goes first; a held pack line
 * comes back as the same pack and pays as tablets; a returned Strip puts its tablets back into its batch;
 * the preview and the receipt print "2 Strip = 20 Tablet · B:…"; and with the switch OFF a posted pack
 * is ignored, so every other counter sells exactly as it does today.
 */
class MedicalCounterMySqlTest extends MySqlTenantTestCase
{
    private const CODE = 'medctest';

    private const ON = ['product_screen_v2' => true, 'packs' => true, 'medical_counter' => true];

    private static bool $built = false;

    private string $host;
    private string $storeDb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
        $this->host = self::CODE . '.' . config('tenancy.tenant_base_domain');
        $this->storeDb = $this->tenantDb . '_medc';

        if (! self::$built) {
            $this->createStoreDatabase();
            DB::setDefaultConnection('master');
            (new MasterSeeder)->run();
            Artisan::call('system:routes-sync');
            $this->seedMasterTenant();
            $this->useStoreDb();
            $this->buildStore();
            self::$built = true;
        } else {
            $this->useStoreDb();
        }
        $this->setFeatures(self::ON);
    }

    public function test_the_counter_shows_packs_batches_and_rx_only_with_the_switch_and_its_scripts_parse(): void
    {
        $pos = $this->owner()->get($this->url('/pos'))->assertOk()->getContent();
        $this->assertStringContainsString('const medicalCounter = true', $pos);
        $this->assertStringContainsString('"med":{"packs":[', $pos, 'every item carries its packs');
        $this->assertStringContainsString('"rx":true', $pos, 'an antibiotic asks for the prescription');
        $this->assertStringContainsString('id="med-rx-box"', $pos);
        $this->assertStringContainsString('name="notes" id="med-notes"', $pos);
        $this->assertStringContainsString('>Items</h2>', $pos);
        $this->assertStringContainsString('id="review-pay-btn"', $pos, 'the same Review & Pay window');
        $this->assertStringContainsString('id="hold-sale-btn"', $pos);
        $this->assertStringContainsString('id="save-draft-btn"', $pos);
        // the shortcut list is one click (or Ctrl+/) away, and says the easy keys
        $this->assertStringContainsString('id="medKeysModal"', $pos);
        $this->assertStringContainsString('data-med-keys title="All shortcut keys', $pos, 'the top-bar Shortcuts button');
        foreach (['Pay now (exact cash) and print', '<td>Hold sale</td>', 'Held orders — recall (Ctrl+L too)', 'Remove the item', 'A bill held at another counter comes up here'] as $says) {
            $this->assertStringContainsString($says, $pos);
        }
        $this->assertStringContainsString('id="med-held-search"', $pos, 'Held Orders has its search / scan box');
        $this->assertSame(2, DB::connection('tenant')->table('receipt_layout_settings')->whereIn('document_type', ['receipt', 'kot'])
            ->where('show_bill_barcode', 1)->count(), 'the pharmacy demo prints the bill barcode on its bill and KOT (and keeps it after the nightly reset)');
        $this->assertSame(1, substr_count($pos, 'data-med-keys title="All shortcut keys'), 'ONE Shortcuts button, in the top bar (owner: no second key line)');
        $this->assertStringNotContainsString('id="med-keys-btn"', $pos, 'the key line beside the list is gone');
        $this->assertStringContainsString("h: 'held'", $pos, 'Ctrl+H opens the held orders');
        $this->assertStringNotContainsString("r: 'held'", $pos, 'Ctrl+R stays the browser refresh');
        $this->assertScriptsParse($pos);

        // RX-BRANCH-SETTING-1: the branch decides whether the counter asks for the prescription.
        $b = (string) $this->branchId();
        $this->assertStringContainsString('const medAskRx = {"' . $b . '":true}', $pos, 'asks by default');
        $edit = $this->owner()->get($this->url('/branches/' . $b . '/edit'))->assertOk()->getContent();
        $this->assertStringContainsString('id="ask_prescription"', $edit, 'the switch is on the branch screen of a pharmacy tenant');
        DB::connection('tenant')->table('branches')->where('id', $b)->update(['ask_prescription' => 0]);
        try {
            $noAsk = $this->owner()->get($this->url('/pos'))->assertOk()->getContent();
            $this->assertStringContainsString('const medAskRx = {"' . $b . '":false}', $noAsk, 'switched off: the counter does not ask');
        } finally {
            DB::connection('tenant')->table('branches')->where('id', $b)->update(['ask_prescription' => 1]);
        }

        $this->setFeatures(['product_screen_v2' => true, 'packs' => true]);
        $off = $this->owner()->get($this->url('/pos'))->assertOk()->getContent();
        $this->assertStringContainsString('const medicalCounter = false', $off);
        $this->assertStringNotContainsString('"med":{', $off, 'switch off = today\'s payload');
        $this->assertStringNotContainsString('id="med-rx-box"', $off);
        $this->assertStringNotContainsString('.med-list-h', $off, 'no counter styles either');
        $this->assertStringNotContainsString('id="med-notes"', $off);
        $this->assertStringNotContainsString('id="medKeysModal"', $off, 'no new keys or windows on any other counter');
        $this->assertStringNotContainsString('id="med-held-search"', $off);
        $this->assertStringNotContainsString('data-med-keys title=', $off, 'no Shortcuts button on any other counter');
        $this->assertStringContainsString('>Products</h2>', $off);
        $this->assertStringContainsString('const medAskRx = {}', $off, 'no prescription setting read on any other counter');
        $this->assertStringNotContainsString('id="ask_prescription"', $this->owner()->get($this->url('/branches/' . $this->branchId() . '/edit'))->getContent(),
            'and no prescription switch on their branch screen');
        $this->assertScriptsParse($off);
    }

    public function test_strips_and_loose_tablets_leave_as_tablets_and_never_from_an_expired_batch(): void
    {
        [$p, $strip] = $this->panadol();
        $expired = $this->expiredBatch($p, 30);
        $before = $this->stock($p);
        $expiredBefore = $this->batchQty($expired);

        $sale = $this->sell([
            ['product_id' => $p->id, 'quantity' => 2, 'unit_price' => 52.5, 'pack_unit_id' => $strip->id],
            ['product_id' => $p->id, 'quantity' => 3, 'unit_price' => 5.25],
        ], 120.75);
        $this->assertTrue($sale->isSuccessful(), 'sale refused: ' . Str::limit($sale->getContent(), 300));

        $order = SalesOrder::where('status', 'paid')->latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(120.75, (float) $order->grand_total, 0.001, 'the money is what the counter showed');
        $this->assertEqualsWithDelta($before - 23, $this->stock($p), 0.001, '2 Strip + 3 Tablet = 23 tablets');
        $this->assertEqualsWithDelta($expiredBefore, $this->batchQty($expired), 0.001, 'the expired batch was skipped');

        [$packLine, $loose] = $order->lines()->orderBy('id')->get()->all();
        $this->assertEquals(2, (float) $packLine->quantity, 'the bill line is in strips');
        $this->assertSame(10, (int) $packLine->pack_factor);
        $this->assertEquals(20, (float) $packLine->base_quantity);
        $this->assertSame('Strip', $packLine->unit_code);
        $this->assertNotNull($packLine->inventory_batch_id);
        $this->assertStringNotContainsString($expired->batch_no, (string) $packLine->batch_note);
        $this->assertNull($loose->base_quantity, 'a loose tablet is today\'s line');

        // The bill says it: "2 Strip = 20 Tablet · B:PNL… EXP:mm/yy".
        $text = CounterPacks::receiptLine($packLine->fresh(['product.unit']));
        $this->assertStringStartsWith('2 Strip = 20 Tablet · B:', $text);
        $this->assertStringContainsString('EXP:', $text);
        $this->assertNull(CounterPacks::receiptLine($loose->fresh()) === null ? null : (str_contains((string) CounterPacks::receiptLine($loose->fresh()), '=') ? 'pack' : null),
            'a loose tablet line prints its batch only, never "= …"');
    }

    public function test_only_expired_stock_left_refuses_the_sale_and_nothing_moves(): void
    {
        $p = $this->product('Expired Only Tablet');
        $batch = $this->expiredBatch($p, 40);
        $orders = SalesOrder::count();

        $sale = $this->sell([['product_id' => $p->id, 'quantity' => 1, 'unit_price' => 5]], 5);

        $this->assertFalse($sale->isSuccessful(), 'an expired batch is never sold');
        $this->assertStringContainsString('left that is not expired', $sale->getContent());
        $this->assertStringContainsString('more has expired', $sale->getContent());
        $this->assertEqualsWithDelta(40, $this->batchQty($batch), 0.001, 'nothing left the shelf');
        $this->assertSame(0, SalesOrder::where('status', 'paid')->whereHas('lines', fn ($q) => $q->where('product_id', $p->id))->count(), 'no sale was saved');
        $this->assertSame($orders, SalesOrder::count());

        // Switch off: today's rule (the branch says whether stock may go negative) — the counter is unchanged.
        $this->setFeatures(['product_screen_v2' => true, 'packs' => true]);
        $c = DB::connection('tenant');
        $c->table('branches')->update(['allow_negative_stock' => 1]);
        try {
            $old = $this->sell([['product_id' => $p->id, 'quantity' => 1, 'unit_price' => 5]], 5);
            $this->assertTrue($old->isSuccessful(), 'switch off sells as before: ' . Str::limit($old->getContent(), 200));
        } finally {
            $c->table('branches')->update(['allow_negative_stock' => 0]);
        }
    }

    public function test_with_the_switch_off_a_posted_pack_is_ignored(): void
    {
        [$p, $strip] = $this->panadol();
        $this->setFeatures(['product_screen_v2' => true, 'packs' => true]);
        $before = $this->stock($p);

        $sale = $this->sell([['product_id' => $p->id, 'quantity' => 2, 'unit_price' => 5.25, 'pack_unit_id' => $strip->id]], 10.5);
        $this->assertTrue($sale->isSuccessful(), Str::limit($sale->getContent(), 300));

        $line = SalesOrder::where('status', 'paid')->latest('id')->firstOrFail()->lines()->firstOrFail();
        $this->assertNull($line->pack_unit_id);
        $this->assertNull($line->base_quantity);
        $this->assertNull($line->batch_note);
        $this->assertEqualsWithDelta($before - 2, $this->stock($p), 0.001, 'two tablets, as today');
    }

    public function test_a_held_pack_line_comes_back_as_the_same_pack_and_pays_from_the_picked_batch(): void
    {
        [$p, $strip] = $this->panadol();
        $batches = $this->liveBatches($p);
        $this->assertGreaterThanOrEqual(2, $batches->count(), 'Panadol has two batches in the demo');
        $later = $batches->last();   // NOT the one FEFO would take
        $laterBefore = $this->batchQty($later);

        $held = $this->owner()->postJson($this->url('/held-sales'), $this->payload([
            ['product_id' => $p->id, 'quantity' => 1, 'unit_price' => 52.5, 'pack_unit_id' => $strip->id, 'inventory_batch_id' => $later->id],
        ]) + ['save_as_draft' => '0']);
        $this->assertTrue($held->isSuccessful(), 'hold refused: ' . Str::limit($held->getContent(), 300));

        $sale = SalesOrder::where('status', 'held')->latest('id')->firstOrFail();
        $line = $sale->lines()->firstOrFail();
        $this->assertSame((int) $strip->id, (int) $line->pack_unit_id);
        $this->assertEquals(10, (float) $line->base_quantity);
        $this->assertSame((int) $later->id, (int) $line->inventory_batch_id, 'the picked batch is kept');
        $this->assertSame('Strip', $line->unit_code);
        $this->assertSame($laterBefore, $this->batchQty($later), 'holding takes nothing from stock');

        $list = $this->owner()->getJson($this->url('/api/pos/held-sales?branch_id=' . $this->branchId()))->assertOk()->json('sales');
        $recalled = collect($list)->firstWhere('id', $sale->id);
        $this->assertNotNull($recalled);
        $this->assertSame((int) $strip->id, $recalled['lines'][0]['pack_unit_id'], 'recall brings the pack back');
        $this->assertSame((int) $later->id, $recalled['lines'][0]['inventory_batch_id']);

        $pay = $this->sell([[
            'sales_order_line_id' => $line->id, 'product_id' => $p->id, 'quantity' => 1, 'unit_price' => 52.5,
            'pack_unit_id' => $strip->id, 'inventory_batch_id' => $later->id,
        ]], 52.5, ['held_sale_id' => $sale->id]);
        $this->assertTrue($pay->isSuccessful(), 'pay refused: ' . Str::limit($pay->getContent(), 300));
        $this->assertSame('paid', $sale->fresh()->status);
        $this->assertEqualsWithDelta($laterBefore - 10, $this->batchQty($later), 0.001, '10 tablets left the PICKED batch');

        // A batch that is not this product's (or is expired) is never "picked" — FEFO as always.
        $this->assertNull(CounterPacks::preferredBatch($p, $this->branchId(), $this->expiredBatch($p, 5)->id));
        $other = $this->product('Other Medicine');
        $this->assertNull(CounterPacks::preferredBatch($other, $this->branchId(), $later->id));
    }

    public function test_a_returned_strip_puts_its_tablets_back_into_its_batch(): void
    {
        [$p, $strip] = $this->panadol();
        $sale = $this->sell([['product_id' => $p->id, 'quantity' => 2, 'unit_price' => 52.5, 'pack_unit_id' => $strip->id]], 105);
        $this->assertTrue($sale->isSuccessful(), Str::limit($sale->getContent(), 300));
        $order = SalesOrder::where('status', 'paid')->latest('id')->firstOrFail();
        $line = $order->lines()->firstOrFail();
        $batch = InventoryBatch::findOrFail($line->inventory_batch_id);
        $before = $this->batchQty($batch);

        $this->owner()->post($this->url('/sales-returns'), [
            'sales_order_id' => $order->id, 'reason' => 'Doctor changed the medicine', 'refund_method' => 'cash', 'refund_amount' => 52.5,
            'lines' => [['sales_order_line_id' => $line->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $this->assertEquals(1, (float) $line->fresh()->returned_quantity, 'one Strip came back');
        $this->assertEqualsWithDelta($before + 10, $this->batchQty($batch), 0.001, 'its 10 tablets went back into the same batch');
        $cost = (float) DB::connection('tenant')->table('stock_ledgers')->where('reference_type', 'sales_return')->where('product_id', $p->id)->orderByDesc('id')->value('unit_cost');
        $this->assertEqualsWithDelta((float) $line->fresh()->unit_cost / 10, $cost, 0.0001, 'at the cost of one tablet');
    }

    public function test_the_bill_preview_prints_the_pack_and_the_batch(): void
    {
        [$p, $strip] = $this->panadol();
        $res = $this->owner()->postJson($this->url('/api/pos/bill-preview'), [
            'branch_id' => $this->branchId(), 'terminal_id' => $this->terminal()->id, 'order_type' => 'takeaway',
            'lines' => [['product_id' => $p->id, 'quantity' => 1, 'unit_price' => 52.5, 'pack_unit_id' => $strip->id, 'batch_note' => 'PNLTEST EXP:09/28']],
        ])->assertOk();
        $html = (string) $res->json('html');
        $this->assertStringContainsString('1 Strip = 10 Tablet · B:PNLTEST EXP:09/28', $html);
        $this->assertStringNotContainsString('aria-label="Barcode', $html, 'switch off: no barcode');

        // BILL-BARCODE-1: with the bill switch on, a HELD order's preview carries its code; a new cart has none.
        DB::connection('tenant')->table('receipt_layout_settings')->updateOrInsert(
            ['branch_id' => $this->branchId(), 'document_type' => 'receipt'],
            ['paper_size' => '80mm', 'is_active' => 1, 'show_bill_barcode' => 1, 'created_at' => now(), 'updated_at' => now()]);
        try {
            $held = $this->owner()->postJson($this->url('/held-sales'), $this->payload([['product_id' => $p->id, 'quantity' => 1, 'unit_price' => 52.5, 'pack_unit_id' => $strip->id]]) + ['save_as_draft' => '0']);
            $this->assertTrue($held->isSuccessful(), Str::limit($held->getContent(), 200));
            $heldId = (int) SalesOrder::where('status', 'held')->latest('id')->value('id');
            $line = [['product_id' => $p->id, 'quantity' => 1, 'unit_price' => 52.5, 'pack_unit_id' => $strip->id]];
            $base = ['branch_id' => $this->branchId(), 'terminal_id' => $this->terminal()->id, 'order_type' => 'takeaway', 'lines' => $line];
            $withHeld = (string) $this->owner()->postJson($this->url('/api/pos/bill-preview'), $base + ['held_sale_id' => $heldId])->assertOk()->json('html');
            $this->assertStringContainsString('aria-label="Barcode BL' . $heldId . '"', $withHeld, 'the held bill previews with its own code');
            $newCart = (string) $this->owner()->postJson($this->url('/api/pos/bill-preview'), $base)->assertOk()->json('html');
            $this->assertStringNotContainsString('aria-label="Barcode', $newCart, 'a cart that was never held has no bill code');
        } finally {
            DB::connection('tenant')->table('receipt_layout_settings')->where('document_type', 'receipt')->update(['show_bill_barcode' => 0]);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════════

    private function sell(array $lines, float $total, array $extra = [])
    {
        return $this->owner()->postJson($this->url('/pos'), $extra + $this->payload($lines) + [
            'kot_print_intent' => 'skip', 'receipt_print_intent' => 'skip',
            'payments' => [['payment_method_id' => (int) DB::connection('tenant')->table('payment_methods')->where('method_type', 'cash')->value('id'),
                'amount' => $total, 'tendered_amount' => ceil($total / 100) * 100]],
        ]);
    }

    private function payload(array $lines): array
    {
        return [
            'branch_id' => $this->branchId(), 'terminal_id' => $this->terminal()->id, 'order_type' => 'takeaway',
            'discount_type' => 'none', 'client_uuid' => (string) Str::uuid(), 'lines' => $lines,
        ];
    }

    /** @return array{0: Product, 1: ProductPackUnit} */
    private function panadol(): array
    {
        $p = Product::where('sku', 'PH-PNL500')->firstOrFail();
        $strip = ProductPackUnit::where('product_id', $p->id)->where('name', 'Strip')->firstOrFail();

        return [$p, $strip];
    }

    private function product(string $name): Product
    {
        $panadol = Product::where('sku', 'PH-PNL500')->firstOrFail();
        $sku = 'MC-' . strtoupper(substr(md5($name), 0, 6));
        $p = Product::create(['sku' => $sku, 'name' => $name, 'slug' => Str::slug($name), 'unit_id' => $panadol->unit_id, 'category_id' => $panadol->category_id,
            'product_type' => 'simple', 'product_kind' => 'sale_item', 'inventory_consumption_method' => 'stock_item', 'is_sellable' => true, 'is_pos_visible' => true,
            'is_purchasable' => true, 'is_stock_tracked' => true, 'has_expiry' => true, 'requires_batch' => true, 'default_selling_price' => 5, 'status' => 'active']);
        \App\Models\Tenant\ProductVariant::create(['product_id' => $p->id, 'sku' => $sku, 'name' => $name, 'is_default' => true, 'is_active' => true]);

        return $p->fresh();
    }

    private function expiredBatch(Product $p, float $qty): InventoryBatch
    {
        $no = 'EXP-' . strtoupper(Str::random(5));
        app(InventoryService::class)->postIn(
            branch: \App\Models\Tenant\Branch::findOrFail($this->branchId()), product: $p, variant: $p->defaultVariant,
            quantity: $qty, unitCost: 4, movementType: 'adjustment_in', referenceType: 'test', batchNo: $no,
            expiryDate: now()->subMonths(2)->endOfMonth()->toDateString(), notes: 'expired stock for the test',
        );

        return InventoryBatch::where('product_id', $p->id)->where('batch_no', $no)->firstOrFail();
    }

    private function liveBatches(Product $p)
    {
        return InventoryBatch::where('product_id', $p->id)->whereDate('expiry_date', '>=', today())
            ->whereIn('id', DB::connection('tenant')->table('stock_balances')->where('product_id', $p->id)->where('quantity_on_hand', '>', 10)->pluck('inventory_batch_id'))
            ->orderBy('expiry_date')->get();
    }

    private function stock(Product $p): float
    {
        return (float) DB::connection('tenant')->table('stock_balances')->where('product_id', $p->id)->sum('quantity_on_hand');
    }

    private function batchQty(InventoryBatch $b): float
    {
        return (float) DB::connection('tenant')->table('stock_balances')->where('inventory_batch_id', $b->id)->sum('quantity_on_hand');
    }

    private function branchId(): int
    {
        return (int) DB::connection('tenant')->table('branches')->orderBy('id')->value('id');
    }

    private function terminal(): Terminal
    {
        return Terminal::where('code', 'C1')->firstOrFail();
    }

    /** Every inline script on the page must parse — a broken one leaves the counter dead with no error. */
    private function assertScriptsParse(string $html): void
    {
        $node = getenv('NODE_BINARY') ?: 'D:/laragon2/bin/nodejs/node-v20.20.1-win-x64/node.exe';
        if (! is_file($node)) {
            $this->addToAssertionCount(1);

            return;   // no node on this machine — the render checks above still ran
        }
        preg_match_all('#<script(?![^>]*\bsrc=)([^>]*)>(.*?)</script>#si', $html, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m);
        foreach ($m as $i => $s) {
            if (preg_match('/type\s*=\s*["\'](?!text\/javascript|module)[^"\']+/i', $s[1])) {
                continue;
            }
            $file = tempnam(sys_get_temp_dir(), 'pos') . '.js';
            file_put_contents($file, $s[2]);
            exec('"' . $node . '" --check "' . $file . '" 2>&1', $out, $code);
            @unlink($file);
            $this->assertSame(0, $code, "inline script #{$i} does not parse:\n" . implode("\n", array_slice($out, 0, 8)));
            $out = [];
        }
    }

    private function url(string $path): string
    {
        return 'http://' . $this->host . $path;
    }

    private function owner()
    {
        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->actingAs(User::on('tenant')->where('email', 'owner@pharmacydemo.com')->firstOrFail(), 'tenant');
    }

    private function setFeatures(array $features): void
    {
        DB::connection('master')->table('tenants')->where('tenant_code', self::CODE)->update(['primary_business' => 'pharmacy', 'features' => json_encode($features)]);
        if (app()->bound('tenant')) {
            app('tenant')->features = $features;
        }
    }

    private function useStoreDb(): void
    {
        config(['database.connections.tenant.database' => $this->storeDb]);
        DB::purge('tenant');
    }

    private function createStoreDatabase(): void
    {
        if (stripos($this->storeDb, 'test') === false) {
            throw new \RuntimeException('the test database name must contain "test"');
        }
        $config = config('database.connections.tenant');
        $pdo = new PDO("mysql:host={$config['host']};port={$config['port']}", $config['username'], $config['password'] ?? '');
        $pdo->exec('DROP DATABASE IF EXISTS `' . $this->storeDb . '`');
        $pdo->exec('CREATE DATABASE `' . $this->storeDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->useStoreDb();
        if (Artisan::call('migrate:fresh', ['--database' => 'tenant', '--path' => 'database/migrations/tenant', '--force' => true]) !== 0) {
            throw new \RuntimeException('migrations failed: ' . Artisan::output());
        }
    }

    private function seedMasterTenant(): void
    {
        $m = DB::connection('master');
        if ($old = $m->table('tenants')->where('tenant_code', self::CODE)->value('id')) {
            foreach (['tenant_domains', 'tenant_databases', 'subscriptions', 'tenant_setting_changes'] as $t) {
                $m->table($t)->where('tenant_id', $old)->delete();
            }
            $m->table('tenants')->where('id', $old)->delete();
        }
        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        $m->table('tenant_databases')->where('db_database', $this->storeDb)->delete();

        $id = $m->table('tenants')->insertGetId([
            'tenant_code' => self::CODE, 'business_name' => 'Medical Counter Test', 'owner_name' => 'Demo Owner', 'owner_email' => 'owner@pharmacydemo.com',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0, 'primary_business' => 'pharmacy', 'features' => json_encode(self::ON),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_databases')->insert([
            'tenant_id' => $id, 'db_connection' => 'tenant', 'db_host' => config('database.connections.tenant.host'),
            'db_port' => (int) config('database.connections.tenant.port'), 'db_database' => $this->storeDb,
            'db_username' => config('database.connections.tenant.username'), 'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_domains')->insert([
            'tenant_id' => $id, 'domain' => $this->host, 'is_primary' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('subscriptions')->insert([
            'tenant_id' => $id, 'plan_id' => $m->table('plans')->where('code', 'pharmacy_demo')->value('id'),
            'status' => 'active', 'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function buildStore(): void
    {
        $tenant = Tenant::on('master')->where('tenant_code', self::CODE)->firstOrFail();
        app()->instance('tenant', $tenant);
        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        (new \ReflectionMethod(TenantProvisioner::class, 'seedTenantBaseData'))->invoke(app(TenantProvisioner::class), $tenant, 'demo1234');
        app(PharmacyDemoSeeder::class)->run();
    }
}
