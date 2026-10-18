<?php
/**
 * SYSTEM-MAP-1 — a bird's-eye map of Bingoo, built from the code and the schema, never by hand.
 *
 * What it answers: "if I change table X / screen Y, what else moves?"
 *   - every table, its columns, its foreign keys (enforced) and its *_id links without a key (inferred);
 *   - every named route (screen / action), its controller, and the tables that controller reaches —
 *     through the models it uses, the services it calls (one level deep) and raw DB::table('…') calls.
 *
 * Read-only. Reads the schema of a MIGRATED database (a test DB is ideal: same migrations as prod)
 * and the source tree. Writes one JSON file.
 *
 *   php tools/system-map/build.php --tenant-db=pos_test_tenant_xxx --master-db=pos_test_master_xxx \
 *       [--host=127.0.0.1 --port=3306 --user=root --pass=] [--out=docs/system-map/system-map.json]
 */

$opt = getopt('', ['tenant-db:', 'master-db:', 'host::', 'port::', 'user::', 'pass::', 'out::']);
foreach (['tenant-db', 'master-db'] as $k) {
    if (empty($opt[$k])) {
        fwrite(STDERR, "missing --{$k}\n");
        exit(1);
    }
}
$root = realpath(__DIR__ . '/../..');
$out  = $opt['out'] ?? $root . '/docs/system-map/system-map.json';

