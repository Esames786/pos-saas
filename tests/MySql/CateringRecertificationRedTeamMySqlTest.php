<?php

namespace Tests\MySql;

use App\Models\Tenant\Account;
use App\Models\Tenant\CateringEvent;
use App\Services\Catering\CateringEstimateService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PDO;
use Tests\MySql\Support\TenantFixtures;

/** Certification-only adversarial probes. No production code depends on this file. */
class CateringRecertificationRedTeamMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;
    private int $branchId;
    private int $paymentMethodId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();

        $this->cleanTenant([
            'catering_email_logs', 'catering_event_reminders', 'catering_final_invoices',
            'catering_advances', 'catering_cost_snapshots', 'catering_estimate_line_cost_blocks',
            'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'catering_material_rates', 'catering_product_cost_blocks', 'catering_product_profiles',
            'journal_lines', 'journal_entries', 'cash_bank_account_transactions', 'cash_bank_accounts',
            'accounts', 'payment_methods', 'products', 'categories', 'customers', 'branches',
        ]);

        (new DefaultChartOfAccountsSeeder)->run();
        $this->estimates = app(CateringEstimateService::class);
        $this->branchId = $this->makeBranch();

        $cashAccountId = $this->tenant()->table('cash_bank_accounts')->insertGetId([
            'code' => 'CB-CODEX-RACE', 'name' => 'Codex Race Cash', 'account_type' => 'cash',
            'account_id' => Account::where('code', '1110')->value('id'),
            'opening_balance' => 0, 'current_balance' => 0, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->paymentMethodId = $this->makePaymentMethod(['cash_bank_account_id' => $cashAccountId]);
    }

    public function test_advance_can_commit_after_invoice_has_frozen_an_empty_advance_set(): void
    {
        $event = $this->confirmedEvent();

        // Hold the invoice-number range. The real issue() path first locks the event,
        // reads the advances, then reaches this lock while choosing its invoice number.
        $gate = $this->independentTenantPdo();
        $gate->exec('SET SESSION innodb_lock_wait_timeout = 20');
        $gate->beginTransaction();
        $gate->query("SELECT invoice_no FROM catering_final_invoices WHERE invoice_no LIKE 'CI-%' FOR UPDATE")->fetchAll();

        $invoice = $this->worker(['final-invoice', $event->id]);
        usleep(2_500_000);
        $this->assertTrue($this->stillRunning($invoice), 'invoice should be paused after taking the event lock');

        // AdvanceService does not take the event/document lock, so it can commit in
        // the middle of issue() even though issue() already froze its advance set.
        $advance = $this->worker(['advance', $event->id, 30000, $this->paymentMethodId]);
        usleep(2_500_000);
        $this->assertTrue($this->stillRunning($advance),
            'the FK check makes the insert wait, but posting_type was already decided before that wait');

        $gate->commit();
        $invoiceOut = $this->finish($invoice);
        $advanceOut = $this->finish($advance);
        $this->assertStringStartsWith('OK:final-invoice:', $invoiceOut);
        $this->assertStringStartsWith('OK:advance:advance:', $advanceOut,
            'reproduction: the receipt woke after invoicing but retained its stale pre-invoice classification');

        $issued = $this->tenant()->table('catering_final_invoices')->where('catering_event_id', $event->id)->first();
        $this->assertSame('0.00', (string) $issued->advance_total,
            'reproduction: invoice omitted the advance that committed before the invoice transaction');
        $this->assertSame('0.00', (string) $issued->advance_applied);
        $this->assertSame(30000.0, (float) $this->tenant()->table('catering_advances')->where('catering_event_id', $event->id)->sum('amount'));

        $liability = $this->accountNet('2300');
        $receivable = $this->accountNet('1300');
        $this->assertSame(-30000.0, $liability, 'advance liability remains uncleared');
        $this->assertSame(100000.0, $receivable, 'invoice posts the full receivable instead of the net position');
    }

    private function confirmedEvent(): CateringEvent
    {
        $categoryId = $this->makeCategory();
        $productId = $this->makeProduct($categoryId, ['default_purchase_price' => 400]);
        $this->tenant()->table('catering_material_rates')->insert([
            'product_id' => $productId, 'rate' => 400,
            'effective_from' => now()->subDay()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId, 'customer_name' => 'Codex Race Customer',
            'booking_date' => now()->toDateString(), 'event_date' => now()->addDays(5)->toDateString(),
            'pax' => 200,
        ]);
        $this->estimates->saveDraftLines($event->currentEstimate, [[
            'product_id' => $productId, 'item_name' => 'Catering Package', 'quantity' => 100, 'rate' => 1000,
        ]]);
        $this->estimates->markSent($event->currentEstimate->refresh());
        $this->estimates->confirmEvent($event->refresh());

        return $event->refresh();
    }

    private function accountNet(string $code): float
    {
        $accountId = Account::where('code', $code)->value('id');
        $row = $this->tenant()->table('journal_lines')
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->where('account_id', $accountId)->first();

        return round((float) $row->d - (float) $row->c, 2);
    }

    /** @return array{proc: resource, pipes: array} */
    private function worker(array $args): array
    {
        $cmd = array_merge(
            [PHP_BINARY, base_path('tests/MySql/Support/catering_rate_race_worker.php')],
            array_map('strval', $args)
        );
        $pipes = [];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), array_merge(getenv() ?: [], [
            'EDGE_TEST_TENANT_DB' => $this->tenantDb,
            'APP_ENV' => 'testing',
            'START_FILE' => '',
        ]));

        return ['proc' => $proc, 'pipes' => $pipes];
    }

    private function finish(array $handle): string
    {
        $out = trim(stream_get_contents($handle['pipes'][1]));
        $err = trim(stream_get_contents($handle['pipes'][2]) ?: '');
        fclose($handle['pipes'][1]);
        fclose($handle['pipes'][2]);
        proc_close($handle['proc']);

        return $out !== '' ? $out : 'STDERR:'.$err;
    }

    private function stillRunning(array $handle): bool
    {
        return (bool) (proc_get_status($handle['proc'])['running'] ?? false);
    }
}
