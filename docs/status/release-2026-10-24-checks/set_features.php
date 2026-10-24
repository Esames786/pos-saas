<?php
// Turns switches ON for one tenant — never off. T=<code> ON=<key,key,...>. Goes through TenantBusinessService::setFeatures,
// the same path as the Master screen: a switch that needs another is refused unless both are on, and every change is
// recorded in tenant_setting_changes (note: this release's staged rollout). Every switch already on stays on.
use App\Models\Master\Tenant;
use App\Services\Central\TenantBusinessService;
use App\Support\TenantFeatures;
use Illuminate\Support\Facades\DB;

$t = Tenant::where('tenant_code', (string) getenv('T'))->firstOrFail();
$add = array_values(array_filter(array_map('trim', explode(',', (string) getenv('ON')))));
$known = array_keys(TenantFeatures::all());
foreach ($add as $k) { if (! in_array($k, $known, true)) { echo "UNKNOWN switch {$k} — nothing changed\n"; return; } }
$before = $t->features ?: [];
$wanted = [];
foreach ($known as $k) { $wanted[$k] = ! empty($before[$k]) || in_array($k, $add, true); }
$n = app(TenantBusinessService::class)->setFeatures($t, $wanted, null);
DB::connection('master')->table('tenant_setting_changes')->where('tenant_id', $t->id)->whereNull('note')
    ->where('created_at', '>=', now()->subMinute())->where('setting', 'like', 'feature:%')
    ->update(['note' => 'UNIFIED-W3W4 staged rollout (owner-approved 25 Oct)']);
$after = $t->fresh()->features ?: [];
$lost = array_keys(array_filter($before, fn ($v, $k) => $v && empty($after[$k]), ARRAY_FILTER_USE_BOTH));
ksort($after);
printf("%s: %d switch(es) turned on; now %s%s\n", $t->tenant_code, $n,
    json_encode(array_keys(array_filter($after))), $lost ? ' — LOST: ' . implode(',', $lost) : '');
