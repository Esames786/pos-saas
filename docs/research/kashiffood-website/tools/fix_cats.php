<?php
// Replace the two empty sitemap "categories" with ones built from the products' own category_info.
[, $dir] = $argv;
$c = json_decode(file_get_contents("$dir/categories.json"), true);
$p = json_decode(file_get_contents("$dir/products.json"), true);
$c = array_values(array_filter($c, fn ($x) => $x['name'] !== '' && ! in_array($x['id'], ['431671', '431689'], true)));

$build = function (string $id, string $name, int $seq, string $note) use ($p) {
    $items = array_values(array_filter($p, fn ($x) => (string) $x['category_id'] === $id));
    // sequence 0 = never set; those go last.
    usort($items, fn ($a, $b) => [(int) $a['sequence'] === 0, (int) $a['sequence']] <=> [(int) $b['sequence'] === 0, (int) $b['sequence']]);
    $items = array_map(fn ($x) => array_diff_key($x, ['url' => 1, 'addon_groups' => 1]), $items);

    return ['url' => null, 'id' => $id, 'name' => $name, 'sequence' => (string) $seq, 'layout' => 'list_menu_thirteen',
        'image' => null, 'icon' => null, 'hide_cat_name' => '1', 'description' => '', 'note' => $note, 'items' => $items];
};
$c[] = $build('431671', 'Platters', 9, 'Sitemap ka link (/category/New Arrival ) khali page deta hai; items product pages se jore. Jagah menu screenshot se: BBQ New Arrival ke baad.');
$c[] = $build('431689', 'Midnight Deals', 27, 'Sitemap ka link khali page deta hai; items product pages se jore. Menu screenshot me nazar nahi aati (shayad sirf raat ke waqt). Asal jagah maloom nahi, aakhir me rakhi.');
usort($c, fn ($a, $b) => (int) $a['sequence'] <=> (int) $b['sequence']);
file_put_contents("$dir/categories.json", json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo count($c) . ' categories, ' . array_sum(array_map(fn ($x) => count($x['items']), $c)) . " items\n";
