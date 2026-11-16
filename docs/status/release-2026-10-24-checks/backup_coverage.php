<?php
// READ-ONLY. Run right after `tenants:backup --no-storage` (no --tenant): BACKUP_DIR=<the folder it printed>.
// Discovers, at this moment, every tenant in master and every pos_tenant_* schema on the server, and checks the backup's
// manifest has an OK file for master and for each one. Prints MISSING lines and refuses (exit-like message) on any gap.
use Illuminate\Support\Facades\DB;

$dir = rtrim((string) getenv('BACKUP_DIR'), '/');
$manifest = json_decode((string) @file_get_contents($dir . '/manifest.json'), true);
if (! $manifest) { echo "NO MANIFEST in {$dir}\n"; return; }
$m = DB::connection('master');
$tenants = $m->table('tenants as t')->leftJoin('tenant_databases as d', 'd.tenant_id', '=', 't.id')->get(['t.tenant_code', 'd.db_database']);
$schemas = collect($m->select("SELECT SCHEMA_NAME s FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'pos\\_tenant\\_%'"))->pluck('s');
$ok = collect($manifest['tenants'] ?? [])->filter(fn ($t) => ($t['status'] ?? '') === 'ok')->pluck('db')->filter()->all();
$gaps = 0;
foreach ($tenants as $t) {
    if (! $t->db_database || ! in_array($t->db_database, $ok, true)) { echo "MISSING tenant {$t->tenant_code} ({$t->db_database})\n"; $gaps++; }
}
foreach ($schemas as $s) {
    if (! in_array($s, $ok, true)) { echo "MISSING schema {$s} (on the server, not in the backup)\n"; $gaps++; }
}
// The manifest names the master DB but records no status for it: the dump file itself must be there and not empty.
$masterOk = ! empty($manifest['master_db']) && is_file($dir . '/master.sql') && filesize($dir . '/master.sql') > 0;
if (! $masterOk) { echo "MISSING master\n"; $gaps++; }
printf("tenants in master=%d, schemas on server=%d, backed up OK=%d — %s\n", $tenants->count(), $schemas->count(), count($ok),
    $gaps ? "{$gaps} GAP(S): DO NOT DEPLOY" : 'COVERAGE OK');
