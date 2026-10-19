<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Models\Tenant\Category;
use App\Models\Tenant\Product;
use App\Models\Tenant\Unit;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PURCHASE-ITEMS-IMPORT-1 — purane software ke khareed wale items.
 *
 * Kashif Kitchen ka purana accounting system items ko do alag duniyaon me
 * rakhta tha: GroupId 1 = khareed, GroupId 2 = bikri. Naye system me bikri
 * wali 911 cheezein aa gayin aur khareed wali 1,398 me se SIRF CHHE. Is liye
 * sabzi, masala, packing aur bar-dana kahin darj hi nahi hai, aur costing
 * chaar materials par khari hai.
 *
 * YE COMMAND XLSX NAHI PARHTI. Wo jaan-boojh kar hai: us dump me khareed ki
 * fehrist me pakke hue dish bhi pare hain (CHAPLI KABAB, RABRI) aur adhoore
 * record bhi (na unit na rate). Kaun si cheez kya hai — ye faisla aadmi ka
 * hai, machine ka nahi. Is liye command ek JAANCHI HUI CSV parhti hai, aur
 * sirf wo qatarein uthati hai jin par kism likhi ja chuki ho.
 *
 * JHANDE SAB SE AHEM HISSA HAIN. Mojooda bulk import har item par
 * `is_sellable = true` likhta hai aur `is_pos_visible` ka database default
 * bhi true hai — us raaste se import karne par Tomato aur Thaal Steel POS ki
 * tiles par aur catering ke punch bar me nazar aane lagte, chalti hui event
 * ke dauran. Yahan ulta likha jata hai: bikri band, POS band, khareed khuli.
 *
 * Default DRY RUN. `--yes` ke baghair ek byte nahi likhti.
 */
class ImportPurchaseItemsCommand extends Command
{
    protected $signature = 'catering:import-purchase-items
        {tenant_code : tenant ka code}
        {csv : jaanchi hui CSV ka raasta}
        {--yes : waqai likho}
        {--prefix=OLD- : naye SKU ka prefix — wapas lene ke liye yahi nishan hai}';

    protected $description = 'Import reviewed purchase items from the old software. Dry run unless --yes.';

    /**
     * Purane software ke 43 unit — naye system ke 10 par.
     *
     * Jo yahan nahi hai wo QASDAN nahi hai: MTR/YARD/FT ke liye tenant me koi
     * lambai ka unit hai hi nahi, aur "0"/"1"/"12" unit hain hi nahi. Un ko
     * kisi aur unit par daal dena ek ghalat adad likhna hai — rate per KG aur
     * rate per meter ek cheez nahi. Wo qatarein chhor di jati hain aur naam le
     * kar batayi jati hain.
     */
    private const UNIT_MAP = [
        'KGS' => 'KG', 'KG' => 'KG', 'KGS.' => 'KG',
        'NOS' => 'NOS', 'NO' => 'NOS', 'NOS.' => 'NOS', 'QTY' => 'NOS', 'UNIT' => 'NOS',
        'PCS' => 'PCS', 'PEC' => 'PCS',
        'LTR' => 'LTR', 'LITER' => 'LTR', 'LITERS' => 'LTR', 'ML' => 'LTR',
        'PK' => 'PKT', 'PKT' => 'PKT', 'PACK' => 'PKT',
        'BAG' => 'BAG',
        'CUP' => 'CUP', 'CUPS' => 'CUP',
        'PLATE' => 'PLATE', 'PALTE' => 'PLATE',
        'P/H' => 'PH',
        'DZN' => 'NOS', 'DOZEN' => 'NOS',
        'BOTT' => 'PCS', 'BOTTLE' => 'PCS', 'BT' => 'PCS',
        'TIN' => 'PCS', 'BOX' => 'PCS', 'CTN' => 'PCS', 'CANE' => 'PCS',
        'BUCKET' => 'PCS', 'ROLL' => 'PCS', 'COIL' => 'PCS',
    ];

    /**
     * Jin kism par amal hota hai.
     *
     * SKIP aur REVIEW qasdan bahar hain — un par faisla aadmi karta hai.
     *
     * ASSET BHI BAHAR HAI, AUR YE SAB SE AHEM FAISLA HAI. System me `asset`
     * naam ki koi kism hai hi nahi, aur jo teen kism mojood hain (raw,
     * packaging, semi-finished) wohi teeno catering ke MATERIAL picker me
     * aati hain. Chair, fan, thaal ya LED ko un me se kisi par daal dena unhe
     * "material" bana deta — aisi cheez jis par house charge rate lagta hai
     * aur jo dish ke cost block me chun li jati hai. Wo wohi kharabi hoti jis
     * se bachne ke liye ye poora kaam ho raha hai, bas doosri screen par.
     *
     * Is liye 134 asset abhi nahi aa rahe. Un ke liye ya to nayi kism banegi
     * ya alag intezam — aur wo apna faisla maangta hai.
     */
    private const KINDS = [
        'raw_material' => Product::KIND_RAW_MATERIAL,
        'packaging_material' => Product::KIND_PACKAGING_MATERIAL,
    ];

