<?php
// READ-ONLY query helper for one tenant: T=<code> SQLB64=<base64 SELECT>. Prints rows tab-separated. Refuses anything but SELECT.
use App\Models\Master\Tenant; use App\Services\Tenancy\TenancyManager; use Illuminate\Support\Facades\DB;
$sql = trim((string) base64_decode((string) getenv('SQLB64')));
if (! preg_match('/^select\s/i', $sql) || preg_match('/;\s*\S/', $sql)) { echo "REFUSED: SELECT only\n"; return; }
app(TenancyManager::class)->activate(Tenant::where('tenant_code', getenv('T'))->firstOrFail());
foreach (DB::connection('tenant')->select($sql) as $r) { echo implode("\t", array_map(fn ($v) => $v ?? 'NULL', (array) $r)) . "\n"; }
