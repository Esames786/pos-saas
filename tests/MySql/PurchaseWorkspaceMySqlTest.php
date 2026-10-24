<?php

namespace Tests\MySql;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\Product;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\PurchasingSetting;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierPayment;
use App\Models\Tenant\User;
use App\Services\Finance\SupplierPayableService;
use App\Support\SubmissionKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\IsolatedPharmacyStore;

/**
 * W3/W4 S4 (24 Oct) — the purchase workspace through its real routes:
 *  - receive now, invoice later: one Goods Receipt, no invoice, no payment;
 *  - receive + invoice + pay: the three documents, the supplier balance, cash and the GL, each once;
 *  - goods already received: the invoice (and payment) only — the stock is never received again;
 *  - a payment is checked against the invoice's own balance after Supplier Credit, inside the Post: paying more is
 *    refused and NOTHING of the Post is kept — not the receipt, not the invoice, not the stock;
 *  - the same Post twice is one purchase; the same key with other lines is refused;
 *  - every step needs its own screen's permission, checked on the Post; no switch → no screen.
 */
class PurchaseWorkspaceMySqlTest extends MySqlTenantTestCase
{
    use IsolatedPharmacyStore;

    private const STORE_CODE = 'pwtest';
    private const STORE_SUFFIX = '_pw';
    private const STORE_FEATURES = ['product_screen_v2' => true, 'packs' => true, 'purchasing_v2' => true, 'purchase_workspace' => true];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStore();
    }

    public function test_receive_now_invoice_later_posts_only_the_goods_receipt(): void
    {
        $soap = $this->product('PW Soap Later', $this->piece()->id, ['default_selling_price' => 90]);
        [$grns, $bills, $pays] = $this->counts();

        $this->page()->assertOk()->assertSee('Goods arriving now')->assertSee('Goods already received');
        $res = $this->workspace($this->arriving($soap, '10', '60'), ['record_invoice' => '0'])->assertOk()->json();
        $this->assertTrue($res['ok']);
        $this->assertSame(['Goods Receipt'], array_column($res['documents'], 'kind'));
        $this->assertSame([$grns + 1, $bills, $pays], $this->counts(), 'a receipt only');
        $this->assertEqualsWithDelta(10, $this->stock($soap), 0.001);
        $grn = GoodsReceipt::latest('id')->firstOrFail();
        $this->assertSame('posted', $grn->status);
        $this->assertFalse(PurchaseBill::where('goods_receipt_id', $grn->id)->exists(), 'its invoice can come later');
    }

    public function test_receive_invoice_and_pay_post_together_with_the_supplier_cash_and_gl_each_once(): void
    {
        $supplier = $this->newSupplier('PW Supplier Together');
        $rice = $this->product('PW Rice Together', $this->kg()->id, ['default_selling_price' => 300]);
        $cashBefore = $this->cashMovements();
        $journalsBefore = DB::connection('tenant')->table('journal_entries')->count();

        $res = $this->workspace($this->arriving($rice, '20', '200', $supplier), ['record_invoice' => '1',
            'invoice' => ['supplier_invoice_no' => 'INV-77', 'bill_date' => now()->toDateString()], 'pay' => '1',
            'payment' => $this->paying(1500)])->assertOk()->json();
        $this->assertSame(['Goods Receipt', 'Supplier Invoice', 'Payment'], array_column($res['documents'], 'kind'));

        $bill = PurchaseBill::where('supplier_id', $supplier->id)->firstOrFail();
        $this->assertSame('INV-77', $bill->supplier_invoice_no);
        $this->assertEqualsWithDelta(4000, (float) $bill->grand_total, 0.01, 'the invoice comes from the goods: 20 kg × 200');
        $this->assertEqualsWithDelta(2500, (float) $bill->fresh()->balance_due, 0.01, 'paid 1,500 of it');
        $this->assertEqualsWithDelta(2500, (float) $supplier->fresh()->current_balance, 0.01, 'the supplier is owed the rest');
        $this->assertSame(1, SupplierPayment::where('purchase_bill_id', $bill->id)->count());
        $this->assertSame($cashBefore + 1, $this->cashMovements(), 'cash moved once');
        // A Goods Receipt posts no journal of its own: purchases reach the GL with the invoice (stock / payable).
        $this->assertSame($journalsBefore + 2, DB::connection('tenant')->table('journal_entries')->count(), 'the invoice and the payment each posted to the GL once');
        $this->assertEqualsWithDelta(20, $this->stock($rice), 0.001);
    }

    public function test_goods_already_received_are_invoiced_and_paid_without_receiving_stock_again(): void
    {
        $supplier = $this->newSupplier('PW Supplier Earlier');
        $tea = $this->product('PW Tea Earlier', $this->piece()->id, ['default_selling_price' => 50]);
        $this->owner()->postJson($this->url('/goods-receipts'), $this->arriving($tea, '12', '30', $supplier))->assertOk();
        $grn = GoodsReceipt::where('supplier_id', $supplier->id)->latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(12, $this->stock($tea), 0.001);
        [$grns] = $this->counts();

        $this->owner()->getJson($this->url('/purchases/receipts/' . $grn->id))->assertOk()->assertJsonPath('preview.received', 360);
        $res = $this->workspace(['start' => 'received', 'goods_receipt_id' => $grn->id, 'invoice' => ['bill_date' => now()->toDateString()],
            'pay' => '1', 'payment' => $this->paying(360)])->assertOk()->json();
        $this->assertSame(['Supplier Invoice', 'Payment'], array_column($res['documents'], 'kind'));
        $this->assertSame($grns, $this->counts()[0], 'no new goods receipt');
        $this->assertEqualsWithDelta(12, $this->stock($tea), 0.001, 'its stock is not received again');
        $this->assertEqualsWithDelta(0, (float) PurchaseBill::where('goods_receipt_id', $grn->id)->value('balance_due'), 0.01);

        // One receipt, one invoice: the same receipt again is refused, and nothing more is posted.
        $again = $this->workspace(['start' => 'received', 'goods_receipt_id' => $grn->id, 'invoice' => ['bill_date' => now()->toDateString()]])
            ->assertStatus(422)->json();
        $this->assertStringContainsString('already has its invoice', $again['message']);
        $this->assertSame(1, PurchaseBill::where('goods_receipt_id', $grn->id)->count());
    }

    public function test_paying_more_than_the_invoice_owes_after_supplier_credit_is_refused_and_nothing_of_the_post_is_kept(): void
    {
        PurchasingSetting::tenantDefault()->update(['supplier_running_account' => true]);
        $supplier = $this->newSupplier('PW Supplier Credit');
        // The supplier was paid 1,000 ahead (an advance under Payments).
        app(SupplierPayableService::class)->recordPayment(['supplier_id' => $supplier->id, 'branch_id' => $this->branchId(), 'payment_date' => now()->toDateString(),
            'amount' => 1000, 'payment_method' => 'cash', 'cash_bank_account_id' => $this->cashAccount()], null);
        $oil = $this->product('PW Oil Credit', $this->litre()->id, ['default_selling_price' => 600]);
        [$grns, $bills, $pays] = $this->counts();

        // Invoice 5 L × 500 = 2,500; Supplier Credit pays 1,000 of it; 2,500 is more than the 1,500 left.
        $res = $this->workspace($this->arriving($oil, '5', '500', $supplier), ['record_invoice' => '1', 'invoice' => ['bill_date' => now()->toDateString()], 'pay' => '1',
            'payment' => $this->paying(2500)])->assertStatus(422)->json();
        $this->assertSame('payment', $res['step']);
        $this->assertStringContainsString('Rs 1,500.00 after Supplier Credit', $res['message']);
        $this->assertSame([$grns, $bills, $pays], $this->counts(), 'nothing of the Post is kept — not the receipt, not the invoice');
        $this->assertEqualsWithDelta(0, $this->stock($oil), 0.001, 'and no stock came in');

        // Up to what is left is fine.
        $this->workspace($this->arriving($oil, '5', '500', $supplier), ['record_invoice' => '1', 'invoice' => ['bill_date' => now()->toDateString()], 'pay' => '1',
            'payment' => $this->paying(1500)])->assertOk();
        $bill = PurchaseBill::where('supplier_id', $supplier->id)->latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(0, (float) $bill->balance_due, 0.01);
        $this->assertEqualsWithDelta(0, (float) $supplier->fresh()->current_balance, 0.01);
    }

    public function test_the_same_post_twice_is_one_purchase_and_the_same_key_with_other_lines_is_refused(): void
    {
        $salt = $this->product('PW Salt Twice', $this->kg()->id, ['default_selling_price' => 60]);
        $key = SubmissionKey::fresh();
        $post = fn (string $qty) => $this->workspace($this->arriving($salt, $qty, '40') + ['submission_key' => $key],
            ['record_invoice' => '1', 'invoice' => ['bill_date' => now()->toDateString()], 'pay' => '1', 'payment' => $this->paying(100)]);
        [$grns, $bills, $pays] = $this->counts();

        $first = $post('10')->assertOk()->json();
        $again = $post('10')->assertOk()->json();
        $this->assertTrue($again['already']);
        $this->assertSame(array_column($first['documents'], 'number'), array_column($again['documents'], 'number'), 'the first Post\'s three documents come back');
        $this->assertSame([$grns + 1, $bills + 1, $pays + 1], $this->counts(), 'one of each');
        $this->assertEqualsWithDelta(10, $this->stock($salt), 0.001);

        $other = $post('11')->assertStatus(422)->json();
        $this->assertStringContainsString('already posted as goods receipt', $other['message']);
        $this->assertNotEmpty($other['next_key']);
        $this->assertSame([$grns + 1, $bills + 1, $pays + 1], $this->counts());
    }

    public function test_every_step_needs_its_own_permission_and_a_payment_needs_an_invoice(): void
    {
        $rice = $this->product('PW Rice Roles', $this->kg()->id, ['default_selling_price' => 300]);
        [$grns, $bills, $pays] = $this->counts();

        $res = $this->workspace($this->arriving($rice, '5', '100'), ['record_invoice' => '0', 'pay' => '1', 'payment' => $this->paying(100)])->assertStatus(422)->json();
        $this->assertStringContainsString('record the invoice too', $res['message']);

        $storekeeper = $this->roleUser('PW Storekeeper', ['tenant.purchases.workspace', 'tenant.purchases.workspace.store', 'tenant.purchases.workspace.receipt', 'tenant.goods-receipts.store']);
        $page = $this->actingAs($storekeeper, 'tenant')->call('GET', $this->url('/purchases/new'))->assertOk()->getContent();
        $this->assertStringContainsString('Recording supplier invoices is not part of your role', $page);
        $res = $this->actingAs($storekeeper, 'tenant')->postJson($this->url('/purchases'), array_merge($this->arriving($rice, '5', '100'), ['record_invoice' => '1',
            'invoice' => ['bill_date' => now()->toDateString()]]))->assertStatus(422)->json();
        $this->assertSame('invoice', $res['step']);
        $this->assertStringContainsString('You cannot record the Supplier Invoice', $res['message']);
        $this->assertSame([$grns, $bills, $pays], $this->counts(), 'refused before anything was written');
        $this->actingAs($storekeeper, 'tenant')->postJson($this->url('/purchases'), $this->arriving($rice, '5', '100') + ['record_invoice' => '0'])->assertOk();
        $this->assertSame([$grns + 1, $bills, $pays], $this->counts(), 'the part the role includes posts');

        $clerk = $this->roleUser('PW Invoice Clerk', ['tenant.purchases.workspace', 'tenant.purchases.workspace.store', 'tenant.goods-receipts.store', 'tenant.purchase-bills.store']);
        $res = $this->actingAs($clerk, 'tenant')->postJson($this->url('/purchases'), array_merge($this->arriving($rice, '5', '100'), [
            'record_invoice' => '1', 'invoice' => ['bill_date' => now()->toDateString()], 'pay' => '1', 'payment' => $this->paying(100)]))->assertStatus(422)->json();
        $this->assertSame('payment', $res['step']);
        $this->assertSame([$grns + 1, $bills, $pays], $this->counts());
    }

    public function test_without_the_switch_there_is_no_workspace(): void
    {
        $this->setFeatures(['product_screen_v2' => true, 'packs' => true, 'purchasing_v2' => true]);
        $this->page()->assertNotFound();
        $this->owner()->postJson($this->url('/purchases'), ['start' => 'new'])->assertNotFound();
        $this->setFeatures(['product_screen_v2' => true, 'purchase_workspace' => true]);
        $this->page()->assertNotFound();   // it needs the new purchase screens too
    }

    /** S5: one "Purchases" item and a "New purchase" tab where the workspace is on — by role; today's menu where it is off. */
    public function test_the_compact_purchasing_navigation_follows_the_switch_and_the_role(): void
    {
        $html = $this->page()->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#href="[^"]*/purchases/new" class="[^"]*" id="sidebar-purchases"#', $html, 'one Purchases item, opening the workspace');
        $this->assertStringNotContainsString('<span>Supplier Payments</span>', $html, 'not the long submenu');
        $this->assertMatchesRegularExpression('#class="pur-tabs".*?New purchase.*?Suppliers#s', $html, '"New purchase" is the first tab');

        $receiver = $this->roleUser('PW Receipts Only', ['tenant.goods-receipts.index', 'tenant.goods-receipts.create']);
        $page = $this->actingAs($receiver, 'tenant')->call('GET', $this->url('/goods-receipts'))->assertOk()->getContent();
        $this->assertStringNotContainsString('New purchase', $page, 'no workspace permission: no tab');
        $this->assertMatchesRegularExpression('#href="[^"]*/goods-receipts" class="[^"]*" id="sidebar-purchases"#', $page, 'Purchases opens the first page the role may open');

        $this->setFeatures(['product_screen_v2' => true, 'packs' => true, 'purchasing_v2' => true]);
        $html = $this->owner()->call('GET', $this->url('/goods-receipts'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="sidebar-purchases"', $html, 'switched off: today\'s menu');
        $this->assertStringContainsString('<span>Supplier Payments</span>', $html);
        $this->assertStringNotContainsString('New purchase', $html);
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────────────────────

    /** The goods part of a Post, as the entry area sends it: one line of $count at $price a piece. */
    private function arriving(Product $p, string $count, string $price, ?Supplier $supplier = null): array
    {
        return ['start' => 'new', '_entry_v2' => '1', 'branch_id' => $this->branchId(), 'supplier_id' => ($supplier ?? $this->supplier())->id,
            'receipt_date' => now()->toDateString(), 'lines' => [['product_id' => $p->id, 'buy_pack' => 'base', 'price' => $price, 'packs' => ['base' => $count]]]];
    }

    private function paying(float $amount): array
    {
        return ['amount' => $amount, 'cash_bank_account_id' => $this->cashAccount(), 'payment_method' => 'cash', 'payment_date' => now()->toDateString()];
    }

    private function workspace(array $goods, array $rest = [])
    {
        return $this->owner()->postJson($this->url('/purchases'), array_merge($goods, $rest));
    }

    private function page()
    {
        return $this->owner()->call('GET', $this->url('/purchases/new'));
    }

    private function counts(): array
    {
        return [GoodsReceipt::count(), PurchaseBill::count(), SupplierPayment::count()];
    }

    private function cashMovements(): int
    {
        return DB::connection('tenant')->table('cash_bank_account_transactions')->count();
    }

    private function cashAccount(): int
    {
        return (int) DB::connection('tenant')->table('cash_bank_accounts')->where('is_active', 1)->orderBy('id')->value('id');
    }

    private function newSupplier(string $name): Supplier
    {
        return Supplier::create(['code' => 'PW-' . strtoupper(substr(md5($name), 0, 6)), 'name' => $name, 'status' => 'active', 'opening_balance' => 0, 'current_balance' => 0]);
    }

    /** A cloned role with exactly these permissions (as an owner makes one). */
    private function roleUser(string $roleName, array $permissions): User
    {
        DB::setDefaultConnection('tenant');
        $role = Role::findOrCreate($roleName, 'tenant');
        $role->syncPermissions($permissions);
        $user = User::on('tenant')->firstOrCreate(['email' => strtolower(str_replace(' ', '.', $roleName)) . '@pwtest.test'],
            ['name' => $roleName, 'password' => Hash::make('secret123'), 'status' => 'active']);
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
