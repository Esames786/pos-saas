<?php

namespace Tests\MySql;

use App\Models\Tenant\Combo;
use App\Models\Tenant\Product;
use App\Models\Tenant\SalesOrder;
use App\Support\DealSplit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\IsolatedPharmacyStore;

/**
 * DEAL-WHOLE-SPLIT-1 (8 Nov) — Split Bill moves a deal WHOLE.
 *
 * Before: the Split Bill page listed a deal's price line and each of its items as separate rows, so a cashier could move
 * the deal's price to a new bill and leave its food on the old one (it happened once on a live store in 90 days), and
 * the moved lines lost the link to their deal. Now the page shows the deal as one row with its items under it; moving N
 * deals moves the same share of every item, linked to the new deal line; an item cannot move alone; a deal moves in
 * whole deals. DEAL-SPLIT-REPORT-1: moved lines keep exactly the parts they were sold with.
 *
 * Through the real routes (POST /held-sales, GET/POST /sales-orders/{id}/split-bill) on an isolated store.
 */
class SplitBillDealWholeMySqlTest extends MySqlTenantTestCase
{
    use IsolatedPharmacyStore;

    private const STORE_CODE = 'dsbtest';
    private const STORE_SUFFIX = '_dsb';
    private const STORE_FEATURES = [];
    private const STORE_PLAN = 'enterprise';
    private const STORE_BUSINESS = 'restaurant';

    /** @var list<string> */
    private array $sql = [];

