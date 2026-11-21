<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeLocalPosService;
use App\Services\Printing\EscPosPayloadService;
use App\Services\Sales\SaleIdempotencyService;
use App\Services\Sales\ShiftService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * VEHICLE-NUMBER-1 — quick-sale (drive-through) vehicle capture, online + offline:
 * the canonical sale intent includes it (replay-stable, conflict on change), receipt/KOT
 * print it only when present, and the Edge offline path persists it for quick_sale while
 * always nulling it for other order types (never a stale carried-over value).
 */
class VehicleNumberMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['sales_ledgers', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries', 'stock_ledgers', 'stock_balances', 'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users']);
        $this->branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
    }

    public function test_canonical_intent_includes_the_vehicle_and_hashes_replay_stably(): void
    {
        $svc = app(SaleIdempotencyService::class);
        $base = [
            'branch_id' => $this->branchId, 'order_type' => 'quick_sale', 'vehicle_number' => 'LEA-1234',
            'lines' => [['product_id' => 1, 'quantity' => 1, 'unit_price' => 100]],
        ];

        $this->assertSame('LEA-1234', $svc->canonicalSalePayload($base)['vehicle_number']);

        $hash = $svc->buildPayloadHash($svc->canonicalSalePayload($base));
        $this->assertSame($hash, $svc->buildPayloadHash($svc->canonicalSalePayload($base)), 'identical intent → identical hash (safe replay)');
        $this->assertNotSame($hash, $svc->buildPayloadHash($svc->canonicalSalePayload(array_merge($base, ['vehicle_number' => 'LEB-9999']))), 'a different vehicle is a DIFFERENT intent');
    }

    public function test_receipt_and_kot_print_the_vehicle_only_when_present(): void
    {
        $productId = $this->makeProduct($this->makeCategory());
        $saleId = $this->makeSale($this->branchId, ['order_type' => 'quick_sale', 'vehicle_number' => 'LEA-1234', 'subtotal' => 100, 'grand_total' => 100]);
        $this->makeSaleLine($saleId, $productId);

        $receipt = app(EscPosPayloadService::class)->build(PrintJob::findOrFail($this->makePrintJob(null, ['document_type' => 'receipt', 'print_status' => 'queued', 'printed_at' => null, 'reference_type' => 'sales_order', 'reference_id' => $saleId, 'branch_id' => $this->branchId])));
        $this->assertStringContainsString('Vehicle: LEA-1234', $receipt);

        $kot = app(EscPosPayloadService::class)->build(PrintJob::findOrFail($this->makePrintJob(null, ['document_type' => 'kot', 'print_status' => 'queued', 'printed_at' => null, 'reference_type' => 'sales_order', 'reference_id' => $saleId, 'branch_id' => $this->branchId])));
        $this->assertStringContainsString('VEHICLE: LEA-1234', $kot);

        $plainId = $this->makeSale($this->branchId, ['subtotal' => 100, 'grand_total' => 100]);
        $this->makeSaleLine($plainId, $productId);
        $plain = app(EscPosPayloadService::class)->build(PrintJob::findOrFail($this->makePrintJob(null, ['document_type' => 'receipt', 'print_status' => 'queued', 'printed_at' => null, 'reference_type' => 'sales_order', 'reference_id' => $plainId, 'branch_id' => $this->branchId])));
        $this->assertStringNotContainsString('Vehicle:', $plain, 'no vehicle line without a vehicle');
    }

    public function test_edge_offline_quick_sale_persists_the_vehicle_and_other_types_null_it(): void
    {
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_local_meta']);
        $userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'VN' . Str::random(4)]);
        $terminalId = $this->makeTerminal($this->branchId);
        $productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $cash = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->asBranchServerRuntime();
        $this->acceptTestBaseline([['product_id' => $productId, 'product_variant_id' => null, 'quantity' => 10]]);
        $waiterId = $this->makeWaiter($this->branchId); // PHASE 2b: a quick sale requires a waiter
        $user = User::on('tenant')->find($userId);
        $this->actingAs($user, 'tenant');
        Auth::shouldUse('tenant');
        app(ShiftService::class)->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($terminalId), $userId, 0.0);

        try {
            $complete = fn (array $overrides) => app(EdgeLocalPosService::class)->completePaidSale(array_merge([
                'order_type' => 'quick_sale', 'client_uuid' => (string) Str::uuid(),
                'vehicle_number' => 'LEA-DEFAULT', 'restaurant_waiter_id' => $waiterId,
                'lines' => [['product_id' => $productId, 'quantity' => 1]],
                'payments' => [['payment_method_id' => $cash, 'amount' => 100]],
            ], $overrides), $user, $terminalId);

            $quick = $complete(['vehicle_number' => '  LEA-1234  ']);
            $this->assertSame('LEA-1234', $quick->vehicle_number, 'offline quick sale stores the trimmed vehicle');

            // takeaway carries neither vehicle nor waiter (PHASE 2b), so drop both overrides for it.
            $takeaway = $complete(['order_type' => 'takeaway', 'vehicle_number' => null, 'restaurant_waiter_id' => null]);
            $this->assertNull($takeaway->vehicle_number, 'non-quick-sale types never carry a vehicle');

            // A quick sale with a whitespace-only vehicle is now REFUSED by the hard-require contract.
            try {
                $complete(['vehicle_number' => '   ']);
                $this->fail('a quick sale needs a real vehicle number');
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertArrayHasKey('vehicle_number', $e->errors());
            }
        } finally {
            $this->resetRuntimeRole();
        }
    }
}
