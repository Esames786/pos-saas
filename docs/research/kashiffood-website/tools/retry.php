<?php
// Retry the pages the first crawl missed; merge them into the same JSON files.
require __DIR__ . '/decode.php';
[, $outDir] = $argv;

function get(string $url): ?string
{
    for ($try = 1; $try <= 3; $try++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => 'Mozilla/5.0']);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        sleep(1);
        if ($body && $code === 200) {
            return $body;
        }
        fwrite(STDERR, "try $try $code $url\n");
    }

    return null;
}

$keepItem = fn (array $it) => [
    'id' => $it['item_id'] ?? null, 'name' => trim((string) ($it['item_name'] ?? '')), 'slug' => $it['item_slug'] ?? null,
    'description' => trim((string) ($it['item_description'] ?? '')), 'photo' => $it['photo'] ?? null,
    'tag' => $it['item_tag_name'] ?? '', 'sequence' => $it['sequence'] ?? null, 'available' => ($it['not_available'] ?? '1') !== '2',
    'size_heading' => $it['size_heading'] ?? '',
    'prices' => array_map(fn ($p) => ['size' => $p['size'] ?? null, 'price' => $p['price'] ?? null], $it['prices'] ?? []),
    'category' => $it['category_info']['category_name'] ?? null, 'category_id' => $it['category_info']['cat_id'] ?? null,
];

// Categories: the two whose sitemap slugs carry a space / no id.
$cats = json_decode(file_get_contents("$outDir/categories.json"), true);
$cats = array_values(array_filter($cats, fn ($c) => $c['name'] !== ''));
foreach (['https://kashiffood.com/category/New%20Arrival%20', 'https://kashiffood.com/category/Midnight-Deals'] as $url) {
    $html = get($url);
    if (! $html) { fwrite(STDERR, "FAIL $url\n"); continue; }
    $p = rsc($html);
    $cd = valueAfter($p, '"catDetails":') ?? [];
    $items = valueAfter($p, '"items":', (int) strpos($p, '"categoryDetails"')) ?? [];
    $list = array_values(array_map($keepItem, is_array($items) ? $items : []));
    usort($list, fn ($a, $b) => (int) $a['sequence'] <=> (int) $b['sequence']);
    $cats[] = ['url' => $url, 'id' => $cd['category_id'] ?? null, 'name' => trim((string) ($cd['category_name'] ?? '')),
        'sequence' => $cd['sequence'] ?? null, 'layout' => $cd['category_layout'] ?? null, 'image' => $cd['photo'] ?? null,
        'icon' => $cd['icon_photo'] ?? null, 'hide_cat_name' => $cd['hide_cat_name'] ?? null,
        'description' => $cd['category_description'] ?? '', 'items' => $list];
    fwrite(STDERR, "cat {$cd['category_name']} seq {$cd['sequence']} items " . count($list) . "\n");
}
usort($cats, fn ($a, $b) => (int) $a['sequence'] <=> (int) $b['sequence']);
file_put_contents("$outDir/categories.json", json_encode($cats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$prods = json_decode(file_get_contents("$outDir/products.json"), true);
foreach (['Family-Deal-02', 'Crispy-Fried-Chicken', 'Cheesy-Blaze', 'Butter'] as $slug) {
    $url = "https://kashiffood.com/product/$slug";
    $html = get($url);
    $it = $html ? valueAfter(rsc($html), '"itemDetails":') : null;
    if (! $it) { fwrite(STDERR, "FAIL $url\n"); continue; }
    $row = $keepItem($it) + ['url' => $url];
    $row['addon_groups'] = array_map(fn ($g) => [
        'name' => trim((string) ($g['subcat_name'] ?? '')), 'required' => (bool) ($g['require_addons'] ?? 0),
        'multi_option' => $g['multi_option'] ?? null, 'multi_option_val' => $g['multi_option_val'] ?? null,
        'options' => array_map(fn ($s) => ['name' => trim((string) ($s['sub_item_name'] ?? '')), 'price' => (float) ($s['price'] ?? 0)], $g['sub_item'] ?? []),
    ], is_array($it['addon_item'] ?? null) ? $it['addon_item'] : []);
    $prods[] = $row;
    fwrite(STDERR, "product {$row['name']}\n");
}
file_put_contents("$outDir/products.json", json_encode($prods, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
fwrite(STDERR, 'now ' . count($cats) . ' categories, ' . count($prods) . " products\n");
