<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\PosQuickReportController;
use App\Mail\SalesReportMail;
use App\Models\Tenant\User;
use App\Services\Reports\SalesReportEngine;
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
    /* -- QUICK-REPORT-OPEN-BILLS-1 ------------------------------------------------------------- */

    private function twoOpenBills(): void
    {
        $h = $this->makeSale($this->branchId, ['status' => 'held', 'order_type' => 'dine_in',
            'business_date' => $this->date, 'subtotal' => 500, 'grand_total' => 500]);
        $this->makeSaleLine($h, $this->prodA, ['unit_price' => 500, 'line_total' => 500, 'quantity' => 1]);

        $d = $this->makeSale($this->branchId, ['status' => 'draft', 'order_type' => 'takeaway',
            'business_date' => $this->date, 'subtotal' => 300, 'grand_total' => 300]);
        $this->makeSaleLine($d, $this->prodB, ['unit_price' => 300, 'line_total' => 300, 'quantity' => 1]);
    }

    private function reportCentreData(): array
    {
        $eng = app(\App\Services\Reports\SalesReportEngine::class);

        return app(\App\Services\Reports\SalesReportDocumentService::class)->data(
            $eng->normalizeFilters([
                'date_from' => $this->date, 'date_to' => $this->date, 'branch_ids' => [$this->branchId],
            ]),
            PosQuickReportController::SECTIONS,
        );
    }

    /**
     * SAB SE EHEM TEST -- poore feature ki hifazat isi par hai.
     *
     * Quick Report ke hindse ab jaan-boojh kar barhte hain, is liye "kuch na hile" wali shart
     * REPORT CENTER par hai. Wo include_open bhejta hi nahi, aur salesBase() default par purani
     * population leta hai. Gyarah hisse milaye jate hain, ek nahi.
     */
    public function test_report_center_is_untouched_by_open_bills(): void
    {
        Auth::guard('tenant')->login($this->permittedUser());
        $before = $this->reportCentreData();

        $this->twoOpenBills();
        $after = $this->reportCentreData();

        foreach (['overview', 'categories', 'items', 'categoryItems', 'deals', 'waiters',
                  'orderTypes', 'combos', 'cancellations', 'cashBank', 'bridge'] as $s) {
            $this->assertEquals($before[$s], $after[$s],
                "Report Center ka [{$s}] khule bills se hilna NAHI chahiye");
        }
    }

    /** Quick Report me khule bills HAR section me aayein -- jama, categories, items, sab. */
    public function test_quick_report_counts_open_bills_in_every_section(): void
    {
        Auth::guard('tenant')->login($this->permittedUser());
        $plain = $this->controller()->print($this->req(['sections' => PosQuickReportController::SECTIONS]))->getData();

        $this->twoOpenBills();
        $open = $this->controller()->print($this->req(['sections' => PosQuickReportController::SECTIONS]))->getData();

        $this->assertSame($plain['overview']['orders'] + 2, $open['overview']['orders'],
            'khule bills orders ki ginti me aane chahiye');
        $this->assertEqualsWithDelta((float) $plain['overview']['net_sales'] + 800.0,
            (float) $open['overview']['net_sales'], 0.01, 'NET SALES me 500 + 300 aane chahiye');

        $catAmt = fn (array $d) => collect($d['categories'])->sum(fn ($c) => (float) ((array) $c)['net']);
        $this->assertEqualsWithDelta($catAmt($plain) + 800.0, $catAmt($open), 0.01,
            'Categories me bhi khule bills ka maal aana chahiye');

        $itemQty = fn (array $d) => collect($d['items'])->sum(fn ($i) => (float) ((array) $i)['net_qty']);
        $this->assertEqualsWithDelta($itemQty($plain) + 2.0, $itemQty($open), 0.01,
            'Items ki ginti me khule bills ke items aane chahiye');

        $this->assertNotEmpty($open['categoryItems'], 'Items by Category bhi bharna chahiye');
        $this->assertNotEmpty($open['orderTypes'], 'Order Types bhi bharna chahiye');
    }

    /** Cancelled bill na khula hai na paid -- kisi jama me nahi aa sakta. */
    public function test_a_cancelled_bill_is_never_counted(): void
    {
        Auth::guard('tenant')->login($this->permittedUser());
        $plain = $this->controller()->print($this->req(['sections' => PosQuickReportController::SECTIONS]))->getData();

        $c = $this->makeSale($this->branchId, ['status' => 'cancelled', 'order_type' => 'dine_in',
            'business_date' => $this->date, 'subtotal' => 9999, 'grand_total' => 9999]);
        $this->makeSaleLine($c, $this->prodA, ['unit_price' => 9999, 'line_total' => 9999, 'quantity' => 1]);

        $after = $this->controller()->print($this->req(['sections' => PosQuickReportController::SECTIONS]))->getData();

        $this->assertSame($plain['overview']['orders'], $after['overview']['orders']);
        $this->assertEqualsWithDelta((float) $plain['overview']['net_sales'],
            (float) $after['overview']['net_sales'], 0.01);
    }

    /** Cash/Bank khule bills se NAHI barhta -- un par payment row hoti hi nahi. */
    public function test_cash_bank_never_grows_with_open_bills(): void
    {
        Auth::guard('tenant')->login($this->permittedUser());
        $plain = $this->controller()->print($this->req(['sections' => PosQuickReportController::SECTIONS]))->getData();

        $this->twoOpenBills();
        $after = $this->controller()->print($this->req(['sections' => PosQuickReportController::SECTIONS]))->getData();

        $this->assertEquals($plain['cashBank'], $after['cashBank'],
            'khule bill ka paisa aya nahi -- cash/bank ko usay ginna nahi chahiye');
    }

    /** Khule bills bhi wohi filters mante hain jo baaqi report mante hai. */
    public function test_open_bills_honour_the_same_filters(): void
    {
        Auth::guard('tenant')->login($this->permittedUser());
        $this->twoOpenBills();

        $catA = $this->controller()->print($this->req([
            'sections' => PosQuickReportController::SECTIONS, 'category_ids' => [$this->catA],
        ]))->getData();

        $names = collect($catA['categories'])->map(fn ($c) => ((array) $c)['name'] ?? '')->all();
        $this->assertCount(1, $catA['categories'], 'sirf chuni hui category');
        $this->assertEqualsWithDelta(600.0,
            (float) ((array) $catA['categories'][0])['net'], 0.01,
            'catA: paid 100 + khula 500');
    }
    /* -- QUICK-REPORT-BRANCH-SCOPE-1 ------------------------------------------------------------ */

    /** Doosri branch, uski apni category aur us par ek paid bill. */
    private function secondBranchWithASale(): array
    {
        $b2 = $this->makeBranch(['status' => 'active', 'name' => 'Doosri Branch']);
        $c2 = $this->makeCategory(['name' => 'Sirf Doosri Branch Ka', 'parent_id' => null]);
        $p2 = $this->makeProduct($c2, ['name' => 'Doosri Branch Ka Item']);
        $s2 = $this->makeSale($b2, ['order_type' => 'takeaway', 'business_date' => $this->date,
            'subtotal' => 7000, 'grand_total' => 7000]);
        $this->makeSaleLine($s2, $p2, ['unit_price' => 7000, 'line_total' => 7000, 'quantity' => 1]);

        return [$b2, $c2, $p2];
    }

    /** Us user ko sirf pehli branch do. */
    private function bindToFirstBranch(User $u): void
    {
        DB::connection('tenant')->table('branch_user')->insert([
            'branch_id' => $this->branchId, 'user_id' => $u->id,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * ASAL SHIKAYAT: Tawakkal ka cashier apni parchi par DOOSRE restaurant ka maal parh raha tha
     * (owner ne Singaporean Rice dekh kar pakra). Ab report sirf apni branch ki honi chahiye.
     */
    public function test_a_branch_bound_user_never_sees_another_branch(): void
    {
        [, $c2, ] = $this->secondBranchWithASale();
        $u = $this->permittedUser();
        $this->bindToFirstBranch($u);
        Auth::guard('tenant')->login($u);

        $d = $this->controller()->print($this->req(['sections' => PosQuickReportController::SECTIONS]))->getData();

        $names = collect($d['categories'])->map(fn ($c) => ((array) $c)['name'] ?? '')->all();
        $this->assertNotContains('Sirf Doosri Branch Ka', $names,
            'apni branch se bahar ki category parchi par aani NAHI chahiye');
        $this->assertLessThan(7000.0, (float) $d['overview']['net_sales'],
            'doosri branch ka 7,000 ka bill is jama me nahi aana chahiye');
    }

    /**
     * DOOSRA DARWAZA: modal se doosri branch ki id bhej kar hadd paar na ho.
     *
     * Chunaav ko apni hadd se KAAT-A jaata hai, rad nahi kiya jaata — cashier ne ghalti nahi ki,
     * usay apni branch ka jawab milna chahiye.
     */
    public function test_asking_for_a_foreign_branch_is_clamped_not_obeyed(): void
    {
        [$b2, $c2, ] = $this->secondBranchWithASale();
        $u = $this->permittedUser();
        $this->bindToFirstBranch($u);
        Auth::guard('tenant')->login($u);

        $d = $this->controller()->print($this->req([
            'sections'   => PosQuickReportController::SECTIONS,
            'branch_ids' => [$b2],                       // wo branch jo is user ki nahi hai
        ]))->getData();

        $names = collect($d['categories'])->map(fn ($c) => ((array) $c)['name'] ?? '')->all();
        $this->assertNotContains('Sirf Doosri Branch Ka', $names,
            'maangi hui ghair-branch ka data nahi milna chahiye');
        $this->assertNotEmpty($d['categories'],
            'aur khali parchi bhi nahi — apni branch ka jawab milna chahiye');
    }

    /** Jise koi branch assign na ho (jaise Owner) uska jawab pehle jaisa hi — poora tenant. */
    public function test_an_unbound_user_still_sees_every_branch(): void
    {
        [, $c2, ] = $this->secondBranchWithASale();
        Auth::guard('tenant')->login($this->permittedUser());   // koi branch_user row nahi

        $d = $this->controller()->print($this->req(['sections' => PosQuickReportController::SECTIONS]))->getData();

        $names = collect($d['categories'])->map(fn ($c) => ((array) $c)['name'] ?? '')->all();
        $this->assertContains('Sirf Doosri Branch Ka', $names,
            'khali assignment = koi rukawat nahi — poora tenant nazar aana chahiye');
    }

    /** Apni do branch me se ek chunna chale. */
    public function test_a_user_with_two_branches_can_pick_one(): void
    {
        [$b2, , ] = $this->secondBranchWithASale();
        $u = $this->permittedUser();
        DB::connection('tenant')->table('branch_user')->insert([
            ['branch_id' => $this->branchId, 'user_id' => $u->id],
            ['branch_id' => $b2,             'user_id' => $u->id],
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Auth::guard('tenant')->login($u);

        $both = $this->controller()->print($this->req(['sections' => PosQuickReportController::SECTIONS]))->getData();
        $one  = $this->controller()->print($this->req([
            'sections' => PosQuickReportController::SECTIONS, 'branch_ids' => [$b2],
        ]))->getData();

        $this->assertGreaterThan((float) $one['overview']['net_sales'], (float) $both['overview']['net_sales'],
            'dono branch ka jama ek branch se zyada hona chahiye');
        $names = collect($one['categories'])->map(fn ($c) => ((array) $c)['name'] ?? '')->all();
        $this->assertSame(['Sirf Doosri Branch Ka'], $names, 'chuni hui branch ka hi maal');
    }

    /* ── QUICK-REPORT-WAITER-NULL-1 ──────────────────────────────────────────────────────────────
       A waiter is attached on dine-in only, so counter work carries restaurant_waiter_id = NULL —
       and SQL's IN (...) never matches NULL. Picking waiters therefore DROPPED every takeaway and
       delivery order without saying so: on Khatri 46 of 82 orders in a day, on Kashif Food the
       whole day. These guards hold the shape of the fix: the counter population is reachable, a
       named pick still means only that waiter, and a narrowed report admits what it left out. */

    /** The counter order the old filter could not see: takeaway, no waiter, 300. */
    private function counterOrder(): int
    {
        $term = $this->makeTerminal($this->branchId, ['name' => 'Counter']);
        $s = $this->makeSale($this->branchId, [
            'terminal_id' => $term, 'order_type' => 'takeaway', 'restaurant_waiter_id' => null,
            'business_date' => $this->date, 'subtotal' => 300, 'grand_total' => 300,
        ]);
        $this->makeSaleLine($s, $this->prodA, ['unit_price' => 300, 'line_total' => 300]);

        return $s;
    }

    public function test_orders_with_no_waiter_are_reachable_through_the_no_waiter_option(): void
    {
        $this->counterOrder();
        Auth::guard('tenant')->login($this->permittedUser());
        $total = fn (array $p) => (float) $this->controller()
            ->print($this->req($p + ['sections' => ['overview', 'waiters']]))->getData()['overview']['grand_total'];

        $this->assertSame(600.0, $total([]), 'whole day = 100 (Ali) + 200 (Sara) + 300 (counter)');

        // Every NAMED waiter — exactly what the modal used to send for "everything". The counter
        // order is still out, but that is now a visible choice with a way to undo it, not a silent
        // loss: the "No waiter" box sits right beside the names.
        $this->assertSame(300.0, $total(['waiter_ids' => [$this->w1, $this->w2]]),
            'named waiters alone cannot reach a NULL waiter_id');

        // …and with the sentinel the whole day comes back. THIS is what a ticked-everything modal
        // now sends, so a counter gets its full report.
        $this->assertSame(600.0, $total(['waiter_ids' => [$this->w1, $this->w2, SalesReportEngine::WAITER_NONE]]),
            'the sentinel restores the counter orders');

        // The sentinel ALONE = exactly the counter orders, nothing else.
        $onlyNone = $this->controller()->print($this->req([
            'sections' => ['overview', 'waiters'], 'waiter_ids' => [SalesReportEngine::WAITER_NONE],
        ]))->getData();
        $this->assertSame(300.0, (float) $onlyNone['overview']['grand_total']);
        $this->assertSame(['Unassigned'], array_map(fn ($r) => $r['label'], $onlyNone['waiters']));

        // A single waiter still means THAT waiter. If the OR ever leaked out of its where() group
        // this assertion catches it — "just Ali's orders" would swell to the whole day.
        $this->assertSame(100.0, $total(['waiter_ids' => [$this->w1]]),
            'one waiter = only his own orders, counter work excluded');
    }

    public function test_a_day_where_no_order_has_a_waiter_is_not_emptied(): void
    {
        // Kashif Food's shape: every order in the day is counter work. Ticking every box in the
        // panel — the names AND "No waiter" — must return the day, not nothing.
        DB::connection('tenant')->table('sales_orders')->update(['restaurant_waiter_id' => null]);
        $this->counterOrder();
        Auth::guard('tenant')->login($this->permittedUser());

        $all = $this->controller()->print($this->req([
            'sections' => ['overview'],
            'waiter_ids' => [$this->w1, $this->w2, SalesReportEngine::WAITER_NONE],
        ]))->getData();

        $this->assertSame(600.0, (float) $all['overview']['grand_total'],
            'a counter-only day must not come back empty');
    }

    public function test_the_order_type_filter_is_unchanged(): void
    {
        $this->counterOrder();
        Auth::guard('tenant')->login($this->permittedUser());
        $total = fn (array $p) => (float) $this->controller()
            ->print($this->req($p + ['sections' => ['overview']]))->getData()['overview']['grand_total'];

        // order_type is NOT NULL on every row, so listing them all has always equalled listing
        // none. This guard keeps that true — the waiter fix must not drift into it.
        $this->assertSame($total([]), $total(['order_types' => ['dine_in', 'takeaway', 'quick_sale', 'delivery']]),
            'every order type = no order-type filter');
        $this->assertSame(100.0, $total(['order_types' => ['dine_in']]), 'and one type still narrows');
    }

    public function test_a_narrowed_report_says_so_and_a_full_one_does_not(): void
    {
        $this->counterOrder();
        Auth::guard('tenant')->login($this->permittedUser());
        $render = fn (array $p) => $this->controller()->print($this->req($p + ['sections' => ['overview']]))->render();

        // A full report renders exactly as before — no banner for the Report Center or the nightly
        // PDF to inherit.
        $full = $this->controller()->print($this->req(['sections' => ['overview']]));
        $this->assertSame([], $full->getData()['narrowing']);
        $this->assertStringNotContainsString('PARTIAL', $render([]));

        // Waiter and order-type filters leave no trace in the rows themselves, so the header is the
        // only place a reader can learn the report is a slice. It names the slice.
        $byWaiter = $render(['waiter_ids' => [$this->w1]]);
        $this->assertStringContainsString('PARTIAL — NOT THE WHOLE DAY', $byWaiter);
        $this->assertStringContainsString('Waiters: Ali', $byWaiter);

        $this->assertStringContainsString('Waiters: Ali, Unassigned',
            $render(['waiter_ids' => [$this->w1, SalesReportEngine::WAITER_NONE]]),
            'the counter bucket is named too, so "who is in this report" stays answerable');

        $this->assertStringContainsString('Order types: Dine In', $render(['order_types' => ['dine_in']]));
    }

    public function test_a_saved_selection_keeps_the_no_waiter_option(): void
    {
        $user = $this->permittedUser();
        Auth::guard('tenant')->login($user);

        $this->controller()->saveSettings(Request::create('/pos/quick-report/save-settings', 'POST', [
            'sections' => ['overview'], 'waiter_ids' => [(string) $this->w1, SalesReportEngine::WAITER_NONE],
        ]));

        $saved = DB::connection('tenant')->table('pos_quick_report_settings')
            ->where('user_id', $user->id)->value('payload');

        // The sentinel is a STRING among integer ids. A stray intval anywhere on this path would
        // turn it into 0 and quietly restore the original bug on the next reload.
        $this->assertContains(SalesReportEngine::WAITER_NONE, json_decode($saved, true)['waiter_ids']);
    }
}
