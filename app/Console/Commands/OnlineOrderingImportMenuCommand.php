<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Models\Tenant\Category;
use App\Models\Tenant\Combo;
use App\Models\Tenant\ModifierGroup;
use App\Models\Tenant\Product;
use App\Services\OnlineOrdering\WebImageService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ONLINE-ORDERING-1 — purani ordering website ka menu MOJOODA catalog ke website-khaanon me bharo.
 *
 * Malik ki do shartein: (1) koi alag menu/product/category nahi; (2) "POS kharab na ho". Is liye ye
 * command SIRF ye likhti hai:
 *   - category / product / deal: show_on_website, website_name, website_card_name, website_size_label,
 *     website_category_id, website_tag, website_popular, website_sort_order, website_description,
 *     website_image_path, website_price (sirf jahan website ki qeemat POS se alag ho)
 *   - add-on groups "Website only" channel ke saath (POS par kabhi nahi pohanchte) + un ke pivots
 * POS ka naam, POS qeemat, POS tasveer, tafseel aur category kabhi nahi chhuti.
 *
 * Milaan: `--map` (research ki compare.json — website line → POS cheez ka NAAM) se, tenant me naam par.
 * Jo na mile wo sirf report hota hai. Default DRY RUN; `--yes` ke baghair kuch nahi likhta.
 */
class OnlineOrderingImportMenuCommand extends Command
{
    protected $signature = 'online-ordering:import-menu
        {tenant_code}
        {menu : Purani website ki menu JSON (details → categories → items)}
        {--map= : compare.json (website line → POS cheez)}
        {--crawl= : Crawl ki products.json (Midnight Deals jo menu JSON me nahi)}
        {--images= : Tasveeron ka folder}
        {--yes : Likho. Is ke baghair sirf dikhata hai}';

    protected $description = 'Purani website ka menu catalog ke website-khaanon me bharta hai (default: dry run).';

    private const MIDNIGHT_CATEGORY_ID = '431689';

    /** @var array<string, true> dry run me "naye" gine gaye group signatures */
    private array $seenGroups = [];

    private array $report = ['categories' => [], 'linked' => 0, 'unlinked' => [], 'prices' => [], 'groups' => 0, 'attached' => 0, 'images' => 0, 'no_image' => [], 'merged' => []];

