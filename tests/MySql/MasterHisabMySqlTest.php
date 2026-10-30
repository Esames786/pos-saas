<?php

namespace Tests\MySql;

use App\Models\Master\CentralUser;
use App\Models\Master\CompanyAccount;
use App\Models\Master\CompanyEntry;
use App\Models\Master\CompanyEntryLog;
use App\Models\Master\CompanyPartner;
use App\Models\Master\Tenant;
use App\Services\Saas\BillingRates;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * MASTER-HISAB-1 — Master → Hisab, asli raaste se (route → middleware → controller → Blade).
 *
 * Paisa hai: har figure owner ke October 2026 ke hisaab se jaancha gaya hai (naam badle hue) —
 * aamdani 2,55,886 · kharcha 42,535.66 · nafa 2,13,350.34 · har owner ka hissa 1,06,675.17 ·
 * Owner One ke paas 1,13,350.34, Owner Two ke paas 1,00,000 → Owner One, Owner Two ko 6,675.17 bheje.
 */
class MasterHisabMySqlTest extends MySqlTenantTestCase
{
    private const PERMS = [
        'central.hisab.index', 'central.hisab.export', 'central.hisab.entries.store', 'central.hisab.entries.update',
        'central.hisab.entries.destroy', 'central.hisab.setup.update',
    ];

