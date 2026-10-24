<?php
// READ-ONLY. Before/after fingerprint of every table in every tenant DB, so a release can show that history,
// documents, stock, balances and product settings did not change.
//   RECON=before → /tmp/recon_before.json (per table: columns, highest id, row count and two CRC sums of every row
//                  with id ≤ that highest id; updated_at is left out so a row touched with the same values matches)
//   RECON=after  → the same rows (id ≤ the saved highest id, the saved columns — columns a migration added are not
//                  in it) fingerprinted again and compared. A table that differs is listed with how many of its rows
//                  were updated after the before snapshot (live use in the window); a difference with none is
//                  UNEXPLAINED and stops the release.
// product_pack_units.factor is read as DECIMAL(15,4) both times, so the whole-number → decimal change compares equal.
use App\Models\Master\Tenant;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Support\Facades\DB;

$mode = getenv('RECON') ?: 'before';
$file = getenv('RECON_FILE') ?: '/tmp/recon_before.json';
$skip = ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'migrations'];
$before = $mode === 'after' ? (json_decode((string) @file_get_contents($file), true) ?: []) : [];
if ($mode === 'after' && ! $before) { echo "NO BEFORE SNAPSHOT at {$file}\n"; return; }

$fingerprint = function ($c, string $table, array $cols, ?int $cutoff): array {
    $parts = array_map(function ($col) use ($table) {
        $v = ($table === 'product_pack_units' && $col === 'factor') ? 'CAST(`factor` AS DECIMAL(15,4))' : "`{$col}`";
        return "COALESCE(CAST({$v} AS CHAR), '~')";
    }, $cols);
    $row = "CRC32(CONCAT_WS('|', " . implode(', ', $parts) . '))';
    $sql = "SELECT COUNT(*) n, COALESCE(BIT_XOR({$row}), 0) x, COALESCE(SUM({$row}), 0) s FROM `{$table}`"
        . ($cutoff !== null ? " WHERE `id` <= {$cutoff}" : '');
    $r = $c->selectOne($sql);
    return [(int) $r->n, (string) $r->x, (string) $r->s];
};

$snap = ['taken_at' => now()->toDateTimeString(), 'tenants' => []];
$totalChanged = 0; $unexplained = 0;
foreach (Tenant::orderBy('tenant_code')->get() as $t) {
    $tm = app(TenancyManager::class);
    try {
        $tm->activate($t);
        $c = DB::connection('tenant');
        $db = $c->getDatabaseName();
        $tables = collect($c->select("SELECT TABLE_NAME t FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME", [$db]))->pluck('t');
        $mine = [];
        $changed = [];
        foreach ($tables as $table) {
            if (in_array($table, $skip, true)) { continue; }
            if ($mode === 'before') {
                $colRows = $c->select('SELECT COLUMN_NAME c, DATA_TYPE d FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$db, $table]);
                $cols = array_values(array_filter(array_map(fn ($r) => $r->c, $colRows), fn ($n) => $n !== 'updated_at'));
                $idType = collect($colRows)->firstWhere('c', 'id')?->d;
                $cutoff = in_array($idType, ['int', 'bigint', 'mediumint', 'smallint', 'tinyint'], true)
                    ? (int) $c->table($table)->max('id') : null;
                [$n, $x, $s] = $fingerprint($c, $table, $cols, $cutoff);
                $mine[$table] = ['cols' => $cols, 'cutoff' => $cutoff, 'n' => $n, 'x' => $x, 's' => $s,
                    'upd' => in_array('updated_at', array_map(fn ($r) => $r->c, $colRows), true)];
            } else {
                $b = $before['tenants'][$t->tenant_code]['tables'][$table] ?? null;
                if (! $b) { continue; } // a table this release created
                [$n, $x, $s] = $fingerprint($c, $table, $b['cols'], $b['cutoff']);
                if ([$n, $x, $s] !== [$b['n'], $b['x'], $b['s']]) {
                    $touched = ($b['upd'] && $b['cutoff'] !== null)
                        ? $c->table($table)->where('id', '<=', $b['cutoff'])->where('updated_at', '>=', $before['taken_at'])->count() : 0;
                    $changed[] = sprintf('%s rows %d→%d%s', $table, $b['n'], $n, $touched ? " (updated in the window: {$touched})" : ' UNEXPLAINED');
                    $totalChanged++;
                    if (! $touched) { $unexplained++; }
                }
            }
        }
        // Key figures, all on rows that existed at the before snapshot.
        $cut = fn ($tb) => $mode === 'before' ? ($mine[$tb]['cutoff'] ?? null) : ($before['tenants'][$t->tenant_code]['tables'][$tb]['cutoff'] ?? null);
        $jl = $cut('journal_lines');
        $gl = $c->table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->where('e.status', 'posted')
            ->when($jl !== null, fn ($q) => $q->where('l.id', '<=', $jl))->selectRaw('ROUND(COALESCE(SUM(l.debit),0),2) dr, ROUND(COALESCE(SUM(l.credit),0),2) cr')->first();
        $stock = (string) $c->table('stock_balances')->selectRaw('ROUND(COALESCE(SUM(quantity_on_hand),0),3) q')->value('q');
        $supp = $c->getSchemaBuilder()->hasColumn('suppliers', 'current_balance') ? (string) $c->table('suppliers')->selectRaw('ROUND(COALESCE(SUM(current_balance),0),2) b')->value('b') : '-';
        $figures = "gl_dr={$gl->dr} gl_cr={$gl->cr} stock_qty={$stock} supplier_bal={$supp}";
        if ($mode === 'before') {
            $snap['tenants'][$t->tenant_code] = ['tables' => $mine, 'figures' => $figures];
            printf("%-20s tables=%d %s\n", $t->tenant_code, count($mine), $figures);
        } else {
            $was = $before['tenants'][$t->tenant_code]['figures'] ?? '-';
            printf("%-20s %s%s\n", $t->tenant_code, $changed ? count($changed) . ' table(s) differ' : 'every table identical',
                $figures === $was ? "; {$figures} (same)" : "; FIGURES before: {$was} | after: {$figures}");
            foreach ($changed as $line) { echo "    {$line}\n"; }
        }
    } catch (\Throwable $e) {
        printf("%-20s ERROR %s\n", $t->tenant_code, substr($e->getMessage(), 0, 160));
        $unexplained++;
    } finally {
        $tm->deactivate();
        DB::setDefaultConnection('master');
    }
}
if ($mode === 'before') {
    file_put_contents($file, json_encode($snap));
    echo 'saved ' . count($snap['tenants']) . " tenants to {$file} at {$snap['taken_at']}\n";
} else {
    echo $unexplained ? "RECONCILIATION PROBLEM: {$unexplained} unexplained difference(s)\n"
        : "RECONCILIATION OK: no unexplained difference ({$totalChanged} table(s) changed only by use in the window)\n";
}