    public function handle(TenancyManager $tenancy, WebImageService $images): int
    {
        $tenant = Tenant::where('tenant_code', (string) $this->argument('tenant_code'))->first();
        $menu = $this->json((string) $this->argument('menu'))['details'] ?? null;
        $map = $this->json((string) $this->option('map'))['rows'] ?? null;
        if (! $tenant || ! is_array($menu) || ! is_array($map)) {
            $this->error('Tenant, menu JSON aur --map teeno chahiye.');

            return self::FAILURE;
        }
        $tenancy->activate($tenant);
        $write = (bool) $this->option('yes');

        [$categories, $popularIds] = $this->websiteCategories($menu);
        $byLine = [];
        foreach ($map as $r) {
            if (! empty($r['pos'])) {
                $byLine[$this->key($r['cat'], $r['name'], $r['size'] ?? '')] = $r;
            }
        }

        $run = function () use ($categories, $popularIds, $byLine, $images, $tenant, $write) {
            $usedCategory = [];
            foreach ($categories as $seq => $wc) {
                $target = $this->targetCategory($wc, $byLine);
                if (! $target) {
                    $this->report['categories'][] = sprintf('%-22s → (POS category nahi mili, chhoRi)', $wc['title']);
                    // Report sach bataye: is category ki har line "nahi juRi" me gine.
                    foreach ($wc['items'] as $item) {
                        foreach ($item['prices'] as $pr) {
                            $this->report['unlinked'][] = trim("{$wc['title']} / {$item['name']} ".($pr['size'] ?? '').' (category nahi mili)');
                        }
                    }

                    continue;
                }
                if (isset($usedCategory[$target->id])) {
                    $this->report['merged'][] = "{$wc['title']} → {$target->name} (pehle se \"{$usedCategory[$target->id]}\" ki jagah; dono aik category me)";
                } else {
                    $usedCategory[$target->id] = $wc['title'];
                    $target->fill([
                        'show_on_website' => true,
                        'website_name' => strcasecmp($target->name, $wc['title']) === 0 ? null : $wc['title'],
                        'website_sort_order' => $seq * 10,
                    ]);
                    $write && $target->save();
                }
                $this->report['categories'][] = sprintf('%-22s → %s%s', $wc['title'], $target->name,
                    $wc['midnight'] ? '  (waqt category screen par lagayen, masalan 00:00–04:00)' : '');

                foreach ($wc['items'] as $itemSeq => $item) {
                    $members = [];
                    foreach ($item['prices'] as $sizeIdx => $pr) {
                        $line = $byLine[$this->key($wc['title'], $item['name'], (string) ($pr['size'] ?? ''))] ?? null;
                        $model = $line ? $this->findPos($line['pos']['kind'], $line['pos']['name']) : null;
                        if (! $model) {
                            $this->report['unlinked'][] = trim("{$wc['title']} / {$item['name']} ".($pr['size'] ?? ''));

                            continue;
                        }
                        $this->report['linked']++;

                        $webPrice = (float) $pr['price'];
                        $posPrice = (float) ($model instanceof Combo ? $model->price : $model->default_selling_price);
                        $fields = [
                            'show_on_website' => true,
                            'website_card_name' => $item['name'],
                            'website_size_label' => ($pr['size'] ?? '') !== '' ? $pr['size'] : null,
                            'website_category_id' => (int) $model->category_id === (int) $target->id ? null : $target->id,
                            'website_tag' => $this->tag($item['tag']),
                            'website_popular' => in_array($item['id'], $popularIds, true),
                            'website_sort_order' => $seq * 1000 + $itemSeq * 10 + $sizeIdx,
                            'website_description' => $item['description'] ?: null,
                        ];
                        if (abs($webPrice - $posPrice) > 0.004) {
                            $fields['website_price'] = $webPrice;
                            $this->report['prices'][] = sprintf('%s: POS %s → website %s', $model->name, number_format($posPrice), number_format($webPrice));
                        }
                        $model->fill($fields);
                        $write && $model->save();
                        $members[] = $model;
                    }

                    if ($members === []) {
                        continue;
                    }
                    $this->addons($item['addons'], $members, $write);
                    $this->image($item, $members, $images, $tenant->tenant_code, $write);
                }
            }
        };

        $write ? DB::connection('tenant')->transaction($run) : $run();

        $this->output();
        $this->line($write ? '<info>Likh diya.</info>' : '<comment>Ye sirf dikhaya gaya hai. Likhne ke liye --yes lagayein.</comment>');

        return self::SUCCESS;
    }

