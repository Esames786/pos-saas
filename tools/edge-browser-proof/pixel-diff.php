<?php
/**
 * PHASE 2 — pixel comparison of two screenshot folders (same file names, same viewport), GD only, no Internet, no npm.
 *   php pixel-diff.php <dirA> <dirB> <outDir> [--threshold=0.5] [--mask=x,y,w,h ...] [--tolerance=24]
 * For every PNG present in both dirs: counts pixels whose max channel difference exceeds --tolerance (0–255), ignoring
 * masked rectangles (documented, approved differences such as the shared status slot text), writes <outDir>/<name>.diff.png
 * (differences in red over a dimmed A) and <outDir>/report.json. Exit 1 when any file exceeds --threshold percent.
 */
$args = array_slice($argv, 1);
$dirs = array_values(array_filter($args, fn ($a) => ! str_starts_with($a, '--')));
[$dirA, $dirB, $out] = [$dirs[0] ?? null, $dirs[1] ?? null, $dirs[2] ?? null];
if (! $dirA || ! $dirB || ! $out) { fwrite(STDERR, "usage: php pixel-diff.php <dirA> <dirB> <outDir> [--threshold=0.5] [--mask=x,y,w,h] [--tolerance=24]\n"); exit(2); }
$threshold = 0.5; $tolerance = 24; $masks = [];
foreach ($args as $a) {
    if (str_starts_with($a, '--threshold=')) $threshold = (float) substr($a, 12);
    if (str_starts_with($a, '--tolerance=')) $tolerance = (int) substr($a, 12);
    if (str_starts_with($a, '--mask=')) { $m = array_map('intval', explode(',', substr($a, 7))); if (count($m) === 4) $masks[] = $m; }
}
@mkdir($out, 0775, true);
$report = ['dirA' => $dirA, 'dirB' => $dirB, 'threshold_percent' => $threshold, 'tolerance' => $tolerance, 'masks' => $masks, 'files' => [], 'missing' => []];
$fail = false;
foreach (glob(rtrim($dirA, '/\\') . '/*.png') as $fa) {
    $name = basename($fa); $fb = rtrim($dirB, '/\\') . '/' . $name;
    if (! is_file($fb)) { $report['missing'][] = $name; continue; }
    $a = imagecreatefrompng($fa); $b = imagecreatefrompng($fb);
    $w = min(imagesx($a), imagesx($b)); $h = min(imagesy($a), imagesy($b));
    $sizeSame = imagesx($a) === imagesx($b) && imagesy($a) === imagesy($b);
    $diff = imagecreatetruecolor($w, $h); $red = imagecolorallocate($diff, 255, 0, 0);
    $changed = 0; $compared = 0;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $masked = false;
            foreach ($masks as [$mx, $my, $mw, $mh]) { if ($x >= $mx && $x < $mx + $mw && $y >= $my && $y < $my + $mh) { $masked = true; break; } }
            $pa = imagecolorat($a, $x, $y); $pb = imagecolorat($b, $x, $y);
            $ra = ($pa >> 16) & 0xFF; $ga = ($pa >> 8) & 0xFF; $ba = $pa & 0xFF;
            $rb = ($pb >> 16) & 0xFF; $gb = ($pb >> 8) & 0xFF; $bb = $pb & 0xFF;
            $dim = imagecolorallocate($diff, (int) (128 + $ra / 2), (int) (128 + $ga / 2), (int) (128 + $ba / 2));
            if ($masked) { imagesetpixel($diff, $x, $y, imagecolorallocate($diff, 200, 200, 255)); continue; }
            $compared++;
            if (max(abs($ra - $rb), abs($ga - $gb), abs($ba - $bb)) > $tolerance) { $changed++; imagesetpixel($diff, $x, $y, $red); } else { imagesetpixel($diff, $x, $y, $dim); }
        }
    }
    $pct = $compared ? round($changed * 100 / $compared, 3) : 0.0;
    imagepng($diff, rtrim($out, '/\\') . '/' . preg_replace('/\.png$/', '.diff.png', $name));
    $ok = $sizeSame && $pct <= $threshold;
    if (! $ok) $fail = true;
    $report['files'][$name] = ['size_same' => $sizeSame, 'a' => imagesx($a) . 'x' . imagesy($a), 'b' => imagesx($b) . 'x' . imagesy($b), 'changed_pixels' => $changed, 'compared' => $compared, 'percent' => $pct, 'ok' => $ok];
    printf("%-28s %s  %6.3f%%  %s\n", $name, $sizeSame ? 'size=same' : 'size=DIFF', $pct, $ok ? 'OK' : 'FAIL');
    imagedestroy($a); imagedestroy($b); imagedestroy($diff);
}
file_put_contents(rtrim($out, '/\\') . '/report.json', json_encode($report, JSON_PRETTY_PRINT));
if ($report['missing']) echo 'missing in B: ' . implode(', ', $report['missing']) . "\n";
exit($fail ? 1 : 0);
