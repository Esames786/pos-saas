<?php
// Match the owner's downloaded website images to products / categories by file name; write images.md + images.json.
[, $dataDir, $imgDir] = $argv;
$cats = json_decode(file_get_contents("$dataDir/categories.json"), true);
$prods = json_decode(file_get_contents("$dataDir/products.json"), true);
$base = fn (?string $url) => $url ? rawurldecode(preg_replace('/\?.*$/', '', basename((string) $url))) : null;

$files = [];
foreach (scandir($imgDir) as $f) {
    if ($f[0] === '.') {
        continue;
    }
    $s = @getimagesize("$imgDir/$f");
    $files[$f] = ['file' => $f, 'kb' => (int) round(filesize("$imgDir/$f") / 1024), 'size' => $s ? "{$s[0]}x{$s[1]}" : '-', 'used_by' => []];
}

$missing = [];
foreach ($prods as $p) {
    $f = $base($p['photo']);
    if (! $f || str_contains($f, 'placholde')) {
        $missing[] = [$p['name'], $p['category'], 'no photo (placeholder)'];
        continue;
    }
    if (isset($files[$f])) {
        $files[$f]['used_by'][] = 'Product: ' . $p['name'] . ' (' . $p['category'] . ')';
    } else {
        $missing[] = [$p['name'], $p['category'], $f];
    }
}
foreach ($cats as $c) {
    foreach (['image' => 'Category banner', 'icon' => 'Category icon'] as $k => $label) {
        $f = $c[$k] ? basename((string) $c[$k]) : null;
        if ($f && isset($files[$f])) {
            $files[$f]['used_by'][] = $label . ': ' . $c['name'];
        }
    }
}

$used = array_filter($files, fn ($x) => $x['used_by']);
$other = array_filter($files, fn ($x) => ! $x['used_by']);
file_put_contents("$dataDir/images.json", json_encode(['files' => array_values($files), 'products_without_downloaded_photo' => $missing], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$md = "# Purani website ki tasveerein\n\n";
$md .= "Owner ne website se " . count($files) . " files download kar ke di (14 Oct 2026). Repo me nahi rakhi gayin (taqreeban 54 MB);\n";
$md .= "asal files: `C:\\Users\\Dell\\Downloads\\kashiffood.com\\Order Authentic Flavors _ Kashif Foods in Karachi _ Order Online\\`.\n";
$md .= "File ka naam wahi hai jo website ke image link me hai, is liye har tasveer apne product se jor di gayi hai. Machine-readable: `data/images.json`.\n\n";
$md .= "| | Ginti |\n| --- | --- |\n| Product / category se judi tasveerein | " . count($used) . " |\n| Baqi (banner, logo, icons, svg) | " . count($other) . " |\n| Products jin ki tasveer download me nahi | " . count($missing) . " |\n\n";
$md .= "## Product aur category ki tasveerein\n\n| File | Size | KB | Kis ki |\n| --- | --- | --- | --- |\n";
foreach ($used as $x) {
    $md .= "| `{$x['file']}` | {$x['size']} | {$x['kb']} | " . implode('<br>', $x['used_by']) . " |\n";
}
$md .= "\n## Baqi files (banner slides, logo, icons)\n\n| File | Size | KB |\n| --- | --- | --- |\n";
foreach ($other as $x) {
    $md .= "| `{$x['file']}` | {$x['size']} | {$x['kb']} |\n";
}
$md .= "\n## Products jin ki tasveer download me nahi mili\n\n| Product | Category | Website par image |\n| --- | --- | --- |\n";
foreach ($missing as [$n, $c, $f]) {
    $md .= "| $n | $c | $f |\n";
}
file_put_contents("$dataDir/../images.md", $md);
printf("files %d, used %d, other %d, products missing photo %d\n", count($files), count($used), count($other), count($missing));
