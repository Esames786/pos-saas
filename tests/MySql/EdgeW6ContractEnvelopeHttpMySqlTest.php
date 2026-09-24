<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * W6 (Team 6, wave 2) — the Edge half of the 0.7.0-edge sale contract, over REAL HTTP on a branch_server-booted app
 * (docs/status/edge-w6-contract-and-reconcile-plan.md §B0–§B10):
 *
 *  B1   price-only options ride envelope v1 with ids / group ids / names / deltas (normalized from the synced book) and the
 *       delta-inclusive unit price; a stock-consuming option makes the envelope `edge-sale-envelope-v2` (and only then);
 *  B3   a line-ONLY discount (discount_type none) is syncable — lines[].discount_amount explains totals.discount_amount;
 *  B4   a tip rides totals.tip_amount on a paid sale (the pre-W6 builder guard is lifted);
 *  B5   the Direct-Pay and held-check note rides the top-level `notes` key, which a plain sale never carries (v1 byte shape);
 *  B10  Direct-Pay print intents / direct_pay_print_state are LOCAL ONLY — never a key anywhere in the envelope;
 *  B0.3 the local duplicate-retry fingerprint covers tip, line discounts, the note, kitchen notes and both print intents:
 *       the same request replays the first sale, a changed value under the same client_uuid is a conflict (409), never a
 *       second sale.
 */
