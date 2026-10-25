<?php
// READ-ONLY: render /goods-receipts/create for one tenant as its Owner (GET only) and report the entry area's parts flag.
use App\Models\Master\Tenant; use App\Services\Tenancy\TenancyManager; use Illuminate\Http\Request; use Illuminate\Support\Facades\DB;
$t = Tenant::where('tenant_code', getenv('PROBE_TENANT'))->firstOrFail();
$domain = $t->domains()->where('is_primary', true)->value('domain');
$tm = app(TenancyManager::class); $tm->activate($t);
$ownerId = DB::connection('tenant')->table('model_has_roles as m')->join('roles as r', 'r.id', '=', 'm.role_id')->where('r.name', getenv('PROBE_ROLE') ?: 'Owner')->orderBy('m.model_id')->value('m.model_id');
auth()->guard('tenant')->setUser(\App\Models\Tenant\User::on('tenant')->find($ownerId));
$path = route('tenant.goods-receipts.create', ['subdomain' => explode('.', $domain)[0]], false);
$res = app(\Illuminate\Contracts\Http\Kernel::class)->handle(Request::create('https://' . $domain . $path, 'GET'));
$body = (string) $res->getContent();
$flag = preg_match('/var COUNTED_PARTS = (true|false);/', $body, $m) ? $m[1] : 'absent (old receipt screen)';
printf("%-16s %s=%s GRN create %d, entry area parts flag: %s\n", $t->tenant_code, getenv('PROBE_ROLE') ?: 'Owner', $ownerId, $res->getStatusCode(), $flag);