    private string $host;

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $tenantIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('master');
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
        $this->host = 'http://'.config('tenancy.central_domain');
        $this->wipe();
    }

    protected function tearDown(): void
    {
        $this->wipe();
        foreach ($this->userIds as $id) {
            DB::connection('master')->table('model_has_permissions')->where('model_id', $id)
                ->where('model_type', CentralUser::class)->delete();
            DB::connection('master')->table('central_users')->where('id', $id)->delete();
        }
        DB::connection('master')->table('tenants')->whereIn('id', $this->tenantIds)->delete();
        parent::tearDown();
    }

    private function wipe(): void
    {
        $m = DB::connection('master');
        $m->table('company_entry_logs')->delete();
        $m->table('company_entry_lines')->delete();
        $m->table('company_entries')->delete();
        $m->table('company_accounts')->delete();
        $m->table('company_partners')->delete();
        $m->table('platform_settings')->whereIn('key', array_keys(BillingRates::KEYS))->delete();
        $this->app->forgetScopedInstances();
    }

    private function admin(array $permissions = self::PERMS): CentralUser
    {
        $user = CentralUser::create([
            'name' => 'Hisab Admin '.count($this->userIds),
            'email' => 'hisab'.uniqid().'@example.test',
            'password' => bcrypt('secret'),
            'status' => 'active',
        ]);
        $this->userIds[] = $user->id;
        foreach ($permissions as $p) {
            Permission::findOrCreate($p, 'central');
        }
        $user->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function client(string $name): int
    {
        $t = Tenant::create(['tenant_code' => 'hisab-'.uniqid(), 'business_name' => $name, 'status' => 'active']);
        $this->tenantIds[] = $t->id;

        return $t->id;
    }

    private function hit(CentralUser $u, string $method, string $uri, array $data = [])
    {
        return $this->actingAs($u, 'central')->json($method, $this->host.$uri, $data);
    }

    /** Do owners (50/50), har ek ka apna khata — Master ke "Owners aur khate" popup se. */
    private function setupOwners(CentralUser $u): array
    {
        $this->hit($u, 'PUT', '/hisab/setup', [
            'partners' => [
                ['key' => 'n1', 'name' => 'Owner One', 'share_percent' => 50, 'is_active' => true],
                ['key' => 'n2', 'name' => 'Owner Two', 'share_percent' => 50, 'is_active' => true],
            ],
            'accounts' => [
                ['name' => 'Owner One ka account', 'partner_key' => 'n1', 'is_active' => true],
                ['name' => 'Owner Two ka account', 'partner_key' => 'n2', 'is_active' => true],
            ],
        ])->assertOk()->assertJson(['ok' => true]);

        return [
            CompanyAccount::where('name', 'Owner One ka account')->value('id'),
            CompanyAccount::where('name', 'Owner Two ka account')->value('id'),
        ];
    }

    private function entry(CentralUser $u, array $data): CompanyEntry
    {
        $res = $this->hit($u, 'POST', '/hisab/entries', $data)->assertOk()->assertJson(['ok' => true]);
        $this->assertNotEmpty($res->json('message'));

        return CompanyEntry::query()->latest('id')->firstOrFail();
    }

    /** Owner ka October, jesa unhone bataya. */
    private function october(CentralUser $u, int $one, int $two): array
    {
        [$food, $foods, $grill, $kitchen] = [$this->client('Client Food'), $this->client('Client Foods'), $this->client('Client Grill'), $this->client('Client Kitchen')];
        $in = fn ($date, $tenant, array $lines) => $this->entry($u, ['type' => 'in', 'entry_date' => $date, 'tenant_id' => $tenant, 'lines' => $lines]);
        $out = fn ($date, $cat, $amount, $acc, $extra = []) => $this->entry($u, ['type' => 'out', 'entry_date' => $date,
            'lines' => [['category' => $cat, 'amount' => $amount, 'account_id' => $acc]]] + $extra);

        $in('2026-10-03', $food, [['category' => 'monthly_fee', 'amount' => 35000, 'account_id' => $one]]);
        $out('2026-10-03', 'ai_tools', 30000, $one);
        $card = $out('2026-10-09', 'whatsapp_meta', 0, $one, ['usd_amount' => 1.35, 'bill_amount' => 378.66, 'bank_fee' => 157, 'message_count' => 90]);
        $in('2026-10-10', $foods, [['category' => 'monthly_fee', 'amount' => 20000, 'account_id' => $one]]);
        $out('2026-10-10', 'hosting', 12000, $one);
        $in('2026-10-15', $grill, [
            ['category' => 'monthly_fee', 'amount' => 25000, 'account_id' => $one],
            ['category' => 'whatsapp', 'amount' => 886, 'account_id' => $one],
        ]);
        $split = $in('2026-10-27', $kitchen, [
            ['category' => 'setup_fee', 'amount' => 75000, 'account_id' => $one],
            ['category' => 'setup_fee', 'amount' => 75000, 'account_id' => $two],
        ]);
        $in('2026-10-29', $kitchen, [['category' => 'monthly_fee', 'amount' => 25000, 'account_id' => $two]]);

        return ['card' => $card, 'split' => $split];
    }

    private function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($doc);
    }

    private function text(DOMXPath $x, string $query): string
    {
        $n = $x->query($query);
        $this->assertGreaterThan(0, $n->length, "Not on the page: $query");

        return trim(preg_replace('/\s+/u', ' ', $n->item(0)->textContent));
    }

    public function test_the_owners_october_adds_up_and_says_who_sends_whom(): void
    {
        $u = $this->admin();
        [$one, $two] = $this->setupOwners($u);
        $made = $this->october($u, $one, $two);

        $html = $this->actingAs($u, 'central')->get($this->host.'/hisab?month=2026-10')->assertOk()->getContent();
        $x = $this->xpath($html);

        // Cards.
        $this->assertSame('Rs 255,886', $this->text($x, '//*[@data-hs-kpi="income"]'));
        $this->assertSame('Rs 42,535.66', $this->text($x, '//*[@data-hs-kpi="expense"]'));
        $this->assertSame('Rs 213,350.34', $this->text($x, '//*[@data-hs-kpi="profit"]'));
        $this->assertSame('Rs 213,350.34', $this->text($x, '//*[@data-hs-kpi="total"]'));

        // Barabari: hissa, mila, farq — aur kaun kis ko bheje.
        $p1 = CompanyPartner::where('name', 'Owner One')->value('id');
        $p2 = CompanyPartner::where('name', 'Owner Two')->value('id');
        $this->assertSame('Rs 106,675.17', $this->text($x, "//tr[@data-hs-partner=\"$p1\"]/td[@data-hs-share]"));
        $this->assertSame('Rs 113,350.34', $this->text($x, "//tr[@data-hs-partner=\"$p1\"]/td[@data-hs-got]"));
        $this->assertSame('6675.17', $x->query("//tr[@data-hs-partner=\"$p1\"]/td[@data-hs-diff]")->item(0)->getAttribute('data-hs-diff'));
        $this->assertSame('Rs 100,000', $this->text($x, "//tr[@data-hs-partner=\"$p2\"]/td[@data-hs-got]"));
        $this->assertSame('-6675.17', $x->query("//tr[@data-hs-partner=\"$p2\"]/td[@data-hs-diff]")->item(0)->getAttribute('data-hs-diff'));
        $this->assertSame(1, $x->query('//*[@data-hs-move]')->length);
        $this->assertStringContainsString('Owner One → Owner Two: Rs 6,675.17 bhej dein', $this->text($x, '//*[@data-hs-move]'));

        // Khate (October ke akhir tak).
        $this->assertSame('Rs 113,350.34', $this->text($x, "//*[@data-hs-account=\"$one\"]//*[@data-hs-balance]"));
        $this->assertSame('Rs 100,000', $this->text($x, "//*[@data-hs-account=\"$two\"]//*[@data-hs-balance]"));

        // WhatsApp: clients se 886, card se 535.66 (bill 378.66 + tax 157), nafa 350.34.
        $this->assertSame('Rs 886', $this->text($x, '//*[@data-hs-wa="billed"]'));
        $this->assertSame('Rs 535.66', $this->text($x, '//*[@data-hs-wa="cost"]'));
        $this->assertSame('Rs 350.34', $this->text($x, '//*[@data-hs-wa="profit"]'));
        $this->assertSame('41.46%', $this->text($x, '//table[@data-hs-cards]//td[@data-hs-tax]'));
        $this->assertSame('280.49', $this->text($x, '//table[@data-hs-cards]//td[@data-hs-rate]'));
        $this->assertSame('Rs 5.95', $this->text($x, '//table[@data-hs-cards]//td[@data-hs-per]'));
        $this->assertSame(535.66, $made['card']->total());

        // Sab se nayi card wali entry ne "hamara kharcha fi message" asal kar diya; client ka rate nahi chhua.
        $this->assertSame('5.9518', DB::connection('master')->table('platform_settings')->where('key', BillingRates::WHATSAPP_COST)->value('value'));
        $this->assertNull(DB::connection('master')->table('platform_settings')->where('key', BillingRates::WHATSAPP_RATE)->value('value'));

        // Aik entry, do khate: 1,50,000 = 75,000 + 75,000.
        $this->assertSame([75000.0, 75000.0], $made['split']->lines->pluck('amount')->map(fn ($v) => (float) $v)->all());
        $this->assertSame(8, $x->query('//table[@data-hs-entries]/tbody/tr')->length);
        $this->assertSame(1, $x->query('//a[@href="'.$this->host.'/hisab"]')->length, 'Sidebar shows the Hisab link');

        // Owner One ke khate ka statement: shuru 0, akhir 1,13,350.34.
        $s = $this->xpath($this->actingAs($u, 'central')->get($this->host."/hisab?month=2026-10&account=$one")->assertOk()->getContent());
        $this->assertSame('Rs 113,350.34', $this->text($s, '//*[@data-hs-closing]'));
        // Shuru ka balance + 7 entries (Client Grill ke 25,886 aik line, chahe andar mahana + WhatsApp ho).
        $this->assertSame(8, $s->query('//table[@data-hs-statement]/tbody/tr')->length);
        $this->assertSame(1, $s->query('//table[@data-hs-statement]/tbody/tr[td[contains(., "Rs 25,886")]]')->length);
    }

    public function test_the_excel_file_holds_the_same_hisab_as_the_page(): void
    {
        $u = $this->admin();
        [$one, $two] = $this->setupOwners($u);
        $this->october($u, $one, $two);

        $res = $this->actingAs($u, 'central')->get($this->host.'/hisab/export?month=2026-10')->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', (string) $res->headers->get('Content-Type'));
        $this->assertStringContainsString('bingoo-hisab-2026-10.xlsx', (string) $res->headers->get('Content-Disposition'));
        $book = $this->readXlsx($res->baseResponse->getFile()->getPathname());

        $this->assertSame(['Khulasa', 'Entries', 'Khata - Owner One ka account', 'Khata - Owner Two ka account', 'Mahine-war', 'WhatsApp'], array_keys($book));
        $sum = $this->byLabel($book['Khulasa']);
        $this->assertSame(255886.0, (float) $sum['Aamdani'][1]);
        $this->assertSame(42535.66, (float) $sum['Kharcha'][1]);
        $this->assertSame(213350.34, (float) $sum['Nafa'][1]);
        $this->assertSame(106675.17, (float) $sum['Owner One'][2]);
        $this->assertSame(113350.34, (float) $sum['Owner One'][3]);
        $this->assertSame(6675.17, (float) $sum['Owner One'][4]);
        $this->assertSame(-6675.17, (float) $sum['Owner Two'][4]);
        $this->assertSame(0.5, (float) $sum['Owner One'][1], 'share as a real percent cell');
        $this->assertSame(6675.17, (float) $sum['Owner One → Owner Two'][2]);
        $this->assertSame(350.34, (float) $sum['WhatsApp ka nafa'][1]);

        // Har hissa aik row: 8 entries, do ke do hisse = 10 rows, upar header aur neeche total.
        $entries = $book['Entries'];
        $this->assertCount(12, $entries);
        $this->assertSame(['Total', 255886.0, 42535.66], [end($entries)[0], (float) end($entries)[7], (float) end($entries)[8]]);
        $this->assertSame(2, count(array_filter($entries, fn ($r) => ($r[3] ?? '') === '🧩 One-time fee')), 'the 1,50,000 split shows as two rows');
        $this->assertSame(46298.0, (float) $entries[1][0], 'dates are real Excel dates (3 Oct 2026 = day 46298)');

        $mohsin = $book['Khata - Owner One ka account'];
        $this->assertSame('Akhir ka balance', end($mohsin)[2]);
        $this->assertSame(113350.34, (float) end($mohsin)[6]);
        $wa = $book['WhatsApp'][1];
        $this->assertSame([1.35, 378.66, 157.0, 0.4146, 280.49, 90.0, 5.95, 535.66],
            [(float) $wa[2], (float) $wa[3], (float) $wa[4], round((float) $wa[5], 4), (float) $wa[6], (float) $wa[7], (float) $wa[8], (float) $wa[9]]);

        // "Shuru se ab tak" — aur Excel bhi permission ke baghair nahi milta.
        $all = $this->actingAs($u, 'central')->get($this->host.'/hisab/export?scope=all')->assertOk();
        $this->assertStringContainsString('bingoo-hisab-shuru-se-', (string) $all->headers->get('Content-Disposition'));
        $page = $this->actingAs($u, 'central')->get($this->host.'/hisab?month=2026-10')->getContent();
        $this->assertStringContainsString('/hisab/export?month=2026-10', $page);
        $other = $this->admin(['central.hisab.index']);
        $this->actingAs($other, 'central')->get($this->host.'/hisab/export?month=2026-10')->assertForbidden();
        $this->assertStringNotContainsString('/hisab/export', $this->actingAs($other, 'central')->get($this->host.'/hisab')->getContent());
    }

    /** @return array<string, list<list<mixed>>> sheet naam => rows (string ya number) */
    private function readXlsx(string $path): array
    {
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'a real zip (.xlsx)');
        $wb = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
        $out = [];
        $i = 1;
        foreach ($wb->sheets->sheet as $sheet) {
            $xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet'.$i++.'.xml'));
            $rows = [];
            foreach ($xml->sheetData->row as $row) {
                $cells = [];
                foreach ($row->c as $c) {
                    preg_match('/^([A-Z]+)/', (string) $c['r'], $m);
                    $col = 0;
                    foreach (str_split($m[1]) as $ch) {
                        $col = $col * 26 + ord($ch) - 64;
                    }
                    $cells[$col - 1] = (string) $c['t'] === 'inlineStr' ? (string) $c->is->t : (isset($c->v) ? (string) $c->v : '');
                }
                $rows[] = $cells === [] ? [] : array_replace(array_fill(0, max(array_keys($cells)) + 1, ''), $cells);
            }
            $out[(string) $sheet['name']] = $rows;
        }

        return $out;
    }

    /** Pehle khaane ke label se row dhoondo. */
    private function byLabel(array $rows): array
    {
        $map = [];
        foreach ($rows as $r) {
            if (isset($r[0]) && $r[0] !== '' && ! isset($map[$r[0]])) {
                $map[$r[0]] = $r;
            }
        }

        return $map;
    }

    public function test_sending_the_difference_settles_both_owners(): void
    {
        $u = $this->admin();
        [$one, $two] = $this->setupOwners($u);
        $this->october($u, $one, $two);

        $this->entry($u, ['type' => 'transfer', 'entry_date' => '2026-10-30', 'note' => 'Barabari',
            'lines' => [['account_id' => $one, 'to_account_id' => $two, 'category' => 'transfer', 'amount' => 6675.17]]]);

        $x = $this->xpath($this->actingAs($u, 'central')->get($this->host.'/hisab?month=2026-10')->assertOk()->getContent());
        // Transfer nafa nahi badalta.
        $this->assertSame('Rs 213,350.34', $this->text($x, '//*[@data-hs-kpi="profit"]'));
        foreach (CompanyPartner::pluck('id') as $pid) {
            $this->assertSame('0.00', $x->query("//tr[@data-hs-partner=\"$pid\"]/td[@data-hs-diff]")->item(0)->getAttribute('data-hs-diff'));
        }
        $this->assertSame(0, $x->query('//*[@data-hs-move]')->length);
        $this->assertStringContainsString('Hisab barabar hai', $html = $x->document->saveHTML());
        $this->assertSame('Rs 106,675.17', $this->text($x, "//*[@data-hs-account=\"$one\"]//*[@data-hs-balance]"));
        $this->assertSame('Rs 106,675.17', $this->text($x, "//*[@data-hs-account=\"$two\"]//*[@data-hs-balance]"));
    }

    public function test_a_month_shows_its_own_figures_and_the_settlement_carries_over(): void
    {
        $u = $this->admin();
        [$one, $two] = $this->setupOwners($u);
        $this->october($u, $one, $two);
        $this->entry($u, ['type' => 'in', 'entry_date' => '2026-11-05', 'lines' => [['category' => 'monthly_fee', 'amount' => 10000, 'account_id' => $two]]]);

        $x = $this->xpath($this->actingAs($u, 'central')->get($this->host.'/hisab?month=2026-11')->assertOk()->getContent());
        $this->assertSame('Rs 10,000', $this->text($x, '//*[@data-hs-kpi="income"]'));
        $this->assertSame('Rs 0', $this->text($x, '//*[@data-hs-kpi="expense"]'));
        // Shuru se November tak: nafa 2,23,350.34 → hissa 1,11,675.17; Owner One ke paas 1,13,350.34 → 1,675.17 zyada.
        $p1 = CompanyPartner::where('name', 'Owner One')->value('id');
        $this->assertSame('1675.17', $x->query("//tr[@data-hs-partner=\"$p1\"]/td[@data-hs-diff]")->item(0)->getAttribute('data-hs-diff'));
        $this->assertSame(1, $x->query('//table[@data-hs-entries]/tbody/tr')->length);
    }

    public function test_edit_is_logged_a_stale_edit_is_refused_and_delete_needs_a_reason(): void
    {
        $u = $this->admin();
        [$one] = $this->setupOwners($u);
        $e = $this->entry($u, ['type' => 'out', 'entry_date' => '2026-10-10', 'lines' => [['category' => 'hosting', 'amount' => 11000, 'account_id' => $one]]]);
        // Popup wahi nishaan bhejta hai jo safhe ne diya tha.
        $was = app(\App\Services\Saas\CompanyHisab::class)->fingerprint($e);
        $page = $this->actingAs($u, 'central')->get($this->host.'/hisab?month=2026-10')->assertOk()->getContent();
        $this->assertStringContainsString($was, $page);

        $this->hit($u, 'PUT', "/hisab/entries/{$e->id}", ['type' => 'out', 'entry_date' => '2026-10-10', '_was' => $was,
            'lines' => [['category' => 'hosting', 'amount' => 12000, 'account_id' => $one]]])->assertOk();
        $log = CompanyEntryLog::where('company_entry_id', $e->id)->where('action', 'updated')->firstOrFail();
        $this->assertSame(11000, (int) $log->before['lines'][0]['amount']);
        $this->assertSame(12000, (int) $log->after['lines'][0]['amount']);
        $this->assertSame(12000.0, $e->fresh()->load('lines')->total());

        // Doosre ne pehle wala page khol rakha tha — us ka save chup chaap upar nahi likhta (usi second mein bhi).
        $this->hit($u, 'PUT', "/hisab/entries/{$e->id}", ['type' => 'out', 'entry_date' => '2026-10-10', '_was' => $was,
            'lines' => [['category' => 'hosting', 'amount' => 99, 'account_id' => $one]]])
            ->assertStatus(422)->assertJsonPath('errors.entry.0', 'Ye entry kisi aur ne abhi badli hai. Page dobara kholein.');
        $this->assertSame(12000.0, $e->fresh()->load('lines')->total());

        $this->hit($u, 'DELETE', "/hisab/entries/{$e->id}", ['reason' => ''])->assertStatus(422);
        $this->assertNull($e->fresh()->deleted_at);
        $this->hit($u, 'DELETE', "/hisab/entries/{$e->id}", ['reason' => 'Do dafa daali'])->assertOk();
        $this->assertNotNull($e->fresh()->deleted_at);
        $this->assertSame('Do dafa daali', $e->fresh()->delete_reason);

        $x = $this->xpath($this->actingAs($u, 'central')->get($this->host.'/hisab?month=2026-10')->assertOk()->getContent());
        $this->assertSame('Rs 0', $this->text($x, '//*[@data-hs-kpi="expense"]'), 'a deleted entry leaves the hisab');
        $this->assertSame(0, $x->query('//table[@data-hs-entries]/tbody/tr')->length);
        $d = $this->xpath($this->actingAs($u, 'central')->get($this->host.'/hisab?month=2026-10&deleted=1')->assertOk()->getContent());
        $this->assertSame(1, $d->query('//table[@data-hs-entries]/tbody/tr[contains(@class,"hs-deleted")]')->length);
        $this->assertSame(['created', 'updated', 'deleted'], CompanyEntryLog::where('company_entry_id', $e->id)->orderBy('id')->pluck('action')->all());
    }

    public function test_wrong_entries_are_refused_with_a_plain_reason(): void
    {
        $u = $this->admin();
        [$one, $two] = $this->setupOwners($u);

        $this->hit($u, 'POST', '/hisab/entries', ['type' => 'transfer', 'entry_date' => '2026-10-30',
            'lines' => [['account_id' => $one, 'to_account_id' => $one, 'category' => 'transfer', 'amount' => 10]]])
            ->assertStatus(422)->assertJsonPath('errors', ['lines.0.to_account_id' => ['Dono khate ek hi hain — alag khata chunein.']]);
        $this->hit($u, 'POST', '/hisab/entries', ['type' => 'out', 'entry_date' => '2026-10-30', 'usd_amount' => 1, 'bill_amount' => 280,
            'lines' => [['category' => 'whatsapp_meta', 'amount' => 0, 'account_id' => $one], ['category' => 'hosting', 'amount' => 5, 'account_id' => $one]]])
            ->assertStatus(422)->assertJsonValidationErrors('lines');
        $this->hit($u, 'POST', '/hisab/entries', ['type' => 'out', 'entry_date' => '2026-10-30', 'bill_amount' => 280,
            'lines' => [['category' => 'whatsapp_meta', 'amount' => 0, 'account_id' => $one]]])
            ->assertStatus(422)->assertJsonValidationErrors('usd_amount');
        $this->hit($u, 'POST', '/hisab/entries', ['type' => 'in', 'entry_date' => '2026-10-30',
            'lines' => [['category' => 'hosting', 'amount' => 5, 'account_id' => $one]]])
            ->assertStatus(422)->assertJsonValidationErrors('lines.0.category');
        $this->hit($u, 'POST', '/hisab/entries', ['type' => 'in', 'entry_date' => '2026-10-30',
            'lines' => [['category' => 'monthly_fee', 'amount' => 0, 'account_id' => $one]]])
            ->assertStatus(422)->assertJsonValidationErrors('lines.0.amount');
        $this->assertSame(0, CompanyEntry::count());

        // Kharche par client nahi lagta — bheja bhi jaye to khali.
        $tenant = $this->client('Client Food');
        $e = $this->entry($u, ['type' => 'out', 'entry_date' => '2026-10-30', 'tenant_id' => $tenant,
            'lines' => [['category' => 'domain', 'amount' => 3000, 'account_id' => $two]]]);
        $this->assertNull($e->tenant_id);

        // Hisse 100 se kam — owners save nahi hote.
        $this->hit($u, 'PUT', '/hisab/setup', [
            'partners' => [
                ['key' => 'p'.CompanyPartner::where('name', 'Owner One')->value('id'), 'id' => CompanyPartner::where('name', 'Owner One')->value('id'), 'name' => 'Owner One', 'share_percent' => 60, 'is_active' => true],
                ['key' => 'p'.CompanyPartner::where('name', 'Owner Two')->value('id'), 'id' => CompanyPartner::where('name', 'Owner Two')->value('id'), 'name' => 'Owner Two', 'share_percent' => 30, 'is_active' => true],
            ],
            'accounts' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('partners');
        $this->assertSame(['50.00', '50.00'], CompanyPartner::orderBy('id')->pluck('share_percent')->all());

        // Jis khate mein paisa para ho (Owner Two: −3,000) wo band nahi hota; band khata entry nahi leta.
        $this->hit($u, 'PUT', '/hisab/setup', [
            'partners' => CompanyPartner::orderBy('id')->get()->map(fn ($p) => ['key' => 'p'.$p->id, 'id' => $p->id, 'name' => $p->name, 'share_percent' => 50, 'is_active' => true])->all(),
            'accounts' => [['id' => $two, 'name' => 'Owner Two ka account', 'partner_key' => 'p'.CompanyPartner::where('name', 'Owner Two')->value('id'), 'is_active' => false]],
        ])->assertStatus(422)->assertJsonValidationErrors('accounts');
        $this->assertTrue((bool) CompanyAccount::find($two)->is_active);
        CompanyAccount::whereKey($one)->update(['is_active' => false]);
        $this->hit($u, 'POST', '/hisab/entries', ['type' => 'in', 'entry_date' => '2026-10-30',
            'lines' => [['category' => 'monthly_fee', 'amount' => 5, 'account_id' => $one]]])
            ->assertStatus(422)->assertJsonValidationErrors('lines.0.account_id');
    }

    public function test_only_people_with_the_permission_see_or_touch_the_hisab(): void
    {
        $owner = $this->admin();
        [$one] = $this->setupOwners($owner);
        $other = $this->admin(['central.billing-rates.edit']);

        $this->actingAs($other, 'central')->get($this->host.'/hisab')->assertForbidden();
        $this->hit($other, 'POST', '/hisab/entries', ['type' => 'in', 'entry_date' => '2026-10-30',
            'lines' => [['category' => 'monthly_fee', 'amount' => 5, 'account_id' => $one]]])->assertForbidden();
        $this->assertSame(0, CompanyEntry::count());

        $page = $this->actingAs($other, 'central')->get($this->host.'/billing-rates')->assertOk()->getContent();
        $this->assertStringNotContainsString($this->host.'/hisab"', $page, 'no sidebar link without the permission');
    }

    public function test_every_hisab_route_is_listed_in_the_master_seeder(): void
    {
        // deploy.sh step 3b MasterSeeder chalata hai aur Super Admin ko SIRF us ki list deta hai — jo route
        // wahan nahi, us ki permission har deploy par chhin jati hai.
        $seeder = file_get_contents(database_path('seeders/MasterSeeder.php'));
        $names = collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn ($n) => str_starts_with($n, 'central.hisab.'));
        $this->assertEqualsCanonicalizing(self::PERMS, $names->values()->all());
        foreach ($names as $name) {
            $this->assertStringContainsString("'$name'", $seeder);
        }
    }
}
