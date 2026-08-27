<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\PosQuickReportController;
use App\Mail\SalesReportMail;
use App\Models\Tenant\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\MySql\Support\TenantFixtures;

/**
 * QUICK-REPORT-SEND-1 — the POS Quick Report modal backend.
 *
 * Proves the two things that make it different from the Report Center: it is UNSCOPED (a
 * terminal-bound user still gets every terminal's data — the permission is the only gate) and its
 * per-section multi-select narrows ONLY the picked breakdown rows while the headline overview stays
 * full. Plus the permission gate, A4 email, network job and per-user saved settings.
 */
class PosQuickReportMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const PERM = 'tenant.pos.quick-report-send';
    private string $date = '2026-08-20';

    private int $branchId;
    private int $catA;
    private int $catB;
    private int $prodA;
    private int $prodB;
    private int $w1;
    private int $w2;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'pos_quick_report_settings', 'report_schedules', 'sales_order_lines', 'sales_orders',
            'restaurant_waiters', 'products', 'categories', 'terminals', 'branches',
            'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'users',
        ]);

        $this->branchId = $this->makeBranch(['status' => 'active']);
        $t1 = $this->makeTerminal($this->branchId, ['name' => 'Delivery']);
        $t2 = $this->makeTerminal($this->branchId, ['name' => 'Dine In']);
        $this->catA = $this->makeCategory(['name' => 'Biryani', 'parent_id' => null]);
        $this->catB = $this->makeCategory(['name' => 'Drinks', 'parent_id' => null]);
        $this->prodA = $this->makeProduct($this->catA, ['name' => 'Beef Biryani']);
        $this->prodB = $this->makeProduct($this->catB, ['name' => 'Cola']);
        $this->w1 = $this->makeWaiter($this->branchId, ['name' => 'Ali']);
        $this->w2 = $this->makeWaiter($this->branchId, ['name' => 'Sara']);

        // Sale on terminal 1: dine_in, waiter Ali, a Biryani (catA).
        $s1 = $this->makeSale($this->branchId, ['terminal_id' => $t1, 'order_type' => 'dine_in', 'restaurant_waiter_id' => $this->w1, 'business_date' => $this->date, 'subtotal' => 100, 'grand_total' => 100]);
        $this->makeSaleLine($s1, $this->prodA, ['unit_price' => 100, 'line_total' => 100]);
        // Sale on terminal 2: takeaway, waiter Sara, a Cola (catB).
        $s2 = $this->makeSale($this->branchId, ['terminal_id' => $t2, 'order_type' => 'takeaway', 'restaurant_waiter_id' => $this->w2, 'business_date' => $this->date, 'subtotal' => 200, 'grand_total' => 200]);
        $this->makeSaleLine($s2, $this->prodB, ['unit_price' => 200, 'line_total' => 200]);
    }

    /** A tenant user WITH the permission, bound to a single default terminal (to prove unscoping). */
    private function permittedUser(): User
    {
        $uid = $this->makeUser(['default_branch_id' => $this->branchId]);
        Permission::findOrCreate(self::PERM, 'tenant');
        $user = User::on('tenant')->find($uid);
        $user->givePermissionTo(self::PERM);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return User::on('tenant')->find($uid);
    }

    private function controller(): PosQuickReportController
    {
        return app(PosQuickReportController::class);
    }

    private function req(array $params = []): Request
    {
        return Request::create('/pos/quick-report', 'GET', array_merge(['date' => $this->date], $params));
    }

    /* ── unscoped + per-section post-filter ─────────────────────────────────────────────────────── */
    public function test_report_is_unscoped_and_multi_select_narrows_only_its_own_section(): void
    {
        Auth::guard('tenant')->login($this->permittedUser());

        // No selection → BOTH terminals' data (unscoped): 2 categories, 2 items, 2 waiters, 2 order types.
        $full = $this->controller()->print($this->req(['sections' => PosQuickReportController::SECTIONS]))->getData();
        $this->assertCount(2, $full['categories'], 'unscoped: both terminals\' categories (Biryani + Drinks) appear');
        $this->assertCount(2, $full['items'], 'both items appear');
        $this->assertCount(2, $full['waiters'], 'both waiters appear');
        $this->assertCount(2, $full['orderTypes'], 'both order types appear');
        $this->assertNotNull($full['overview']);

        // Category multi-select → ONLY the picked category; overview stays full (2 items still exist).
        $catOnly = $this->controller()->print($this->req(['sections' => ['overview', 'categories', 'items'], 'category_ids' => [$this->catA]]))->getData();
        $this->assertCount(1, $catOnly['categories'], 'categories section narrowed to the one picked');
        $this->assertSame($this->catA, (int) ($catOnly['categories'][0]['id'] ?? 0));
        $this->assertNotNull($catOnly['overview'], 'overview is NOT narrowed by a category pick (headline stays whole-day)');

        // Item, waiter, order-type selections each narrow only their own section.
        $itemOnly = $this->controller()->print($this->req(['sections' => ['items'], 'product_ids' => [$this->prodB], 'all_items' => 0]))->getData();
        $this->assertCount(1, $itemOnly['items']);
        $this->assertSame($this->prodB, (int) ($itemOnly['items'][0]->product_id ?? 0));

        $wOnly = $this->controller()->print($this->req(['sections' => ['waiters'], 'waiter_ids' => [$this->w1]]))->getData();
        $this->assertCount(1, $wOnly['waiters']);
        $this->assertSame((string) $this->w1, (string) ($wOnly['waiters'][0]['id'] ?? ''));

        $otOnly = $this->controller()->print($this->req(['sections' => ['order_types'], 'order_types' => ['dine_in']]))->getData();
        $this->assertCount(1, $otOnly['orderTypes']);
        $this->assertSame('dine_in', (string) ($otOnly['orderTypes'][0]['id'] ?? ''));
    }

    /* ── permission gate ─────────────────────────────────────────────────────────────────────────── */
    public function test_a_user_without_the_permission_is_refused(): void
    {
        Auth::guard('tenant')->login(User::on('tenant')->find($this->makeUser())); // no permission granted
        try {
            $this->controller()->settings();
            $this->fail('a user without tenant.pos.quick-report-send must be refused');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    /* ── email = A4 PDF to the configured recipients ────────────────────────────────────────────── */
    public function test_email_sends_an_a4_pdf_to_the_schedule_recipients(): void
    {
        Mail::fake();
        DB::connection('tenant')->table('report_schedules')->insert([
            'name' => 'Daily', 'sections' => json_encode(['overview']),
            'recipient_emails' => json_encode(['owner@example.com', 'staff@example.com']),
            'delivery_format' => 'a4_pdf', 'frequency' => 'daily', 'send_time' => '00:30',
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Auth::guard('tenant')->login($this->permittedUser());

        $resp = $this->controller()->email(Request::create('/x', 'POST', ['date' => $this->date, 'sections' => ['overview', 'categories']]));
        $this->assertTrue($resp->getData(true)['ok']);

        Mail::assertSent(SalesReportMail::class, function ($mail) {
            return $mail->hasTo('owner@example.com') && $mail->hasTo('staff@example.com') && $mail->pdfContent !== null;
        });
    }

    /* ── send to network = one report print job ─────────────────────────────────────────────────── */
    public function test_send_to_network_queues_one_report_job(): void
    {
        $printerId = $this->makePrinter(['branch_id' => $this->branchId, 'printer_type' => 'network', 'ip_address' => '127.0.0.1', 'is_active' => 1]);
        Auth::guard('tenant')->login($this->permittedUser());

        $resp = $this->controller()->sendToNetwork(
            Request::create('/x', 'POST', ['date' => $this->date, 'sections' => ['overview'], 'printer_id' => $printerId]),
            app(\App\Services\Printing\EscPosPayloadService::class)
        );
        $this->assertTrue($resp->getData(true)['ok']);

        $job = DB::connection('tenant')->table('print_jobs')->where('document_type', 'report')->latest('id')->first();
        $this->assertNotNull($job, 'a report print job is queued');
        $this->assertSame($printerId, (int) $job->printer_id);
        $this->assertNotEmpty($job->raw_payload);
    }

    /* ── per-user saved settings round-trip ─────────────────────────────────────────────────────── */
    public function test_saved_settings_round_trip(): void
    {
        Auth::guard('tenant')->login($this->permittedUser());

        $this->controller()->saveSettings(Request::create('/x', 'POST', [
            'sections' => ['overview', 'items'], 'product_ids' => [$this->prodA], 'all_items' => 0,
            'waiter_ids' => [$this->w1], 'order_types' => ['dine_in'], 'category_ids' => [$this->catA],
        ]));

        $out = $this->controller()->settings()->getData(true);
        $this->assertSame(['overview', 'items'], $out['settings']['sections']);
        $this->assertSame([$this->prodA], $out['settings']['product_ids']);
        $this->assertFalse($out['settings']['all_items']);
    }
}
