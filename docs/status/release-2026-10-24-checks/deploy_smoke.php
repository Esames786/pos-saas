<?php
// READ-ONLY deploy smoke: per active tenant — journals, tb_diff, negative stock on branches that do
// NOT allow it, negative department stock, and kashifkitchen's KK-CASH manual-journal net. Writes nothing.
use App\Models\Master\Tenant;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Support\Facades\DB;

foreach (Tenant::where('status', 'active')->orderBy('tenant_code')->get() as $t) {
    try {
        app(TenancyManager::class)->activate($t);
        $c = DB::connection('tenant');
        $je = $c->table('journal_entries')->count();
        $tb = (float) $c->table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.status', 'posted')->selectRaw('ROUND(COALESCE(SUM(l.debit) - SUM(l.credit), 0), 2) d')->value('d');
        $negDis = $c->table('stock_balances as s')->join('branches as b', 'b.id', '=', 's.branch_id')
            ->where('s.quantity_on_hand', '<', 0)->where(fn ($q) => $q->where('b.allow_negative_stock', false)->orWhereNull('b.allow_negative_stock'))->count();
        $dept = $c->getSchemaBuilder()->hasTable('department_stock_balances')
            ? $c->table('department_stock_balances')->where('quantity_on_hand', '<', 0)->count() : 0;
        $mj = $c->table('journal_entries')->where('source_type', 'like', 'manual_journal%')->count();
        printf("%-20s je=%-6d tb_diff=%.2f neg_on_disallowed=%d department_negative=%d manual_journals=%d\n", $t->tenant_code, $je, $tb, $negDis, $dept, $mj);
    } catch (\Throwable $e) {
        printf("%-20s ERR %s\n", $t->tenant_code, substr($e->getMessage(), 0, 90));
    } finally {
        app(TenancyManager::class)->deactivate();
    }
}
