<?php

namespace Tests\MySql;

use App\Models\Tenant\Combo;
use App\Models\Tenant\Product;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Terminal;
use App\Support\DealSplit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\IsolatedPharmacyStore;
use Tests\MySql\Support\ParsesInlineScripts;

/**
 * DEAL-SPLIT-REPORT-1 — the deal form (Products → Deals → Add / Edit), through the real pages and the real counter.
 *
 * The owner: "deals mai item k rate b add kartain … option ho all item one rate / all split rates". So the deal's own
 * form asks "How should this deal show in reports?" — One rate (as today) or Each item's rate, a Rs box per item that
 * must add up to the deal's price. Only when the Master switch is on; off, the form, the save and the sale are
 * exactly as before and never name the new columns (a deploy runs new code a second before the migration).
 *
 * End to end: a split deal sold through POST /pos (and held through POST /held-sales) carries its parts on the bill's
 * lines — header = total of parts, each item its part — copied at the moment of sale by SalesOrderLine::creating.
 */
class DealSplitFormMySqlTest extends MySqlTenantTestCase
{
    use IsolatedPharmacyStore;
    use ParsesInlineScripts;

    private const STORE_CODE = 'dsftest';
    private const STORE_SUFFIX = '_dsf';
    private const STORE_FEATURES = [];
    private const STORE_PLAN = 'enterprise';
    private const STORE_BUSINESS = 'restaurant';

    private const ON = ['deal_split' => true];
    private const OFF = [];

    private const NEW_COLUMNS = ['report_by_items', 'report_amount', 'deal_share'];

    /** @var list<string> tenant SQL seen while the probe listens */
    private array $sql = [];

