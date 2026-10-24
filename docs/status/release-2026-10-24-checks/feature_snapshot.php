<?php
// READ-ONLY. Every tenant's switches exactly as stored (tenants.features). `FEAT_SNAP=before` writes
// /tmp/features_before.json; `FEAT_SNAP=after` compares and prints every difference. A release must change none.
use Illuminate\Support\Facades\DB;

$now = DB::connection('master')->table('tenants')->orderBy('tenant_code')->pluck('features', 'tenant_code')
    ->map(fn ($f) => json_decode($f ?: '{}', true) ?: [])->map(function ($f) { ksort($f); return $f; })->all();
if ((getenv('FEAT_SNAP') ?: 'before') === 'before') {
    file_put_contents('/tmp/features_before.json', json_encode($now, JSON_PRETTY_PRINT));
    foreach ($now as $code => $f) { printf("%-20s %s\n", $code, json_encode($f)); }
    echo 'saved ' . count($now) . " tenants to /tmp/features_before.json\n";
    return;
}
$before = json_decode((string) @file_get_contents('/tmp/features_before.json'), true) ?: [];
$diff = 0;
foreach (array_unique(array_merge(array_keys($before), array_keys($now))) as $code) {
    if (($before[$code] ?? null) != ($now[$code] ?? null)) {
        printf("CHANGED %-20s before=%s after=%s\n", $code, json_encode($before[$code] ?? null), json_encode($now[$code] ?? null));
        $diff++;
    }
}
echo $diff ? "SWITCHES CHANGED on {$diff} tenant(s)\n" : 'SWITCHES OK: every tenant exactly as before (' . count($now) . " tenants)\n";
