<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Reports\SalesReportCenterController;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * REPORT-NETWORK-BRIDGE-1 — the report a cashier SENDS to the counter printer ends the way the one
 * he prints from the browser ends.
 *
 * Khatri delivery counter, 27 Oct: the browser print of the category report closed on "Plus
 * Delivery Charge 7,200 / = NET SALES 59,220"; the same report sent to the network printer stopped
 * at GRAND TOTAL. The two are built by different code (print.blade.php vs EscPosPayloadService),
 * and every earlier fix of these lines went into the blade only.
 *
 * This runs both through the real controller on the same day's sales and holds their closing lines
 * to the same labels and figures, section by section.
 */
class ReportNetworkBridgeMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const SECTIONS = ['categories', 'items', 'category_items'];

    private int $branchId;
    private int $printerId;
    private string $day;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'print_jobs', 'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods',
            'products', 'categories', 'printers', 'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch();
        $this->printerId = $this->makePrinter([
            'code' => 'DLV', 'name' => 'Delivery Receipt + KOT', 'print_role' => 'both',
            'printer_type' => 'network', 'ip_address' => '192.168.1.9', 'branch_id' => $this->branchId,
        ]);
        $product = $this->makeProduct($this->makeCategory(['name' => 'Biryani']), ['name' => 'Biryani Chicken']);

        // 3 × 100 of food, 30 delivery on top: lines 300, NET SALES 330.
        $sale = $this->makeSale($this->branchId, [
            'order_type' => 'delivery', 'subtotal' => 300, 'delivery_charge_amount' => 30,
            'grand_total' => 330, 'paid_amount' => 330,
        ]);
        $this->makeSaleLine($sale, $product, ['product_name' => 'Biryani Chicken', 'quantity' => 3, 'unit_price' => 100, 'line_total' => 300, 'line_kind' => 'standard']);
        $this->day = (string) DB::connection('tenant')->table('sales_orders')->where('id', $sale)->value('business_date');

        $perms = array_merge(['tenant.reports.center.index'], array_map(
            fn ($s) => SalesReportCenterController::sectionPermission($s), self::SECTIONS
        ));
        foreach ($perms as $p) {
            Permission::findOrCreate($p, 'tenant');
        }
        $user = User::on('tenant')->find($this->makeUser(['default_branch_id' => $this->branchId]));
        $user->givePermissionTo($perms);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs(User::on('tenant')->find($user->id), 'tenant');
        Auth::shouldUse('tenant');
        view()->share('errors', new ViewErrorBag);
    }

    private function hit(string $action, string $method, array $params)
    {
        $req = Request::create('/reports/center/' . $action, $method, $params + [
            'date_from' => $this->day, 'date_to' => $this->day, 'branch_ids' => [$this->branchId],
        ]);
        $req->headers->set('Accept', $method === 'POST' ? 'application/json' : 'text/html');
        $req->setUserResolver(fn () => auth('tenant')->user());
        app()->instance('request', $req);

        return app()->call([app(SalesReportCenterController::class), $action === 'send-to-network' ? 'sendToNetwork' : 'print'], ['request' => $req]);
    }

    /** "label => amount" for the closing lines of the network slip. */
    private function networkLines(string $section): array
    {
        $resp = $this->hit('send-to-network', 'POST', ['printer_id' => $this->printerId, 'sections' => [$section]]);
        $this->assertTrue(json_decode($resp->getContent(), true)['ok'] ?? false, "send-to-network for {$section}: " . $resp->getContent());

        $raw = (string) PrintJob::on('tenant')->latest('id')->value('raw_payload');
        $text = preg_replace(['/\x1D\x21./s', '/\x1B\x45./s', '/\x1B\x2D./s', '/[\x00-\x08\x0B-\x1F]/'], ['', '', '', ''], $raw);
        preg_match_all('/^((?:Plus|Less) [A-Za-z ]+?|= NET SALES) +(-?[\d,]+)$/m', $text, $m, PREG_SET_ORDER);

        return collect($m)->mapWithKeys(fn ($x) => [trim($x[1]) => (float) str_replace(',', '', $x[2])])->all();
    }

    /** "label => amount" for the closing lines of the browser's thermal print. */
    private function manualLines(string $section): array
    {
        $html = $this->hit('print', 'GET', ['mode' => 'thermal', 'paper' => '80mm', 'sections' => [$section]])->render();
        preg_match_all('#<td(?: colspan="\d+")?>((?:Plus|Less) [^<]+|= NET SALES)</td><td class="amt">([^<]+)</td>#', $html, $m, PREG_SET_ORDER);

        return collect($m)->mapWithKeys(fn ($x) => [trim(html_entity_decode($x[1])) => (float) str_replace(',', '', $x[2])])->all();
    }

    public function test_the_network_slip_closes_on_net_sales_like_the_manual_print(): void
    {
        foreach (self::SECTIONS as $section) {
            $manual = $this->manualLines($section);
            $network = $this->networkLines($section);

            $this->assertSame(['Plus Delivery Charge' => 30.0, '= NET SALES' => 330.0], $manual,
                "{$section}: the manual print is the reference and must close on 30 / 330");
            $this->assertSame($manual, $network, "{$section}: the network slip must close exactly like the manual print");
        }
    }

    /** The lines come after the section's total and before the paper cut. */
    public function test_the_lines_sit_under_grand_total_and_before_the_cut(): void
    {
        $this->networkLines('categories');
        $raw = (string) PrintJob::on('tenant')->latest('id')->value('raw_payload');

        $grand = strpos($raw, 'GRAND TOTAL');
        $delivery = strpos($raw, 'Plus Delivery Charge');
        $net = strpos($raw, '= NET SALES');
        $this->assertNotFalse($grand);
        $this->assertTrue($grand < $delivery && $delivery < $net, 'GRAND TOTAL → delivery → NET SALES');
        $this->assertStringEndsWith("\x1D\x56\x42\x00", $raw, 'the slip still ends with the cut');
    }
}
