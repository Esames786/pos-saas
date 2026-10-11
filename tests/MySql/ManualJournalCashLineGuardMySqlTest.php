<?php

namespace Tests\MySql;

use App\Models\Tenant\Account;
use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\CashBankAccountTransaction;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\User;
use App\Services\Finance\JournalService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\ParsesInlineScripts;

/**
 * MANUAL-JOURNAL-CASH-LINE-GUARD-1 — Cash/Bank sirf apni hi satar par.
 *
 * Ek live tenant par (1 Oct 2026, paanch journal): drawer drawing ki DEBIT satar par chuna gaya, to
 * drawing Cash Book me "In" ban gayi; aur jahan credit group head 1100 par gaya wahan drawer
 * chuna hi nahi gaya, to paisa nikla magar Cash Book me kuch nahi aaya. Drawer 29,230 zyada
 * dikhata tha. GL aur Trial Balance barabar the, is liye kisi ko pata nahi chala.
 *
 * Fixture usi tenant ki shakl ki hai: drawer "Cash in Hand" GL 1110 se juda, drawings 3000-1 /
 * 3000-2 (Equity ke neeche), "Advance to Employees" 1000-1, aur 1100 Cash on Hand group head.
 *
 * Har guard ASLI HTTP raaste par chalta hai — controller ki validation, route ka middleware,
 * aur render hua safha.
 */
class ManualJournalCashLineGuardMySqlTest extends MySqlTenantTestCase
{
    use ParsesInlineScripts;

    private const OPENING = 100000.0;

    private int $tenantId;
    private int $ownerId;
    private int $drawerId;      // HAND-CASH "Cash in Hand" → 1110
    private int $bankId;        // BANK-MAIN → 1210
    private string $host;

