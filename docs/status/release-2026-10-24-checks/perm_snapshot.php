<?php
// READ-ONLY. Every tenant (+ master): role → permission names, user → roles, user → direct permissions.
// `PERM_SNAP=before` writes /tmp/perm_before.json; `PERM_SNAP=after` compares with it.
$mode = getenv('PERM_SNAP') ?: 'before';
$grab = function (string $conn): array {
    $c = DB::connection($conn);
    $roles = [];
    foreach ($c->table('role_has_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')
        ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
        ->get(['r.id', 'r.name as role', 'r.guard_name', 'p.name as perm']) as $row) {
        $roles[$row->id.' '.$row->role.' ('.$row->guard_name.')'][] = $row->perm;
    }
    foreach ($c->table('roles')->get(['id', 'name', 'guard_name']) as $r) {
        $roles[$r->id.' '.$r->name.' ('.$r->guard_name.')'] ??= [];
    }
    $users = [];
    foreach ($c->table('model_has_roles')->get(['model_id', 'model_type', 'role_id']) as $m) {
        $users[$m->model_type.'#'.$m->model_id][] = (int) $m->role_id;
    }
    $direct = [];
    foreach ($c->table('model_has_permissions as mp')->join('permissions as p', 'p.id', '=', 'mp.permission_id')
        ->get(['mp.model_id', 'mp.model_type', 'p.name']) as $m) {
        $direct[$m->model_type.'#'.$m->model_id][] = $m->name;
    }
    foreach ($roles as &$p) { sort($p); }
    foreach ($users as &$u) { sort($u); }
    foreach ($direct as &$d) { sort($d); }
    ksort($roles); ksort($users); ksort($direct);

    return ['roles' => $roles, 'users' => $users, 'direct' => $direct, 'perm_count' => $c->table('permissions')->count()];
};

$snap = ['master' => $grab('master')];
$tm = app(App\Services\Tenancy\TenancyManager::class);
foreach (App\Models\Master\Tenant::orderBy('id')->get() as $t) {
    try {
        $tm->activate($t);
        $snap[$t->tenant_code] = $grab('tenant');
    } catch (Throwable $e) {
        $snap[$t->tenant_code] = ['error' => substr($e->getMessage(), 0, 120)];
    } finally {
        $tm->deactivate();
        DB::setDefaultConnection('master');
    }
}

if ($mode === 'before') {
    file_put_contents('/tmp/perm_before.json', json_encode($snap));
    foreach ($snap as $code => $s) {
        printf("%-20s %s\n", $code, isset($s['error']) ? 'ERROR '.$s['error'] : 'roles='.count($s['roles']).' grants='.array_sum(array_map('count', $s['roles'])).' users='.count($s['users']).' direct='.array_sum(array_map('count', $s['direct'])).' perms='.$s['perm_count']);
    }
    exit;
}

$before = json_decode(file_get_contents('/tmp/perm_before.json'), true);
$lost = 0;
foreach ($before as $code => $b) {
    $a = $snap[$code] ?? null;
    if (isset($b['error']) || ! $a || isset($a['error'])) { printf("%-20s skipped (%s)\n", $code, $b['error'] ?? $a['error'] ?? 'missing after'); continue; }
    $gained = [];
    foreach ($b['roles'] as $role => $perms) {
        $missing = array_diff($perms, $a['roles'][$role] ?? []);
        if (! isset($a['roles'][$role])) { echo "  !! $code role GONE: $role\n"; $lost++; }
        if ($missing) { echo "  !! $code $role LOST ".count($missing).': '.implode(', ', array_slice($missing, 0, 5))."\n"; $lost += count($missing); }
        $plus = array_values(array_diff($a['roles'][$role] ?? [], $perms));
        if ($plus) { $gained[] = "$role +".count($plus).(count($plus) <= 3 ? ' ['.implode(', ', $plus).']' : ''); }
    }
    foreach (($b['direct'] ?? []) as $u => $perms) {
        $missing = array_diff($perms, $a['direct'][$u] ?? []);
        if ($missing) { echo "  !! $code direct $u LOST ".implode(', ', $missing)."\n"; $lost += count($missing); }
    }
    foreach (($a['direct'] ?? []) as $u => $perms) {
        $plus = array_values(array_diff($perms, $b['direct'][$u] ?? []));
        if ($plus) { $gained[] = "user $u direct +[".implode(', ', $plus).']'; }
    }
    $userChanges = 0;
    foreach ($b['users'] as $u => $r) { if (($a['users'][$u] ?? []) !== $r) { $userChanges++; echo "  !! $code user $u roles changed\n"; } }
    printf("%-20s roles %d→%d grants %d→%d users_changed=%d gained: %s\n", $code, count($b['roles']), count($a['roles']),
        array_sum(array_map('count', $b['roles'])), array_sum(array_map('count', $a['roles'])), $userChanges, $gained ? implode('; ', $gained) : '-');
    $lost += $userChanges;
}
echo $lost === 0 ? "PERMISSIONS OK: no role lost anything, no user lost a role\n" : "PERMISSIONS PROBLEM: $lost\n";
