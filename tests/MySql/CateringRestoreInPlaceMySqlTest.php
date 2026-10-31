<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Catering\CateringEstimateController;
use App\Http\Controllers\Tenant\Catering\CateringEventController;
use App\Models\Tenant\Account;
use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringEventRevision;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Services\Catering\CateringAdvanceService;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringEventHistoryService;
use App\Services\Catering\CateringFinalInvoiceService;
use App\Services\Catering\CateringFinancialPositionService;
use App\Services\Catering\CateringLineCostBlockService;
use App\Services\Catering\CateringProductionReleaseService;
use App\Support\TenantClock;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-RESTORE-IN-PLACE-1 — 31 October.
 *
 * Client: "jab bhi restore karta hoon, hamesha nayi revision ban jaati hai.
 * Restore karoon to asal me wohi purani wapas aaye, aur wo edit ho sake. Agar
 * user kuch badle aur save kare tab nayi revision main ho." Aur malik ki shart:
 * "payment, refund, kitchen sheet, invoice, draft invoice — koi cheez kharab
 * na ho." Plan: docs/plans/catering-restore-in-place-2026-10-31.md
 *
 * ── KYA BACHANA HAI ────────────────────────────────────────────────────────
 *
 *  1. Restore NAYI ROW nahi banata — Q1 khud wapas current hota hai, apni
 *     purani haalat (sent/accepted/draft) me, aur us ka ek byte nahi badalta.
 *  2. Paisa nahi hilta: receipts, refunds, journal — sab booking se jude hain.
 *     Baqaya current version ke total ke saath chalta hai, bas.
 *  3. Kitchen sheet, proforma aur final invoice apne aap restored version se.
 *  4. Restored (bheja hua) version kabhi nahi likha jaata: pehli BADLI hui Save
 *     agli revision banati hai, bina badle Save kuch nahi banati.
 *  5. Restore wohi deewar maanta hai jo revise: invoice ke baad band, release
 *     ke baad permission chahiye — pehle ye sirf screen par tha.
 *
 * Har jaanch asal service/controller chala kar hai, Blade parh kar nahi.
 */
class CateringRestoreInPlaceMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringLineCostBlockService $lineBlocks;

    private int $branchId;

    private int $biryaniId;

    private int $raitaId;

    private int $unitId;

    private int $paymentMethodId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);

        $this->cleanTenant([
            'catering_event_revisions',
            'catering_material_issue_lines', 'catering_material_issues',
            'catering_production_release_lines', 'catering_production_releases',
            'catering_final_invoices', 'catering_refunds', 'catering_advances',
            'catering_estimate_line_cost_blocks', 'catering_estimate_lines', 'catering_estimates',
            'catering_events', 'catering_settings',
            'catering_product_cost_blocks', 'catering_product_profiles', 'catering_material_rates',
            'journal_lines', 'journal_entries', 'stock_ledgers',
            'cash_bank_account_transactions', 'cash_bank_accounts', 'accounts', 'payment_methods',
            'customers', 'product_translations', 'units', 'products', 'categories', 'branches',
        ]);

        (new DefaultChartOfAccountsSeeder)->run();

        $this->estimates = app(CateringEstimateService::class);
        $this->lineBlocks = app(CateringLineCostBlockService::class);

        $this->branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $this->unitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A cost-block dish (material + making), so a version carries real
        // decisions — the customer's share of the chicken — that a copy could
        // lose. And a plain dish priced by hand.
        $this->biryaniId = $this->makeProduct($categoryId, ['name' => 'Chicken Biryani', 'sku' => 'RIP-BIR', 'unit_id' => $this->unitId]);
        $chickenId = $this->makeProduct($categoryId, [
            'name' => 'Chicken', 'sku' => 'RIP-CHK', 'unit_id' => $this->unitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);
        $this->raitaId = $this->makeProduct($categoryId, ['name' => 'Raita', 'sku' => 'RIP-RAI', 'unit_id' => $this->unitId]);

        CateringMaterialRate::create([
            'product_id' => $chickenId, 'rate' => 80, 'unit_id' => $this->unitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);
        CateringMaterialRate::create([
            'product_id' => $this->raitaId, 'rate' => 100, 'unit_id' => $this->unitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);
        CateringProductProfile::updateOrCreate(
            ['product_id' => $this->biryaniId],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'blocks']
        );
        CateringProductCostBlock::create([
            'product_id' => $this->biryaniId, 'label' => 'Chicken',
            'block_type' => CateringProductCostBlock::TYPE_MATERIAL,
            'material_product_id' => $chickenId, 'quantity_per_unit' => 0.5,
            'unit_id' => $this->unitId, 'rate' => 100,
            'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
            'rate_basis' => CateringProductCostBlock::RATE_PER_MATERIAL_UNIT,
            'sort_order' => 1,
        ]);
        CateringProductCostBlock::create([
            'product_id' => $this->biryaniId, 'label' => 'Making',
            'block_type' => CateringProductCostBlock::TYPE_CHARGE,
            'charge_role' => CateringProductCostBlock::ROLE_MAKING,
            'rate' => 300, 'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
            'sort_order' => 2,
        ]);

        $cashAccountId = $this->tenant()->table('cash_bank_accounts')->insertGetId([
            'code' => 'CB-'.uniqid(), 'name' => 'Catering Cash', 'account_type' => 'cash',
            'account_id' => Account::where('code', '1110')->value('id'),
            'opening_balance' => 0, 'current_balance' => 900000, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->paymentMethodId = $this->makePaymentMethod(['cash_bank_account_id' => $cashAccountId]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────────

    /** Q1 = biryani 10 KG (customer brings 2 KG of the chicken) + raita 4 KG at 500. */
    private function booking(?string $eventDate = null): CateringEvent
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId, 'customer_name' => 'MR. SHAKEEL',
            'booking_date' => now()->subDays(3)->toDateString(),
            'event_date' => $eventDate ?? now()->addDays(20)->toDateString(),
            'pax' => 500,
        ]);

        $this->estimates->saveDraftLines($event->currentEstimate, [
            ['product_id' => $this->biryaniId, 'item_name' => 'Chicken Biryani',
                'quantity' => 10, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 0],
            ['product_id' => $this->raitaId, 'item_name' => 'Raita',
                'quantity' => 4, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 500],
        ]);

        $line = $event->refresh()->currentEstimate->lines->first();
        $chicken = $this->lineBlocks->snapshotsFor($line)->firstWhere('label', 'Chicken');
        $this->lineBlocks->setCustomerSupplied($chicken, false, 2.0);

        return $event->refresh();
    }

    /** Q1 sent; Q2 = a revision with MORE biryani, also sent. Returns [event, q1, q2]. */
    private function twoSentVersions(?string $eventDate = null, bool $acceptBoth = false): array
    {
        $event = $this->booking($eventDate);
        $q1 = $event->currentEstimate;
        $this->estimates->markSent($q1->refresh());
        if ($acceptBoth) {
            $this->estimates->markAccepted($q1->refresh());
        }

        $q2 = $this->estimates->revise($q1->refresh());
        $this->estimates->saveDraftLines($q2, $this->linesPayload($q2, ['Chicken Biryani' => 25]));
        $this->estimates->markSent($q2->refresh());
        if ($acceptBoth) {
            $this->estimates->markAccepted($q2->refresh());
        }

        return [$event->refresh(), $q1->refresh(), $q2->refresh()];
    }

    /** The lines exactly as the booking screen's form posts them, with optional quantity changes. */
    private function linesPayload(CateringEstimate $estimate, array $quantities = []): array
    {
        return $estimate->lines()->get()->map(fn ($line) => [
            'line_uuid' => $line->line_uuid,
            'product_id' => $line->product_id,
            'item_name' => $line->item_name,
            'item_name_ur' => $line->item_name_ur,
            'quantity' => $quantities[$line->item_name] ?? (float) $line->quantity,
            'unit_id' => $line->unit_id,
            'unit_code' => $line->unit_code,
            'rate' => (float) $line->rate,
            'instructions' => $line->instructions,
        ])->values()->all();
    }

    /** Every stored byte of a version's lines and cost blocks — timestamps included. */
    private function storedRowsOf(CateringEstimate $estimate): array
    {
        $db = DB::connection('tenant');
        $lines = $db->table('catering_estimate_lines')->where('catering_estimate_id', $estimate->id)
            ->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $blocks = $db->table('catering_estimate_line_cost_blocks')
            ->whereIn('catering_estimate_line_id', array_column($lines, 'id'))
            ->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $head = (array) $db->table('catering_estimates')->where('id', $estimate->id)->first();
        unset($head['status'], $head['superseded_at'], $head['updated_at']);

        return compact('head', 'lines', 'blocks');
    }

    private function liveVersions(CateringEvent $event): int
    {
        return CateringEstimate::where('catering_event_id', $event->id)
            ->whereNotIn('status', [CateringEstimate::STATUS_SUPERSEDED, CateringEstimate::STATUS_CANCELLED])
            ->count();
    }

    private function trialBalance(): float
    {
        return (float) DB::connection('tenant')->table('journal_lines')
            ->selectRaw('ROUND(COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0), 2) as d')->value('d');
    }

    private function moneyRows(): array
    {
        $db = DB::connection('tenant');

        return [
            'advances' => $db->table('catering_advances')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'refunds' => $db->table('catering_refunds')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'journal_entries' => $db->table('journal_entries')->count(),
            'journal_lines' => $db->table('journal_lines')->count(),
            'trial_balance' => $this->trialBalance(),
        ];
    }

    private function actAsOperator(?callable $gate = null): void
    {
        Gate::before($gate ?? fn (?\App\Models\Tenant\User $u = null) => true);
        $user = \App\Models\Tenant\User::on('tenant')
            ->find($this->makeUser(['employee_code' => 'RP'.Str::random(4)]));
        $this->actingAs($user, 'tenant');
        Auth::shouldUse('tenant');
    }

    private function saveThroughTheScreen(CateringEstimate $estimate, array $lines)
    {
        $req = Request::create('/x', 'PUT', [
            'lines' => $lines,
            'service_charge_amount' => 0, 'other_charge_label' => null, 'other_charge_amount' => 0,
            'discount_type' => 'none', 'discount_value' => 0, 'tax_amount' => 0,
        ]);
        $req->setLaravelSession(app('session.store'));
        $req->setUserResolver(fn () => auth()->user());
        app()->instance('request', $req);

        return app(CateringEstimateController::class)->update($req, $estimate);
    }

    private function flash(string $key): mixed
    {
        return app('session.store')->get($key);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. Restore = wohi purani, nayi row nahi.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_restore_brings_the_old_version_back_as_itself_and_makes_no_new_one(): void
    {
        [$event, $q1, $q2] = $this->twoSentVersions();
        $q1Before = $this->storedRowsOf($q1);
        $q2Before = $this->storedRowsOf($q2);
        $versionsBefore = CateringEstimate::where('catering_event_id', $event->id)->count();

        $restored = $this->estimates->restoreVersion($q1);

        $this->assertSame($q1->id, $restored->id, 'wohi Q1 wapas aaya — copy nahi');
        $this->assertSame($versionsBefore, CateringEstimate::where('catering_event_id', $event->id)->count(),
            'koi nayi version nahi bani — client ki asal shikayat yehi thi');

        $this->assertSame(CateringEstimate::STATUS_SENT, $restored->status, 'Q1 bheji hui thi, bheji hui hi wapas aayi');
        $this->assertNull($restored->superseded_at);
        $this->assertSame(CateringEstimate::STATUS_SUPERSEDED, $q2->refresh()->status, 'Q2 ek taraf ho gayi');
        $this->assertSame($q1->id, $event->refresh()->currentEstimate->id, 'har screen ab Q1 parhegi');
        $this->assertSame(1, $this->liveVersions($event), 'hamesha EK current');

        // Not a byte of either version's content moved.
        $this->assertSame($q1Before, $this->storedRowsOf($q1), 'Q1 ki lines aur cost blocks byte-barabar');
        $this->assertSame($q2Before, $this->storedRowsOf($q2), 'Q2 apni kahani ke saath mehfooz');
    }

    public function test_each_version_comes_back_as_what_it_was(): void
    {
        $event = $this->booking();
        $q1 = $event->currentEstimate;
        $this->estimates->markSent($q1->refresh());
        $this->estimates->markAccepted($q1->refresh());

        // Revise and STOP at draft — the booking goes to draft, as it always has.
        $q2 = $this->estimates->revise($q1->refresh());
        $this->assertSame(CateringEvent::STATUS_DRAFT, $event->refresh()->status);

        // Accepted comes back accepted; the booking follows the finalised paper
        // to `quoted`, exactly as Finalize would have taken it.
        $this->estimates->restoreVersion($q1->refresh());
        $this->assertSame(CateringEstimate::STATUS_ACCEPTED, $q1->refresh()->status);
        $this->assertSame(CateringEstimate::STATUS_SUPERSEDED, $q2->refresh()->status);
        $this->assertSame(CateringEvent::STATUS_QUOTED, $event->refresh()->status);

        // The draft that stepped aside comes back a DRAFT, editable in place —
        // and the booking goes back to draft, as revise() takes it.
        $this->estimates->restoreVersion($q2->refresh());
        $this->assertSame(CateringEstimate::STATUS_DRAFT, $q2->refresh()->status);
        $this->assertSame(CateringEstimate::STATUS_SUPERSEDED, $q1->refresh()->status);
        $this->assertSame(CateringEvent::STATUS_DRAFT, $event->refresh()->status);
        $this->assertNull($event->confirmed_at);
        $this->assertSame(1, $this->liveVersions($event));
    }

    public function test_a_confirmed_booking_stays_confirmed_when_a_sent_version_comes_back(): void
    {
        [$event, $q1] = $this->twoSentVersions(null, acceptBoth: true);
        $this->assertSame(CateringEvent::STATUS_CONFIRMED, $event->status);

        $this->estimates->restoreVersion($q1);

        // Q1 is paper the customer already holds — nobody re-accepts it.
        $this->assertSame(CateringEvent::STATUS_CONFIRMED, $event->refresh()->status);
        $this->assertNotNull($event->confirmed_at);
    }

    public function test_back_and_forth_always_leaves_exactly_one_current(): void
    {
        [$event, $q1, $q2] = $this->twoSentVersions();

        foreach ([$q1, $q2, $q1, $q2] as $version) {
            $this->estimates->restoreVersion($version->refresh());
            $this->assertSame(1, $this->liveVersions($event));
            $this->assertSame($version->id, $event->refresh()->currentEstimate->id);
        }

        $this->assertSame(2, CateringEstimate::where('catering_event_id', $event->id)->count(),
            'chaar restore, phir bhi do hi versions');

        $this->expectException(RuntimeException::class);
        $this->estimates->restoreVersion($q2->refresh()); // already current
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Paisa — receipts, refunds, khate.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_restore_moves_no_money_and_the_balance_follows_the_restored_total(): void
    {
        [$event, $q1, $q2] = $this->twoSentVersions(null, acceptBoth: true);
        app(CateringAdvanceService::class)->record($event->refresh(), [
            'amount' => 3000, 'payment_method_id' => $this->paymentMethodId,
            'received_date' => now()->subDay()->toDateString(),
        ]);

        $before = $this->moneyRows();
        $positions = app(CateringFinancialPositionService::class);
        $this->assertSame(round((float) $q2->grand_total, 2), $positions->position($event->refresh())['billed']);

        $this->estimates->restoreVersion($q1);

        $this->assertSame($before, $this->moneyRows(),
            'ek receipt, ek refund, ek journal line nahi hili — aur khate barabar');
        $this->assertSame(0.0, $this->trialBalance());

        $after = $positions->position($event->refresh());
        $this->assertSame(round((float) $q1->grand_total, 2), $after['billed'], 'baqaya Q1 ke total se');
        $this->assertSame(3000.0, $after['net_received'], 'mila hua paisa wohi');
        $this->assertSame(round((float) $q1->grand_total - 3000, 2), $after['balance_due']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Kitchen sheet, proforma, final invoice.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_kitchen_sheet_follows_the_restored_version_and_its_own_release_is_not_stale(): void
    {
        $event = $this->booking();
        $q1 = $event->currentEstimate;
        $this->estimates->markSent($q1->refresh());
        $this->estimates->markAccepted($q1->refresh());
        $releases = app(CateringProductionReleaseService::class);
        $releases->release($event->refresh());

        $q2 = $this->estimates->revise($q1->refresh());
        $this->estimates->saveDraftLines($q2, $this->linesPayload($q2, ['Chicken Biryani' => 25]));
        $this->estimates->markSent($q2->refresh());
        $this->assertTrue($event->refresh()->hasStaleRelease(), 'Q2 par release purani thi');

        $this->estimates->restoreVersion($q1->refresh());

        $event->refresh();
        $this->assertSame(CateringEvent::STATUS_RELEASED, $event->status, 'release kabhi nahi mitaai jaati');
        $this->assertFalse($event->hasStaleRelease(),
            'release Q1 se hui thi aur Q1 hi wapas hai — parcha purana nahi. Purani copy (Q3) yahan jhoota "stale" kehti thi.');

        $sheet = $releases->sheetFor($event);
        $this->assertEqualsCanonicalizing(
            $q1->lines()->pluck('quantity')->map(fn ($q) => (float) $q)->all(),
            $sheet->lines->pluck('quantity')->map(fn ($q) => (float) $q)->all(),
            'kitchen sheet Q1 ki miqdaar chhaapti hai'
        );
    }

    public function test_proforma_and_the_final_invoice_come_from_the_restored_version(): void
    {
        $eventDay = app(TenantClock::class)->now()->subDays(2)->toDateString();
        [$event, $q1] = $this->twoSentVersions($eventDay, acceptBoth: true);

        $this->estimates->restoreVersion($q1);
        $invoices = app(CateringFinalInvoiceService::class);

        $proforma = $invoices->proforma($event->refresh());
        $this->assertSame(round((float) $q1->grand_total, 2), round((float) $proforma->grand_total, 2), 'proforma Q1 ka');

        // Q1 is already finalised, so it is billed directly — no copy to finalise first.
        $invoice = $invoices->issue($event->refresh());
        $this->assertSame($q1->id, (int) $invoice->catering_estimate_id);
        $this->assertSame(round((float) $q1->grand_total, 2), round((float) $invoice->grand_total, 2));
        $this->assertSame(0.0, $this->trialBalance());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Restore ki deewarein — ab service me, sirf screen par nahi.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_restore_is_refused_once_the_booking_is_invoiced(): void
    {
        $eventDay = app(TenantClock::class)->now()->subDays(2)->toDateString();
        [$event, $q1, $q2] = $this->twoSentVersions($eventDay, acceptBoth: true);
        app(CateringFinalInvoiceService::class)->issue($event->refresh());

        try {
            $this->estimates->restoreVersion($q1);
            $this->fail('invoice ke baad restore nahi hona chahiye');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('closed to commercial change', $e->getMessage());
        }

        $this->assertSame(CateringEstimate::STATUS_SUPERSEDED, $q1->refresh()->status, 'kuch nahi badla');
        $this->assertSame($q2->id, $event->refresh()->currentEstimate->id);
    }

    public function test_restore_after_release_needs_the_edit_after_release_permission(): void
    {
        [$event, $q1, $q2] = $this->twoSentVersions(null, acceptBoth: true);
        app(CateringProductionReleaseService::class)->release($event->refresh());

        $this->actAsOperator(fn (?\App\Models\Tenant\User $u = null, string $ability = '') => $ability === 'tenant.catering.estimates.edit-after-release' ? false : true);

        try {
            $this->estimates->restoreVersion($q1);
            $this->fail('release ke baad bina ijazat restore nahi hona chahiye — kitchen sheet badal jaati');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('edit-after-release', $e->getMessage());
        }

        $this->assertSame($q2->id, $event->refresh()->currentEstimate->id, 'kuch nahi badla');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. History.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_history_remembers_the_restored_version_and_revert_still_works(): void
    {
        [$event, $q1] = $this->twoSentVersions();
        $history = app(CateringEventHistoryService::class);
        $checkpoint = $history->record($event->refresh(), 'finalized');

        $this->estimates->restoreVersion($q1);

        $snapshot = $history->snapshotOf($event->refresh());
        $this->assertEqualsCanonicalizing([10.0, 4.0],
            collect($snapshot['lines'])->pluck('quantity')->map(fn ($q) => (float) $q)->all(),
            'History Q1 yaad rakhe — max(version_no) wali Q2 nahi');

        // Revert past the restored (sent) version: a new version, Q1 untouched.
        $history->revertTo($checkpoint);
        $this->assertSame(CateringEstimate::STATUS_SUPERSEDED, $q1->refresh()->status);
        $this->assertSame(10.0, (float) $q1->lines()->where('item_name', 'Chicken Biryani')->value('quantity'));
        $this->assertSame(1, $this->liveVersions($event));
        $this->assertSame(3, (int) $event->refresh()->currentEstimate->version_no);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. Restored version par Save — badla to agli revision, na badla to kuch nahi.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_saving_a_change_on_the_restored_version_makes_the_next_revision_and_keeps_q1(): void
    {
        [$event, $q1] = $this->twoSentVersions(null, acceptBoth: true);
        $this->estimates->restoreVersion($q1);
        $q1Before = $this->storedRowsOf($q1->refresh());
        $this->actAsOperator();

        // On the screen the page reloads and the person edits before Save. Without
        // this, restore and Save land in the same second and the Finalize check
        // below cannot tell which version was superseded last.
        $this->travel(1)->minutes();

        $this->saveThroughTheScreen($q1->refresh(), $this->linesPayload($q1, ['Raita' => 6]));

        $this->assertNull($this->flash('errors'), 'Save mana nahi hona chahiye tha');
        $q3 = $event->refresh()->currentEstimate;
        $this->assertSame(3, (int) $q3->version_no, 'agli revision Q3 — Q2 nahi, wo pehle se hai');
        $this->assertTrue($q3->isDraft());
        $this->assertSame(1, $this->liveVersions($event));
        $this->assertStringContainsString('Q3', (string) $this->flash('status'));

        // The change is on Q3 …
        $this->assertSame(6.0, (float) $q3->lines()->where('item_name', 'Raita')->value('quantity'));

        // … and every decision on the lines that did NOT change came along:
        // the customer still brings 2 KG of the chicken.
        $biryani = $q3->lines()->where('item_name', 'Chicken Biryani')->first();
        $chicken = $this->lineBlocks->snapshotsFor($biryani)->firstWhere('label', 'Chicken');
        $this->assertEqualsWithDelta(2.0, $chicken->suppliedQty(), 0.001, 'party ka hissa salamat');
        $this->assertSame(10.0, (float) $biryani->quantity);

        // … while Q1, the customer's paper, is exactly as it was.
        $this->assertSame(CateringEstimate::STATUS_SUPERSEDED, $q1->refresh()->status);
        $this->assertSame($q1Before, $this->storedRowsOf($q1), 'Q1 par ek byte nahi likha gaya');

        // Same road as Create Revision: the booking goes back to draft.
        $this->assertSame(CateringEvent::STATUS_DRAFT, $event->refresh()->status);
        $this->assertSame('restored_revised', CateringEventRevision::where('catering_event_id', $event->id)
            ->orderByDesc('id')->value('action'));

        // Finalizing Q3 shows the move FROM Q1 — the figures the customer was
        // actually being billed — not from Q2, the next number down.
        $html = app(CateringEventController::class)->show($event->fresh())->render();
        $this->assertStringContainsString('const previousTotal = '.(float) $q1->grand_total.';', $html);
    }

    public function test_saving_the_restored_version_without_a_change_makes_nothing(): void
    {
        [$event, $q1] = $this->twoSentVersions(null, acceptBoth: true);
        $this->estimates->restoreVersion($q1);
        $versions = CateringEstimate::where('catering_event_id', $event->id)->count();
        $q1Before = $this->storedRowsOf($q1->refresh());
        $this->actAsOperator();

        $this->saveThroughTheScreen($q1->refresh(), $this->linesPayload($q1));

        $this->assertNull($this->flash('errors'));
        $this->assertStringContainsString('Kuch nahi badla', (string) $this->flash('status'));
        $this->assertSame($versions, CateringEstimate::where('catering_event_id', $event->id)->count(),
            'bina badle Save — koi Q3 nahi');
        $this->assertSame($q1->id, $event->refresh()->currentEstimate->id);
        $this->assertSame(CateringEstimate::STATUS_ACCEPTED, $q1->refresh()->status);
        $this->assertSame(CateringEvent::STATUS_CONFIRMED, $event->status, 'aur booking bhi jahan thi wahin');
        $this->assertSame($q1Before, $this->storedRowsOf($q1));
    }

    /** The old rule still stands for a quotation that was simply sent. */
    public function test_an_ordinary_sent_quotation_still_refuses_a_save(): void
    {
        $event = $this->booking();
        $q1 = $event->currentEstimate;
        $this->estimates->markSent($q1->refresh());
        $this->actAsOperator();

        $this->saveThroughTheScreen($q1->refresh(), $this->linesPayload($q1, ['Raita' => 6]));

        $this->assertNotNull($this->flash('errors'), 'bheji hui quotation par seedha Save — pehle jaisa mana');
        $this->assertSame(1, CateringEstimate::where('catering_event_id', $event->id)->count());
        $this->assertSame(4.0, (float) $q1->lines()->where('item_name', 'Raita')->value('quantity'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7. Screen — asal controller se render.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_booking_screen_opens_the_editor_on_a_restored_version_only(): void
    {
        [$event, $q1] = $this->twoSentVersions();
        $this->actAsOperator();

        // Before the restore: Q2 is an ordinary sent quotation — locked view and Create Revision, as always.
        $html = app(CateringEventController::class)->show($event->fresh())->render();
        $this->assertStringNotContainsString('id="estimate-form"', $html);
        $this->assertStringContainsString('id="revise-form"', $html);
        $this->assertStringNotContainsString('id="restored-version-note"', $html);

        $this->estimates->restoreVersion($q1);
        $html = app(CateringEventController::class)->show($event->fresh())->render();

        $this->assertStringContainsString('id="estimate-form"', $html, 'restored Q1 edit ke liye khuli');
        $this->assertStringContainsString('id="restored-version-note"', $html, 'aur patti batati hai Save par kya hoga');
        $this->assertStringContainsString('<strong>Q3</strong>', $html, 'agli revision ka sahi number — Q2 nahi');
        $this->assertStringContainsString('>Restored</span>', $html, 'Version History me nishan');
        $this->assertStringNotContainsString('id="revise-form"', $html,
            'Create Revision yahan nahi — wo sirf be-badli copy banata, jo Restore ne band kiya');

        // The controls that write at once would write onto the customer's paper.
        $this->assertStringNotContainsString('btn-outline-primary mt-1 live-apply', $html, 'Apply rate band');
        $this->assertStringContainsString('readonly title="Rate badalne ke liye row ki pencil use karein"', $html);
        $this->assertStringNotContainsString('/line-cost-blocks/', $html, 'Cost Details ke chhote buttons band');
        // The form, not the words — the editor's script mentions "Recalculate Cost" in a dialog.
        $this->assertStringNotContainsString('data-reprice action=', $html, 'Recalculate Cost band');
        $this->assertStringNotContainsString('id="finalize-form"', $html, 'Q1 pehle se bheji hui hai');

        // And the Restore confirm on Q2 tells the truth about the money.
        $this->assertStringContainsString('koi nayi Q nahi banegi', $html);
        $this->assertStringContainsString('graahak ka total', $html);
    }
}