    /** @return array{0: array<int, array>, 1: list<string>} website categories (tarteeb se) aur Popular Items ke item ids */
    private function websiteCategories(array $menu): array
    {
        uasort($menu, fn ($a, $b) => (int) ($a['categoryDetail']['sequence'] ?? 0) <=> (int) ($b['categoryDetail']['sequence'] ?? 0));

        $out = [];
        $popular = [];
        foreach ($menu as $c) {
            $title = trim((string) $c['title']);
            $keys = $c['sortedItemKeys'] ?? array_keys($c['items'] ?? []);
            if (strcasecmp($title, 'Popular Items') === 0) {
                foreach ($keys as $k) {
                    $popular[] = (string) ($c['items'][$k]['item_id'] ?? '');
                }

                continue;
            }

            $items = [];
            foreach ($keys as $k) {
                $it = $c['items'][$k];
                $items[] = [
                    'id' => (string) $it['item_id'], 'name' => trim((string) $it['item_name']),
                    'description' => trim(strip_tags(str_replace('<br>', ' ', (string) ($it['item_description'] ?? '')))),
                    'tag' => trim((string) ($it['item_tag_name'] ?? '')),
                    'photo' => (string) ($it['photo'] ?? ''),
                    'prices' => array_map(fn ($p) => ['size' => trim((string) ($p['size'] ?? '')), 'price' => (float) $p['price']], $it['prices'] ?? []),
                    'addons' => is_array($it['addon_item'] ?? null) ? $it['addon_item'] : [],
                ];
            }
            $out[(int) ($c['categoryDetail']['sequence'] ?? count($out))] = ['title' => $title, 'items' => $items, 'midnight' => false];
        }

        // Midnight Deals menu JSON me nahi (sirf raat ko dikhti thi) — crawl se.
        $crawl = $this->option('crawl') ? $this->json((string) $this->option('crawl')) : null;
        if (is_array($crawl)) {
            $mid = array_values(array_filter($crawl, fn ($p) => (string) ($p['category_id'] ?? '') === self::MIDNIGHT_CATEGORY_ID));
            usort($mid, fn ($a, $b) => (int) $a['sequence'] <=> (int) $b['sequence']);
            $items = array_map(fn ($p) => [
                'id' => (string) $p['id'], 'name' => trim((string) $p['name']),
                'description' => trim(strip_tags(str_replace(['<br>', '<br/>'], ' ', (string) ($p['description'] ?? '')))),
                'tag' => trim((string) ($p['tag'] ?? '')), 'photo' => (string) ($p['photo'] ?? ''),
                'prices' => array_map(fn ($x) => ['size' => trim((string) ($x['size'] ?? '')), 'price' => (float) $x['price']], $p['prices'] ?? []),
                'addons' => [],
            ], $mid);
            if ($items) {
                $out[max(array_keys($out) ?: [0]) + 1] = ['title' => 'Midnight Deals', 'items' => $items, 'midnight' => true];
            }
        }
        ksort($out);

        return [array_values($out) === [] ? [] : array_combine(range(1, count($out)), array_values($out)), $popular];
    }

    /** Website category ke items jin POS categories me hain unme se aksar wali; sab aik hi parent ke bachche hon to parent. */
    private function targetCategory(array $wc, array $byLine): ?Category
    {
        $names = [];
        foreach ($wc['items'] as $item) {
            foreach ($item['prices'] as $pr) {
                $line = $byLine[$this->key($wc['title'], $item['name'], $pr['size'])] ?? null;
                if ($line && ! empty($line['pos']['category'])) {
                    $names[] = $line['pos']['category'];
                }
            }
        }
        if ($names === []) {
            return null;
        }

        $parents = array_unique(array_map(fn ($n) => str_contains($n, '>') ? trim(explode('>', $n)[0]) : null, $names));
        $leaves = array_count_values(array_map(fn ($n) => trim(str_contains($n, '>') ? explode('>', $n)[1] : $n), $names));
        arsort($leaves);

        $pick = count($leaves) > 1 && count($parents) === 1 && reset($parents) !== null ? reset($parents) : array_key_first($leaves);

        return Category::whereRaw('LOWER(name) = ?', [mb_strtolower($pick)])->orderByDesc('is_active')->first();
    }