    public function handle(TenancyManager $tenancy): int
    {
        $tenant = Tenant::where('tenant_code', (string) $this->argument('tenant_code'))->first();
        if (! $tenant) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('csv');
        if (! is_readable($path)) {
            $this->error("CSV nahi mili: {$path}");

            return self::FAILURE;
        }

        $tenancy->activate($tenant);
        $apply = (bool) $this->option('yes');
        $prefix = (string) $this->option('prefix');

        $this->line($apply ? '<comment>LIKH RAHA HOON</comment>' : '<info>DRY RUN</info> — likhne ke liye --yes dein.');
        $this->newLine();

        $units = Unit::where('is_active', true)->get()->keyBy(fn ($u) => mb_strtoupper($u->code));
        $category = $this->purchaseCategory($apply);

        $plan = [];
        $skipped = [];

        $fh = fopen($path, 'r');
        $header = fgetcsv($fh);
        $idx = array_flip(array_map('trim', $header ?: []));

        while (($row = fgetcsv($fh)) !== false) {
            $name = trim((string) ($row[$idx['Item']] ?? ''));
            $kind = trim((string) ($row[$idx['TajweezKardaKism']] ?? ''));
            $code = trim((string) ($row[$idx['OldCode']] ?? ''));
            $unitRaw = mb_strtoupper(trim((string) ($row[$idx['OldUnit']] ?? '')));
            $rate = (float) ($row[$idx['OldRate']] ?? 0);

            if ($name === '' || ! isset(self::KINDS[$kind])) {
                continue; // SKIP aur REVIEW yahin ruk jate hain
            }

            $unitCode = self::UNIT_MAP[$unitRaw] ?? null;
            if ($unitCode === null || ! $units->has($unitCode)) {
                $skipped[] = [$name, $unitRaw ?: '(khali)', 'is unit ka koi jor nahi'];

                continue;
            }

            $sku = $prefix.($code !== '' ? $code : Str::slug($name));
            if (Product::where('sku', $sku)->exists()) {
                $skipped[] = [$name, $sku, 'pehle se mojood — dobara nahi banega'];

                continue;
            }

            $plan[] = [
                'sku' => $sku,
                'name' => $name,
                'kind' => self::KINDS[$kind],
                'unit_id' => $units[$unitCode]->id,
                'unit_code' => $unitCode,
                'rate' => $rate,
            ];
        }
        fclose($fh);

        $byKind = [];
        foreach ($plan as $p) { $byKind[$p['kind']] = ($byKind[$p['kind']] ?? 0) + 1; }

        $this->line('Banne wale products: <info>'.count($plan).'</info>');
        foreach ($byKind as $k => $n) { $this->line("   {$k}: {$n}"); }
        $this->line('Chhore gaye: <comment>'.count($skipped).'</comment>');
        $this->newLine();

        if ($skipped !== []) {
            $this->line('<comment>Ye nahi banenge:</comment>');
            $this->table(['item', 'tafseel', 'wajah'], array_slice($skipped, 0, 15));
            if (count($skipped) > 15) { $this->line('   …aur '.(count($skipped) - 15)); }
            $this->newLine();
        }

        if (! $apply) {
            $this->table(['sku', 'naam', 'kism', 'unit', 'rate'], array_map(
                fn ($p) => [$p['sku'], mb_substr($p['name'], 0, 30), $p['kind'], $p['unit_code'], number_format($p['rate'], 2)],
                array_slice($plan, 0, 10)
            ));
            $this->info('Kuch likha nahi gaya. --yes de kar chalayein.');

            return self::SUCCESS;
        }

        $made = 0;
        DB::connection('tenant')->transaction(function () use ($plan, $category, &$made) {
            foreach ($plan as $p) {
                Product::create([
                    'category_id' => $category?->id,
                    'unit_id' => $p['unit_id'],
                    'sku' => $p['sku'],
                    'name' => $p['name'],
                    'slug' => Str::slug($p['name']).'-'.mb_strtolower($p['sku']),
                    'product_type' => 'simple',
                    'product_kind' => $p['kind'],
                    // YEHI WO CHAAR SATREIN HAIN JIN PAR SAB KUCH TIKA HAI.
                    'is_sellable' => false,
                    'is_pos_visible' => false,
                    'is_purchasable' => true,
                    'is_stock_tracked' => true,
                    'default_purchase_price' => $p['rate'],
                    'default_selling_price' => 0,
                    'status' => 'active',
                ]);
                $made++;
            }
        });

        $this->newLine();
        $this->info("{$made} products ban gaye.");
        $this->line("Wapas lene ke liye: SKU jo '{$prefix}' se shuru hote hain.");

        return self::SUCCESS;
    }

    private function purchaseCategory(bool $apply): ?Category
    {
        $existing = Category::where('name', 'Purchase Items')->first();
        if ($existing || ! $apply) {
            return $existing;
        }

        return Category::create([
            'name' => 'Purchase Items',
            'slug' => 'purchase-items',
            'is_active' => true,
        ]);
    }
}