class EdgeW6ContractEnvelopeHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $cashId;
    private int $tikka;
    private int $naan;
    private int $extras;
    private int $cheese;
    private int $extraNaan;
    private int $baselineId;

    protected function setUp(): void
    {
        putenv('APP_ROLE=branch_server');
        $_ENV['APP_ROLE'] = $_SERVER['APP_ROLE'] = 'branch_server';
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv("EDGE_LOCAL_APP_KEY={$key}");
        $_ENV['EDGE_LOCAL_APP_KEY'] = $_SERVER['EDGE_LOCAL_APP_KEY'] = $key;
        parent::setUp();

        config(['database.connections.edge_local' => array_merge(
            config('database.connections.edge_local', []),
            ['host' => config('database.connections.tenant.host'), 'port' => config('database.connections.tenant.port'),
                'database' => $this->tenantDb, 'username' => config('database.connections.tenant.username'),
                'password' => config('database.connections.tenant.password')]
        )]);
        DB::purge('edge_local');
        DB::setDefaultConnection('tenant');

        $this->ensureEdgeSchema();
        $this->cleanTenant([
            'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'edge_local_table_reservations',
            'sales_order_line_cancellations', 'kot_batch_lines', 'kot_batches', 'print_jobs', 'manager_approvals', 'model_has_permissions', 'permissions',
            'sales_ledgers', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries', 'stock_ledgers', 'stock_balances',
            'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods',
            'product_modifier_group', 'modifiers', 'modifier_groups', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);
        $t = fn (string $table) => DB::connection('tenant')->table($table);
        $now = now();

        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi', 'manual_discount_approval_mode' => 'auto_approve']);
        $this->terminalId = $this->makeTerminal($this->branchId, ['name' => 'Counter 1']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'default_terminal_id' => $this->terminalId, 'employee_code' => 'W6E' . Str::random(4)]);
        $this->cashId = $this->makePaymentMethod(['method_type' => 'cash', 'name' => 'Cash']);
        $cat = $this->makeCategory(['name' => 'Grills', 'is_active' => 1]);
        $prod = fn (string $name, float $price) => $this->makeProduct($cat, ['name' => $name, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active',
            'default_selling_price' => $price, 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1]);
        $this->tikka = $prod('Chicken Tikka', 250);
        $this->naan = $prod('Naan', 40);
        // A GLOBAL group (branch_id NULL — the common shape, bootstrap v7 now exports it) with a price-only option and a
        // stock-consuming option linked to Naan.
        $this->extras = $t('modifier_groups')->insertGetId(['branch_id' => null, 'name' => 'Extras', 'min_select' => 0, 'max_select' => 3, 'is_required' => 0, 'sort_order' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $this->cheese = $t('modifiers')->insertGetId(['modifier_group_id' => $this->extras, 'name' => 'Extra Cheese', 'price_delta' => 50, 'linked_product_id' => null, 'consume_stock' => 0, 'linked_quantity' => null, 'is_default' => 0, 'sort_order' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $this->extraNaan = $t('modifiers')->insertGetId(['modifier_group_id' => $this->extras, 'name' => 'Extra Naan', 'price_delta' => 40, 'linked_product_id' => $this->naan, 'consume_stock' => 1, 'linked_quantity' => 1, 'is_default' => 0, 'sort_order' => 2, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $t('product_modifier_group')->insert(['product_id' => $this->tikka, 'modifier_group_id' => $this->extras, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now]);

        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->baselineId = (int) $this->acceptTestBaseline([
            ['product_id' => $this->tikka, 'product_variant_id' => null, 'quantity' => 50],
            ['product_id' => $this->naan, 'product_variant_id' => null, 'quantity' => 50],
        ])->id;
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalId])->assertOk();
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(201);
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        parent::tearDown();
    }

    private function payload(array $lines, array $extra = [], float $pay = 100000): array
    {
        return array_merge([
            'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(), 'lines' => $lines,
            'payments' => [['payment_method_id' => $this->cashId, 'amount' => $pay, 'tendered_amount' => $pay]],
        ], $extra);
    }

    private function envelopeFor(int $saleId): array
    {
        $uuid = DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->value('sale_uuid');
        $row = DB::connection('tenant')->table('edge_sync_outbox')->where('sale_uuid', $uuid)->first();
        $this->assertNotNull($row, 'the paid sale wrote its outbox envelope');
        $env = json_decode((string) $row->envelope, true);
        $this->assertSame($row->envelope_schema_version, $env['envelope_schema_version'], 'the outbox row records the envelope schema');

        return $env;
    }

    /** @return list<string> every key at any depth of the envelope */
    private function allKeys(array $node): array
    {
        $keys = [];
        foreach ($node as $k => $v) {
            if (is_string($k)) {
                $keys[] = $k;
            }
            if (is_array($v)) {
                $keys = array_merge($keys, $this->allKeys($v));
            }
        }

        return $keys;
    }

    private function opt(int $id): array
    {
        // client-sent names / deltas are ignored by the server (priced + named from the synced book)
        return ['modifier_group_id' => 0, 'modifier_id' => $id, 'name' => 'CLIENT', 'price_delta' => 999];
    }

    public function test_price_only_options_ride_envelope_v1_with_ids_names_and_deltas_and_a_plain_sale_keeps_the_v1_shape(): void
    {
        $sale = $this->postJson('/edge/local/pos/sales', $this->payload([['product_id' => $this->tikka, 'quantity' => 2, 'modifiers' => [$this->opt($this->cheese)]]]))
            ->assertStatus(201)->json();
        $env = $this->envelopeFor((int) $sale['sale_id']);

        $this->assertSame('edge-sale-envelope-v1', $env['envelope_schema_version'], 'a price-only option never needs v2');
        $this->assertEquals(300.0, (float) $env['lines'][0]['unit_price'], 'catalog 250 + option delta 50 (server-priced)');
        $mod = $env['lines'][0]['modifiers'][0];
        $this->assertSame($this->extras, (int) $mod['modifier_group_id']);
        $this->assertSame('Extras', $mod['modifier_group_name']);
        $this->assertSame($this->cheese, (int) $mod['modifier_id']);
        $this->assertSame('Extra Cheese', $mod['name']);
        $this->assertEquals(50.0, (float) $mod['price_delta']);
        $this->assertArrayNotHasKey('notes', $env, 'a sale without a note keeps the exact pre-W6 top-level key set');
        $this->assertEquals(0.0, (float) $env['totals']['tip_amount']);
        $this->assertSame(hash('sha256', \App\Services\Edge\EdgeCanonicalJson::encode(array_diff_key($env, ['content_hash' => 1]))), $env['content_hash']);
    }

    public function test_a_stock_consuming_option_emits_envelope_v2_and_only_then(): void
    {
        $sale = $this->postJson('/edge/local/pos/sales', $this->payload([['product_id' => $this->tikka, 'quantity' => 2, 'modifiers' => [$this->opt($this->extraNaan)]]]))
            ->assertStatus(201)->json();
        $env = $this->envelopeFor((int) $sale['sale_id']);

        $this->assertSame('edge-sale-envelope-v2', $env['envelope_schema_version']);
        $this->assertSame($this->extraNaan, (int) $env['lines'][0]['modifiers'][0]['modifier_id']);
        $this->assertEquals(290.0, (float) $env['lines'][0]['unit_price']);
        // the appliance already consumed the linked stock operationally (2 × 1 naan) — the Cloud posts the official FEFO on v2
        $this->assertEquals(48.0, $this->edgeOnHand($this->baselineId, $this->naan));

        // the option book changes (no longer consumes stock) → the next sale with the same option is v1 again
        DB::connection('tenant')->table('modifiers')->where('id', $this->extraNaan)->update(['consume_stock' => 0]);
        $plain = $this->postJson('/edge/local/pos/sales', $this->payload([['product_id' => $this->tikka, 'quantity' => 1, 'modifiers' => [$this->opt($this->extraNaan)]]]))
            ->assertStatus(201)->json();
        $this->assertSame('edge-sale-envelope-v1', $this->envelopeFor((int) $plain['sale_id'])['envelope_schema_version']);
    }

    public function test_tip_line_only_discount_and_the_note_ride_the_envelope_on_direct_pay_and_on_a_settled_held_check(): void
    {
        $sale = $this->postJson('/edge/local/pos/sales', $this->payload(
            [['product_id' => $this->tikka, 'quantity' => 2, 'discount_amount' => 30]],
            ['tip_amount' => 20, 'notes' => '  no onions  '],
        ))->assertStatus(201)->json();
        $row = DB::connection('tenant')->table('sales_orders')->find($sale['sale_id']);
        $this->assertEquals(490.0, (float) $row->grand_total, '500 − 30 line discount + 20 tip');
        $env = $this->envelopeFor((int) $sale['sale_id']);
        $this->assertSame('edge-sale-envelope-v1', $env['envelope_schema_version'], 'tip / line discount / note are additive v1 keys');
        $this->assertEquals(20.0, (float) $env['totals']['tip_amount']);
        $this->assertSame('none', $env['totals']['discount_type']);
        $this->assertEquals(30.0, (float) $env['totals']['discount_amount']);
        $this->assertEquals(30.0, (float) $env['lines'][0]['discount_amount']);
        $this->assertEquals(490.0, (float) $env['totals']['grand_total']);
        $this->assertSame('no onions', $env['notes']);

        // held check: the note is written on hold, survives a revise that omits it, and rides the SETTLED envelope
        $held = $this->postJson('/edge/local/pos/held-sales', ['order_type' => 'takeaway', 'notes' => 'birthday table', 'lines' => [['product_id' => $this->naan, 'quantity' => 2]]])
            ->assertStatus(201)->json();
        $lineId = (int) DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $held['sale_id'])->value('id');
        $this->postJson('/edge/local/pos/held-sales', ['held_sale_id' => $held['sale_id'], 'order_type' => 'takeaway',
            'lines' => [['sales_order_line_id' => $lineId, 'product_id' => $this->naan, 'quantity' => 3]]])->assertOk();
        $this->postJson("/edge/local/pos/held-sales/{$held['sale_id']}/settle", ['client_uuid' => (string) Str::uuid(),
            'payments' => [['payment_method_id' => $this->cashId, 'amount' => 120, 'tendered_amount' => 120]]])->assertOk();
        $settledEnv = $this->envelopeFor((int) $held['sale_id']);
        $this->assertSame('birthday table', $settledEnv['notes']);
        $this->assertSame(2, DB::connection('tenant')->table('edge_sync_outbox')->count(), 'one envelope per PAID sale — never for the hold / revise');
    }

    public function test_direct_pay_print_intents_never_enter_the_envelope(): void
    {
        $sale = $this->postJson('/edge/local/pos/sales', $this->payload([['product_id' => $this->tikka, 'quantity' => 1]],
            ['kot_print_intent' => 'print', 'receipt_print_intent' => 'skip']))->assertStatus(201)->json();
        $this->assertNotNull(DB::connection('tenant')->table('sales_orders')->where('id', $sale['sale_id'])->value('direct_pay_print_state'),
            'the intents are persisted LOCALLY (Online direct_pay_print_state)');

        $env = $this->envelopeFor((int) $sale['sale_id']);
        $keys = $this->allKeys($env);
        foreach (['kot_print_intent', 'receipt_print_intent', 'direct_pay_print_state', 'print_intents', 'printing'] as $forbidden) {
            $this->assertNotContains($forbidden, $keys, "print field [{$forbidden}] must never enter a sync envelope");
        }
        $this->assertStringNotContainsString('print_intent', (string) DB::connection('tenant')->table('edge_sync_outbox')->value('envelope'));
    }

    public function test_the_local_retry_fingerprint_covers_tip_line_discounts_notes_kitchen_notes_and_print_intents(): void
    {
        $base = $this->payload([['product_id' => $this->tikka, 'quantity' => 1, 'discount_amount' => 10, 'kitchen_note' => 'well done']],
            ['tip_amount' => 15, 'notes' => 'window seat', 'kot_print_intent' => 'print', 'receipt_print_intent' => 'print']);
        $first = $this->postJson('/edge/local/pos/sales', $base)->assertStatus(201)->json('sale_id');
        // the SAME request replays the first sale (a lost-response retry)
        $this->assertSame($first, $this->postJson('/edge/local/pos/sales', $base)->assertStatus(201)->json('sale_id'));

        $variants = [
            'tip' => array_merge($base, ['tip_amount' => 16]),
            'notes' => array_merge($base, ['notes' => 'door seat']),
            'line discount' => array_merge($base, ['lines' => [['product_id' => $this->tikka, 'quantity' => 1, 'discount_amount' => 11, 'kitchen_note' => 'well done']]]),
            'kitchen note' => array_merge($base, ['lines' => [['product_id' => $this->tikka, 'quantity' => 1, 'discount_amount' => 10, 'kitchen_note' => 'rare']]]),
            'kot print intent' => array_merge($base, ['kot_print_intent' => 'skip']),
            'receipt print intent' => array_merge($base, ['receipt_print_intent' => 'skip']),
        ];
        foreach ($variants as $what => $payload) {
            $this->postJson('/edge/local/pos/sales', $payload)->assertStatus(409);
        }
        $this->assertSame(1, DB::connection('tenant')->table('sales_orders')->count(), 'a changed intent is a conflict, never a second sale');
        $this->assertSame(1, DB::connection('tenant')->table('edge_sync_outbox')->count());
    }
}
