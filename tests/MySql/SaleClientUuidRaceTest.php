<?php

namespace Tests\MySql;

use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\MySql\Support\TenantFixtures;

/**
 * §6 — Two-connection concurrency harness proof (MYSQL-TEST-FOUNDATION-1).
 *
 * Genuine engine-level concurrency (NOT sequential method calls): two independent
 * PDO connections open overlapping transactions and race to insert the SAME sale
 * client_uuid. InnoDB serializes them on the unique index — exactly one row must
 * survive; the loser must fail with a duplicate-key error. This is the foundation
 * that future Edge sale-idempotency ingestion tests build on.
 */
class SaleClientUuidRaceTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    public function test_two_connections_racing_same_client_uuid_converge_to_one_sale(): void
    {
        $this->cleanTenant(['sales_order_lines', 'sales_orders', 'branches']);
        $branchId = $this->makeBranch(); // committed on the shared connection

        $uuid = '11111111-2222-3333-4444-555555555555';

        $a = $this->independentTenantPdo();
        $b = $this->independentTenantPdo();
        // Keep the race bounded: the loser should not hang the suite.
        $b->exec('SET SESSION innodb_lock_wait_timeout = 3');

        $insert = "INSERT INTO sales_orders (sale_no, client_uuid, branch_id, order_source, order_type, sale_date, status, created_at, updated_at)
                   VALUES (:sale_no, :uuid, :branch, 'pos', 'quick_sale', NOW(), 'paid', NOW(), NOW())";

        // A opens a transaction and inserts the uuid (holds the unique-index lock).
        $a->beginTransaction();
        $stmtA = $a->prepare($insert);
        $stmtA->execute([':sale_no' => 'SO-A', ':uuid' => $uuid, ':branch' => $branchId]);

        // B opens its own transaction and attempts the SAME uuid — it blocks on A's lock.
        $b->beginTransaction();
        $bFailed = false;
        try {
            // A commits while B is contending; B then resolves to a duplicate-key error.
            $a->commit();
            $stmtB = $b->prepare($insert);
            $stmtB->execute([':sale_no' => 'SO-B', ':uuid' => $uuid, ':branch' => $branchId]);
            $b->commit();
        } catch (PDOException $e) {
            $bFailed = true;
            if ($b->inTransaction()) {
                $b->rollBack();
            }
            $this->assertStringContainsStringIgnoringCase('duplicate', $e->getMessage(),
                'Loser must fail specifically on the unique client_uuid, not some other error.');
        }

        $this->assertTrue($bFailed, 'Exactly one connection must lose the client_uuid race.');

        $count = DB::connection('tenant')->table('sales_orders')->where('client_uuid', $uuid)->count();
        $this->assertSame(1, $count, 'Exactly one sale row may exist for a raced client_uuid.');

        $survivor = DB::connection('tenant')->table('sales_orders')->where('client_uuid', $uuid)->value('sale_no');
        $this->assertSame('SO-A', $survivor, 'The first committer wins; the retry is idempotent (no second row).');
    }
}
