<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Catering\CateringEstimateController;
use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\Product;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringPunchedRateAdoptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-ADOPT-PUNCHED-RATE-1 — 25 September.
 *
 * A dish priced from cost blocks that HAS no cost blocks refuses to let its
 * quotation be sent. EV-20260925-0073 sat blocked on "Chatni Green". The only
 * way out was to leave the booking, open Cost Blocks and add a part by hand —
 * and what operators actually added was an empty block at rate 0, purely to
 * open the door. On the live tenant "Chatni", "Paratha (Pcs)" and "Decoration"
 * all carry one; Decoration sold for 45,000 with a cost basis of nothing.
 *
 * So the offer is made at the wall, carrying the rate the operator already
 * typed on that quotation.
 *
 * The assertion that matters most is the one about where the NUMBER comes from:
 * the browser says which dish, never how much. A form post that could name its
 * own rate would be a way to price any dish from any screen.
 */
class CateringAdoptPunchedRateMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'catering_product_cost_blocks', 'catering_estimate_line_cost_blocks',
            'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'catering_product_profiles', 'catering_event_revisions',
            'units', 'products', 'categories', 'customers', 'branches',
        ]);

        $this->branchId = $this->makeBranch();
        $this->unitId = $this->tenant()->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The offer names the dish and the rate THIS quotation carries. */
    public function test_a_blocked_dish_is_offered_with_the_rate_from_this_quotation(): void
    {
        [$estimate, $pid] = $this->quotationWithBlocklessDish(240.0);

        $offers = app(CateringPunchedRateAdoptionService::class)->offersFor($estimate);

        $this->assertCount(1, $offers);
        $this->assertSame($pid, $offers[0]['product_id']);
        $this->assertSame(240.0, $offers[0]['rate'], 'the rate is the one on the line, not a guess');
    }

    /** Saying yes gives the dish a charge block and lets the quotation go. */
    public function test_adopting_unblocks_the_quotation(): void
    {
        [$estimate, $pid] = $this->quotationWithBlocklessDish(240.0);

        $this->assertFalse($this->readiness($estimate)['ready'], 'blocked to begin with');

        $this->send($estimate, [$pid]);

        $block = CateringProductCostBlock::where('product_id', $pid)->where('is_active', true)->first();
        $this->assertNotNull($block, 'the dish now has a cost basis');
        $this->assertSame('charge', $block->block_type,
            'a charge block — a material block would need a material, and half of one blocks again later');
        $this->assertSame('240.0000', (string) $block->rate);
        $this->assertSame('per_dish_unit', $block->rate_basis);

        $this->assertSame(CateringEstimate::STATUS_SENT, $estimate->fresh()->status,
            'and the quotation actually went');
    }

    /**
     * THE ONE THAT MATTERS. The request says WHICH dish; it can never say how
     * much. A rate smuggled in through the form is ignored and the line's own
     * rate is used.
     */
    public function test_the_request_cannot_name_its_own_rate(): void
    {
        [$estimate, $pid] = $this->quotationWithBlocklessDish(240.0);

        $this->send($estimate, [$pid], [
            'rate' => 99999,
            'adopt_punched_rate_amount' => 99999,
            'blocks' => [['rate' => 99999]],
        ]);

        $block = CateringProductCostBlock::where('product_id', $pid)->first();
        $this->assertSame('240.0000', (string) $block->rate,
            'the amount is read from the line, never from the browser');
    }

    /** A zero rate is a real answer — the live case was a complimentary chatni. */
    public function test_a_free_item_adopts_zero(): void
    {
        [$estimate, $pid] = $this->quotationWithBlocklessDish(0.0);

        $this->send($estimate, [$pid]);

        $this->assertSame('0.0000', (string) CateringProductCostBlock::where('product_id', $pid)->first()->rate);
        $this->assertSame(CateringEstimate::STATUS_SENT, $estimate->fresh()->status);
    }

    /**
     * It never touches a dish that already has a cost basis. This is the path
     * for giving a rate to a dish with none — not for changing a price.
     */
    public function test_a_dish_that_already_has_blocks_is_never_offered_or_touched(): void
    {
        [$estimate, $pid] = $this->quotationWithBlocklessDish(240.0);

        CateringProductCostBlock::create([
            'product_id' => $pid, 'label' => 'Making', 'block_type' => 'charge',
            'charge_basis' => 'per_unit', 'rate_basis' => 'per_dish_unit',
            'commercial_rate_source' => 'manual', 'rate' => 900, 'is_active' => true, 'sort_order' => 1,
        ]);

        $this->assertSame([], app(CateringPunchedRateAdoptionService::class)->offersFor($estimate),
            'nothing is offered for a dish that already has a rate');

        // And a stale screen that posts it anyway is refused, not obeyed.
        $this->send($estimate, [$pid]);

        $this->assertSame(1, CateringProductCostBlock::where('product_id', $pid)->count(),
            'no second block was created');
        $this->assertSame('900.0000', (string) CateringProductCostBlock::where('product_id', $pid)->first()->rate,
            'and the existing rate is untouched');
    }

    /** A dish that is not on this quotation cannot be priced through it. */
    public function test_a_dish_from_another_booking_is_refused(): void
    {
        [$estimate] = $this->quotationWithBlocklessDish(240.0);
        $stranger = $this->blocklessDish('Somebody Elses Dish');

        $this->send($estimate, [$stranger]);

        $this->assertSame(0, CateringProductCostBlock::where('product_id', $stranger)->count());
        $this->assertSame(CateringEstimate::STATUS_DRAFT, $estimate->fresh()->status,
            'and the send did not go through on a bad request');
    }

    /** Sending with no offer taken behaves exactly as it always did. */
    public function test_sending_without_adopting_is_unchanged(): void
    {
        [$estimate, $pid] = $this->quotationWithBlocklessDish(240.0);

        $this->send($estimate, []);

        $this->assertSame(0, CateringProductCostBlock::where('product_id', $pid)->count(),
            'nothing was created behind the operator\'s back');
        $this->assertSame(CateringEstimate::STATUS_DRAFT, $estimate->fresh()->status,
            'and the blocker still blocks');
    }

    /**
     * The screen must actually DRAW the offer, with a working address on it.
     *
     * The first version of the blade wrote `$estimate->id` — a variable this
     * view does not have. Every assertion above still passed, because they call
     * the controller directly and never render anything. The page went out with
     * action="/catering/estimates//send" and a button that did nothing, and it
     * took a warning in a production log to find it.
     */
    public function test_the_screen_draws_the_offer_with_a_working_address(): void
    {
        [$estimate, $pid] = $this->quotationWithBlocklessDish(240.0);

        // The layout reads $errors, which ShareErrorsFromSession supplies on a
        // real request; rendering directly skips the middleware.
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        $this->actingAsSender();

        $html = app(\App\Http\Controllers\Tenant\Catering\CateringEventController::class)
            ->show($estimate->event)
            ->render();

        $this->assertStringContainsString('adopt_punched_rate', $html, 'the offer is on the page');
        $this->assertStringContainsString("/catering/estimates/{$estimate->id}/send", $html,
            'and the form posts to a real estimate');
        $this->assertStringNotContainsString('/catering/estimates//send', $html,
            'never an empty id — that is the bug this test exists for');
        $this->assertStringContainsString('har quotation par bhi lagega', $html,
            'and the consequence is stated before the button');
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /**
     * The block is behind @can('tenant.catering.estimates.send'), so the screen
     * needs somebody allowed to send. Only the per-user assignment is made here:
     * the permission rows themselves come from a migration and are not a test's
     * to create or clear.
     */
    private function actingAsSender(): void
    {
        $user = \App\Models\Tenant\User::on('tenant')->find(
            $this->makeUser(['employee_code' => 'AP'.\Illuminate\Support\Str::random(4)])
        );
        $this->actingAs($user, 'tenant');
        \Illuminate\Support\Facades\Auth::shouldUse('tenant');

        DB::connection('tenant')->table('cache')->where('key', 'like', '%spatie.permission.cache%')->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $user->givePermissionTo(
            \Spatie\Permission\Models\Permission::on('tenant')->firstOrCreate(
                ['name' => 'tenant.catering.estimates.send', 'guard_name' => 'tenant']
            )
        );
    }

    private function send(CateringEstimate $estimate, array $adopt, array $extra = []): void
    {
        $req = Request::create("/catering/estimates/{$estimate->id}/send", 'POST',
            array_merge(['adopt_punched_rate' => $adopt], $extra));
        $req->setLaravelSession(app('session.store'));

        try {
            app(CateringEstimateController::class)->send($req, $estimate);
        } catch (\Throwable $e) {
            // The controller answers a bad request with back()->withErrors, and
            // back() needs a referer the test has not set. The refusal has
            // already happened by then; the assertions below read the database.
        }
    }

    private function readiness(CateringEstimate $estimate): array
    {
        return app(\App\Services\Catering\CateringEstimateCostingService::class)->readiness($estimate);
    }

    /** A dish priced from blocks, with none — the exact live shape. */
    private function blocklessDish(string $name): int
    {
        $categoryId = $this->makeCategory(['name' => 'FOOD '.substr(md5($name), 0, 4)]);
        $pid = $this->makeProduct($categoryId, [
            'name' => $name, 'sku' => 'D-'.substr(md5($name), 0, 6),
            'product_kind' => Product::KIND_SALE_ITEM,
        ]);

        CateringProductProfile::create([
            'product_id' => $pid,
            'catering_enabled' => true,
            'costing_mode' => CateringProductProfile::COSTING_BLOCKS,
        ]);

        return $pid;
    }

    /** @return array{0: CateringEstimate, 1: int} */
    private function quotationWithBlocklessDish(float $rate): array
    {
        $pid = $this->blocklessDish('Chatni Green');

        $estimates = app(CateringEstimateService::class);
        $event = $estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'MR INAM BHAI',
            'customer_phone' => '03102051803',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(3)->toDateString(),
            'pax' => 120,
        ]);

        $estimate = $event->currentEstimate;
        $estimates->saveDraftLines($estimate, [[
            'product_id' => $pid,
            'item_name' => 'Chatni Green',
            'quantity' => 120,
            'unit_id' => $this->unitId,
            'unit_code' => 'KG',
            'rate' => $rate,
        ]]);

        return [$estimate->refresh(), $pid];
    }
}
