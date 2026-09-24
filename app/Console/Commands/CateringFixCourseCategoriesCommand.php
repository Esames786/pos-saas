<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * CATERING-COURSE-ORDER-1 (data) — wo chand items jo ghalat course me baithe hain.
 *
 * Kaghaz ab khane ki tarteeb me chhapta hai (CourseOrder), aur tarteeb
 * `categories.sort_order` se aati hai. Us ka seedha natija ye hai ke ek ghalat
 * lagi hui category ab NAZAR AATI hai: Paratha "BBQ" ke neeche chhapta hai aur
 * Aaloo Tarkari bhi. Pehle sab kuch punch order me tha, is liye ye ghalti kabhi
 * saamne nahi aayi.
 *
 * DAAIRA JAAN-BOOJH KAR CHHOTA HAI. Sirf wo item badle ja rahe hain jahan naam
 * khud faisla kar deta hai — roti roti hai. Jo cheezein BAWARCHI ka faisla hain
 * unhe chhua tak nahi:
 *
 *   Salam Bakra   FRIED   — saabit bakra; BBQ ho sakta hai, magar ye tay karna
 *                           mera kaam nahi
 *   Puri Single   FRIED   — talii hui roti; FRIED bhi jaiz hai, NAN-TANDOOR bhi
 *   Steam Mutton  FRIED   — dam pukht hai, tala hua nahi; shayad CURRIES
 *   Beef/Chicken  OTHERS  — ye khane ki line nahi, khaam maal ki line lagti hai
 *
 * Ye chaar command REPORT karti hai aur wahin ruk jati hai. Ek script ka kisi
 * desi dish ka course tay karna wohi ghalti hai jo pehle se theek karni pad
 * rahi hai.
 *
 * MEHFOOZ KYUN (dono baatein prod par naapi gayin, badalne se pehle):
 *   • in paanchon ka POS par EK BHI sale nahi — `sales_order_lines` me 0 rows.
 *     Yani koi report, koi purana hisaab is se nahi hilta.
 *   • is tenant ki printer routing `production_station` par chalti hai,
 *     category par nahi — `catering_printer_mappings` ki saari 8 rows ka
 *     `category_id` NULL hai. Yani KOT kahin aur nahi chala jayega.
 *
 * Default DRY RUN hai. Likhne ke liye --yes dena parta hai, aur command purani
 * halat chhaap kar deti hai taake wapas palti ja sake.
 */
class CateringFixCourseCategoriesCommand extends Command
{
    protected $signature = 'catering:fix-course-categories {tenant_code} {--yes}';

    protected $description = 'Move the handful of catering items that sit in the wrong course category. Dry run unless --yes.';

    /** Ye ek tenant ki maloom ghalatiyan hain, koi aam qaida nahi. */
    private const ALLOWED = ['kashifkitchen'];

    /**
     * product ka naam => [mojooda category, nayi category, wajah]
     *
     * Naam + MOJOODA category dono par milaya jata hai, sirf id par nahi:
     * agar koi ye pehle haath se theek kar chuka hai to command khamoshi se
     * chhod deti hai, aur kisi aur cheez par ghalti se nahi chalti.
     */
    private const MOVES = [
        'Paratha' => ['BBQ', 'NAN-TANDOOR', 'roti hai, BBQ nahi'],
        'Paratha (Pcs)' => ['FRIED', 'NAN-TANDOOR', 'wohi roti, dusri shakl'],
        'Aaloo Tarkari' => ['BBQ', 'SIDE LINES', 'salan hai; Chana Tarkari pehle se SIDE LINES me'],
        'Decoration' => ['AFTARI', 'OTHERS', 'khana hi nahi — service hai'],
        'Singaporian Rice (Family Pack Large)' => ['OTHERS', 'RICE', 'uska bhai "Counter" pehle se RICE me'],
    ];

    /** Jin par faisla bawarchi ka hai — sirf batao, chhedo mat. */
    private const REPORT_ONLY = [
        'Salam Bakra' => 'saabit bakra — BBQ ho sakta hai?',
        'Puri Single' => 'talii hui roti — FRIED ya NAN-TANDOOR?',
        'Steam Mutton' => 'dam pukht hai, tala hua nahi — CURRIES?',
        'Beef' => 'khane ki line nahi lagti — khaam maal?',
        'Chicken' => 'khane ki line nahi lagti — khaam maal?',
    ];

