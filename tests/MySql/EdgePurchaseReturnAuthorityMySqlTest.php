<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeSyncOutbox;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeBootstrapService;
use App\Services\Edge\EdgeLocalPurchaseReturnService;
use App\Services\Edge\EdgePurchaseReturnCacheService;
use App\Services\Edge\EdgePurchaseReturnEnvelopeBuilder;
use App\Services\Edge\EdgeSupplierFinanceCacheService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\EdgePurchaseReturnFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE EDGE — F3: the appliance's purchase-return authority against a FRESH warm projection (Branch Server, no Cloud).
 * Canonical rules mirrored: a return is built against the source goods receipt; returnable = received − official returns −
 * pending local returns; the branch must hold the stock it returns; a reason is required (header or line); the supplier
 * must be active; a return without a source receipt needs the Online POS; stale projection fails closed. Local effects:
 * operational stock OUT exactly once, provisional supplier payable effect, PENDING SYNC event, immutable envelope — no GL.
 */
class EdgePurchaseReturnAuthorityMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;
    use EdgePurchaseReturnFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $cashierId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(array_merge(self::PR_EDGE_TABLES, self::PR_TABLES, [
            'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta',
            'model_has_permissions', 'permissions', 'products', 'categories', 'units', 'terminals', 'branches', 'users',
        ]));
        $this->branchId = $this->makeBranch(['name' => 'Return Branch', 'allow_negative_stock' => 0]);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'PR' . Str::random(4)]);
        $this->cashierId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'PC' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $unit = DB::table('units')->insertGetId(['code' => 'pc', 'name' => 'Piece', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $product = $this->makeProduct($this->makeCategory(), ['name' => 'Returnable Raw Item', 'unit_id' => $unit, 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_purchasable' => 1, 'is_sellable' => 1, 'status' => 'active']);
        $this->seedCloudPurchaseReturnTruth($this->branchId, $product, $unit);
        $this->bindEdgeLocalMeta($this->branchId, 1, deviceUuid: 'pr-box');
        DB::table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema'), 'authority_last_ack_at' => now()->subMinute()]);
        $this->asBranchServerRuntime();
        $this->acceptTestBaseline([['product_id' => $product, 'product_variant_id' => null, 'quantity' => 10]]);   // the branch holds 10 locally
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->seedEdgeCredential($this->cashierId, $this->branchId, 1);
        $this->grantEdgePermission($this->userId, EdgeLocalPurchaseReturnService::PERM_STORE);
        $this->grantEdgePermission($this->userId, EdgeLocalPurchaseReturnService::PERM_POST);
        $this->projectPurchaseReturnsToAppliance($this->branchId);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function svc(): EdgeLocalPurchaseReturnService
    {
        return app(EdgeLocalPurchaseReturnService::class);
    }

    private function cache(): EdgePurchaseReturnCacheService
    {
        return app(EdgePurchaseReturnCacheService::class);
    }

    private function user(): User
    {
        return User::on('tenant')->find($this->userId);
    }

    private function postLocalReturn(array $overrides = [], ?User $user = null): array
    {
        return $this->svc()->postReturn(array_merge([
            'cloud_grn_id' => $this->prGrnId, 'reason_code' => 'damaged', 'lines' => [['cloud_grn_line_id' => $this->prGrnLineId, 'quantity' => 7]],
        ], $overrides), $user ?? $this->user(), $this->terminalId);
    }

    private function refuse(callable $fn, string $needle): void
    {
        try {
            $fn();
            $this->fail("expected a refusal containing [{$needle}]");
        } catch (ValidationException $e) {
            $this->assertStringContainsString($needle, collect($e->errors())->flatten()->implode(' | '));
        }
    }

    private function localOnHand(): float
    {
        return (float) DB::table('edge_operational_stock_balances')->where('product_id', $this->prProductId)->sum('quantity_on_hand');
    }

    private function nothingRecorded(): void
    {
        $this->assertSame(0, DB::table(EdgePurchaseReturnCacheService::T_EVENTS)->count());
        $this->assertSame(0, DB::table('edge_sync_outbox')->count());
        $this->assertSame(0, DB::table('edge_operational_stock_movements')->where('movement_type', 'purchase_return')->count());
        $this->assertSame(10.0, $this->localOnHand());
    }

    public function test_a_return_against_the_source_receipt_reduces_local_stock_once_and_queues_the_immutable_event(): void
    {
        $this->assertTrue($this->cache()->freshness()['ok']);
        $view = $this->svc()->grn($this->prGrnId);
        $this->assertSame('PB-PR-1', $view['grn']['bill_no']);
        $line = $view['lines'][0];
        $this->assertSame(['quantity_received' => 10.0, 'cloud_returned_quantity' => 0.0, 'pending_local_quantity' => 0.0, 'returnable' => 10.0, 'unit_cost' => 300.0], array_intersect_key($line, array_flip(['quantity_received', 'cloud_returned_quantity', 'pending_local_quantity', 'returnable', 'unit_cost'])));
        $this->assertSame(10.0, $line['local_on_hand']);

        $event = $this->postLocalReturn(['notes' => 'crushed cartons', 'lines' => [['cloud_grn_line_id' => $this->prGrnLineId, 'quantity' => 7, 'reason_code' => 'damaged']]]);

        // Local operational effect — once; the canonical valuation (GRN unit cost) drives the total.
        $this->assertSame('pending', $event['sync']['state']);
        $this->assertSame(2100.0, $event['grand_total'], '7 × 300');
        $this->assertSame(3.0, $this->localOnHand(), 'LOCAL_STOCK_RETURN_TO_SUPPLIER: 10 → 3');
        $this->assertSame(1, DB::table('edge_operational_stock_movements')->where('movement_type', 'purchase_return')->where('direction', 'out')->count());
        $lines = $this->cache()->lines($this->prGrnId);
        $this->assertSame(['pending_local_quantity' => 7.0, 'already_returned' => 7.0, 'returnable' => 3.0], array_intersect_key($lines[0], array_flip(['pending_local_quantity', 'already_returned', 'returnable'])));
        // The provisional supplier effect rides the F2 position (Cr subledger = payable down).
        $this->assertSame(-2100.0, (float) DB::table(EdgeSupplierFinanceCacheService::T_EFFECTS)->where('event_uuid', $event['event_uuid'])->sum('payable_delta'));

        // The immutable event: schema, identity, self-consistent hash, canonical facts.
        $row = EdgeSyncOutbox::on('tenant')->where('sale_uuid', $event['event_uuid'])->firstOrFail();
        $this->assertSame(EdgePurchaseReturnEnvelopeBuilder::SCHEMA, $row->envelope_schema_version);
        $envelope = json_decode((string) $row->envelope, true);
        $copy = $envelope;
        unset($copy['content_hash']);
        $this->assertSame(hash('sha256', app(EdgeBootstrapService::class)->canonicalJson($copy)), $envelope['content_hash']);
        $this->assertSame('purchase_return', $envelope['event_type']);
        $this->assertSame($this->prGrnId, $envelope['goods_receipt']['cloud_grn_id']);
        $this->assertSame($this->prBillId, $envelope['goods_receipt']['cloud_bill_id']);
        $this->assertSame($this->prSupplierId, $envelope['supplier']['cloud_supplier_id']);
        $this->assertSame($this->prGrnLineId, $envelope['lines'][0]['cloud_grn_line_id']);
        $this->assertSame(7.0, (float) $envelope['lines'][0]['quantity']);
        $this->assertSame(300.0, (float) $envelope['lines'][0]['unit_cost']);
        $this->assertSame('damaged', $envelope['lines'][0]['reason_code']);
        $this->assertSame('damaged', $envelope['reason_code']);
        $this->assertSame(2100.0, (float) $envelope['totals']['grand_total']);
        $this->assertSame($this->cache()->freshness()['watermark'], $envelope['freshness']['purchase_return_watermark']);
        $this->assertSame($this->userId, $envelope['actor']['user_id']);

        // NO fake local official effects: no purchase_returns document, no GL, no subledger, no official stock ledger.
        $this->assertSame(0, DB::table('purchase_returns')->count());
        $this->assertSame(0, DB::table('journal_entries')->where('source_type', 'purchase_return')->count());
        $this->assertSame(1, DB::table('supplier_ledgers')->count(), 'the seeded opening row only');
        $this->assertSame(0, DB::table('stock_ledgers')->where('movement_type', 'purchase_return')->count());
    }

    public function test_returnable_quantity_accounts_for_pending_local_returns_and_never_over_returns(): void
    {
        $this->postLocalReturn(['lines' => [['cloud_grn_line_id' => $this->prGrnLineId, 'quantity' => 7]]]);
        $this->refuse(fn () => $this->postLocalReturn(['lines' => [['cloud_grn_line_id' => $this->prGrnLineId, 'quantity' => 4]]]), 'only 3.000 returnable');
        $this->postLocalReturn(['lines' => [['cloud_grn_line_id' => $this->prGrnLineId, 'quantity' => 3]]]);
        $this->assertSame(0.0, $this->cache()->lines($this->prGrnId)[0]['returnable']);
        $this->refuse(fn () => $this->postLocalReturn(['lines' => [['cloud_grn_line_id' => $this->prGrnLineId, 'quantity' => 0.5]]]), 'only 0.000 returnable');
        $this->assertSame(0.0, $this->localOnHand());
        $this->assertSame(2, DB::table('edge_sync_outbox')->where('envelope_schema_version', EdgePurchaseReturnEnvelopeBuilder::SCHEMA)->count());
        $this->assertSame(10.0, (float) DB::table(EdgePurchaseReturnCacheService::T_EVENT_LINES)->sum('quantity'), 'never more than received');
    }

    public function test_refusals_leave_nothing_behind(): void
    {
        $this->refuse(fn () => $this->postLocalReturn(['cloud_grn_id' => 0]), 'needs the Online POS');
        $this->refuse(fn () => $this->postLocalReturn(['cloud_grn_id' => 999999]), 'not in the branch server');
        $this->refuse(fn () => $this->postLocalReturn(['lines' => [['cloud_grn_line_id' => $this->prGrnLineId, 'quantity' => 0]]]), 'at least one received line');
        $this->refuse(fn () => $this->postLocalReturn(['lines' => [['cloud_grn_line_id' => 999999, 'quantity' => 1]]]), 'does not belong to the selected goods receipt');
        $this->refuse(fn () => $this->postLocalReturn(['reason_code' => null]), 'return reason is required');
        $this->refuse(fn () => $this->postLocalReturn(['reason_code' => 'because']), 'valid return reason');
        $this->refuse(fn () => $this->postLocalReturn(['return_date' => 'not-a-date']), 'valid return date');
        $this->nothingRecorded();

        // The branch does not physically hold what it wants to return (local operational stock 10, line returnable 10 — return 10 after a local sale would fail).
        DB::table('edge_operational_stock_balances')->where('product_id', $this->prProductId)->update(['quantity_on_hand' => 4]);
        try {
            $this->postLocalReturn(['lines' => [['cloud_grn_line_id' => $this->prGrnLineId, 'quantity' => 7]]]);
            $this->fail('cannot return stock the branch does not hold');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Insufficient branch stock', collect($e->errors())->flatten()->implode(' '));
        }
        $this->assertSame(0, DB::table('edge_sync_outbox')->count());
        $this->assertSame(4.0, $this->localOnHand(), 'the refusal rolled back — nothing left the branch');

        // Inactive supplier → refused (as Online).
        DB::table(EdgePurchaseReturnCacheService::T_GRNS)->update(['supplier_status' => 'inactive']);
        $this->refuse(fn () => $this->postLocalReturn(['lines' => [['cloud_grn_line_id' => $this->prGrnLineId, 'quantity' => 1]]]), 'inactive');
    }

    public function test_a_stale_projection_fails_closed(): void
    {
        DB::table('edge_local_meta')->update(['standby_purchase_return_watermark_seen' => 'pr:newer-cloud-position', 'authority_last_ack_at' => now()]);
        app()->forgetInstance(\App\Services\Edge\EdgeBranchContext::class);
        $this->assertFalse($this->cache()->freshness()['ok']);
        $this->refuse(fn () => $this->postLocalReturn(), 'not current on this branch server');
        $this->nothingRecorded();
        $this->assertSame(10.0, $this->cache()->lines($this->prGrnId)[0]['returnable'], 'the appliance still shows what it HOLDS, flagged stale — never a guess');
    }

    public function test_a_cashier_without_the_online_permissions_is_refused_and_a_store_only_user_cannot_post(): void
    {
        $cashier = User::on('tenant')->find($this->cashierId);
        try {
            $this->postLocalReturn([], $cashier);
            $this->fail('no permission');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not allowed', $e->getMessage());
        }
        $this->grantEdgePermission($this->cashierId, EdgeLocalPurchaseReturnService::PERM_STORE);   // Online: may save a draft, may not post
        try {
            $this->postLocalReturn([], User::on('tenant')->find($this->cashierId));
            $this->fail('store without post must not post offline');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not allowed', $e->getMessage());
        }
        $this->assertSame(['can_view' => true, 'can_post' => false], $this->svc()->permissionsFor(User::on('tenant')->find($this->cashierId)));
        $this->nothingRecorded();
    }

    public function test_handback_findings_name_pending_failed_and_divergent_purchase_returns(): void
    {
        $a = $this->postLocalReturn(['lines' => [['cloud_grn_line_id' => $this->prGrnLineId, 'quantity' => 2]]]);
        $b = $this->postLocalReturn(['lines' => [['cloud_grn_line_id' => $this->prGrnLineId, 'quantity' => 3]]]);
        $this->assertSame(['pending' => 2, 'failed' => 0, 'divergent' => 0], array_intersect_key($this->cache()->handbackFindings(), array_flip(['pending', 'failed', 'divergent'])));
        DB::table('edge_sync_outbox')->where('sale_uuid', $a['event_uuid'])->update(['state' => EdgeSyncOutbox::STATE_FAILED_PERMANENT]);
        DB::table('edge_sync_outbox')->where('sale_uuid', $b['event_uuid'])->delete();
        $f = $this->cache()->handbackFindings();
        $this->assertSame(['pending' => 0, 'failed' => 1, 'divergent' => 1], array_intersect_key($f, array_flip(['pending', 'failed', 'divergent'])), json_encode($f));
        $this->assertSame('failed', $this->cache()->event($a['event_uuid'])['sync']['state']);
        $this->assertSame('missing', $this->cache()->event($b['event_uuid'])['sync']['state']);
    }
}