require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Str;

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $opt['host'] ?? '127.0.0.1', $opt['port'] ?? '3306'),
    $opt['user'] ?? 'root', $opt['pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// ── 1. Schema ───────────────────────────────────────────────────────────────────────────────────
function schemaOf(PDO $pdo, string $db, string $side): array
{
    $tables = [];
    $q = $pdo->prepare('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION');
    $q->execute([$db]);
    foreach ($q->fetchAll(PDO::FETCH_NUM) as [$t, $c, $type]) {
        $tables[$t] ??= ['name' => $t, 'side' => $side, 'columns' => [], 'fk_out' => [], 'inferred_out' => []];
        $tables[$t]['columns'][] = $c . ' ' . $type;
    }
    unset($tables['migrations']);
    $q = $pdo->prepare('SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, r.DELETE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k
        JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
        WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL');
    $q->execute([$db]);
    foreach ($q->fetchAll(PDO::FETCH_NUM) as [$t, $c, $ref, $rule]) {
        if (isset($tables[$t])) {
            $tables[$t]['fk_out'][] = ['col' => $c, 'table' => $ref, 'on_delete' => $rule];
        }
    }

    return $tables;
}
$tables = schemaOf($pdo, $opt['tenant-db'], 'tenant');
foreach (schemaOf($pdo, $opt['master-db'], 'master') as $name => $t) {
    $tables[isset($tables[$name]) ? 'master.' . $name : $name] = $t;   // a name on both sides keeps the tenant one bare
}

// *_id columns with no foreign key: guess the table from the column name (dropping leading words:
// linked_product_id → products, posted_by_user_id → users). Polymorphic pairs are marked as such.
foreach ($tables as $name => &$t) {
    $fkCols = array_column($t['fk_out'], 'col');
    $colNames = array_map(fn ($c) => explode(' ', $c)[0], $t['columns']);
    foreach ($colNames as $col) {
        if (! str_ends_with($col, '_id') || in_array($col, $fkCols, true)) {
            continue;
        }
        $base = substr($col, 0, -3);
        if (in_array(Str::before($base, '_') . '_type', $colNames, true) || in_array($base . '_type', $colNames, true)) {
            $t['inferred_out'][] = ['col' => $col, 'table' => '(polymorphic)'];
            continue;
        }
        $type = '';
        foreach ($t['columns'] as $c) if (str_starts_with($c, $col . ' ')) $type = $c;
        if (str_contains($type, 'varchar')) {
            $t['inferred_out'][] = ['col' => $col, 'table' => '(external id)'];
            continue;
        }
        if (preg_match('/^(reversed_of|reversal_of|parent)(_|$)/', $base)) {
            $t['inferred_out'][] = ['col' => $col, 'table' => $name];
            continue;
        }
        $words = explode('_', $base);
        $tableWords = explode('_', str_replace('master.', '', $name));
        $hit = null;
        $cross = false;
        for ($i = 0; $i < count($words) && ! $hit; $i++) {
            $cand = Str::plural(implode('_', array_slice($words, $i)));
            // the table's own prefixes first (print_jobs.claimed_by_agent_id → print_agents), then the bare name
            $tries = [];
            for ($p = count($tableWords) - 1; $p >= 1; $p--) $tries[] = implode('_', array_slice($tableWords, 0, $p)) . '_' . $cand;
            $tries[] = $cand;
            foreach ($tries as $try) {
                foreach ([$t['side'] === 'master' ? 'master.' . $try : $try, $try, 'master.' . $try] as $key) {
                    if (isset($tables[$key])) {
                        $hit = $key;
                        $cross = $tables[$key]['side'] !== $t['side'];
                        break 2;
                    }
                }
            }
        }
        $t['inferred_out'][] = ['col' => $col, 'table' => $hit ?? '(unknown)'] + ($cross ? ['cross_db' => true] : []);
    }
}
unset($t);

// ── 2. Domains ──────────────────────────────────────────────────────────────────────────────────
function domainOf(string $t, string $side): string
{
    if ($side === 'master') return 'Bingoo Master';
    $rules = [
        'System'          => '/^(cache|cache_locks|jobs|job_batches|failed_jobs)$/',
        'Reports'         => '/^(report_|share_|pos_quick_report)/',
        'Catering'        => '/^catering_/',
        'Manufacturing'   => '/^(bom|boms|production_|material_requisition|wip_|manufactur|finished_good)/',
        'Finance'         => '/^(accounts|account_|journal_|cash_bank|fiscal|bank_|expense|customer_payment|tax_|opening_balance)/',
        'Purchasing'      => '/^(purchase_|purchasing_|goods_receipt|supplier)/',
        'Inventory'       => '/^(stock_|inventory_|department|wastage|kitchen_wastage)/',
        'Kitchen & Recipes' => '/^(recipe|kitchen_|kds_)/',
        'Restaurant'      => '/^restaurant_/',
        'Sales & POS'     => '/^(sales_|sale_|shift|terminal|held_|daily_closing|manager_approval|void_reason|kot_|order_|delivery_|split_|customers?$|customer_|cash_count|payment_methods)/',
        'Catalog'         => '/^(products?$|product_|categor|units?$|unit_|modifier|combo|promotion|service_charge|price_)/',
        'Printing'        => '/^(print|receipt_layout|terminal_printer|category_printer)/',
        'Website'         => '/^(web_|online_|website)/',
        'Edge (offline)'  => '/^edge_/',
        'Users & Access'  => '/^(users?$|roles?$|permissions?$|model_has|role_has|user_|password_|personal_access|sessions?$|manager_pins)/',
        'Branches & Settings' => '/^(branches|branch_|currenc|settings|setting_|languages|tenant_)/',
    ];
    foreach ($rules as $d => $re) {
        if (preg_match($re, $t)) return $d;
    }

    return 'Other';
}
foreach ($tables as $name => &$t) {
    $t['domain'] = domainOf(str_replace('master.', '', $name), $t['side']);
    $t['fk_in'] = [];
    $t['inferred_in'] = [];
    $t['models'] = [];
    $t['used_by'] = [];
}
unset($t);
foreach ($tables as $name => $t) {
    foreach ($t['fk_out'] as $fk) {
        if (isset($tables[$fk['table']])) $tables[$fk['table']]['fk_in'][] = ['table' => $name, 'col' => $fk['col'], 'on_delete' => $fk['on_delete']];
    }
    foreach ($t['inferred_out'] as $l) {
        if (isset($tables[$l['table']])) $tables[$l['table']]['inferred_in'][] = ['table' => $name, 'col' => $l['col']];
    }
}

// ── 3. Models → tables ──────────────────────────────────────────────────────────────────────────
$modelTable = [];
foreach (['Tenant', 'Master'] as $side) {
    foreach (glob($root . "/app/Models/{$side}/*.php") as $file) {
        $class = "App\\Models\\{$side}\\" . basename($file, '.php');
        try {
            $ref = new ReflectionClass($class);
            if ($ref->isAbstract() || ! $ref->isSubclassOf(Illuminate\Database\Eloquent\Model::class)) continue;
            $table = $ref->newInstanceWithoutConstructor()->getTable();
        } catch (Throwable) {
            continue;
        }
        $key = $side === 'Master' && isset($tables['master.' . $table]) ? 'master.' . $table : $table;
        if (isset($tables[$key])) {
            $modelTable[$class] = $key;
            $tables[$key]['models'][] = class_basename($class);
        }
    }
}

// A source file → the tables it reaches: models it names, DB::table('x'), and (one level) the services it uses.
$fileTablesCache = [];
function tablesInFile(string $file, array $modelTable, array $tables, int $depth = 0): array
{
    global $fileTablesCache, $root;
    $key = $file . '#' . $depth;
    if (isset($fileTablesCache[$key])) return $fileTablesCache[$key];
    $src = @file_get_contents($file) ?: '';
    $found = [];
    foreach ($modelTable as $class => $table) {
        $short = class_basename($class);
        if (str_contains($src, $class) || (preg_match('/\buse\s+' . preg_quote($class, '/') . '\s*;/', $src) && preg_match('/\b' . $short . '\b/', $src))) {
            $found[$table] = 'model ' . $short;
        }
    }
    if (preg_match_all("/->table\\(\\s*'([a-z0-9_]+)'/", $src, $m)) {
        foreach ($m[1] as $t) if (isset($tables[$t])) $found[$t] ??= 'DB::table';
    }
    if ($depth === 0 && preg_match_all('/\\\\?(App\\\\Services\\\\[A-Za-z0-9\\\\]+)/', $src, $m)) {
        foreach (array_unique($m[1]) as $svc) {
            $path = $root . '/app/' . str_replace('\\', '/', substr($svc, 4)) . '.php';
            if (is_file($path)) {
                foreach (tablesInFile($path, $modelTable, $tables, 1) as $t => $why) $found[$t] ??= 'via ' . class_basename($svc);
            }
        }
    }

    return $fileTablesCache[$key] = $found;
}

// ── 4. Routes → controller → tables ─────────────────────────────────────────────────────────────
$screens = [];
foreach (app('router')->getRoutes() as $route) {
    $name = $route->getName();
    $action = $route->getActionName();
    if (! $name || ! str_contains($action, '@') || (! str_starts_with($name, 'tenant.') && ! str_starts_with($name, 'central.'))) continue;
    [$controller, $method] = explode('@', $action);
    try {
        $file = (new ReflectionClass($controller))->getFileName();
    } catch (Throwable) {
        continue;
    }
    $reach = tablesInFile($file, $modelTable, $tables);
    $screens[] = [
        'route' => $name, 'uri' => '/' . ltrim($route->uri(), '/'), 'methods' => implode('|', array_diff($route->methods(), ['HEAD'])),
        'controller' => class_basename($controller) . '@' . $method,
        'group' => implode('.', array_slice(explode('.', $name), 0, 2)),
        'tables' => array_keys($reach),
    ];
    foreach ($reach as $t => $why) {
        $tables[$t]['used_by'][$name] = $why;
    }
}

foreach ($tables as &$t) {
    $t['used_by_count'] = count($t['used_by']);
    $t['used_by'] = array_map(fn ($r, $w) => ['route' => $r, 'why' => $w], array_keys($t['used_by']), $t['used_by']);
}
unset($t);

// ── 5. Work in progress — every worktree of this repo on this machine, against the production branch ──
$git = fn (string $dir, string $args) => trim((string) @shell_exec('git -C ' . escapeshellarg($dir) . ' ' . $args . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null')));
$prodBranch = 'feat/14d-2-plan-upgrade-requests';
$work = [];
$wt = null;
foreach (explode("\n", $git($root, 'worktree list --porcelain')) as $line) {
    if (str_starts_with($line, 'worktree ')) {
        $wt = ['path' => substr($line, 9), 'branch' => '(detached)', 'head' => ''];
    } elseif ($wt && str_starts_with($line, 'HEAD ')) {
        $wt['head'] = substr($line, 5, 8);
    } elseif ($wt && str_starts_with($line, 'branch ')) {
        $wt['branch'] = str_replace('refs/heads/', '', substr($line, 7));
    } elseif ($line === '' && $wt) {
        $work[] = $wt;
        $wt = null;
    }
}
if ($wt) $work[] = $wt;
foreach ($work as &$w) {
    $ab = explode("\t", $git($root, 'rev-list --left-right --count ' . escapeshellarg($prodBranch . '...' . $w['head'])) ?: "0\t0");
    $w['behind_prod'] = (int) ($ab[0] ?? 0);
    $w['ahead_of_prod'] = (int) ($ab[1] ?? 0);
    $w['last_commit'] = $git($root, 'log -1 --format=%cs·%s ' . escapeshellarg($w['head']));
    $w['uncommitted_files'] = is_dir($w['path']) ? count(array_filter(explode("\n", $git($w['path'], 'status --porcelain --untracked-files=no')))) : null;
}
unset($w);
usort($work, fn ($a, $b) => strcmp(explode('·', $b['last_commit'])[0] ?? '', explode('·', $a['last_commit'])[0] ?? ''));
$plans = [];
foreach (glob($root . '/docs/plans/*.md') ?: [] as $f) {
    $first = trim((string) fgets(fopen($f, 'r')));
    $plans[] = ['file' => 'docs/plans/' . basename($f), 'title' => ltrim($first, '# '), 'modified' => date('Y-m-d', filemtime($f))];
}
usort($plans, fn ($a, $b) => strcmp($b['modified'], $a['modified']));

$map = [
    'built_at' => date('c'),
    'prod_branch' => $prodBranch,
    'prod_branch_head' => $git($root, 'rev-parse --short ' . escapeshellarg($prodBranch)),
    'work' => $work,
    'plans' => array_slice($plans, 0, 25),
    'git' => trim((string) @shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD')),
    'source' => ['tenant_db' => $opt['tenant-db'], 'master_db' => $opt['master-db']],
    'counts' => [
        'tables' => count($tables),
        'foreign_keys' => array_sum(array_map(fn ($t) => count($t['fk_out']), $tables)),
        'inferred_links' => array_sum(array_map(fn ($t) => count($t['inferred_out']), $tables)),
        'screens' => count($screens),
    ],
    'tables' => array_values($tables),
    'screens' => $screens,
];
@mkdir(dirname($out), 0777, true);
file_put_contents($out, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode($map['counts']), "\n", 'written: ', $out, "\n";

// The browsable page: viewer.html with a slim copy of the map inside (no server needed — open it).
$slim = ['built_at' => $map['built_at'], 'git' => $map['git'], 'counts' => $map['counts'], 't' => [], 's' => [],
    'prod_branch' => $map['prod_branch'], 'prod_head' => $map['prod_branch_head'], 'work' => $map['work'], 'plans' => $map['plans']];
foreach ($map['tables'] as $t) {
    $slim['t'][] = [$t['name'], $t['domain'], $t['side'], array_map(fn ($c) => explode(' ', $c)[0], $t['columns']),
        array_map(fn ($f) => [$f['col'], $f['table'], $f['on_delete']], $t['fk_out']),
        array_map(fn ($f) => [$f['col'], $f['table']], $t['inferred_out']), $t['models']];
}
foreach ($map['screens'] as $s) {
    $slim['s'][] = [$s['route'], $s['uri'], $s['methods'], $s['controller'], $s['tables']];
}
$page = str_replace('/*__DATA__*/null', json_encode($slim, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG), file_get_contents(__DIR__ . '/viewer.html'));
file_put_contents(dirname($out) . '/index.html', $page);
echo 'written: ', dirname($out), "/index.html\n";