    private function findPos(string $kind, string $name): Product|Combo|null
    {
        $q = $kind === 'combo' ? Combo::query() : Product::query();

        return $q->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($name))])->orderByRaw("status = 'active' DESC")->first();
    }

    /** Add-on groups "Website only" — aik jaisa group (naam + options + hadd) dobara nahi banta. */
    private function addons(array $groups, array $members, bool $write): void
    {
        foreach ($groups as $i => $g) {
            $options = array_values(array_filter(array_map(fn ($s) => [
                'name' => trim((string) $s['sub_item_name']), 'price' => (float) ($s['price'] ?? 0),
            ], $g['sub_item'] ?? []), fn ($o) => $o['name'] !== ''));
            if ($options === []) {
                continue;
            }

            $name = trim((string) $g['subcat_name']);
            $min = ! empty($g['require_addons']) ? 1 : 0;
            $max = ($g['multi_option'] ?? 'one') === 'one' ? 1 : count($options);
            $signature = $name.'|'.$min.'|'.$max.'|'.implode(',', array_map(fn ($o) => $o['name'].'='.$o['price'], $options));

            $group = ModifierGroup::where('channel', 'website')->where('name', $name)->get()
                ->first(fn ($cand) => $this->signature($cand) === $signature);
            if (! $group && ! $write && isset($this->seenGroups[$signature])) {
                $this->report['attached'] += count($members);   // dry run: pehle hi "naya" gina ja chuka

                continue;
            }
            if (! $group) {
                $this->seenGroups[$signature] = true;
                $this->report['groups']++;
                if (! $write) {
                    $this->report['attached'] += count($members);

                    continue;
                }
                $group = ModifierGroup::create([
                    'name' => $name, 'min_select' => $min, 'max_select' => $max, 'is_required' => $min > 0,
                    'sort_order' => 0, 'status' => 'active', 'channel' => 'website',
                ]);
                foreach ($options as $j => $o) {
                    $group->modifiers()->create(['name' => $o['name'], 'price_delta' => $o['price'], 'sort_order' => $j, 'status' => 'active']);
                }
            }

            foreach ($members as $m) {
                $this->report['attached']++;
                if ($write) {
                    $m->modifierGroups()->syncWithoutDetaching([$group->id => ['sort_order' => $i]]);
                }
            }
        }
    }

    private function signature(ModifierGroup $g): string
    {
        return $g->name.'|'.$g->min_select.'|'.$g->max_select.'|'.$g->modifiers()->orderBy('sort_order')->get()
            ->map(fn ($m) => $m->name.'='.(float) $m->price_delta)->implode(',');
    }

    private function image(array $item, array $members, WebImageService $images, string $tenantCode, bool $write): void
    {
        if (collect($members)->every(fn ($m) => $m->website_image_path)) {
            return;   // pehle se hai — dobara chalane par nayi file nahi
        }

        $file = $this->findImage($item['photo']);
        if (! $file) {
            $this->report['no_image'][] = $item['name'];

            return;
        }
        $this->report['images']++;
        if (! $write) {
            return;
        }

        $path = $images->store($file, $tenantCode, $item['name']);
        foreach ($members as $m) {
            if (! $m->website_image_path) {
                $m->website_image_path = $path;
                $m->save();
            }
        }
    }

    /** Browser ne file naam badle hote hain ("(3)" → "-3-") — harf/adad par milao. Logo = koi tasveer nahi. */
    private function findImage(string $photoUrl): ?string
    {
        $dir = (string) $this->option('images');
        $name = basename(parse_url($photoUrl, PHP_URL_PATH) ?: '');
        if ($dir === '' || $name === '' || stripos($name, 'logo') !== false) {
            return null;
        }

        static $index = null;
        if ($index === null) {
            $index = [];
            foreach (glob(rtrim($dir, '/\\').'/*') ?: [] as $f) {
                $index[$this->norm(pathinfo($f, PATHINFO_FILENAME))] = $f;
            }
        }

        return $index[$this->norm(pathinfo($name, PATHINFO_FILENAME))] ?? null;
    }

    private function norm(string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(rawurldecode($s)));
    }

    private function tag(string $tag): ?string
    {
        return match (mb_strtolower($tag)) {
            'popular' => 'popular', 'hot selling' => 'hot_selling', 'new' => 'new', default => null,
        };
    }

    private function key(string $cat, string $name, string $size): string
    {
        return mb_strtolower(trim($cat).'|'.trim($name).'|'.trim($size));
    }

    private function json(string $path): ?array
    {
        return $path !== '' && is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    }

    private function output(): void
    {
        $r = $this->report;
        $this->info('Categories:');
        foreach ($r['categories'] as $c) {
            $this->line('  '.$c);
        }
        foreach ($r['merged'] as $m) {
            $this->warn('  Aik POS category me milin: '.$m);
        }
        $this->info("Sizes jore: {$r['linked']} · nahi mile: ".count($r['unlinked']));
        foreach ($r['unlinked'] as $u) {
            $this->warn('  POS me nahi mila: '.$u);
        }
        $this->info('Website price (POS se alag): '.count($r['prices']));
        foreach ($r['prices'] as $p) {
            $this->line('  '.$p);
        }
        $this->info("Add-on groups naye: {$r['groups']} · lagaye: {$r['attached']} (sab \"Website only\" — POS par nahi)");
        $this->info("Tasveerein: {$r['images']} · nahi mili: ".count($r['no_image']));
        foreach ($r['no_image'] as $n) {
            $this->line('  bina tasveer: '.$n);
        }
    }
}
