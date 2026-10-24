<?php
// READ-ONLY page probe for ONE tenant (PROBE_TENANT=<code>): renders the key screens through the app's own HTTP
// kernel as that tenant's Owner (GET only — nothing is clicked or saved). Prints each screen's status; a 5xx, an
// error page, or a screen answering differently from what its switch says is a FAIL.
//   /purchases/new must answer 404 unless purchase_workspace (+ purchasing_v2) is on, then 200.
use App\Models\Master\Tenant;
use App\Services\Tenancy\TenancyManager;
use App\Support\TenantFeatures;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$code = (string) getenv('PROBE_TENANT');
$t = Tenant::where('tenant_code', $code)->first();
if (! $t) { echo "{$code}: no such tenant\n"; return; }
$domain = $t->domains()->where('is_primary', true)->value('domain') ?? $t->domains()->value('domain');

$tm = app(TenancyManager::class);
$tm->activate($t);
$c = DB::connection('tenant');
$ownerId = $c->table('model_has_roles as m')->join('roles as r', 'r.id', '=', 'm.role_id')
    ->where('r.name', getenv('PROBE_ROLE') ?: 'Owner')->where('r.guard_name', 'tenant')->orderBy('m.model_id')->value('m.model_id');
$owner = $ownerId ? \App\Models\Tenant\User::on('tenant')->find($ownerId) : null;
$s = $c->getSchemaBuilder();
$productId = $c->table('products')->orderBy('id')->value('id');
$recipeId = $s->hasTable('recipes') ? $c->table('recipes')->orderBy('id')->value('id') : null;
$bomId = $s->hasTable('manufacturing_boms') ? $c->table('manufacturing_boms')->orderBy('id')->value('id') : null;
$cateringId = $s->hasTable('catering_product_profiles') ? $c->table('catering_product_profiles')->orderBy('id')->value('id') : null;
$dishId = $s->hasTable('catering_product_profiles') ? $c->table('catering_product_profiles')->orderByDesc('id')->value('product_id') : null;
$materialId = $c->table('products')->where('product_kind', 'raw_material')->orderByDesc('id')->value('id');

$ws = TenantFeatures::on(TenantFeatures::PURCHASE_WORKSPACE, $t) && TenantFeatures::on(TenantFeatures::PURCHASING_V2, $t);
$pages = [
    'dashboard' => ['tenant.dashboard', []],
    'pos' => ['tenant.pos.index', []],
    'products' => ['tenant.products.index', []],
    'product add' => ['tenant.products.create', []],
    'product edit' => $productId ? ['tenant.products.edit', [$productId]] : null,
    'recipe edit' => $recipeId ? ['tenant.recipes.edit', [$recipeId]] : null,
    'bom edit' => $bomId ? ['tenant.manufacturing.bom.edit', [$bomId]] : null,
    'cost blocks' => $cateringId ? ['tenant.catering.cost-blocks.edit', [$cateringId]] : null,
    'dish edit' => $dishId ? ['tenant.products.edit', [$dishId]] : null,
    'material edit' => $materialId ? ['tenant.products.edit', [$materialId]] : null,
    'po new' => ['tenant.purchase-orders.create', []],
    'grn new' => ['tenant.goods-receipts.create', []],
    'return new' => ['tenant.purchase-returns.create', []],
    'cash book' => ['tenant.reports.cash-book.index', []],
    'purchases/new' => ['tenant.purchases.workspace', []],
];
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);
$fails = 0; $line = [];
foreach (array_filter($pages) as $label => [$name, $params]) {
    try {
        $path = route($name, ['subdomain' => explode('.', (string) $domain)[0]] + $params, false);
    } catch (\Throwable $e) { $line[] = "{$label}=noroute"; continue; }
    $tm->activate($t);
    auth()->guard('tenant')->setUser($owner);
    $req = Request::create('https://' . $domain . $path, 'GET');
    try {
        $t0 = microtime(true);
        $res = $kernel->handle($req);
        $ms = (int) ((microtime(true) - $t0) * 1000);
        $st = $res->getStatusCode();
        $body = (string) $res->getContent();
        $kernel->terminate($req, $res);
    } catch (\Throwable $e) { $st = 'EXC'; $body = $e->getMessage(); }
    $errorPage = is_int($st) && $st === 200 && preg_match('/Whoops|Server Error|ErrorException|Undefined (variable|array key|index)/', $body);
    $expect = $label === 'purchases/new' ? ($ws ? ($owner && $owner->can('tenant.purchases.workspace') ? 200 : 403) : 'closed') : null;
    $bad = ! is_int($st) || $st >= 500 || $errorPage || ($expect === 'closed' ? ! in_array($st, [403, 404], true) : ($expect !== null && $st !== $expect))
        || ($expect === null && ! in_array($st, [200, 302, 403], true));
    if ($bad) { $fails++; }
    if ($label === 'dashboard' && is_int($st) && $st === 200 && preg_match('/id="sidebar-purchases"[^>]*|href="([^"]+)"[^>]*id="sidebar-purchases"/', $body, $mm)) { $line[] = 'sidebar Purchases→' . (parse_url($mm[1] ?? '', PHP_URL_PATH) ?: '?'); }
    $line[] = $label . '=' . $st . (isset($ms) && $ms > 1500 ? '(' . round($ms / 1000, 1) . 's)' : '') . ($bad ? '!' : '') . ($st === 302 ? '→' . parse_url((string) $res->headers->get('Location'), PHP_URL_PATH) : '');
}
printf("%-18s %s=%s %s :: %s\n", $code, getenv('PROBE_ROLE') ?: 'Owner', $owner ? '#' . $owner->id : 'NONE', $fails ? "FAIL({$fails})" : 'OK', implode('  ', $line));