    private bool $listening = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStore();
        $this->allModules();
        DealSplit::flush();
        $this->switch(false);
    }

    protected function tearDown(): void
    {
        DealSplit::flush();
        parent::tearDown();
    }

    /* ── switch off: the form, the save and the sale exactly as before ─────────────────────────────── */

    public function test_switch_off_the_form_shows_no_report_section_and_a_crafted_post_saves_none_of_it(): void
    {
        [$biryani, $bbq] = $this->items('Off');

        $this->listen();
        $create = $this->owner()->get($this->url('/combos/create'))->assertOk()->getContent();
        $this->assertNoReportSection($create, 'create page');

        // A crafted POST naming the new fields: they are ignored.
        $this->owner()->post($this->url('/combos'), $this->dealInput('DSF Off Platter', 900, [[$biryani, '400'], [$bbq, '500']], true))
            ->assertRedirect()->assertSessionHasNoErrors();
        $combo = Combo::where('name', 'DSF Off Platter')->sole();
        $this->assertFalse((bool) $this->raw('combos', $combo->id)->report_by_items, 'the crafted report_by_items is not saved');
        $this->assertSame([null, null], $this->rawParts($combo), 'no crafted part is saved');

        $edit = $this->owner()->get($this->url('/combos/' . $combo->id . '/edit'))->assertOk()->getContent();
        $this->assertNoReportSection($edit, 'edit page');

        $this->owner()->put($this->url('/combos/' . $combo->id), $this->dealInput('DSF Off Platter', 950, [[$biryani, '450'], [$bbq, '500']], true))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('950.00', $combo->fresh()->price, 'the edit itself saved, as before');
        $this->assertFalse((bool) $this->raw('combos', $combo->id)->report_by_items);
        $this->assertSame([null, null], $this->rawParts($combo));

        $this->assertNotEmpty($this->sql, 'the probe heard the tenant queries');
        $this->assertSame([], $this->sqlNaming(['report_by_items', 'report_amount']),
            'switch off: no query names a new column (a tenant one second before the migration must not fail)');
    }

    /* ── switch on: the section, saving, and edit showing what was saved ───────────────────────────── */

    public function test_switch_on_the_form_asks_the_question_and_a_split_that_adds_up_saves_and_shows_on_edit(): void
    {
        $this->switch(true);
        [$biryani, $bbq] = $this->items('On');

        $create = $this->owner()->get($this->url('/combos/create'))->assertOk()->getContent();
        $this->assertStringContainsString('How should this deal show in reports?', $create);
        $this->assertStringContainsString('One rate</strong> — the whole deal as one line', $create);
        $this->assertStringContainsString('Each item\'s rate</strong> — split the deal\'s money between its items', $create);
        $this->assertMatchesRegularExpression('/id="report_by_items_0" value="0"\s+checked/', $create, 'a new deal starts on One rate');
        $this->assertDoesNotMatchRegularExpression('/id="report_by_items_1" value="1"\s+checked/', $create);
        $this->assertStringContainsString('Report rate (Rs)', $create);
        $this->assertMatchesRegularExpression('/name="components\[0\]\[report_amount\]"[^>]*data-report-amount\s+disabled/', $create,
            'on One rate the box is off, so it is not sent');
        // The Add Component button clones the row template: the new row brings its own box.
        $template = Str::between($create, '<template id="component-row-template">', '</template>');
        $this->assertStringContainsString('name="components[__INDEX__][report_amount]"', $template);
        $this->assertStringContainsString('data-report-amount', $template);
        $this->assertInlineScriptsParse($create, 'deal create page', 'deal-split-sum');

        $this->listen();
        $this->owner()->post($this->url('/combos'), $this->dealInput('DSF Family Platter', 900, [[$biryani, '400'], [$bbq, '500']], true))
            ->assertRedirect()->assertSessionHasNoErrors();
        $combo = Combo::where('name', 'DSF Family Platter')->sole();
        $this->assertTrue((bool) $this->raw('combos', $combo->id)->report_by_items);
        $this->assertSame(['400.00', '500.00'], $this->rawParts($combo));
        $this->assertNotEmpty($this->sqlNaming(['report_by_items']), 'the probe CAN see a new column when one is written');

        $edit = $this->owner()->get($this->url('/combos/' . $combo->id . '/edit'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="report_by_items_1" value="1"\s+checked/', $edit, 'edit shows the saved choice');
        $this->assertMatchesRegularExpression('/name="components\[0\]\[report_amount\]"[^>]*value="400.00"/', $edit);
        $this->assertMatchesRegularExpression('/name="components\[1\]\[report_amount\]"[^>]*value="500.00"/', $edit);
        $this->assertDoesNotMatchRegularExpression('/name="components\[\d\]\[report_amount\]"[^>]*disabled/', $edit, 'the boxes are on');
        $this->assertMatchesRegularExpression('/id="deal-split-sum"[^>]*class="[^"]*text-success[^"]*">Parts add up to 900 — deal price 900</', $edit,
            'the live line, green: the parts add up');
        $this->assertInlineScriptsParse($edit, 'deal edit page', 'deal-split-sum');

        // Edit again with new parts — the recreated rows carry them.
        $this->owner()->put($this->url('/combos/' . $combo->id), $this->dealInput('DSF Family Platter', 1800, [[$biryani, '800'], [$bbq, '1000']], true))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('1800.00', $combo->fresh()->price);
        $this->assertSame(['800.00', '1000.00'], $this->rawParts($combo));
    }

    public function test_parts_that_do_not_add_up_are_refused_with_the_gap_and_nothing_changes(): void
    {
        $this->switch(true);
        [$biryani, $bbq] = $this->items('Gap');
        $before = $this->fingerprint();

        $this->owner()->from($this->url('/combos/create'))
            ->post($this->url('/combos'), $this->dealInput('DSF Gap Platter', 900, [[$biryani, '400'], [$bbq, '450']], true))
            ->assertRedirect($this->url('/combos/create'))
            ->assertSessionHasErrors(['report_by_items' => 'Parts add up to 850 but the deal price is 900 — 50 short. Make them equal, or choose "One rate".']);
        $this->assertSame($before, $this->fingerprint(), 'a refused deal saves nothing');

        // The form comes back with the message, the choice and what was typed.
        $again = $this->owner()->get($this->url('/combos/create'))->assertOk()->getContent();
        $this->assertStringContainsString('Parts add up to 850 but the deal price is 900 — 50 short.', $again);
        $this->assertMatchesRegularExpression('/id="report_by_items_1" value="1"\s+checked/', $again);
        $this->assertMatchesRegularExpression('/name="components\[1\]\[report_amount\]"[^>]*value="450"/', $again);
        $this->assertMatchesRegularExpression('/id="deal-split-sum"[^>]*class="[^"]*text-danger[^"]*">Parts add up to 850 — deal price 900 \(50 short\)</', $again);

        $this->owner()->post($this->url('/combos'), $this->dealInput('DSF Gap Platter', 900, [[$biryani, '400'], [$bbq, '600']], true))
            ->assertSessionHasErrors(['report_by_items' => 'Parts add up to 1,000 but the deal price is 900 — 100 too much. Make them equal, or choose "One rate".']);
        $this->owner()->post($this->url('/combos'), $this->dealInput('DSF Gap Platter', 900, [[$biryani, '400'], [$bbq, null]], true))
            ->assertSessionHasErrors('report_by_items');
        $this->assertStringContainsString('type a report rate for every item', session('errors')->first('report_by_items'));
        $this->owner()->post($this->url('/combos'), $this->dealInput('DSF Gap Platter', 0, [[$biryani, '0'], [$bbq, '0']], true))
            ->assertSessionHasErrors('report_by_items');
        $this->assertStringContainsString('at least one item needs a report rate above 0', session('errors')->first('report_by_items'));
        $this->owner()->post($this->url('/combos'), $this->dealInput('DSF Gap Platter', 900, [[$biryani, '400'], [$bbq, '499.98']], true))
            ->assertSessionHasErrors('report_by_items');
        $this->assertSame($before, $this->fingerprint(), 'nothing saved by any refused deal');

        // Within a paisa is equal.
        $this->owner()->post($this->url('/combos'), $this->dealInput('DSF Gap Platter', 900, [[$biryani, '400'], [$bbq, '499.99']], true))
            ->assertSessionHasNoErrors();
        $this->assertSame(['400.00', '499.99'], $this->rawParts(Combo::where('name', 'DSF Gap Platter')->sole()));
    }

    public function test_a_price_changed_on_a_split_deal_without_fixing_the_parts_is_refused(): void
    {
        $this->switch(true);
        [$biryani, $bbq] = $this->items('Price');
        $combo = $this->splitDeal('DSF Price Platter', 900, [[$biryani, '400'], [$bbq, '500']]);
        $before = $this->fingerprint();

        $this->owner()->put($this->url('/combos/' . $combo->id), $this->dealInput('DSF Price Platter', 1000, [[$biryani, '400'], [$bbq, '500']], true))
            ->assertSessionHasErrors(['report_by_items' => 'Parts add up to 900 but the deal price is 1,000 — 100 short. Make them equal, or choose "One rate".']);
        $this->assertSame($before, $this->fingerprint(), 'price, parts and rows untouched');
        $this->assertSame('900.00', $combo->fresh()->price);
    }

    public function test_switching_back_to_one_rate_saves_report_by_items_0_and_keeps_no_parts(): void
    {
        $this->switch(true);
        [$biryani, $bbq] = $this->items('Back');
        $combo = $this->splitDeal('DSF Back Platter', 900, [[$biryani, '400'], [$bbq, '500']]);

        // One rate: the browser switches the boxes off, so no amount is sent — and a price change needs no parts.
        $this->owner()->put($this->url('/combos/' . $combo->id), $this->dealInput('DSF Back Platter', 950, [[$biryani, null], [$bbq, null]], false))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse((bool) $this->raw('combos', $combo->id)->report_by_items);
        $this->assertSame([null, null], $this->rawParts($combo));
        $this->assertSame('950.00', $combo->fresh()->price);

        // Even if amounts are sent with One rate, none is kept (a one-rate deal has no parts).
        $this->owner()->put($this->url('/combos/' . $combo->id), $this->dealInput('DSF Back Platter', 950, [[$biryani, '450'], [$bbq, '400']], false))
            ->assertSessionHasNoErrors();
        $this->assertSame([null, null], $this->rawParts($combo));

        $edit = $this->owner()->get($this->url('/combos/' . $combo->id . '/edit'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="report_by_items_0" value="0"\s+checked/', $edit);
    }

    /* ── end to end: the counter's real sale carries the parts ─────────────────────────────────────── */

    public function test_end_to_end_a_split_deal_sold_and_held_at_the_counter_carries_its_parts_on_the_bill(): void
    {
        $this->switch(true);
        [$biryani, $bbq, $raita] = $this->items('E2E', withRaita: true);
        $combo = $this->splitDeal('DSF E2E Platter', 900, [[$biryani, '400'], [$bbq, '500'], [$raita, '0']]);
        $this->owner()->post($this->url('/combos'), $this->dealInput('DSF E2E One Rate', 700, [[$biryani, null], [$bbq, null]], false))
            ->assertSessionHasNoErrors();
        $oneRate = Combo::where('name', 'DSF E2E One Rate')->sole();

        $this->listen();
        $sale = $this->sell(array_merge($this->dealLines($combo, 'h1'), $this->dealLines($oneRate, 'h2')), 1600);
        $this->assertTrue($sale->isSuccessful(), Str::limit($sale->getContent(), 400));
        $order = $this->lastSale();
        $this->assertSame([
            ['combo_header', $biryani->id, '900.00', '900.00'],
            ['component', $biryani->id, '0.00', '400.00'],
            ['component', $bbq->id, '0.00', '500.00'],
            ['component', $raita->id, '0.00', '0.00'],
        ], $this->billLines($order->id, $combo->id), 'header = total of parts, each item its own part; the price is untouched');
        $this->assertSame([
            ['combo_header', $biryani->id, '700.00', null],
            ['component', $biryani->id, '0.00', null],
            ['component', $bbq->id, '0.00', null],
        ], $this->billLines($order->id, $oneRate->id), 'a One rate deal on the same bill carries nothing');
        $this->assertEqualsWithDelta(1600, (float) $order->fresh()->grand_total, 0.001, 'the bill total is the deals\' prices');
        $this->assertNotEmpty($this->sqlNaming(['deal_share']), 'the probe CAN see deal_share when the sale writes it');

        // The same deal held on a running bill.
        $held = $this->hold($this->dealLines($combo, 'h3'));
        $this->assertTrue($held->isSuccessful(), Str::limit($held->getContent(), 400));
        $heldId = (int) $held->json('sale_id');
        $this->assertSame('held', SalesOrder::findOrFail($heldId)->status);
        $this->assertSame(['900.00', '400.00', '500.00', '0.00'], array_column($this->billLines($heldId, $combo->id), 3),
            'a held bill carries the parts too');

        // Editing the deal later never moves what was sold.
        $this->owner()->put($this->url('/combos/' . $combo->id), $this->dealInput('DSF E2E Platter', 900, [[$biryani, '300'], [$bbq, '600'], [$raita, '0']], true))
            ->assertSessionHasNoErrors();
        $this->assertSame(['900.00', '400.00', '500.00', '0.00'], array_column($this->billLines($order->id, $combo->id), 3),
            'the parts were copied at the sale');
    }

    public function test_end_to_end_switch_off_the_same_sale_leaves_deal_share_null_and_never_names_it(): void
    {
        $this->switch(true);
        [$biryani, $bbq] = $this->items('E2E Off');
        $combo = $this->splitDeal('DSF E2E Off Platter', 900, [[$biryani, '400'], [$bbq, '500']]);
        $this->switch(false);

        $this->listen();
        $sale = $this->sell($this->dealLines($combo, 'h1'), 900);
        $this->assertTrue($sale->isSuccessful(), Str::limit($sale->getContent(), 400));
        $held = $this->hold($this->dealLines($combo, 'h2'));
        $this->assertTrue($held->isSuccessful(), Str::limit($held->getContent(), 400));

        $this->assertSame([null, null, null], array_column($this->billLines($this->lastSale()->id, $combo->id), 3), 'sale: nothing written');
        $this->assertSame([null, null, null], array_column($this->billLines((int) $held->json('sale_id'), $combo->id), 3), 'held bill: nothing written');
        $this->assertNotEmpty($this->sqlNaming(['insert into `sales_order_lines`']), 'the probe heard the line inserts');
        $this->assertSame([], $this->sqlNaming(self::NEW_COLUMNS), 'switch off: the sale never names a new column');
    }

    /* ── the deploy window: switch on, columns not there yet ───────────────────────────────────────── */

    public function test_switch_on_before_the_migration_the_form_and_the_sale_work_as_before(): void
    {
        $this->switch(true);
        [$biryani, $bbq] = $this->items('Window');
        $combo = $this->splitDeal('DSF Window Platter', 900, [[$biryani, '400'], [$bbq, '500']]);
        $migration = require base_path('database/migrations/tenant/2026_11_08_000001_add_deal_split_report_columns.php');

        $migration->down();
        DealSplit::flush();
        try {
            $this->assertFalse(DealSplit::active(), 'switch on, columns missing = not active');
            $this->listen();
            $create = $this->owner()->get($this->url('/combos/create'))->assertOk()->getContent();
            $this->assertNoReportSection($create, 'create page before the migration');
            $this->owner()->post($this->url('/combos'), $this->dealInput('DSF Window New', 600, [[$biryani, '300'], [$bbq, '300']], true))
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->owner()->put($this->url('/combos/' . $combo->id), $this->dealInput('DSF Window Platter', 900, [[$biryani, '400'], [$bbq, '500']], true))
                ->assertRedirect()->assertSessionHasNoErrors();
            $sale = $this->sell($this->dealLines($combo->fresh(), 'h1'), 900);
            $this->assertTrue($sale->isSuccessful(), Str::limit($sale->getContent(), 400));
            $this->assertSame([], $this->sqlNaming(self::NEW_COLUMNS), 'nothing names a column that is not there');
        } finally {
            $migration->up();
            DealSplit::flush();
        }
        $this->assertTrue(DealSplit::active());
    }

    /* ── helpers ───────────────────────────────────────────────────────────────────────────────────── */

    private function assertNoReportSection(string $html, string $page): void
    {
        foreach (['How should this deal show in reports?', 'report_by_items', 'report_amount', 'Report rate', 'data-report', 'deal-split'] as $marker) {
            $this->assertStringNotContainsString($marker, $html, "{$page}: switch off shows nothing new ({$marker})");
        }
        $this->assertStringContainsString('name="components[0][quantity]"', $html, "{$page}: the rows are still there");
        $this->assertStringContainsString('id="component-row-template"', $html);
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

    private function splitDeal(string $name, float $price, array $parts): Combo
    {
        $this->owner()->post($this->url('/combos'), $this->dealInput($name, $price, $parts, true))->assertRedirect()->assertSessionHasNoErrors();

        return Combo::where('name', $name)->sole();
    }

    /** The lines the counter sends for one deal: the header with the money, then each item at 0 under it. */
    private function dealLines(Combo $combo, string $key): array
    {
        $components = $combo->components()->orderBy('sort_order')->orderBy('id')->get();
        $lines = [[
            'product_id' => $components->first()->product_id, 'product_variant_id' => '', 'quantity' => 1, 'unit_price' => (float) $combo->price,
            'line_kind' => 'combo_header', 'combo_id' => $combo->id, 'line_name' => $combo->name,
            'client_line_key' => $key, 'parent_client_line_key' => '', 'discount_amount' => 0, 'tax_amount' => 0, 'modifiers' => '[]',
        ]];
        foreach ($components as $component) {
            $lines[] = [
                'product_id' => $component->product_id, 'product_variant_id' => '', 'quantity' => (float) $component->quantity, 'unit_price' => 0,
                'line_kind' => 'component', 'combo_id' => $combo->id, 'line_name' => '',
                'client_line_key' => $key . ':component:' . $component->id, 'parent_client_line_key' => $key,
                'discount_amount' => 0, 'tax_amount' => 0, 'modifiers' => '[]',
            ];
        }

        return $lines;
    }

    /** @return list<array{0: string, 1: int, 2: string, 3: ?string}> kind, product, line_total, deal_share — as stored */
    private function billLines(int $orderId, int $comboId): array
    {
        $rows = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $orderId)->where('combo_id', $comboId)
            ->orderBy('id')->get();
        $header = $rows->firstWhere('line_kind', 'combo_header');
        $this->assertNotNull($header, 'the deal\'s header line');
        foreach ($rows->where('line_kind', 'component') as $component) {
            $this->assertSame((int) $header->id, (int) $component->parent_sales_order_line_id, 'each item sits under its deal');
        }

        return $rows->map(fn ($r) => [$r->line_kind, (int) $r->product_id, (string) $r->line_total, $r->deal_share === null ? null : (string) $r->deal_share])
            ->values()->all();
    }

    /** @return array{0: Product, 1: Product, 2?: Product} */
    private function items(string $tag, bool $withRaita = false): array
    {
        $names = ['DSF Biryani ' . $tag, 'DSF BBQ ' . $tag];
        if ($withRaita) {
            $names[] = 'DSF Raita ' . $tag;
        }

        return array_map(fn ($name) => Product::where('name', $name)->first()
            ?? $this->product($name, $this->piece()->id, ['inventory_consumption_method' => 'none', 'is_stock_tracked' => false, 'default_selling_price' => 100]),
            $names);
    }

    private function raw(string $table, int $id): object
    {
        return DB::connection('tenant')->table($table)->where('id', $id)->first();
    }

    /**
     * @return list<?string> each component's part, in row order, as stored. Read with `select *` so the test's own
     * reading never names the column the switch-off probe is listening for.
     */
    private function rawParts(Combo $combo): array
    {
        return DB::connection('tenant')->table('combo_components')->where('combo_id', $combo->id)->orderBy('sort_order')->orderBy('id')
            ->get()->map(fn ($r) => $r->report_amount === null ? null : (string) $r->report_amount)->all();
    }

    /** Every deal and every deal row, byte for byte. */
    private function fingerprint(): array
    {
        $c = DB::connection('tenant');

        return [
            'combos' => md5(json_encode($c->table('combos')->orderBy('id')->get())),
            'combo_components' => md5(json_encode($c->table('combo_components')->orderBy('id')->get())),
        ];
    }

    private function switch(bool $on): void
    {
        $this->setFeatures($on ? self::ON : self::OFF);
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
        $planId = $m->table('plans')->where('code', 'dsf-all')->value('id') ?? $m->table('plans')->insertGetId([
            'code' => 'dsf-all', 'name' => 'Deal split form test', 'price' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();
        foreach ($m->table('modules')->where('is_active', 1)->pluck('id') as $moduleId) {
            $m->table('plan_modules')->insert(['plan_id' => $planId, 'module_id' => $moduleId, 'is_enabled' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $m->table('subscriptions')->where('tenant_id', $m->table('tenants')->where('tenant_code', self::STORE_CODE)->value('id'))->update(['plan_id' => $planId]);
    }
}
