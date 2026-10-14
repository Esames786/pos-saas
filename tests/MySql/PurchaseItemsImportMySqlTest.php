<?php

namespace Tests\MySql;

use App\Models\Tenant\Product;
use App\Models\Tenant\Unit;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * PURCHASE-ITEMS-IMPORT-1 — purane software ke khareed wale items.
 *
 * Puraane accounting system me khareed aur bikri do alag duniyaein thin:
 * 1,398 purchase items aur 567 sale items, aur un me ek bhi mushtarak nahi.
 * Naye system me bikri wali 911 cheezein aa gayin aur khareed wali sirf CHHE.
 *
 * IS TEST KA ASAL MAQSAD CHAAR SATREIN HAIN. Mojooda bulk import har item par
 * `is_sellable = true` likhta hai, aur `is_pos_visible` ka database default
 * bhi true hai. Us raaste se ye data daalne par Tomato aur Plain Shopper POS
 * ki tiles par aur catering ke punch bar me dish ki tarah nazar aate — chalti
 * hui event ke dauran, 900+ ki tadaad me. Ye test us soorat ko wapas aane se
 * rokta hai.
 */
class PurchaseItemsImportMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private string $csv;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant(['products', 'categories', 'units']);

        foreach ([['KG', 'weight'], ['PCS', 'quantity'], ['NOS', 'quantity']] as [$code, $type]) {
            DB::connection('tenant')->table('units')->insert([
                'code' => $code, 'name' => $code, 'unit_type' => $type,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->csv = sys_get_temp_dir().'/pi-'.getmypid().'.csv';
        $fh = fopen($this->csv, 'w');
        fputcsv($fh, ['OldCode', 'Item', 'OldCategory', 'OldUnit', 'OldRate', 'TajweezKardaKism', 'Nishan']);
        fputcsv($fh, ['9', 'TOMATO', 'REGULAR', 'KGS', '120', 'raw_material', '']);
        fputcsv($fh, ['50', 'PLAIN SHOPPER', 'PACKING MATERIAL', 'PCS', '4', 'packaging_material', '']);
        fputcsv($fh, ['70', 'THAAL STEEL', 'BAR DANA (ASSETS)', 'PCS', '2400', 'asset', '']);
        fputcsv($fh, ['80', 'CHAPLI KABAB', 'REGULAR', 'KGS', '900', 'REVIEW', 'naam dish jaisa hai']);
        fputcsv($fh, ['90', 'BIRYANI', 'REGULAR', 'KGS', '0', 'SKIP', 'pehle se menu item']);
        fputcsv($fh, ['95', 'MALMAL CLOTH', 'REGULAR', 'YARD', '60', 'raw_material', '']);
        fclose($fh);
    }

    protected function tearDown(): void
    {
        @unlink($this->csv);

        // APNE PEECHE SAAF KARNA — ye ikhtiyari nahi.
        //
        // `tenant_databases.db_database` par UNIQUE hai, aur master test DB
        // runs ke darmiyan BAQI rehti hai. Ek dafa chhori hui qatar har us
        // doosre test ko torti hai jo apna tenant banata hai ("Duplicate
        // entry ... for key tenant_databases_db_database_unique") — aur wo
        // kharabi mere test ke green hone ke baad shuru hoti hai, kisi aur
        // ke naam par.
        DB::connection('master')->table('tenant_databases')
            ->where('db_database', $this->tenantDb)->delete();

        parent::tearDown();
    }

    // ── Wo chaar satrein ───────────────────────────────────────────────────

    /**
     * Har naya item khareed ke liye khula, bikri aur POS ke liye BAND.
     *
     * Ye is poore kaam ki buniyad hai. Catering ka dish picker `sellable` par
     * chalta hai aur POS ki tiles `is_pos_visible` par; dono me se koi bhi
     * sach ho jaye to sabzi aur shopper dish ki fehrist me aa jate hain.
     */
    public function test_every_imported_item_is_buyable_but_never_sellable(): void
    {
        $this->import();

        $tomato = Product::where('sku', 'OLD-9')->firstOrFail();

        $this->assertTrue((bool) $tomato->is_purchasable, 'khareed ke picker me aana chahiye');
        $this->assertFalse((bool) $tomato->is_sellable, 'catering ke dish picker me NAHI');
        $this->assertFalse((bool) $tomato->is_pos_visible, 'POS ki tiles par NAHI');
        $this->assertTrue((bool) $tomato->is_stock_tracked, 'stock me jata hai');
        $this->assertSame(Product::KIND_RAW_MATERIAL, $tomato->product_kind);
        $this->assertSame('120.00', (string) $tomato->default_purchase_price);
    }

    /** Aur ye ek ek kar ke nahi, HAR ek par. */
    public function test_not_one_imported_item_slips_through_as_sellable(): void
    {
        $this->import();

        $leaked = Product::where('sku', 'like', 'OLD-%')
            ->where(fn ($q) => $q->where('is_sellable', true)->orWhere('is_pos_visible', true))
            ->pluck('name')->all();

        $this->assertSame([], $leaked,
            'in me se koi bhi bikne wali shakl me nahi hona chahiye: '.implode(', ', $leaked));
    }

    // ── Jo qasdan bahar rakhe gaye ─────────────────────────────────────────

    /**
     * ASSET andar nahi aata, aur ye sab se soch kar liya gaya faisla hai.
     *
     * System me `asset` naam ki koi kism hai hi nahi, aur jo teen kism mojood
     * hain (raw, packaging, semi-finished) wohi teeno catering ke MATERIAL
     * picker me aati hain. Thaal ya chair ko un me se kisi par daal dena usay
     * "material" bana deta — aisi cheez jis par house charge rate lagta hai
     * aur jo dish ke cost block me chun li jati hai.
     */
    public function test_an_asset_is_not_quietly_turned_into_a_material(): void
    {
        $this->import();

        $this->assertNull(Product::where('sku', 'OLD-70')->first(),
            'thaal steel ko material bana dena wohi kharabi hai, bas doosri screen par');
    }

    /** Jis par aadmi ne faisla nahi kiya, wo andar nahi aata. */
    public function test_review_and_skip_rows_are_left_for_a_person(): void
    {
        $this->import();

        $this->assertNull(Product::where('sku', 'OLD-80')->first(), 'REVIEW — dish jaisa naam');
        $this->assertNull(Product::where('sku', 'OLD-90')->first(), 'SKIP — pehle se menu item');
    }

    /**
     * Jis unit ka jor na ho, us par andaza nahi lagaya jata.
     *
     * Tenant me lambai ka koi unit hai hi nahi. `MALMAL CLOTH` ko KG ya PCS
     * par daal dena rate ko jhoota kar deta: 60 per yard aur 60 per kilo ek
     * cheez nahi. Chhor dena aur naam le kar batana behtar hai.
     */
    public function test_an_unmappable_unit_is_refused_rather_than_guessed(): void
    {
        $this->import();

        $this->assertNull(Product::where('sku', 'OLD-95')->first(),
            'YARD ka koi jor nahi — andaza lagane se behtar hai chhor dena');
    }

    // ── Chalane ka bartaao ─────────────────────────────────────────────────

    /** --yes ke baghair ek byte nahi. */
    public function test_a_dry_run_writes_nothing(): void
    {
        $before = Product::count();

        $this->artisan('catering:import-purchase-items', [
            'tenant_code' => $this->tenantCodeForTest(),
            'csv' => $this->csv,
        ]);

        $this->assertSame($before, Product::count());
    }

    /** Dobara chalane par kuch nahi badalta. */
    public function test_running_it_twice_creates_nothing_the_second_time(): void
    {
        $this->import();
        $after = Product::count();

        $this->import();

        $this->assertSame($after, Product::count(), 'SKU pehle se mojood hai — dobara nahi banega');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function import(): void
    {
        $this->artisan('catering:import-purchase-items', [
            'tenant_code' => $this->tenantCodeForTest(),
            'csv' => $this->csv,
            '--yes' => true,
        ]);
    }

    /**
     * Command ASAL raaste se chalti hai — `TenancyManager::activate()` samet.
     *
     * Is liye test ka tenant ek asli `tenant_databases` qatar maangta hai, jo
     * harness khud nahi banata (wo tenant connection seedha point kar deta
     * hai). Wo qatar yahan banayi jati hai aur isi test DB par ishara karti
     * hai, taake activate wohi kaam kare jo prod par karega. Is ke baghair
     * test command ke ird-gird se guzar jata — aur ek test jo asal raasta na
     * chale, wo sirf apne aap ko jaanchta hai.
     */
    private function tenantCodeForTest(): string
    {
        $master = DB::connection('master');
        $tenant = $master->table('tenants')->first();

        // updateOrInsert, "agar na ho to banao" nahi: master test DB runs ke
        // darmiyan BAQI rehti hai (woh `migrate` hoti hai, `migrate:fresh`
        // nahi), is liye ek dafa ki ghalat qatar hamesha ke liye chipak jati
        // hai aur har agla run usi par gir-ta rehta hai.
        {
            $c = config('database.connections.tenant');
            // Pehle is DB ki koi purani qatar hatao — crash hui run ki
            // chhori hui qatar warna UNIQUE par rok deti hai.
            $master->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
            $master->table('tenant_databases')->updateOrInsert([
                'tenant_id' => $tenant->id,
            ], [
                'db_connection' => 'mysql',
                'db_host' => $c['host'],
                'db_port' => $c['port'],
                'db_database' => $this->tenantDb,
                'db_username' => $c['username'],
                // Ye khana encrypted rakha jata hai — activate() usay decrypt
                // karta hai, is liye saada text yahan "payload is invalid" deta.
                'db_password' => \Illuminate\Support\Facades\Crypt::encryptString((string) ($c['password'] ?? '')),
                'migration_status' => 'completed',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return (string) $tenant->tenant_code;
    }
}
