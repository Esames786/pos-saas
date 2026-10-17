<?php
// READ-ONLY: read the public pages of the client's current website (sitemap → categories + products),
// politely (one request at a time, with a pause), and save the menu structure as JSON.
// Usage: php crawl.php <scratch dir> <out dir>
require __DIR__ . '/decode.php';

[, $scratch, $outDir] = $argv;
@mkdir($outDir, 0777, true);
$sitemap = file_get_contents("$scratch/sitemap.xml");
preg_match_all('#<loc>([^<]+)</loc>#', $sitemap, $m);
$urls = array_map('trim', $m[1]);
$catUrls = array_values(array_filter($urls, fn ($u) => str_contains($u, '/category/')));
$prodUrls = array_values(array_filter($urls, fn ($u) => str_contains($u, '/product/')));

function fetchPage(string $url): ?string
{
    $parts = parse_url($url);
    $path = implode('/', array_map('rawurlencode', explode('/', $parts['path'] ?? '/')));
    $ctx = stream_context_create(['http' => ['header' => "User-Agent: Mozilla/5.0\r\n", 'timeout' => 30, 'ignore_errors' => true]]);
    $body = @file_get_contents($parts['scheme'] . '://' . $parts['host'] . $path, false, $ctx);
    usleep(700000);   // be gentle with the client's live site

    return $body === false ? null : $body;
}

$keepItem = fn (array $it) => [
    'id' => $it['item_id'] ?? null,
    'name' => trim((string) ($it['item_name'] ?? '')),
    'slug' => $it['item_slug'] ?? null,
    'description' => trim((string) ($it['item_description'] ?? '')),
    'photo' => $it['photo'] ?? null,
    'tag' => $it['item_tag_name'] ?? '',
    'sequence' => $it['sequence'] ?? null,
    'available' => ($it['not_available'] ?? '1') !== '2',
    'size_heading' => $it['size_heading'] ?? '',
    'prices' => array_map(fn ($p) => ['size' => $p['size'] ?? null, 'price' => $p['price'] ?? null], $it['prices'] ?? []),
    'category' => $it['category_info']['category_name'] ?? null,
    'category_id' => $it['category_info']['cat_id'] ?? null,
];

$categories = [];
foreach ($catUrls as $i => $url) {
    $html = fetchPage($url);
    if (! $html) { fwrite(STDERR, "FAIL $url\n"); continue; }
    $p = rsc($html);
    $cd = valueAfter($p, '"catDetails":') ?? [];
    $items = valueAfter($p, '"items":', (int) strpos($p, '"categoryDetails"')) ?? [];
    $list = array_values(array_map($keepItem, is_array($items) ? $items : []));
    usort($list, fn ($a, $b) => (int) $a['sequence'] <=> (int) $b['sequence']);
    $categories[] = [
        'url' => $url,
        'id' => $cd['category_id'] ?? $cd['cat_id'] ?? null,
        'name' => trim((string) ($cd['category_name'] ?? '')),
        'sequence' => $cd['sequence'] ?? null,
        'layout' => $cd['category_layout'] ?? null,
        'image' => $cd['photo'] ?? null,
        'icon' => $cd['icon_photo'] ?? null,
        'hide_cat_name' => $cd['hide_cat_name'] ?? null,
        'description' => $cd['category_description'] ?? '',
        'items' => $list,
    ];
    fwrite(STDERR, sprintf("cat %2d/%d %-28s items %d\n", $i + 1, count($catUrls), $cd['category_name'] ?? '?', count($list)));
}
usort($categories, fn ($a, $b) => (int) $a['sequence'] <=> (int) $b['sequence']);
file_put_contents("$outDir/categories.json", json_encode($categories, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$products = [];
foreach ($prodUrls as $i => $url) {
    $html = fetchPage($url);
    if (! $html) { fwrite(STDERR, "FAIL $url\n"); continue; }
    $it = valueAfter(rsc($html), '"itemDetails":');
    if (! $it) { fwrite(STDERR, "NO ITEM $url\n"); continue; }
    $row = $keepItem($it);
    $row['url'] = $url;
    $row['addon_groups'] = array_map(fn ($g) => [
        'name' => trim((string) ($g['subcat_name'] ?? '')),
        'required' => (bool) ($g['require_addons'] ?? 0),
        'multi_option' => $g['multi_option'] ?? null,
        'multi_option_val' => $g['multi_option_val'] ?? null,
        'options' => array_map(fn ($s) => ['name' => trim((string) ($s['sub_item_name'] ?? '')), 'price' => (float) ($s['price'] ?? 0)], $g['sub_item'] ?? []),
    ], is_array($it['addon_item'] ?? null) ? $it['addon_item'] : []);
    $products[] = $row;
    if (($i + 1) % 20 === 0) fwrite(STDERR, "product " . ($i + 1) . "/" . count($prodUrls) . "\n");
}
file_put_contents("$outDir/products.json", json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
fwrite(STDERR, "done: " . count($categories) . " categories, " . count($products) . " products\n");
