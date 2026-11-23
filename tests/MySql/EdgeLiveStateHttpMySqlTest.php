<?php

namespace Tests\MySql;

use App\Models\Master\EdgeDevice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeSetupCloudFixture;
use Tests\MySql\Support\RegistersTestTenant;

/**
 * EDGE 0.9 stream C — K1 GET /api/edge/live-state over the REAL central-domain HTTP stack (AuthenticateEdgeDevice) against
 * the real tenant schema: only the device's branch; open shifts with running counters; open table sessions with their
 * unpaid (held) orders, lines, payments and KOT state; stand-alone held sales; money as decimal strings; no customer PII;
 * a MONOTONIC per-device revision + state hash + generated_at; `since` short-circuit; read-only (every tenant table
 * byte-identical after the calls — nothing is created, so nothing can be duplicated); stale/foreign devices fail closed.
 */
class EdgeLiveStateHttpMySqlTest extends MySqlTenantTestCase
{
    use EdgeSetupCloudFixture;
    use RegistersTestTenant;

    public const TENANT_CODE = 'e09clive';

    private EdgeDevice $device;
    private string $secret = 'live-secret';
    private array $ids = [];

    private const TABLES = ['terminals', 'shifts', 'restaurant_floors', 'restaurant_tables', 'restaurant_table_sessions', 'sales_orders',
        'sales_order_lines', 'sale_payments', 'payment_methods', 'kot_batches', 'kot_batch_lines', 'products'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEdgeCloud();
        $this->seedOperationalState();
        $this->device = $this->makeDevice($this->branchA, $this->secret);
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanupEdgeCloud();
        } catch (\Throwable $e) {
        }
        parent::tearDown();
    }

    private function seedOperationalState(): void
    {
        $this->cleanTenant(['kot_batch_lines', 'kot_batches', 'sale_payments', 'sales_order_lines', 'sales_orders', 'restaurant_table_sessions',
            'restaurant_tables', 'restaurant_floors', 'shifts', 'terminals', 'payment_methods', 'products']);
        $c = DB::connection('tenant');
        $t = ['created_at' => '2026-11-21 10:00:00', 'updated_at' => '2026-11-21 10:00:00'];
        $i = &$this->ids;
        $owner = $this->users['owner'];

        $i['cash'] = $c->table('payment_methods')->insertGetId(['code' => 'CASH', 'name' => 'Cash', 'method_type' => 'cash', 'is_cash_drawer' => 1, 'is_active' => 1] + $t);
        $i['p1'] = $c->table('products')->insertGetId(['sku' => 'BIR', 'name' => 'Biryani', 'slug' => 'biryani', 'status' => 'active'] + $t);
        $i['p2'] = $c->table('products')->insertGetId(['sku' => 'RAI', 'name' => 'Raita', 'slug' => 'raita', 'status' => 'active'] + $t);

        foreach (['A' => $this->branchA, 'B' => $this->branchB] as $k => $b) {
            $i["t1$k"] = $c->table('terminals')->insertGetId(['branch_id' => $b, 'code' => "T1$k", 'name' => "Till 1 $k"] + $t);
            $i["t2$k"] = $c->table('terminals')->insertGetId(['branch_id' => $b, 'code' => "T2$k", 'name' => "Till 2 $k"] + $t);
            $i["floor$k"] = $c->table('restaurant_floors')->insertGetId(['branch_id' => $b, 'name' => "Main $k"] + $t);
            $i["tbl$k"] = $c->table('restaurant_tables')->insertGetId(['branch_id' => $b, 'restaurant_floor_id' => $i["floor$k"], 'table_no' => "T$k", 'status' => 'occupied'] + $t);
        }
        // Shifts: A/till1 open (running counters), A/till2 CLOSED, B/till1 open.
        $shift = fn (int $b, int $term, string $status, array $x = []) => $c->table('shifts')->insertGetId(array_merge([
            'shift_uuid' => (string) Str::ulid(), 'branch_id' => $b, 'terminal_id' => $term, 'opened_by_user_id' => $owner,
            'opening_cash' => 1500, 'status' => $status, 'opened_at' => '2026-11-21 04:00:00', 'business_date' => '2026-11-21',
        ], $x) + $t);
        $i['shiftA'] = $shift($this->branchA, $i['t1A'], 'open', ['total_sales' => 2450.5, 'total_cash' => 2000.25, 'total_card' => 450.25,
            'total_refunds' => 100, 'total_cash_refunds' => 100, 'total_discount' => 50, 'total_tax' => 0, 'expected_cash' => 3400.25]);
        $i['shiftA2'] = $shift($this->branchA, $i['t2A'], 'closed', ['closed_at' => '2026-11-21 09:00:00']);
        $i['shiftB'] = $shift($this->branchB, $i['t1B'], 'open');

        $session = fn (int $b, int $tbl, string $status, ?int $shiftId) => $c->table('restaurant_table_sessions')->insertGetId([
            'session_uuid' => (string) Str::ulid(), 'session_no' => 'S-' . Str::random(8), 'branch_id' => $b, 'restaurant_table_id' => $tbl,
            'opened_by_user_id' => $owner, 'opened_shift_id' => $shiftId, 'guest_count' => 4, 'status' => $status, 'business_date' => '2026-11-21',
            'opened_at' => '2026-11-21 09:30:00', 'customer_name' => 'Secret Customer', 'customer_phone' => '03001234567',
        ] + $t);
        $i['sessA'] = $session($this->branchA, $i['tblA'], 'open', $i['shiftA']);
        $i['sessClosed'] = $session($this->branchA, $i['tblA'], 'closed', $i['shiftA']);
        $i['sessB'] = $session($this->branchB, $i['tblB'], 'open', $i['shiftB']);

        $order = fn (int $b, ?int $sess, string $status, array $x = []) => $c->table('sales_orders')->insertGetId(array_merge([
            'sale_uuid' => (string) Str::ulid(), 'sale_no' => 'SO-' . Str::random(8), 'branch_id' => $b, 'terminal_id' => $b === $this->branchA ? $i['t1A'] : $i['t1B'],
            'shift_id' => $b === $this->branchA ? $i['shiftA'] : $i['shiftB'], 'restaurant_table_session_id' => $sess,
            'restaurant_table_id' => $sess ? ($b === $this->branchA ? $i['tblA'] : $i['tblB']) : null, 'order_type' => $sess ? 'dine_in' : 'takeaway',
            'sale_date' => '2026-11-21', 'business_date' => '2026-11-21', 'subtotal' => 900, 'discount_amount' => 0, 'tax_amount' => 0,
            'grand_total' => 900, 'paid_amount' => 0, 'balance_due' => 900, 'status' => $status, 'customer_name' => 'Secret Customer',
            'customer_phone' => '03001234567', 'delivery_address' => 'Secret Street 1', 'created_by_user_id' => $owner,
        ], $x) + $t);
        $i['oTable1'] = $order($this->branchA, $i['sessA'], 'held', ['notes' => 'no chilli', 'paid_amount' => 200, 'balance_due' => 700]);
        $i['oTable2'] = $order($this->branchA, $i['sessA'], 'held', ['is_draft' => 1]);
        $i['oPaid'] = $order($this->branchA, $i['sessA'], 'paid', ['paid_amount' => 900, 'balance_due' => 0]);
        $i['oHeld'] = $order($this->branchA, null, 'held', ['grand_total' => 450.75, 'subtotal' => 450.75, 'balance_due' => 450.75]);
        $i['oHeldB'] = $order($this->branchB, null, 'held');
        $i['oSessB'] = $order($this->branchB, $i['sessB'], 'held');

        $line = fn (int $o, int $p, float $q, array $x = []) => $c->table('sales_order_lines')->insertGetId(array_merge([
            'line_uuid' => (string) Str::ulid(), 'sales_order_id' => $o, 'product_id' => $p, 'product_name' => $p === $i['p1'] ? 'Biryani' : 'Raita',
            'quantity' => $q, 'unit_price' => 450, 'line_total' => 450 * $q, 'kot_sent' => 0, 'kot_sent_quantity' => 0,
        ], $x) + $t);
        $i['l1'] = $line($i['oTable1'], $i['p1'], 2, ['kot_sent' => 1, 'kot_sent_quantity' => 2, 'kitchen_note' => 'extra spicy', 'modifiers' => json_encode([['name' => 'Large']])]);
        $i['l2'] = $line($i['oTable1'], $i['p2'], 3, ['kot_sent' => 1, 'kot_sent_quantity' => 1]);
        $i['l3'] = $line($i['oTable2'], $i['p1'], 1);
        $i['l4'] = $line($i['oHeld'], $i['p2'], 1.5, ['unit_price' => 300.5, 'line_total' => 450.75]);
        $line($i['oPaid'], $i['p1'], 2, ['kot_sent' => 1, 'kot_sent_quantity' => 2]);
        $line($i['oHeldB'], $i['p1'], 1);

        $c->table('sale_payments')->insert(['payment_uuid' => (string) Str::ulid(), 'sales_order_id' => $i['oTable1'], 'payment_method_id' => $i['cash'], 'amount' => 200] + $t);
        $i['kot1'] = $c->table('kot_batches')->insertGetId(['event_uuid' => (string) Str::uuid(), 'sales_order_id' => $i['oTable1'], 'sequence_no' => 1, 'event_type' => 'send'] + $t);
        $i['kot2'] = $c->table('kot_batches')->insertGetId(['event_uuid' => (string) Str::uuid(), 'sales_order_id' => $i['oTable1'], 'sequence_no' => 2, 'event_type' => 'send'] + $t);
        $c->table('kot_batch_lines')->insert([
            ['kot_batch_id' => $i['kot1'], 'sales_order_line_id' => $i['l1'], 'product_name' => 'Biryani', 'quantity' => 2] + $t,
            ['kot_batch_id' => $i['kot1'], 'sales_order_line_id' => $i['l2'], 'product_name' => 'Raita', 'quantity' => 1] + $t,
            ['kot_batch_id' => $i['kot2'], 'sales_order_line_id' => $i['l1'], 'product_name' => 'Biryani', 'quantity' => 0] + $t,
        ]);
    }

    private function live(?string $since = null, ?EdgeDevice $device = null, ?string $secret = null): \Illuminate\Testing\TestResponse
    {
        return $this->getJson($this->central('/api/edge/live-state' . ($since !== null ? '?since=' . urlencode($since) : '')),
            $this->deviceHeaders($device ?? $this->device, $secret ?? $this->secret));
    }

    public function test_the_branch_live_state_has_the_k1_shape_and_only_the_devices_branch(): void
    {
        $res = $this->live()->assertOk();
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $j = $res->json();
        $c = DB::connection('tenant');

        $this->assertSame('edge-live-state-v1', $j['schema']);
        $this->assertSame('1', $j['revision']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $j['state_hash']);
        $this->assertNotEmpty($j['generated_at']);
        $this->assertSame($this->branchA, $j['branch_id']);
        $this->assertFalse($j['truncated']);

        // Shifts: only branch A's OPEN shift, with running counters as decimal strings.
        $this->assertCount(1, $j['shifts']);
        $s = $j['shifts'][0];
        $this->assertSame($c->table('shifts')->where('id', $this->ids['shiftA'])->value('shift_uuid'), $s['shift_uuid']);
        $this->assertSame($this->ids['t1A'], $s['terminal_id']);
        $this->assertSame('1500.00', $s['opening_cash']);
        $this->assertSame(['total_sales' => '2450.50', 'cash_sales' => '2000.25', 'card_sales' => '450.25', 'bank_transfer_sales' => '0.00',
            'cheque_sales' => '0.00', 'refunds' => '100.00', 'cash_refunds' => '100.00', 'discounts' => '50.00', 'tax' => '0.00',
            'cash_in' => '0.00', 'cash_out' => '0.00', 'expected_cash' => '3400.25'], $s['running']);
        $this->assertSame($this->users['owner'], $s['opened_by_user_id']);

        // Table sessions: only the OPEN session of branch A, with its HELD orders (the paid one is not mirrored).
        $this->assertCount(1, $j['table_sessions']);
        $ts = $j['table_sessions'][0];
        $this->assertSame($c->table('restaurant_table_sessions')->where('id', $this->ids['sessA'])->value('session_uuid'), $ts['session_uuid']);
        $this->assertSame($this->ids['tblA'], $ts['table_id']);
        $this->assertSame(4, $ts['covers']);
        $this->assertSame($s['shift_uuid'], $ts['opened_shift_uuid']);
        $this->assertSame([$this->ids['oTable1'], $this->ids['oTable2']], array_column($ts['orders'], 'order_id'));
        $o1 = $ts['orders'][0];
        $this->assertSame('open', $o1['status']);
        $this->assertSame($s['shift_uuid'], $o1['shift_uuid']);
        $this->assertSame('900.00', $o1['total']);
        $this->assertSame('200.00', $o1['paid']);
        $this->assertSame('700.00', $o1['balance_due']);
        $this->assertSame('no chilli', $o1['notes']);
        $this->assertSame([['payment_uuid' => $o1['payments'][0]['payment_uuid'], 'payment_method_id' => $this->ids['cash'], 'method' => 'cash', 'method_code' => 'CASH', 'amount' => '200.00']], $o1['payments']);
        $this->assertSame(['send', 'send'], array_column($o1['kot_batches'], 'event_type'));
        [$l1, $l2] = $o1['lines'];
        $this->assertSame('2', $l1['qty']);
        $this->assertSame('450.00', $l1['unit_price']);
        $this->assertSame('extra spicy', $l1['notes']);
        $this->assertSame([['name' => 'Large']], $l1['modifiers']);
        $this->assertSame('fired', $l1['kot_state']);
        $this->assertSame($c->table('kot_batches')->where('id', $this->ids['kot2'])->value('event_uuid'), $l1['kot_batch_uuid'], 'the latest batch of the line');
        $this->assertSame('pending', $l2['kot_state'], 'partly sent (1 of 3) is pending — stream A\'s rule');
        $this->assertSame(['450.00', '0.00', '0.00'], [$l1['unit_price'], $l1['discount_amount'], $l1['tax_amount']]);
        $this->assertSame('Biryani', $l1['product_name']);
        $this->assertArrayHasKey('line_kind', $l1);
        $this->assertArrayHasKey('combo_id', $l1);
        $this->assertArrayHasKey('restaurant_waiter_id', $ts);
        $this->assertArrayHasKey('restaurant_waiter_id', $o1);
        $this->assertSame('0.00', $o1['service_charge']);
        $this->assertNotEmpty($ts['session_no']);
        $this->assertSame('2026-11-21', $s['business_date']);
        $this->assertSame('1', $l2['kot_sent_qty']);
        $this->assertTrue($ts['orders'][1]['is_draft']);
        $this->assertSame('pending', $ts['orders'][1]['lines'][0]['kot_state']);
        $this->assertNull($ts['orders'][1]['lines'][0]['kot_batch_uuid']);

        // Held sales: branch A's stand-alone held sale only.
        $this->assertSame([$this->ids['oHeld']], array_column($j['held_sales'], 'order_id'));
        $this->assertSame('450.75', $j['held_sales'][0]['total']);
        foreach (['order_type', 'order_no', 'shift_uuid', 'subtotal', 'discount', 'tax', 'service_charge', 'order_uuid', 'held_at', 'terminal_id', 'customer_id', 'lines'] as $k) {
            $this->assertArrayHasKey($k, $j['held_sales'][0], "held_sales[].{$k}");
        }
        $this->assertSame(26, strlen((string) $j['held_sales'][0]['order_uuid']), 'identities are the stored ULIDs');
        $this->assertSame('takeaway', $j['held_sales'][0]['order_type']);
        $this->assertSame('1.5', $j['held_sales'][0]['lines'][0]['qty']);
        $this->assertSame('300.50', $j['held_sales'][0]['lines'][0]['unit_price']);
        $this->assertNotEmpty($j['held_sales'][0]['held_at']);

        // No customer PII and nothing of branch B.
        $raw = $res->getContent();
        foreach (['Secret Customer', '03001234567', 'Secret Street'] as $pii) {
            $this->assertStringNotContainsString($pii, $raw);
        }
        foreach (['shiftB', 'sessB', 'oHeldB', 'oSessB'] as $k) {
            $uuid = $c->table(str_starts_with($k, 'shift') ? 'shifts' : (str_starts_with($k, 'sess') ? 'restaurant_table_sessions' : 'sales_orders'))
                ->where('id', $this->ids[$k])->value(str_starts_with($k, 'shift') ? 'shift_uuid' : (str_starts_with($k, 'sess') ? 'session_uuid' : 'sale_uuid'));
            $this->assertStringNotContainsString($uuid, $raw, "{$k} of branch B must not leak");
        }

        // Branch B's device sees branch B only.
        $deviceB = $this->makeDevice($this->branchB, 'secret-b');
        $jb = $this->live(null, $deviceB, 'secret-b')->assertOk()->json();
        $this->assertSame($this->branchB, $jb['branch_id']);
        $this->assertSame([$this->ids['sessB']], array_column($jb['table_sessions'], 'session_id'));
        $this->assertSame([$this->ids['oHeldB']], array_column($jb['held_sales'], 'order_id'));
        $this->assertSame('1', $jb['revision'], 'revisions are per device');
    }

    public function test_the_revision_is_monotonic_stable_while_unchanged_and_since_short_circuits(): void
    {
        $first = $this->live()->assertOk()->json();
        $again = $this->live()->assertOk()->json();
        $this->assertSame($first['revision'], $again['revision'], 'no change, no new revision');
        $this->assertSame($first['state_hash'], $again['state_hash']);

        $same = $this->live($first['revision'])->assertOk()->json();
        $this->assertTrue($same['unchanged']);
        $this->assertSame($first['revision'], $same['revision']);
        $this->assertArrayNotHasKey('shifts', $same);
        $this->assertNotEmpty($same['generated_at']);

        // A cashier adds a line on the Cloud → a NEW, greater revision; an old `since` gets the full state.
        DB::connection('tenant')->table('sales_order_lines')->insert(['line_uuid' => (string) Str::ulid(), 'sales_order_id' => $this->ids['oTable2'],
            'product_id' => $this->ids['p2'], 'product_name' => 'Raita', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100,
            'created_at' => now(), 'updated_at' => now()]);
        $changed = $this->live($first['revision'])->assertOk()->json();
        $this->assertArrayNotHasKey('unchanged', $changed);
        $this->assertGreaterThan((int) $first['revision'], (int) $changed['revision']);
        $this->assertNotSame($first['state_hash'], $changed['state_hash']);
        $this->assertCount(2, $changed['table_sessions'][0]['orders'][1]['lines']);

        // Paying the held sale on the Cloud removes it from the mirror → another revision.
        DB::connection('tenant')->table('sales_orders')->where('id', $this->ids['oHeld'])->update(['status' => 'paid']);
        $paid = $this->live($changed['revision'])->assertOk()->json();
        $this->assertSame([], $paid['held_sales']);
        $this->assertSame((int) $changed['revision'] + 1, (int) $paid['revision']);
        $this->assertSame($paid['revision'], (string) DB::connection('master')->table('edge_devices')->where('id', $this->device->id)->value('live_state_revision'));
    }

    public function test_live_state_is_read_only(): void
    {
        $before = $this->tableFingerprints(self::TABLES);
        $this->live()->assertOk();
        $this->live('1')->assertOk();
        $this->live('stale-revision')->assertOk();
        $this->assertSame($before, $this->tableFingerprints(self::TABLES), 'the live-state endpoint never writes a tenant row');
    }

    public function test_stale_foreign_or_unbootstrapped_devices_fail_closed(): void
    {
        // Wrong secret / no headers.
        $this->getJson($this->central('/api/edge/live-state'))->assertStatus(401);
        $this->live(null, $this->device, 'wrong')->assertStatus(401);

        // A device that has not finished its bootstrap.
        $this->device->forceFill(['status' => EdgeDevice::STATUS_PENDING_BOOTSTRAP])->save();
        $this->live()->assertStatus(409)->assertJsonPath('code', 'EDGE_DEVICE_NOT_CURRENT')->assertJsonPath('reason', 'not_bootstrapped');
        $this->device->forceFill(['status' => EdgeDevice::STATUS_READY])->save();

        // Entitlement withdrawn.
        config(['edge.testing.assume_entitled' => false, 'app.edge_feature_enabled' => false]);
        $this->live()->assertStatus(403)->assertJsonPath('code', 'EDGE_ENTITLEMENT_REQUIRED');
        config(['edge.testing.assume_entitled' => true]);

        // Revoked device.
        $this->device->forceFill(['status' => EdgeDevice::STATUS_REVOKED, 'active_slot' => null, 'revoked_at' => now()])->save();
        $this->live()->assertStatus(401);
    }
}