    protected function setUp(): void
    {
        parent::setUp();

        // CSRF browser ka kaam hai; is imtihan ka mauzu validation aur middleware hai.
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        $this->host = 'mjguard.' . config('tenancy.tenant_base_domain');

        $this->seedMaster();
        $this->seedSubscription();
        $this->bootTenant();
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)
                ->where('tenant_id', $this->tenantId)->delete();
            $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenants')->where('tenant_code', 'mjguard')->delete();
        } catch (\Throwable) {
            // best effort — asli nateeja kabhi na chhupe
        }
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // 1. Sahi drawing post hoti hai — drawer 1110 ki satar par, Cash Book me OUT
    // ══════════════════════════════════════════════════════════════════════════════

    public function test_sahi_drawing_post_hoti_hai_aur_drawer_se_out_hota_hai(): void
    {
        $res = $this->postJournal([
            ['code' => '3000-1', 'debit' => 5000, 'credit' => 0],
            ['code' => '1110', 'debit' => 0, 'credit' => 5000, 'drawer' => $this->drawerId],
        ]);

        $res->assertSessionHasNoErrors();
        $res->assertRedirect();

        $entry = JournalEntry::where('source_type', 'manual_journal')->sole();
        $txn = CashBankAccountTransaction::where('reference_type', 'manual_journal')
            ->where('reference_id', $entry->id)->sole();

        $this->assertSame($this->drawerId, (int) $txn->cash_bank_account_id);
        $this->assertSame('out', $txn->direction, 'drawing = paisa drawer se BAHAR');
        $this->assertSame(5000.0, (float) $txn->amount);
        $this->assertSame(self::OPENING - 5000, $this->drawerBalance(), 'drawer 5,000 kam hua');
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // 2. Drawer ghalat satar par → rad, kuch nahi likha gaya  (R1)
    // ══════════════════════════════════════════════════════════════════════════════

    public function test_drawing_ki_satar_par_drawer_rad_hota_hai(): void
    {
        $lines = [
            ['code' => '3000-1', 'debit' => 5000, 'credit' => 0, 'drawer' => $this->drawerId],
            ['code' => '1110', 'debit' => 0, 'credit' => 5000],
        ];

        // Browser ka raasta: form par wapas, satar 0 ke drawer par ghalti.
        $res = $this->postJournal($lines);
        $res->assertSessionHasErrors('lines.0.cash_bank_account_id');

        $msg = session('errors')->first('lines.0.cash_bank_account_id');
        $this->assertStringContainsString('Cash in Hand is linked to 1110', $msg, 'paighaam sahi khaata batata hai');
        $this->assertStringContainsString('not on 3000-1', $msg, 'aur ghalat satar ka naam leta hai');

        // JSON raasta: 422 usi key par.
        $json = $this->actingAsOwner()->postJson('http://' . $this->host . '/finance/manual-journals', $this->payload($lines));
        $json->assertStatus(422)->assertJsonValidationErrors('lines.0.cash_bank_account_id');

        $this->assertNothingWritten();
    }

    /** Jo drawer kisi GL khaate se juda hi nahi, wo kisi satar par nahi baith sakta. */
    public function test_be_khaata_drawer_rad_hota_hai(): void
    {
        $orphan = CashBankAccount::create([
            'code' => 'LOOSE', 'name' => 'Loose Drawer', 'account_type' => 'cash', 'account_id' => null,
            'opening_balance' => 0, 'current_balance' => 0, 'is_active' => true,
        ])->id;

        $res = $this->postJournal([
            ['code' => '3000-1', 'debit' => 500, 'credit' => 0],
            ['code' => '1110', 'debit' => 0, 'credit' => 500, 'drawer' => $orphan],
        ]);

        $res->assertSessionHasErrors('lines.1.cash_bank_account_id');
        $this->assertStringContainsString('not linked to any account', session('errors')->first('lines.1.cash_bank_account_id'));
        $this->assertNothingWritten();
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // 3. Cash ki satar bina drawer ke  (R2)
    // ══════════════════════════════════════════════════════════════════════════════

    public function test_cash_satar_par_akela_drawer_khud_bhar_jata_hai(): void
    {
        // Band (inactive) drawer usi khaate par ho to bhi wo ginti me nahi — chunao sirf active me.
        CashBankAccount::create([
            'code' => 'OLD-CASH', 'name' => 'Old Drawer', 'account_type' => 'cash', 'account_id' => $this->accId('1110'),
            'opening_balance' => 0, 'current_balance' => 0, 'is_active' => false,
        ]);

        $res = $this->postJournal([
            ['code' => '3000-1', 'debit' => 6000, 'credit' => 0],
            ['code' => '1110', 'debit' => 0, 'credit' => 6000],   // drawer NAHI chuna
        ]);

        $res->assertSessionHasNoErrors();

        $txn = CashBankAccountTransaction::where('reference_type', 'manual_journal')->sole();
        $this->assertSame($this->drawerId, (int) $txn->cash_bank_account_id, '1110 ka akela active drawer khud chuna gaya');
        $this->assertSame('out', $txn->direction);
        $this->assertSame(self::OPENING - 6000, $this->drawerBalance());
    }

    public function test_ek_khaate_par_do_drawer_hon_to_chunna_parta_hai(): void
    {
        // Do drawer ek hi khaate 1110 par (CASH-MAIN aur HAND-CASH).
        CashBankAccount::create([
            'code' => 'CASH-MAIN', 'name' => 'Main Cash Drawer', 'account_type' => 'cash', 'account_id' => $this->accId('1110'),
            'opening_balance' => 0, 'current_balance' => 0, 'is_active' => true,
        ]);

        $res = $this->postJournal([
            ['code' => '3000-1', 'debit' => 6000, 'credit' => 0],
            ['code' => '1110', 'debit' => 0, 'credit' => 6000],
        ]);

        $res->assertSessionHasErrors('lines.1.cash_bank_account_id');
        $msg = session('errors')->first('lines.1.cash_bank_account_id');
        $this->assertStringContainsString('more than one', $msg);
        $this->assertStringContainsString('Cash in Hand', $msg);
        $this->assertStringContainsString('Main Cash Drawer', $msg);
        $this->assertNothingWritten();
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // 4. Group head par posting nahi  (R3)
    // ══════════════════════════════════════════════════════════════════════════════

    public function test_group_head_1100_rad_hota_hai(): void
    {
        // JE-20261001-0013 ki hoobahoo shakl: Dr 3000-1 / Cr 1100, koi drawer nahi.
        $res = $this->postJournal([
            ['code' => '3000-1', 'debit' => 6000, 'credit' => 0],
            ['code' => '1100', 'debit' => 0, 'credit' => 6000],
        ]);

        $res->assertSessionHasErrors('lines.1.account_id');
        $msg = session('errors')->first('lines.1.account_id');
        $this->assertStringContainsString('1100 Cash on Hand is a group', $msg);
        $this->assertStringContainsString('1110', $msg, 'neeche ka khaata sujhata hai');
        $this->assertNothingWritten();
    }

    public function test_equity_ka_group_head_bhi_rad_hota_hai(): void
    {
        $res = $this->postJournal([
            ['code' => '3000', 'debit' => 1000, 'credit' => 0],
            ['code' => '1110', 'debit' => 0, 'credit' => 1000, 'drawer' => $this->drawerId],
        ]);

        $res->assertSessionHasErrors('lines.0.account_id');
        $this->assertNothingWritten();
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // 5. Narration asli ho  (R5)
    // ══════════════════════════════════════════════════════════════════════════════

    public function test_nuqta_ya_dash_narration_nahi(): void
    {
        foreach (['.', '-', '...', ' . ', 'ab'] as $bad) {
            $res = $this->postJournal([
                ['code' => '3000-1', 'debit' => 100, 'credit' => 0],
                ['code' => '1110', 'debit' => 0, 'credit' => 100, 'drawer' => $this->drawerId],
            ], $bad);

            $res->assertSessionHasErrors('description');
        }
        $this->assertNothingWritten();

        // Urdu narration theek hai — shart harf ki hai, angrezi ki nahi.
        $this->postJournal([
            ['code' => '3000-1', 'debit' => 100, 'credit' => 0],
            ['code' => '1110', 'debit' => 0, 'credit' => 100, 'drawer' => $this->drawerId],
        ], 'مالک کی رقم')->assertSessionHasNoErrors();
        $this->assertSame(1, JournalEntry::where('source_type', 'manual_journal')->count());
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // 6. Purani ghalat entry ab bhi Reverse hoti hai  + B1 + B2
    // ══════════════════════════════════════════════════════════════════════════════

    public function test_purani_ghalat_entry_reverse_hoti_hai_aur_reversal_ka_safha_khulta_hai(): void
    {
        $legacy = $this->legacyWrongDrawing(5000);
        $this->assertSame(self::OPENING + 5000, $this->drawerBalance(), 'purana tareeqa: drawing "In" ban gayi thi');

        $res = $this->actingAsOwner()->post(
            'http://' . $this->host . '/finance/manual-journals/' . $legacy->id . '/reverse', ['reason' => 'drawer ghalat satar par']);

        $reversal = JournalEntry::where('reversed_entry_id', $legacy->id)->sole();
        $res->assertRedirect(url('/finance/manual-journals/' . $reversal->id));

        // Cash: ulti satar, drawer wapas apni jagah.
        $rev = CashBankAccountTransaction::where('reference_type', 'manual_journal_reversal')->sole();
        $this->assertSame('out', $rev->direction);
        $this->assertSame(5000.0, (float) $rev->amount);
        $this->assertSame(self::OPENING, $this->drawerBalance(), 'drawer wapas 100,000');

        // B1 — Reverse ke baad jo safha khulta hai wo 404 nahi, aur reversal ka naam leta hai.
        $page = $this->actingAsOwner()->get('http://' . $this->host . '/finance/manual-journals/' . $reversal->id);
        $page->assertOk();
        $html = $page->getContent();
        $this->assertStringContainsString($reversal->entry_no, $html);
        $this->assertStringContainsString('This is a reversal of', $html);
        $this->assertStringContainsString($legacy->entry_no, $html);
        $this->assertStringNotContainsString('data-bs-target="#reverseModal"', $html, 'reversal par Reverse ka button nahi');
        $this->assertStringContainsString('Cash / Bank Impact', $html, 'reversal ki cash satar bhi dikhti hai');

        // Asal ka "reversed by" link bhi ab khulta hai.
        $orig = $this->actingAsOwner()->get('http://' . $this->host . '/finance/manual-journals/' . $legacy->id);
        $orig->assertOk();
        $this->assertStringContainsString('This entry was reversed by', $orig->getContent());

        // B2 — list me reversal apne badge ke saath, aur asal par "Reversed".
        $list = $this->actingAsOwner()->get('http://' . $this->host . '/finance/manual-journals')->assertOk()->getContent();
        $row = fn (string $entryNo) => $this->rowOf($list, $entryNo);
        $this->assertStringContainsString('Reversal</span>', $row($reversal->entry_no), 'reversal list me, badge ke saath');
        $this->assertStringContainsString('Reversed</span>', $row($legacy->entry_no), 'asal par Reversed ka nishan');
        $this->assertStringContainsString($reversal->entry_no, $row($legacy->entry_no), 'asal batata hai kis ne palta');
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // 7. Double click = ek entry  (B3)
    // ══════════════════════════════════════════════════════════════════════════════

    public function test_ek_hi_form_do_bar_bhejne_par_ek_journal_banta_hai(): void
    {
        $payload = $this->payload([
            ['code' => '3000-1', 'debit' => 5000, 'credit' => 0],
            ['code' => '1110', 'debit' => 0, 'credit' => 5000, 'drawer' => $this->drawerId],
        ], 'Partner A drawing') + ['_submit_token' => (string) Str::uuid()];

        $first  = $this->actingAsOwner()->post('http://' . $this->host . '/finance/manual-journals', $payload);
        $second = $this->actingAsOwner()->post('http://' . $this->host . '/finance/manual-journals', $payload);

        $first->assertSessionHasNoErrors();
        $second->assertRedirect();
        $this->assertSame(1, JournalEntry::where('source_type', 'manual_journal')->count(), 'JE-0021/0022 jaisa joda nahi');
        $this->assertSame(1, CashBankAccountTransaction::where('reference_type', 'manual_journal')->count());
        $this->assertSame(self::OPENING - 5000, $this->drawerBalance(), 'drawer se ek hi bar 5,000');
    }

    /** Ghalat form rad ho to token azad — theek kar ke dobara bhejna rukna nahi chahiye. */
    public function test_rad_hua_form_theek_kar_ke_dobara_bheja_ja_sakta_hai(): void
    {
        $token = (string) Str::uuid();

        $this->postJournal([
            ['code' => '3000-1', 'debit' => 5000, 'credit' => 0, 'drawer' => $this->drawerId],
            ['code' => '1110', 'debit' => 0, 'credit' => 5000],
        ], 'Partner A drawing', $token)->assertSessionHasErrors('lines.0.cash_bank_account_id');

        $this->postJournal([
            ['code' => '3000-1', 'debit' => 5000, 'credit' => 0],
            ['code' => '1110', 'debit' => 0, 'credit' => 5000, 'drawer' => $this->drawerId],
        ], 'Partner A drawing', $token)->assertSessionHasNoErrors();

        $this->assertSame(1, JournalEntry::where('source_type', 'manual_journal')->count());
    }

    public function test_reverse_do_bar_dabane_par_ek_hi_reversal(): void
    {
        $legacy = $this->legacyWrongDrawing(1620);
        $url = 'http://' . $this->host . '/finance/manual-journals/' . $legacy->id . '/reverse';

        // Ek hi modal ka token do bar (double click)…
        $token = (string) Str::uuid();
        $this->actingAsOwner()->post($url, ['reason' => 'x', '_submit_token' => $token]);
        $this->actingAsOwner()->post($url, ['reason' => 'x', '_submit_token' => $token]);

        // …aur ek doosre tab se, naye token ke saath — bani hui reversal par le jata hai.
        $third = $this->actingAsOwner()->post($url, ['reason' => 'x', '_submit_token' => (string) Str::uuid()]);

        $reversal = JournalEntry::where('reversed_entry_id', $legacy->id)->sole();
        $third->assertRedirect(url('/finance/manual-journals/' . $reversal->id));
        $third->assertSessionHas('status');
        $this->assertStringContainsString('already reversed', session('status'));

        $this->assertSame(1, CashBankAccountTransaction::where('reference_type', 'manual_journal_reversal')->count());
        $this->assertSame(self::OPENING, $this->drawerBalance(), 'drawer ek hi bar wapas aaya');
    }

    public function test_dono_post_routes_par_duplicate_guard_hai(): void
    {
        foreach (['tenant.finance.manual-journals.store', 'tenant.finance.manual-journals.reverse'] as $name) {
            $route = collect(Route::getRoutes())->first(fn ($r) => $r->getName() === $name);
            $this->assertNotNull($route, "route [{$name}] mojood");
            $this->assertContains('prevent.duplicate.submit', $route->gatherMiddleware(), "[{$name}] par guard");
        }
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // 8. Form sahi entry ko aasan banata hai  (R4) — asli render
    // ══════════════════════════════════════════════════════════════════════════════

    public function test_form_khaate_se_drawer_chalata_hai_aur_group_head_band_hai(): void
    {
        $html = $this->actingAsOwner()->get('http://' . $this->host . '/finance/manual-journals/create')
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('<small class="text-muted">(opt)</small>', $html, '"(opt)" gaya');
        $this->assertStringNotContainsString('on any cash or bank line', $html, 'purana gumraah karne wala tip gaya');
        $this->assertStringContainsString('Choose the drawer or bank on the cash line itself', $html);

        // Har drawer ka option apna khaata saath laata hai — picker isi se chalta hai.
        $this->assertMatchesRegularExpression(
            '/<option value="' . $this->drawerId . '" data-gl="' . $this->accId('1110') . '" data-name="Cash in Hand"/', $html);

        // Khali satar par picker band (koi khaata nahi chuna).
        $this->assertMatchesRegularExpression(
            '/<select name="lines\[0\]\[cash_bank_account_id\]"[^>]*\bdisabled\b/', $html);

        // Group head dikhta hai magar chuna nahi ja sakta.
        $this->assertMatchesRegularExpression(
            '/<option value="' . $this->accId('1100') . '"[^>]*\bdisabled\b[^>]*>\s*1100 — Cash on Hand \(group\)/', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/<option value="' . $this->accId('1110') . '"[^>]*\bdisabled\b/', $html, '1110 chuna ja sakta hai');

        // Live preview aur double-submit ka browser wala hissa.
        foreach (['syncCashCell', 'syncCashPreview', "'Cash Book: '", '_submit_token', 'minlength="3"'] as $needle) {
            $this->assertStringContainsString($needle, $html, "[{$needle}] safhe par");
        }

        $this->assertInlineScriptsParse($html, 'manual journal form', 'syncCashCell');
    }

    /** Validation fail ke baad form wapas aaye to ghalti usi satar ke neeche dikhti hai. */
    public function test_ghalti_usi_satar_ke_neeche_dikhti_hai(): void
    {
        $this->from('http://' . $this->host . '/finance/manual-journals/create')->postJournal([
            ['code' => '3000-1', 'debit' => 5000, 'credit' => 0, 'drawer' => $this->drawerId],
            ['code' => '1110', 'debit' => 0, 'credit' => 5000],
        ]);

        $html = $this->actingAsOwner()->get('http://' . $this->host . '/finance/manual-journals/create')->getContent();
        $this->assertStringContainsString('Choose it on the 1110 line, not on 3000-1', $html);
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // helpers
    // ══════════════════════════════════════════════════════════════════════════════

    /** Form wala payload: har satar, khali reference, aur narration. */
    private function payload(array $lines, string $description = 'Partner A drawing'): array
    {
        $payload = [
            'entry_date'   => '2026-10-01',
            'description'  => $description,
            'reference_no' => '',
            'lines'        => [],
        ];

        foreach ($lines as $l) {
            $row = [
                'account_id' => $this->accId($l['code']),
                'debit'      => $l['debit'],
                'credit'     => $l['credit'],
            ];
            if (! empty($l['drawer'])) {
                $row['cash_bank_account_id'] = $l['drawer'];
            }
            $payload['lines'][] = $row;
        }

        return $payload;
    }

    private function postJournal(array $lines, string $description = 'Partner A drawing', ?string $token = null)
    {
        $payload = $this->payload($lines, $description);
        if ($token) {
            $payload['_submit_token'] = $token;
        }

        return $this->actingAsOwner()->post('http://' . $this->host . '/finance/manual-journals', $payload);
    }

    /**
     * Guard se PEHLE wali entry, jaisi ek live tenant par bani: Dr Drawing / Cr 1110, drawer drawing
     * ki DEBIT satar par — is liye Cash Book me "In". Purana controller isay seedha likhta tha; ab
     * HTTP raasta isay rad karta hai, is liye wohi do qadam yahan hath se.
     */
    private function legacyWrongDrawing(float $amount): JournalEntry
    {
        $entry = app(JournalService::class)->post(
            sourceType:  'manual_journal',
            sourceId:    ((int) JournalEntry::where('source_type', 'manual_journal')->max('source_id')) + 1,
            sourceNo:    null,
            description: '.',
            entryDate:   '2026-10-01',
            lines:       [
                ['account_id' => $this->accId('3000-1'), 'debit' => $amount, 'credit' => 0],
                ['account_id' => $this->accId('1110'), 'debit' => 0, 'credit' => $amount],
            ],
            userId:      $this->ownerId,
        );

        $drawer = CashBankAccount::find($this->drawerId);
        $after = (float) $drawer->current_balance + $amount;
        CashBankAccountTransaction::create([
            'cash_bank_account_id' => $drawer->id, 'transaction_date' => '2026-10-01',
            'direction' => 'in', 'amount' => $amount, 'balance_after' => $after,
            'transaction_type' => 'manual_journal', 'reference_type' => 'manual_journal',
            'reference_id' => $entry->id, 'notes' => 'Manual journal ' . $entry->entry_no,
            'created_by_user_id' => $this->ownerId,
        ]);
        $drawer->update(['current_balance' => $after]);

        return $entry;
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame(0, JournalEntry::where('source_type', 'manual_journal')->count(), 'koi journal nahi bana');
        $this->assertSame(0, CashBankAccountTransaction::count(), 'cash book ko chhua bhi nahi');
        $this->assertSame(self::OPENING, $this->drawerBalance(), 'drawer ka balance wahi');
    }

    private function drawerBalance(): float
    {
        return (float) CashBankAccount::find($this->drawerId)->current_balance;
    }

    /** List ki woh `<tr>` jis me ye entry number hai. */
    private function rowOf(string $html, string $entryNo): string
    {
        $at = strpos($html, 'class="fw-semibold">' . $entryNo . '</a>');   // apni satar ka link, "of/by" wala nahi
        $this->assertNotFalse($at, "[{$entryNo}] list me mojood");
        $open = strrpos(substr($html, 0, $at), '<tr');
        $close = strpos($html, '</tr>', $at);

        return substr($html, $open, $close - $open);
    }

    private function actingAsOwner()
    {
        return $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant');
    }

    private function accId(string $code): int
    {
        return (int) Account::where('code', $code)->value('id');
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $m = DB::connection('master');

        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        $m->table('tenants')->where('tenant_code', 'mjguard')->delete();

        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => 'mjguard', 'business_name' => 'Journal Guard Kitchen',
            'owner_name' => 'Owner', 'owner_email' => 'owner@mjguard.test',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $m->table('tenant_databases')->insert([
            'tenant_id' => $this->tenantId, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'),
            'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => $this->tenantDb,
            'db_username' => config('database.connections.tenant.username'),
            'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $m->table('tenant_domains')->insert([
            'tenant_id' => $this->tenantId, 'domain' => $this->host, 'is_primary' => 1,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Saare modules khule — imtihan validation ka hai, plan-entitlement ka nahi. */
    private function seedSubscription(): void
    {
        $m = DB::connection('master');

        $planId = $m->table('plans')->where('code', 'mjguard-plan')->value('id')
            ?: $m->table('plans')->insertGetId([
                'code' => 'mjguard-plan', 'name' => 'Journal Guard', 'price' => 0,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);

        $m->table('plan_modules')->where('plan_id', $planId)->delete();
        foreach ($m->table('modules')->pluck('id') as $moduleId) {
            $m->table('plan_modules')->insert(['plan_id' => $planId, 'module_id' => $moduleId, 'is_enabled' => 1]);
        }

        $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
        $m->table('subscriptions')->insert([
            'tenant_id' => $this->tenantId, 'plan_id' => $planId, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function bootTenant(): void
    {
        DB::setDefaultConnection('tenant');

        // permissions/roles tenant MIGRATION se aate hain — unhe kabhi truncate nahi karna.
        $this->cleanTenant([
            'journal_lines', 'journal_entries', 'supplier_ledgers',
            'cash_bank_account_transactions', 'cash_bank_accounts',
            'model_has_roles', 'users', 'accounts', 'branches',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        (new DefaultChartOfAccountsSeeder)->run();

        // Tenant ke apne khaate — default COA ke group heads ke neeche.
        foreach ([
            ['1000-1', 'ADVANCE TO EMPLOYEES', 'asset', '1000'],
            ['3000-1', 'PARTNER A DRAWING', 'equity', '3000'],
            ['3000-2', 'PARTNER B DRAWING', 'equity', '3000'],
        ] as [$code, $name, $type, $parent]) {
            Account::create([
                'code' => $code, 'name' => $name, 'type' => $type,
                'normal_balance' => Account::normalBalanceForType($type),
                'parent_id' => $this->accId($parent), 'is_active' => true, 'sort_order' => 999,
            ]);
        }

        $this->drawerId = CashBankAccount::create([
            'code' => 'HAND-CASH', 'name' => 'Cash in Hand', 'account_type' => 'cash',
            'account_id' => $this->accId('1110'),
            'opening_balance' => self::OPENING, 'current_balance' => self::OPENING, 'is_active' => true,
        ])->id;

        $this->bankId = CashBankAccount::create([
            'code' => 'BANK-MAIN', 'name' => 'Main Bank Account', 'account_type' => 'bank',
            'account_id' => $this->accId('1210'),
            'opening_balance' => 0, 'current_balance' => 0, 'is_active' => true,
        ])->id;

        $c = DB::connection('tenant');

        foreach ([
            'tenant.finance.manual-journals.index',
            'tenant.finance.manual-journals.create',
            'tenant.finance.manual-journals.store',
            'tenant.finance.manual-journals.show',
            'tenant.finance.manual-journals.reverse',
        ] as $permName) {
            $c->table('permissions')->updateOrInsert(
                ['name' => $permName, 'guard_name' => 'tenant'],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }

        $ownerRole = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId([
                'name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);
        foreach ($c->table('permissions')->where('guard_name', 'tenant')->pluck('id') as $permId) {
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $permId, 'role_id' => $ownerRole], []);
        }

        $this->ownerId = $c->table('users')->insertGetId([
            'name' => 'Guard Owner', 'email' => 'mjguard-owner@test.local', 'password' => bcrypt('x'),
            'employee_code' => 'MJGOWN', 'status' => 'active', 'locale' => 'en',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert([
            'role_id' => $ownerRole, 'model_type' => User::class, 'model_id' => $this->ownerId,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