    private bool $listening = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStore();
        $this->allModules();
        DealSplit::flush();
        $this->setFeatures([]);
    }

    protected function tearDown(): void
    {
        DealSplit::flush();
        parent::tearDown();
    }

    public function test_the_split_page_shows_a_deal_as_one_row_with_its_items_under_it(): void
    {
        [$deal, $biryani, $bbq, $drink] = $this->menu('Page');
        $order = $this->held(array_merge($this->dealLines($deal, 'd1', 2), [$this->plain($drink, 1)]));
        $header = $this->line($order, 'combo_header');
        $items = $this->lines($order, 'component');

        $html = $this->owner()->get($this->url('/sales-orders/' . $order->id . '/split-bill'))->assertOk()->getContent();

        $this->assertStringContainsString('value="' . $header->id . '"', $html, 'the deal is a row');
        foreach ($items as $item) {
            $this->assertDoesNotMatchRegularExpression('/name="lines\[\d+\]\[sales_order_line_id\]" value="' . $item->id . '"/', $html, 'an item of the deal is not a row of its own');
        }
        $this->assertStringContainsString('- 2 x DSB Biryani Page', $html, 'its items are listed under it');
        $this->assertStringContainsString('- 2 x DSB BBQ Page', $html);
        $this->assertStringContainsString('Deal — its items go with it.', $html);
        $this->assertMatchesRegularExpression('/value="' . $this->line($order, 'standard')->id . '"/', $html, 'a plain item is still a row');
    }

    public function test_splitting_one_of_two_deals_moves_its_items_with_it_linked_to_the_new_deal_line(): void
    {
        [$deal, $biryani, $bbq, $drink] = $this->menu('One');
        $order = $this->held(array_merge($this->dealLines($deal, 'd1', 2), [$this->plain($drink, 1)]));
        $header = $this->line($order, 'combo_header');

        $this->split($order, [[$header->id, 1]])->assertOk();

        $new = $this->newest($order);
        $this->assertSame([
            ['combo_header', $biryani->id, '1.000', '900.00', null],
            ['component', $biryani->id, '1.000', '0.00', 'new-header'],
            ['component', $bbq->id, '1.000', '0.00', 'new-header'],
        ], $this->shape($new), 'the new bill: one deal and its items, linked to the new deal line');
        $this->assertSame([
            ['combo_header', $biryani->id, '1.000', '900.00', null],
            ['component', $biryani->id, '1.000', '0.00', 'old-header'],
            ['component', $bbq->id, '1.000', '0.00', 'old-header'],
            ['standard', $drink->id, '1.000', '100.00', null],
        ], $this->shape($order->fresh()), 'the old bill keeps the other deal with its items, and the drink');
        $this->assertEqualsWithDelta(900, (float) $new->grand_total, 0.001);
        $this->assertEqualsWithDelta(1000, (float) $order->fresh()->grand_total, 0.001, 'money: 1,900 before = 900 + 1,000 after');
    }

    public function test_moving_the_whole_deal_leaves_no_item_behind(): void
    {
        [$deal, , , $drink] = $this->menu('All');
        $order = $this->held(array_merge($this->dealLines($deal, 'd1', 2), [$this->plain($drink, 1)]));

        $this->split($order, [[$this->line($order, 'combo_header')->id, 2]])->assertOk();

        $this->assertSame(['standard'], array_column($this->shape($order->fresh()), 0), 'only the drink stays — no deal item left behind');
        $this->assertSame(['combo_header', 'component', 'component'], array_column($this->shape($this->newest($order)), 0));
        $this->assertSame(['new-header', 'new-header'], array_column(array_slice($this->shape($this->newest($order)), 1), 4));
    }

    public function test_an_item_of_a_deal_cannot_be_split_alone_and_nothing_changes(): void
    {
        [$deal] = $this->menu('Alone');
        $order = $this->held($this->dealLines($deal, 'd1', 2));
        $before = $this->shape($order->fresh());
        $bills = SalesOrder::count();

        $this->split($order, [[$this->lines($order, 'component')->first()->id, 1]])
            ->assertSessionHasErrors(['split' => 'DSB Biryani Alone is part of a deal — split the deal itself; its items go with it.']);

        $this->assertSame($bills, SalesOrder::count(), 'no new bill');
        $this->assertSame($before, $this->shape($order->fresh()), 'the bill is as it was');
    }

    public function test_a_deal_splits_in_whole_deals(): void
    {
        [$deal] = $this->menu('Half');
        $order = $this->held($this->dealLines($deal, 'd1', 2));
        $bills = SalesOrder::count();

        $this->split($order, [[$this->line($order, 'combo_header')->id, 0.5]])->assertSessionHasErrors('split');
        $this->assertStringContainsString('whole', (string) session('errors')->first('split'), 'half a deal is refused');
        $this->assertSame($bills, SalesOrder::count());
    }

    public function test_switch_on_a_moved_deal_keeps_the_parts_it_was_sold_with(): void
    {
        $this->switch(true);
        [$deal, $biryani, $bbq] = $this->menu('Parts', [400, 500]);
        $order = $this->held($this->dealLines($deal, 'd1', 2));
        $this->assertSame(['900.00', '400.00', '500.00'], $this->parts($order), 'held: the parts are on the bill');

        // The deal's parts change before the split; the moved lines must keep what the bill had.
        $this->owner()->put($this->url('/combos/' . $deal->id), $this->dealInput($deal->name, 900, [[$biryani, '300'], [$bbq, '600']], true))
            ->assertSessionHasNoErrors();

        $this->split($order, [[$this->line($order, 'combo_header')->id, 1]])->assertOk();

        $this->assertSame(['900.00', '400.00', '500.00'], $this->parts($this->newest($order)), 'moved: the parts it was sold with');
        $this->assertSame(['900.00', '400.00', '500.00'], $this->parts($order->fresh()), 'stayed: unchanged');
    }

    public function test_a_deal_held_before_it_was_split_stays_as_it_was_sold_when_moved(): void
    {
        $this->switch(true);
        [$deal] = $this->menu('Before', [400, 500]);
        $this->switch(false);
        $order = $this->held($this->dealLines($deal, 'd1', 2));
        $this->assertSame([null, null, null], $this->parts($order), 'held while the switch was off: no parts');

        $this->switch(true);
        $this->split($order, [[$this->line($order, 'combo_header')->id, 1]])->assertOk();

        $this->assertSame([null, null, null], $this->parts($this->newest($order)), 'moved: still no parts — the move does not read the deal again');
    }

    public function test_switch_off_the_split_never_names_a_new_column(): void
    {
        [$deal, , , $drink] = $this->menu('Off');
        $order = $this->held(array_merge($this->dealLines($deal, 'd1', 2), [$this->plain($drink, 2)]));

        $this->listen();
        $this->split($order, [[$this->line($order, 'combo_header')->id, 1], [$this->line($order, 'standard')->id, 1]])->assertOk();

        $this->assertNotEmpty($this->sqlNaming(['insert into `sales_order_lines`']), 'the probe heard the moved lines');
        $this->assertSame([], $this->sqlNaming(['deal_share', 'report_amount', 'report_by_items']), 'switch off: no new column named');
        $this->assertSame([null, null, null], array_map(fn ($r) => $r->deal_share, DB::connection('tenant')->table('sales_order_lines')
            ->where('sales_order_id', $this->newest($order)->id)->whereNotNull('combo_id')->orderBy('id')->get()->all()));
    }

    /* ── helpers ───────────────────────────────────────────────────────────────────────────────────── */

    /** @return array{0: Combo, 1: Product, 2: Product, 3: Product} */
    private function menu(string $tag, ?array $parts = null): array
    {
        $make = fn (string $name, float $price) => Product::where('name', $name)->first()
            ?? $this->product($name, $this->piece()->id, ['inventory_consumption_method' => 'none', 'is_stock_tracked' => false, 'default_selling_price' => $price]);
        $biryani = $make('DSB Biryani ' . $tag, 500);
        $bbq = $make('DSB BBQ ' . $tag, 600);
        $drink = $make('DSB Drink ' . $tag, 100);
        $byItems = $parts !== null;
        $this->owner()->post($this->url('/combos'), $this->dealInput('DSB Platter ' . $tag, 900,
            [[$biryani, $byItems ? (string) $parts[0] : null], [$bbq, $byItems ? (string) $parts[1] : null]], $byItems))
            ->assertRedirect()->assertSessionHasNoErrors();

        return [Combo::where('name', 'DSB Platter ' . $tag)->sole(), $biryani, $bbq, $drink];
    }

    /** @param list<array{0: Product, 1: ?string}> $parts */
    private function dealInput(string $name, float $price, array $parts, bool $byItems): array
    {
        $components = [];
        foreach ($parts as $i => [$product, $amount]) {
            $row = ['product_id' => (string) $product->id, 'product_variant_id' => '', 'quantity' => '1', 'sort_order' => (string) (($i + 1) * 10)];
            if ($amount !== null) {
                $row['report_amount'] = $amount;
            }
            $components[] = $row;
        }

        return ['name' => $name, 'code' => '', 'price' => (string) $price, 'branch_id' => '', 'category_id' => '', 'status' => 'active',
            'sort_order' => '0', 'description' => '', 'report_by_items' => $byItems ? '1' : '0', 'components' => $components];
    }

    /** The lines the counter sends for $qty of one deal: the header with the money, then each item at 0 under it. */
    private function dealLines(Combo $combo, string $key, int $qty): array
    {
        $components = $combo->components()->orderBy('sort_order')->orderBy('id')->get();
        $lines = [[
            'product_id' => $components->first()->product_id, 'product_variant_id' => '', 'quantity' => $qty, 'unit_price' => (float) $combo->price,
            'line_kind' => 'combo_header', 'combo_id' => $combo->id, 'line_name' => $combo->name,
            'client_line_key' => $key, 'parent_client_line_key' => '', 'discount_amount' => 0, 'tax_amount' => 0, 'modifiers' => '[]',
        ]];
        foreach ($components as $component) {
            $lines[] = [
                'product_id' => $component->product_id, 'product_variant_id' => '', 'quantity' => (float) $component->quantity * $qty, 'unit_price' => 0,
                'line_kind' => 'component', 'combo_id' => $combo->id, 'line_name' => '',
                'client_line_key' => $key . ':component:' . $component->id, 'parent_client_line_key' => $key,
                'discount_amount' => 0, 'tax_amount' => 0, 'modifiers' => '[]',
            ];
        }

        return $lines;
    }

    private function plain(Product $product, int $qty): array
    {
        return ['product_id' => $product->id, 'product_variant_id' => '', 'quantity' => $qty, 'unit_price' => (float) $product->default_selling_price,
            'line_kind' => 'standard', 'client_line_key' => 'p' . $product->id, 'parent_client_line_key' => '',
            'discount_amount' => 0, 'tax_amount' => 0, 'modifiers' => '[]'];
    }

    private function held(array $lines): SalesOrder
    {
        $held = $this->hold($lines);
        $this->assertTrue($held->isSuccessful(), Str::limit($held->getContent(), 400));
        $order = SalesOrder::findOrFail((int) $held->json('sale_id'));
        $this->assertSame('held', $order->status);

        return $order;
    }

    private function split(SalesOrder $order, array $pairs)
    {
        return $this->owner()->post($this->url('/sales-orders/' . $order->id . '/split-bill'), [
            'lines' => array_map(fn ($p) => ['sales_order_line_id' => $p[0], 'quantity' => $p[1]], $pairs),
        ]);
    }

    private function newest(SalesOrder $order): SalesOrder
    {
        return SalesOrder::where('notes', 'Split from ' . $order->sale_no)->latest('id')->firstOrFail();
    }

    private function line(SalesOrder $order, string $kind): object
    {
        return $this->lines($order, $kind)->firstOrFail();
    }

    private function lines(SalesOrder $order, string $kind)
    {
        return DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $order->id)
            ->where('line_kind', $kind)->orderBy('id')->get();
    }

    /** kind, product, qty, line_total, which header an item hangs under ('new-header' / 'old-header' / id). */
    private function shape(SalesOrder $order): array
    {
        $rows = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $order->id)->orderBy('id')->get();
        $header = $rows->firstWhere('line_kind', 'combo_header');
        $tag = fn ($r) => $r->parent_sales_order_line_id === null ? null
            : ($header && (int) $r->parent_sales_order_line_id === (int) $header->id
                ? (str_starts_with((string) $order->notes, 'Split from') ? 'new-header' : 'old-header')
                : 'other:' . $r->parent_sales_order_line_id);

        return $rows->map(fn ($r) => [$r->line_kind, (int) $r->product_id, (string) $r->quantity, (string) $r->line_total, $tag($r)])->values()->all();
    }

    /** @return list<?string> deal_share of the deal's lines in order, read with select * */
    private function parts(SalesOrder $order): array
    {
        return DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $order->id)->whereNotNull('combo_id')
            ->orderBy('id')->get()->map(fn ($r) => $r->deal_share === null ? null : (string) $r->deal_share)->all();
    }

    private function switch(bool $on): void
    {
        $this->setFeatures($on ? ['deal_split' => true] : []);
        DealSplit::flush();
    }

    private function listen(): void
    {
        $this->sql = [];
        if (! $this->listening) {
            DB::listen(function (QueryExecuted $q) {
                if ($q->connectionName === 'tenant') {
                    $this->sql[] = $q->sql;
                }
            });
            $this->listening = true;
        }
    }

    /** @return list<string> */
    private function sqlNaming(array $needles): array
    {
        return array_values(array_filter($this->sql, fn ($s) => Str::contains(strtolower($s), array_map('strtolower', $needles))));
    }

    private function allModules(): void
    {
        $m = DB::connection('master');
        $planId = $m->table('plans')->where('code', 'dsb-all')->value('id') ?? $m->table('plans')->insertGetId([
            'code' => 'dsb-all', 'name' => 'Split bill deal test', 'price' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();
        foreach ($m->table('modules')->where('is_active', 1)->pluck('id') as $moduleId) {
            $m->table('plan_modules')->insert(['plan_id' => $planId, 'module_id' => $moduleId, 'is_enabled' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $m->table('subscriptions')->where('tenant_id', $m->table('tenants')->where('tenant_code', self::STORE_CODE)->value('id'))->update(['plan_id' => $planId]);
    }
}