    /**
     * Kya badlega, ye tay karna — likhne se bilkul alag.
     *
     * Alag is liye ke asal khatra yahin hai: kis cheez ko haath lagana hai aur
     * kis ko nahi. Ye hissa bina master tenant, bina artisan, seedha test hota
     * hai; aur ek aisa faisla jo test na ho sake, aksar ghalat hi nikalta hai.
     *
     * @return array{moves: list<array{0:int,1:string,2:string,3:string,4:string}>, skips: list<array{0:string,1:string}>, rollback: array<int,int|null>}
     */
    public static function plan(\Illuminate\Database\Connection $db): array
    {
        $moves = [];
        $skips = [];
        $rollback = [];

        foreach (self::MOVES as $name => [$from, $to, $why]) {
            $product = $db->table('products as p')
                ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                ->where('p.name', $name)
                ->first(['p.id', 'p.category_id', DB::raw('c.name as cat')]);

            if (! $product) {
                $skips[] = [$name, 'product nahi mila'];

                continue;
            }

            if ($product->cat === $to) {
                $skips[] = [$name, "pehle se {$to} me hai"];

                continue;
            }

            if ($product->cat !== $from) {
                // Beech me kisi ne kuch aur kar diya, ya yehi naam kisi aur
                // cheez par lag gaya. Andaza lagane se behtar hai ruk jana —
                // ye command ghalat category theek karne aayi hai, ek aur
                // banane nahi.
                $skips[] = [$name, "tawaqqo {$from} thi, mila '".($product->cat ?? 'NO-CAT')."'"];

                continue;
            }

            if (! $db->table('categories')->where('name', $to)->value('id')) {
                $skips[] = [$name, "category '{$to}' is tenant par nahi hai"];

                continue;
            }

            $rollback[(int) $product->id] = $product->category_id;
            $moves[] = [(int) $product->id, $name, $from, $to, $why];
        }

        return ['moves' => $moves, 'skips' => $skips, 'rollback' => $rollback];
    }

    public function handle(TenancyManager $tenancy): int
    {
        $code = (string) $this->argument('tenant_code');

        if (! in_array($code, self::ALLOWED, true)) {
            $this->error("Refusing: '{$code}' is not in the allow-list (".implode(', ', self::ALLOWED).').');

            return self::FAILURE;
        }

        $tenant = Tenant::where('tenant_code', $code)->first();
        if (! $tenant) {
            $this->error("Tenant '{$code}' not found.");

            return self::FAILURE;
        }

        $tenancy->activate($tenant);
        $db = DB::connection('tenant');
        $apply = (bool) $this->option('yes');

        $this->line($apply ? '<comment>APPLYING</comment>' : '<info>DRY RUN</info> — pass --yes to write.');
        $this->newLine();

        ['moves' => $planned, 'skips' => $skips, 'rollback' => $rollback] = self::plan($db);

        foreach ($skips as [$name, $reason]) {
            $this->warn("  skip  {$name} — {$reason}");
        }

        if (! $planned) {
            $this->newLine();
            $this->info('Kuch badalne ko nahi.');
            $this->reportOnly($db);

            return self::SUCCESS;
        }

        $this->table(['id', 'item', 'abhi', 'nayi', 'wajah'], $planned);

        if (! $apply) {
            $this->newLine();
            $this->info('Kuch likha nahi gaya. --yes de kar chalayein.');
            $this->reportOnly($db);

            return self::SUCCESS;
        }

        foreach ($planned as [$id, , , $to]) {
            $db->table('products')->where('id', $id)->update([
                'category_id' => $db->table('categories')->where('name', $to)->value('id'),
                'updated_at' => now(),
            ]);
        }

        $this->newLine();
        $this->info(count($planned).' item badle gaye.');
        // Purani halat chhaap do — ek line se wapas palta ja sake.
        $this->line('<comment>rollback:</comment> '.json_encode($rollback));

        $this->reportOnly($db);

        return self::SUCCESS;
    }

    /** Jin par malik ka faisla darkar hai. */
    private function reportOnly(\Illuminate\Database\Connection $db): void
    {
        $rows = [];
        foreach (self::REPORT_ONLY as $name => $question) {
            $r = $db->table('products as p')->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                ->where('p.name', $name)->first([DB::raw('c.name as cat')]);
            if ($r) {
                $rows[] = [$name, $r->cat ?? 'NO-CAT', $question];
            }
        }

        if (! $rows) {
            return;
        }

        $this->newLine();
        $this->line('<comment>Malik ka faisla darkar — ye NAHI badle gaye:</comment>');
        $this->table(['item', 'abhi', 'sawal'], $rows);
    }
}
