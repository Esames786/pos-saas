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

    /* ── unscoped + a filter narrows the WHOLE report (everything cascades) ─────────────────────── */
    public function test_report_is_unscoped_and_a_filter_narrows_the_whole_report(): void
    {
        Auth::guard('tenant')->login($this->permittedUser());

        // No filter → BOTH terminals (unscoped): 2 of every dimension.
        $full = $this->controller()->print($this->req(['sections' => PosQuickReportController::SECTIONS]))->getData();
        $this->assertCount(2, $full['categories'], 'unscoped: both terminals\' categories appear');
        $this->assertCount(2, $full['items']);
        $this->assertCount(2, $full['waiters']);
        $this->assertCount(2, $full['orderTypes']);

        // category_ids=[catA] → the WHOLE report follows catA's only order (t1 / dine_in / w1 / pA):
        // items, waiters AND order types all collapse to that one — not just the categories section.
        $catA = $this->controller()->print($this->req(['sections' => PosQuickReportController::SECTIONS, 'category_ids' => [$this->catA]]))->getData();
        $this->assertCount(1, $catA['categories'], 'only the picked category');
        $this->assertCount(1, $catA['items'], 'items follow the category — only pA');
        $this->assertSame($this->prodA, (int) ($catA['items'][0]->product_id ?? 0));
        $this->assertCount(1, $catA['waiters'], 'waiters follow the category — only w1 (who sold catA)');
        $this->assertCount(1, $catA['orderTypes'], 'order types follow the category — only dine_in');

        // order_types=[takeaway] AND-composes and narrows the whole report to sale2 (catB / pB / w2).
        $tk = $this->controller()->print($this->req(['sections' => ['categories', 'items', 'waiters'], 'order_types' => ['takeaway']]))->getData();
        $this->assertCount(1, $tk['items']);
        $this->assertSame($this->prodB, (int) ($tk['items'][0]->product_id ?? 0));
        $this->assertCount(1, $tk['categories']);
    }

    /* ── a parent category pulls in its SUB-categories ──────────────────────────────────────────── */
    public function test_selecting_a_parent_category_includes_its_child_categories(): void
    {
        $parent = $this->makeCategory(['name' => 'Rice', 'parent_id' => null]);
        $child  = $this->makeCategory(['name' => 'Biryani Rice', 'parent_id' => $parent]);
        $prod   = $this->makeProduct($child, ['name' => 'Chicken Biryani']);
        $term   = $this->makeTerminal($this->branchId, ['name' => 'X']);
        $s = $this->makeSale($this->branchId, ['terminal_id' => $term, 'order_type' => 'dine_in', 'business_date' => $this->date, 'subtotal' => 300, 'grand_total' => 300]);
        $this->makeSaleLine($s, $prod, ['unit_price' => 300, 'line_total' => 300]);

        Auth::guard('tenant')->login($this->permittedUser());

        // Pick the PARENT → the child sub-category's item is included (descendants), and the unrelated
        // top-level categories are excluded.
        $data = $this->controller()->print($this->req(['sections' => ['items'], 'category_ids' => [$parent]]))->getData();
        $ids = array_map(fn ($r) => (int) $r->product_id, $data['items']);
        $this->assertContains($prod, $ids, 'a parent category pulls in its sub-category items');
        $this->assertNotContains($this->prodA, $ids, 'unrelated categories are excluded');
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
    /* ── QUICK-REPORT-OPEN-BILLS-1 ─────────────────────────────────────────────────────────────── */

    /** Ek held aur ek draft bill usi din par — beech-e-service ki soorat. */
    private function twoOpenBills(): void
    {
        $h = $this->makeSale($this->branchId, ['status' => 'held', 'order_type' => 'dine_in',
            'business_date' => $this->date, 'subtotal' => 500, 'grand_total' => 500]);
        $this->makeSaleLine($h, $this->prodA, ['unit_price' => 500, 'line_total' => 500]);

        $d = $this->makeSale($this->branchId, ['status' => 'draft', 'order_type' => 'takeaway',
            'business_date' => $this->date, 'subtotal' => 300, 'grand_total' => 300]);
        $this->makeSaleLine($d, $this->prodB, ['unit_price' => 300, 'line_total' => 300]);
    }

    /**
     * SAB SE EHEM TEST: Report Center ka hisab hilna NAHI chahiye.
     *
     * Isi liye population (SalesReportEngine::POPULATION) ko chhua hi nahi gaya — khule bills ek
     * ALAG query se aate hain. Ye test wohi sabit karta hai: khule bills mojood hone ke bawajood
     * paid ka hisab hu-ba-hu wohi rehta hai.
     */
    public function test_open_bills_never_move_the_paid_figures(): void
    {
        Auth::guard('tenant')->login($this->permittedUser());
        $before = $this->controller()->print($this->req(['sections' => PosQuickReportController::SECTIONS]))->getData();

        $this->twoOpenBills();

        $after = $this->controller()->print($this->req(['sections' => PosQuickReportController::SECTIONS]))->getData();

        $this->assertSame($before['overview']['orders'], $after['overview']['orders'],
            'khule bills paid orders ki ginti nahi barha sakte');
        $this->assertSame($before['overview']['net_sales'], $after['overview']['net_sales'],
            'NET SALES apni jagah qaayam rehna chahiye — warna Report Center ka hindsa bhi hil jayega');
        $this->assertSame($before['overview']['grand_total'], $after['overview']['grand_total']);
        $this->assertEquals($before['cashBank'], $after['cashBank'],
            'cash/bank payments par chalta hai, aur khule bill par payment hoti hi nahi');
    }

    /** Aur Quick Report par wo alag figure nazar aana chahiye. */
    public function test_quick_report_reports_the_open_bills_separately(): void
    {
        Auth::guard('tenant')->login($this->permittedUser());
        $this->twoOpenBills();

        $data = $this->controller()->print($this->req(['sections' => PosQuickReportController::SECTIONS]))->getData();

        $this->assertArrayHasKey('open', $data, 'Quick Report ko khule bills ka figure milna chahiye');
        $this->assertSame(2, $data['open']['orders'], 'ek held + ek draft');
        $this->assertEqualsWithDelta(800.0, (float) $data['open']['grand_total'], 0.01, '500 + 300');
    }

    /**
     * Report Center is kunji ke BAGHAIR aata hai — us ke liye ye satrein banti bhi nahi.
     * Yehi wo cheez hai jo poore feature ko sirf Quick Report tak mehdood rakhti hai.
     */
    public function test_report_center_never_receives_the_open_figure(): void
    {
        Auth::guard('tenant')->login($this->permittedUser());
        $this->twoOpenBills();

        $filters = app(\App\Services\Reports\SalesReportEngine::class)->normalizeFilters([
            'date_from' => $this->date, 'date_to' => $this->date, 'branch_ids' => [$this->branchId],
        ]);

        $plain = app(\App\Services\Reports\SalesReportDocumentService::class)
            ->data($filters, PosQuickReportController::SECTIONS);

        $this->assertNull($plain['open'] ?? null,
            'jo caller khule bills na maange usay milna hi nahi chahiye');
    }

    /** Cancelled bill khula nahi hota — wo mansookh hai, aur kisi jama me nahi aa sakta. */
    public function test_a_cancelled_bill_is_not_an_open_bill(): void
    {
        Auth::guard('tenant')->login($this->permittedUser());
        $c = $this->makeSale($this->branchId, ['status' => 'cancelled', 'order_type' => 'dine_in',
            'business_date' => $this->date, 'subtotal' => 9999, 'grand_total' => 9999]);
        $this->makeSaleLine($c, $this->prodA, ['unit_price' => 9999, 'line_total' => 9999]);

        $data = $this->controller()->print($this->req(['sections' => PosQuickReportController::SECTIONS]))->getData();

        $this->assertSame(0, $data['open']['orders'], 'cancelled bill khula nahi hai');
        $this->assertEqualsWithDelta(0.0, (float) $data['open']['grand_total'], 0.01);
    }

    /** Khule bills bhi wohi filters mante hain jo baaqi report mante hai. */
    public function test_open_bills_honour_the_same_filters(): void
    {
        Auth::guard('tenant')->login($this->permittedUser());
        $this->twoOpenBills();

        $catA = $this->controller()->print($this->req([
            'sections' => PosQuickReportController::SECTIONS, 'category_ids' => [$this->catA],
        ]))->getData();

        $this->assertSame(1, $catA['open']['orders'], 'sirf catA wala khula bill');
        $this->assertEqualsWithDelta(500.0, (float) $catA['open']['grand_total'], 0.01);
    }

    /** Parchi DO raaston se chhapti hai — dono par satrein aani chahiye. */
    public function test_both_renderers_carry_the_open_lines(): void
    {
        Auth::guard('tenant')->login($this->permittedUser());
        $this->twoOpenBills();

        $data = $this->controller()->print($this->req(['sections' => PosQuickReportController::SECTIONS]))->getData();

        $html = view('tenant.reports.center.print', $data)->render();
        $this->assertStringContainsString('Still Open (2)', $html, 'blade par khule bills ki satar nahi aayi');
        $this->assertStringContainsString('EXPECTED', $html, 'blade par expected ki satar nahi aayi');

        $bytes = app(\App\Services\Printing\EscPosPayloadService::class)->buildReport($data);
        $txt = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $bytes);
        $this->assertStringContainsString('Still Open (2)', $txt, 'printer ki bytes me satar nahi aayi');
        $this->assertStringContainsString('EXPECTED', $txt, 'printer ki bytes me expected nahi aaya');
    }
}
