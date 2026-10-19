<?php
// Final website ↔ POS comparison → compare.md (+ data/compare.json). Reuses compare.php's matcher.
[, $apiFile, $crawlProducts, $posFile, $outDir] = $argv;
require __DIR__ . '/compare_lib.php';

$api = json_decode(file_get_contents($apiFile), true)['details'];
$crawl = json_decode(file_get_contents($crawlProducts), true);
$pos = array_values(array_filter(array_map(fn ($l) => json_decode($l, true), file($posFile)), fn ($x) => is_array($x)));
$posById = [];
foreach ($pos as $p) {
    $posById[$p['kind'] . ':' . $p['id']] = $p + ['tok' => tokens($p['name'])];
}

// Hand-checked matches (14 Oct): name too different for the matcher, same thing by contents + price.
$override = [
    'Chicken biryani Take Away|' => 'product:168',
    'Pocket Friendly Deal 01|' => 'combo:69',
    'Pocket Friendly Deal 02|' => 'combo:71',
    'Meal 01|' => 'combo:73',
    'Meal 02|' => 'combo:74',
    'Family Deal 02|' => 'combo:43',
    'Chicken Chatni Roll|Mayo' => 'product:144',
    'Chicken Chatni Roll|Cheese' => 'product:145',
    // The website's Al-Faham matches the POS's two INACTIVE deals by price; the live POS items are these.
    'Grill Chicken Al-Fahom|Half' => 'product:196',
    'Grill Chicken Al-Fahom|Full' => 'product:207',
];

$lines = [];
$cats = $api;
uasort($cats, fn ($a, $b) => (int) $a['categoryDetail']['sequence'] <=> (int) $b['categoryDetail']['sequence']);
foreach ($cats as $c) {
    $cat = trim($c['title']);
    if ($cat === 'Popular Items') {
        continue;
    }
    foreach ($c['sortedItemKeys'] ?? array_keys($c['items']) as $k) {
        $it = $c['items'][$k];
        foreach ($it['prices'] as $pr) {
            $lines[] = [$cat, trim($it['item_name']), trim((string) ($pr['size'] ?? '')), (float) $pr['price']];
        }
    }
}
$midnight = array_values(array_filter($crawl, fn ($p) => (string) $p['category_id'] === '431689'));
usort($midnight, fn ($a, $b) => (int) $a['sequence'] <=> (int) $b['sequence']);
foreach ($midnight as $m) {
    $desc = trim(preg_replace('/\s+/', ' ', str_replace(['<br>', '<br/>'], ' ', (string) $m['description'])));
    foreach ($m['prices'] as $pr) {
        $lines[] = ['Midnight Deals', trim($m['name']), trim((string) ($pr['size'] ?? '')), (float) $pr['price'], $desc];
    }
}

$rows = [];
$matched = [];
foreach ($lines as $line) {
    [$cat, $name, $size, $price] = $line;
    $key = "$name|$size";
    if (isset($override[$key])) {
        $best = $posById[$override[$key]];
        $score = 1.0;
        $how = 'haath se';
    } elseif ($cat === 'Midnight Deals') {
        // The website names these by number only; the POS names them by what is inside. Match the
        // CONTENTS: Regular = the dish alone, Combo = dish + fries + drink; a named size (Deal 19)
        // is itself the dish. Only the POS's own Midnight deals are candidates.
        $what = match (true) {
            $size === 'Combo' => $line[4] . ' fries drink',
            $size === 'Regular' || $size === '' => $line[4],
            default => $size . ' drink',
        };
        [$best, $score] = bestMatch(tokens($what . ' midnight'), $price, $posById, 'Midnight');
        $how = 'andar ki cheez';
    } else {
        [$best, $score] = bestMatch(tokens($name . ' ' . $size), $price, $posById);
        $how = 'naam';
    }
    $status = match (true) {
        ! $best || $score < 0.45 => 'POS me nahi mila',
        $best['status'] !== 'active' => 'POS me band (inactive)',
        abs((float) $best['price'] - $price) > 0.005 => 'Qeemat alag',
        default => 'Sahi',
    };
    if ($best && $score >= 0.45) {
        $matched[$best['kind'] . ':' . $best['id']] = true;
    }
    $rows[] = compact('cat', 'name', 'size', 'price', 'status', 'score', 'how') + [
        'pos' => $best && $score >= 0.45 ? ['kind' => $best['kind'], 'id' => $best['id'], 'name' => $best['name'], 'price' => (float) $best['price'],
            'category' => trim(($best['parent'] ? $best['parent'] . ' > ' : '') . $best['category'])] : null,
    ];
}

// POS items the website does not sell: active, sellable, POS-visible, not a raw/packaging role.
$posOnly = array_values(array_filter($pos, fn ($p) => ! isset($matched[$p['kind'] . ':' . $p['id']])
    && $p['status'] === 'active' && (int) $p['sell'] === 1 && (int) $p['pos'] === 1
    && ! in_array($p['kindrole'], ['raw_material', 'packaging_material'], true)));
usort($posOnly, fn ($a, $b) => [$a['parent'] . $a['category'], $a['name']] <=> [$b['parent'] . $b['category'], $b['name']]);

