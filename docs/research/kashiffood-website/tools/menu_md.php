<?php
// Build menu.md (category → items → sizes/prices → add-ons) from the crawled JSON.
[, $dir] = $argv;
$cats = json_decode(file_get_contents("$dir/categories.json"), true);
$prods = json_decode(file_get_contents("$dir/products.json"), true);
$byId = [];
foreach ($prods as $p) {
    $byId[$p['id']] = $p;
}
$seen = [];
$money = fn ($v) => number_format((float) $v, 0);

$out = "# Kashif Foods — purani website ka menu (tarteeb ke saath)\n\n";
$out .= "Ye file `data/categories.json` aur `data/products.json` se khud bani hai (kashiffood.com ke public pages, "
    . date('d M Y') . "). Tarteeb wahi hai jo website par hai: pehle category ka sequence, phir us ke andar item ka sequence.\n\n";
$out .= "| # | Category | Items |\n| --- | --- | --- |\n";
$n = 0;
foreach ($cats as $c) {
    if ($c['name'] === '') {
        continue;
    }
    $out .= '| ' . ++$n . ' | ' . $c['name'] . ' | ' . count($c['items']) . " |\n";
}
$out .= "\n";

foreach ($cats as $c) {
    if ($c['name'] === '') {
        continue;
    }
    $out .= "## {$c['name']}\n\n| # | Item | Sizes / qeemat (Rs) | Add-ons | Tag |\n| --- | --- | --- | --- | --- |\n";
    foreach ($c['items'] as $i => $it) {
        $full = $byId[$it['id']] ?? $it;
        $seen[$it['id']] = true;
        $sizes = implode('<br>', array_map(fn ($p) => ($p['size'] ? trim($p['size']) . ': ' : '') . $money($p['price']), $full['prices'] ?? []));
        $addons = implode('<br>', array_map(function ($g) use ($money) {
            $opts = implode(', ', array_map(fn ($o) => $o['name'] . ($o['price'] ? ' +' . $money($o['price']) : ''), $g['options']));

            return '**' . ($g['name'] ?: 'Options') . '**' . ($g['required'] ? ' (zaroori)' : '') . ': ' . $opts;
        }, $full['addon_groups'] ?? []));
        $name = $full['name'] . ($full['description'] ? '<br><small>' . str_replace(["\r", "\n", '|'], [' ', ' ', '/'], $full['description']) . '</small>' : '');
        $out .= '| ' . ($i + 1) . ' | ' . $name . ' | ' . ($sizes ?: '-') . ' | ' . ($addons ?: '-') . ' | ' . ($full['tag'] ?: '') . " |\n";
    }
    $out .= "\n";
}

$orphans = array_filter($prods, fn ($p) => ! isset($seen[$p['id']]));
if ($orphans) {
    $out .= "## Sitemap me hain magar upar ki kisi category list me nahi\n\n| Item | Category (product page ke mutabiq) | Sizes / qeemat (Rs) |\n| --- | --- | --- |\n";
    foreach ($orphans as $p) {
        $sizes = implode('<br>', array_map(fn ($x) => ($x['size'] ? trim($x['size']) . ': ' : '') . $money($x['price']), $p['prices']));
        $out .= "| {$p['name']} | " . ($p['category'] ?? '-') . " | $sizes |\n";
    }
}
file_put_contents("$dir/../menu.md", $out);
echo 'menu.md: ' . strlen($out) . " bytes, orphans " . count($orphans) . "\n";
