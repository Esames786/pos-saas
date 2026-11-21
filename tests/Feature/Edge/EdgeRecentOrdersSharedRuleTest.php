<?php

namespace Tests\Feature\Edge;

use App\Services\Edge\EdgeReturnableSaleProjectionService;
use App\Support\Pos\RecentOrders;
use Tests\TestCase;

/**
 * PHASE 4 — GAP C static gate (no database): there is ONE Recent Orders list rule and both runtimes call it. The data
 * proof (mirror + HTTP JSON twin) is tests/MySql/EdgeRecentOrdersMirrorHttpMySqlTest; this guards the shape of the code
 * so an Edge-only (or Online-only) rule cannot creep back in.
 */
class EdgeRecentOrdersSharedRuleTest extends TestCase
{
    private function source(string $relative): string
    {
        return (string) file_get_contents(base_path($relative));
    }

    public function test_both_runtimes_call_the_one_recent_orders_rule_and_own_no_list_rule_of_their_own(): void
    {
        $online = $this->source('app/Http/Controllers/Tenant/POSController.php');
        $edge = $this->source('app/Services/Edge/EdgeLocalOrderLifecycleService.php');
        $edgeController = $this->source('app/Http/Controllers/Edge/EdgeLocalHeldSalesController.php');

        foreach (['online' => $online, 'edge' => $edge] as $runtime => $src) {
            $this->assertStringContainsString('RecentOrders::applyInclusion(', $src, "{$runtime}: inclusion comes from the shared rule");
            $this->assertStringContainsString('RecentOrders::present(', $src, "{$runtime}: the row comes from the shared presenter");
            $this->assertStringContainsString('RecentOrders::LIMIT', $src, "{$runtime}: the limit is the shared one");
            $this->assertStringNotContainsString("'status', '!=', 'held'", $src, "{$runtime}: no private held filter");
            $this->assertStringNotContainsString("'table' =>", $src, "{$runtime}: no private row builder (table label)");
            $this->assertStringNotContainsString("number_format(", substr($src, strpos($src, 'function recentSales')), "{$runtime}: no private total formatting in recentSales");
        }
        // The Edge controller is a pass-through: no post-processing of the rows (the W-B time/ago patch is gone).
        $this->assertStringNotContainsString('formatSale', substr($edgeController, strpos($edgeController, 'function recentSales'), 800));
    }

    public function test_the_shared_rule_lists_every_non_held_sale_and_the_projection_mirrors_that_population(): void
    {
        $this->assertSame(['held'], RecentOrders::EXCLUDED_STATUSES, 'cancelled sales are LISTED (Online rule); only held checks are left out');
        $this->assertSame(50, RecentOrders::LIMIT);
        $this->assertSame(['paid', 'partially_returned'], EdgeReturnableSaleProjectionService::RETURNABLE_STATUSES, 'what may be RETURNED is a different rule and stays');
        $this->assertGreaterThanOrEqual(RecentOrders::LIMIT, (int) config('edge.returns.recent_orders_mirror_limit'), 'the mirrored population is a superset of the list');

        $projection = $this->source('app/Services/Edge/EdgeReturnableSaleProjectionService.php');
        $this->assertStringContainsString('RecentOrders::EXCLUDED_STATUSES', $projection, 'the projection population uses the shared inclusion');
        foreach (["'restaurant_table_id' =>", "'delivery_channel_id' =>", "'delivery_rider_id' =>", "'payment_status' =>"] as $field) {
            $this->assertStringContainsString($field, $projection, "the package carries {$field}");
        }
        $cache = $this->source('app/Services/Edge/EdgeReturnableSaleCacheService.php');
        $this->assertStringContainsString('public static function scopeReturnable(', $cache, 'the return guard is one explicit scope');
        foreach (['app/Services/Edge/EdgeLocalReturnService.php', 'app/Http/Controllers/Edge/EdgeLocalReturnController.php'] as $file) {
            $src = $this->source($file);
            $this->assertStringContainsString('scopeReturnable(', $src, "{$file} guards the return flow with the Cloud status");
            $this->assertStringNotContainsString("whereIn('status', ['paid', 'partially_returned'", $src, "{$file}: no private returnable filter that would accept every shadow row");
        }
    }

    public function test_the_shared_view_consumes_exactly_the_keys_the_presenter_ships(): void
    {
        $view = $this->source('resources/views/tenant/pos/index.blade.php');
        $presenter = $this->source('app/Support/Pos/RecentOrders.php');
        $loader = substr($view, strpos($view, 'function loadRecentSales()'), 4000);
        $meta = substr($view, strpos($view, 'function posOrderMeta(s)'), 1500);
        preg_match_all('/\bs\.([a-z_]+)\b/', $loader . $meta, $m);
        $consumed = array_values(array_unique(array_diff($m[1], ['printing', 'id'])));
        sort($consumed);
        foreach ($consumed as $key) {
            $this->assertStringContainsString("'{$key}' =>", $presenter, "the shared view reads s.{$key}; the presenter must ship it");
        }
        $this->assertContains('table', $consumed);
        $this->assertContains('total', $consumed);
    }
}