file_put_contents("$outDir/data/compare.json", json_encode(['rows' => $rows, 'pos_only' => $posOnly], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$count = array_count_values(array_column($rows, 'status'));
$m = fn ($v) => number_format((float) $v, 0);
$md = "# Website menu vs hamara POS menu (Kashif Food)\n\n";
$md .= "14 Oct 2026. Website: `data/menu-api.json` (owner ki di hui menu API) + crawl ki 21 Midnight Deals. POS: prod `kashiffood`, sirf parh kar.\n";
$md .= "Har website line = ek item ka ek size. Milaan naam se (spelling ke farq theek kar ke); " . count($override) . " lines haath se jori gayin (andar ki cheezein aur qeemat dekh kar).\n\n";
$md .= "| Nateeja | Lines |\n| --- | --- |\n";
foreach (['Sahi', 'Qeemat alag', 'POS me band (inactive)', 'POS me nahi mila'] as $s) {
    $md .= "| $s | " . ($count[$s] ?? 0) . " |\n";
}
$md .= '| **Kul website lines** | **' . count($rows) . "** |\n| POS me hain, website par nahi (active, bikne wale) | " . count($posOnly) . " |\n\n";
$md .= <<<'TXT'
## Samajh (haath se dekh kar)

- **Sizes:** website par ek item ke andar sizes hain (Chicken Biryani: Plain/small/Large/Platter/Family Pack); POS me har size
  **alag product** hai (Chicken Biryani (Sadi), (Small), (Large), (Platter), Family Pack 6 Pcs). Website ka "Plain" = POS ka "Sadi".
  Website "small/Large" = POS "1 Person/2 Person" (Chinese) ya "Small/Large" (Pizza Fries).
- **"With Fries":** POS me har burger/sandwich/crispy ka alag "(With Fries)" product hai (zyada tar Rs 150 mehnga). Website par
  ye alag item nahi; wahan "Add Fries" add-on hai (Pocket Friendly Deal par +150). Neeche "POS me hain, website par nahi" ki
  zyada tar lines yehi hain.
- **Pocket Friendly "+ Fries":** POS deals #70 (900) aur #72 (1,100) = website ke Pocket Friendly Deal 01/02 + "Add Fries +150".
- **Midnight Deal 11** ("Any 1 Pizza Fries With 2 Regular Drinks", 950): POS me do deals hain, #32 Tikka aur #33 Miami Supreme;
  website par customer ek chunta hai.
- **Midnight Deal 17** (Garlic Rice With Shashlik, 1,550): POS ke Midnight deals me nahi. POS me ye sirf aam deal #75 (1,650) hai.
- **Al-Faham:** website ki qeemat (1,600 / 3,100) POS ke do **band** deals (#1, #2) jaisi hai; POS ke chalu products #196/#207
  1,650 / 3,200 par hain.
- **Extra Chicken Piece** (website add-on, +200) = POS product "Extra Chicken Pcs" (#169, 200).

TXT;

foreach (['Qeemat alag', 'POS me band (inactive)', 'POS me nahi mila'] as $s) {
    $sub = array_filter($rows, fn ($r) => $r['status'] === $s);
    if (! $sub) {
        continue;
    }
    $md .= "## $s\n\n| Website category | Website item | Size | Website Rs | POS item | POS Rs |\n| --- | --- | --- | --- | --- | --- |\n";
    foreach ($sub as $r) {
        $md .= "| {$r['cat']} | {$r['name']} | {$r['size']} | " . $m($r['price']) . ' | ' . ($r['pos']['name'] ?? '-') . ($r['pos'] ? " (#{$r['pos']['id']})" : '') . ' | ' . ($r['pos'] ? $m($r['pos']['price']) : '-') . " |\n";
    }
    $md .= "\n";
}

$md .= "## Poori list (website ki tarteeb me)\n\n| Website category | Website item | Size | Website Rs | POS item | POS Rs | Nateeja |\n| --- | --- | --- | --- | --- | --- | --- |\n";
foreach ($rows as $r) {
    $md .= "| {$r['cat']} | {$r['name']} | {$r['size']} | " . $m($r['price']) . ' | ' . ($r['pos'] ? $r['pos']['name'] . ($r['pos']['kind'] === 'combo' ? ' (deal)' : '') : '-') . ' | ' . ($r['pos'] ? $m($r['pos']['price']) : '-') . " | {$r['status']} |\n";
}

$md .= "\n## POS me hain, website par nahi (" . count($posOnly) . ")\n\nActive, bikne wale, POS par dikhne wale; raw/packaging nahi.\n\n| POS category | Item | Qism | Rs |\n| --- | --- | --- | --- |\n";
foreach ($posOnly as $p) {
    $md .= '| ' . trim(($p['parent'] ? $p['parent'] . ' > ' : '') . $p['category']) . " | {$p['name']} | " . ($p['kind'] === 'combo' ? 'deal' : 'product') . ' | ' . $m($p['price']) . " |\n";
}
file_put_contents("$outDir/compare.md", $md);
echo json_encode($count) . ' pos_only ' . count($posOnly) . "\n";
