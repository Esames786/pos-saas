<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Services\Finance\CashShortageExpenseService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * FINANCE-RESTART-1 — a business starts its books again from its sales: every POS sale (and return) stays exactly as
 * it is, with its journal and its cash/card movement; everything else in finance, purchasing, expenses and stock goes,
 * and the business re-enters it. Asked by a live restaurant's owner (3 Nov): "sirf sales aur short shift closing rahein,
 * cash aur card ka balance un ke hisaab se; purchase, expense, GRN, inventory, naye heads, opening balances — sab hatao."
 *
 * Kept:   sales_orders and everything a sale is (lines, payments, returns, KOTs, shifts); journals whose source starts
 *         with "sales_"; cash/bank movements whose type starts with "sales_"; the shift-closing short-cash expenses —
 *         put back to DRAFT so the business reports them again; products, suppliers (balance 0), users, settings.
 * Removed: every other journal; every other cash/bank movement; expenses; GRNs, supplier invoices, payments, returns,
 *         purchase orders, supplier ledger and credit allocations; stock, kitchen, department and manufacturing
 *         movements; opening balances; void-purge copies; the business's own account heads (and its own expense
 *         categories) that nothing kept uses.
 * System heads all stay (a deploy re-seeds them anyway); reports already leave out a head with nothing on it.
 * Rebuilt: each cash/bank account's running balance and current balance from the movements that stay.
 *
 * Refused (nothing written): catering, customer-account or manufacturing records exist (their journals would be taken
 * off while the documents stay); a kept sale journal touches a head that is not cash/bank or income.
 *
 * One transaction. Before it commits it proves: sales identical (counts and amounts), trial balance balances, nothing
 * left pointing at a removed row. Default DRY RUN: the same work runs and is rolled back. --yes commits it.
 * Export the records first: finance:export-tenant. Take a backup first.
 */
class FinanceRestartKeepSalesCommand extends Command
{
    protected $signature = 'finance:restart-keep-sales {tenant_code} {--yes}';

    protected $description = 'Start a tenant\'s books again from its POS sales (everything else in finance / purchasing / expenses / stock removed). Dry run unless --yes.';

    private const DRY_RUN = '__finance_restart_dry_run__';

    /** Taken off whole (children first). Only tables that exist are touched. */
    private const WIPE = [
        // purchasing + supplier account
        'goods_receipt_revisions', 'supplier_credit_moves', 'supplier_credit_reallocations', 'purchase_bill_return_nettings',
        'supplier_credit_allocations', 'supplier_ledgers', 'supplier_payments',
        'purchase_bill_lines', 'purchase_bills', 'goods_receipt_lines', 'goods_receipts',
        'purchase_return_lines', 'purchase_returns', 'purchase_order_lines', 'purchase_orders',
        // stock
        'stock_ledgers', 'stock_balances', 'inventory_batches',
        'stock_adjustment_lines', 'stock_adjustments', 'stock_count_lines', 'stock_count_sessions',
        'stock_transfer_lines', 'stock_transfers', 'recipe_consumptions',
        'kitchen_production_ingredients', 'kitchen_productions', 'kitchen_wastages',
        'department_stock_ledgers', 'department_stock_balances', 'department_stock_transfer_lines', 'department_stock_transfers',
        'department_count_lines', 'department_count_sessions', 'department_count_adjustments', 'department_consumption_exceptions',
        'department_handovers',
        // finance
        'opening_balance_lines', 'opening_balance_batches', 'purged_entries', 'purged_numbers',
    ];

    /** A business with any of these is not a POS-only business: refused. */
    private const MUST_BE_EMPTY = [
        'catering_events', 'catering_advances', 'catering_final_invoices', 'customer_payments', 'customer_ledgers',
        'manufacturing_consumption_records', 'production_orders', 'finished_good_receipts',
    ];

    /** Sales — must be identical before and after. */
    private const SALES = ['sales_orders', 'sales_order_lines', 'sale_payments', 'sales_returns', 'sales_return_lines', 'kot_batches', 'shifts'];

    public function handle(TenancyManager $tenancy): int
    {
        $tenant = Tenant::where('tenant_code', (string) $this->argument('tenant_code'))->first();
        if (! $tenant) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }
        $tenancy->activate($tenant);
        $c = DB::connection('tenant');
        $has = fn (string $t) => $c->getSchemaBuilder()->hasTable($t);

        $apply = (bool) $this->option('yes');
        $this->line(($apply ? '<comment>APPLYING</comment>' : '<info>DRY RUN</info> — nothing is written; pass --yes to write.') . ' Tenant ' . $tenant->tenant_code);

        foreach (self::MUST_BE_EMPTY as $t) {
            if ($has($t) && $c->table($t)->exists()) {
                $this->error("REFUSED: {$t} has records — this command is only for a POS-only business. Nothing was written.");

                return self::FAILURE;
            }
        }

        $saleJournal = "COALESCE(source_type, '') LIKE 'sales\\_%'";
        $saleCash = "COALESCE(transaction_type, '') LIKE 'sales\\_%'";

        // Kept sale journals may touch only cash/bank heads and income heads.
        $cashHeads = $c->table('cash_bank_accounts')->whereNotNull('account_id')->pluck('account_id')->map(fn ($id) => (int) $id)->all();
        $foreign = $c->table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->whereRaw("COALESCE(e.source_type, '') LIKE 'sales\\_%'")->where('a.type', '!=', 'income')->whereNotIn('a.id', $cashHeads ?: [0])
            ->selectRaw('a.code, a.name, COUNT(*) n')->groupBy('a.code', 'a.name')->get();
        if ($foreign->isNotEmpty()) {
            $this->error('REFUSED: sale journals touch heads that are neither cash/bank nor income: '
                . $foreign->map(fn ($r) => "{$r->code} {$r->name} ({$r->n})")->implode(', ') . '. Nothing was written.');

            return self::FAILURE;
        }

        $fingerprint = function () use ($c, $has, $saleJournal, $saleCash) {
            $f = [];
            foreach (self::SALES as $t) {
                if ($has($t)) {
                    $f[$t] = $c->table($t)->count();
                }
            }
            $f['sales_grand_total'] = round((float) $c->table('sales_orders')->sum('grand_total'), 2);
            $j = $c->table('journal_entries')->whereRaw($saleJournal)->selectRaw('COUNT(*) n, ROUND(SUM(total_debit),2) d')->first();
            $f['sale_journals'] = [(int) $j->n, (float) $j->d];
            $l = $c->table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->whereRaw("COALESCE(e.source_type, '') LIKE 'sales\\_%'")
                ->selectRaw('COUNT(*) n, ROUND(SUM(l.debit),2) d, ROUND(SUM(l.credit),2) c')->first();
            $f['sale_journal_lines'] = [(int) $l->n, (float) $l->d, (float) $l->c];
            $m = $c->table('cash_bank_account_transactions')->whereRaw($saleCash)->selectRaw('COUNT(*) n, ROUND(SUM(amount),2) a')->first();
            $f['sale_cash_rows'] = [(int) $m->n, (float) $m->a];

            return $f;
        };
        $before = $fingerprint();

        // The shift-closing short-cash expenses: the vouchers the cash closing itself made (EXP-SHORT-…). Each goes back
        // to exactly what the closing made — one "Daily Closing — Short Cash" line, the amount the drawer was short (its
        // note says it), Draft — even one a person later opened, re-split into other expenses and posted.
        // A voided one was a closing corrected at the time (a wrong count) — it is not brought back.
        $shortIds = $c->table('expense_vouchers')->where('voucher_no', 'like', 'EXP-SHORT-%')->where('status', '!=', 'void')
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $shortCat = $shortIds ? (int) app(CashShortageExpenseService::class)->ensureCategory()->id : 0;

        $report = [];
        $count = function (string $label, int $n) use (&$report): void {
            $report[] = sprintf('  %-46s %d', $label, $n);
        };

        try {
            $c->transaction(function () use ($c, $has, $apply, $saleJournal, $saleCash, $shortIds, $shortCat, $cashHeads, $fingerprint, $before, &$report, $count) {
                $c->statement('SET FOREIGN_KEY_CHECKS=0');

                // 1. journals and cash/bank movements that are not a sale
                $nonSale = $c->table('journal_entries')->whereRaw("NOT ({$saleJournal})")->pluck('id');
                $count('journal lines removed', $nonSale->chunk(2000)->sum(fn ($ids) => $c->table('journal_lines')->whereIn('journal_entry_id', $ids)->delete()));
                $count('journal entries removed', $c->table('journal_entries')->whereRaw("NOT ({$saleJournal})")->delete());
                $count('cash/bank movements removed (not a sale)', $c->table('cash_bank_account_transactions')->whereRaw("NOT ({$saleCash})")->delete());

                // 2. expenses: the closing's short-cash vouchers back to what the closing made, as drafts; the rest go
                $count('short-cash expenses back to draft as the closing made them', count($shortIds));
                $shortHead = $shortCat ? $c->table('expense_categories')->where('id', $shortCat)->value('account_id') : null;
                $was = ['n' => 0, 'before' => 0.0, 'after' => 0.0, 'examples' => []];
                foreach ($c->table('expense_vouchers')->whereIn('id', $shortIds ?: [0])->orderBy('id')->get() as $v) {
                    $lines = $c->table('expense_voucher_lines')->where('expense_voucher_id', $v->id)->get();
                    $short = preg_match('/short by ([\d,]+(?:\.\d+)?)/i', (string) $v->notes, $m)
                        ? (float) str_replace(',', '', $m[1])
                        : (float) ($lines->where('expense_category_id', $shortCat)->sum('amount') ?: $v->total_amount);
                    $short = round($short, 4);
                    $changed = $v->status !== 'draft' || $lines->count() !== 1 || abs((float) $v->total_amount - $short) > 0.005;
                    if ($changed) {
                        $was['n']++;
                        $was['before'] += (float) $v->total_amount;
                        $was['after'] += $short;
                        if (count($was['examples']) < 5) {
                            $was['examples'][] = sprintf('%s: %s %s in %d line(s) → draft %s', $v->voucher_no, $v->status,
                                number_format((float) $v->total_amount, 2), $lines->count(), number_format($short, 2));
                        }
                    }
                    $c->table('expense_voucher_lines')->where('expense_voucher_id', $v->id)->delete();
                    $c->table('expense_voucher_lines')->insert(['expense_voucher_id' => $v->id, 'expense_category_id' => $shortCat, 'account_id' => $shortHead,
                        'description' => CashShortageExpenseService::CATEGORY_NAME, 'amount' => $short, 'tax_amount' => 0, 'line_total' => $short,
                        'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
                    $c->table('expense_vouchers')->where('id', $v->id)->update(['status' => 'draft', 'subtotal' => $short, 'tax_amount' => 0,
                        'total_amount' => $short, 'posted_at' => null, 'posted_by_user_id' => null, 'voided_at' => null, 'voided_by_user_id' => null,
                        'void_reason' => null, 'updated_at' => now()]);
                }
                $report[] = sprintf('    %d of them had been changed (posted / re-split into other expenses): %s as they were → %s short as closed',
                    $was['n'], number_format($was['before'], 2), number_format($was['after'], 2));
                foreach ($was['examples'] as $e) {
                    $report[] = '      e.g. ' . $e;
                }
                $report[] = sprintf('    short-cash drafts total: %s', number_format((float) $c->table('expense_vouchers')->whereIn('id', $shortIds ?: [0])->sum('total_amount'), 2));
                $count('expense lines removed', $c->table('expense_voucher_lines')->whereNotIn('expense_voucher_id', $shortIds ?: [0])->delete());
                $count('expenses removed', $c->table('expense_vouchers')->whereNotIn('id', $shortIds ?: [0])->delete());

                // 3. purchasing, stock, opening balances, void-purge copies
                foreach (self::WIPE as $t) {
                    if ($has($t)) {
                        $n = $c->table($t)->delete();
                        if ($n) {
                            $count($t . ' removed', $n);
                        }
                    }
                }
                $count('suppliers set to balance 0', $c->table('suppliers')->where(fn ($q) => $q->where('current_balance', '!=', 0)->orWhere('opening_balance', '!=', 0))
                    ->update(['current_balance' => 0, 'opening_balance' => 0]));

                // 4. cash / bank: opening 0, running balance and balance rebuilt from the movements that stay
                $c->table('cash_bank_accounts')->update(['opening_balance' => 0]);
                $c->statement("UPDATE cash_bank_account_transactions t JOIN (SELECT id, SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END)
                    OVER (PARTITION BY cash_bank_account_id ORDER BY transaction_date, id) run FROM cash_bank_account_transactions) x ON x.id = t.id
                    SET t.balance_after = x.run");
                foreach ($c->table('cash_bank_accounts')->get(['id', 'name', 'current_balance']) as $acc) {
                    $now = round((float) $c->table('cash_bank_account_transactions')->where('cash_bank_account_id', $acc->id)
                        ->sum(DB::raw("CASE WHEN direction = 'in' THEN amount ELSE -amount END")), 4);
                    $c->table('cash_bank_accounts')->where('id', $acc->id)->update(['current_balance' => $now]);
                    $report[] = sprintf('  cash/bank %-30s %18s → %18s', $acc->name, number_format((float) $acc->current_balance, 2), number_format($now, 2));
                }

                // 5. the business's own heads that nothing kept uses go
                $keptUse = $c->table('journal_lines')->distinct()->pluck('account_id')->map(fn ($id) => (int) $id)
                    ->merge($cashHeads)->merge($has('departments') ? $c->table('departments')->whereNotNull('payable_account_id')->pluck('payable_account_id') : [])
                    ->map(fn ($id) => (int) $id)->unique()->all();
                $accounts = $c->table('accounts')->get(['id', 'code', 'name', 'parent_id', 'is_system'])->keyBy('id');
                $gone = $accounts->filter(fn ($a) => ! $a->is_system && ! in_array((int) $a->id, $keptUse, true));
                $goneIds = $gone->keys()->map(fn ($id) => (int) $id)->all();
                $systemAbove = function (int $id) use ($accounts) {
                    for ($p = $accounts[$id]->parent_id ?? null; $p; $p = $accounts[$p]->parent_id ?? null) {
                        if ($accounts[$p]->is_system ?? false) {
                            return (int) $p;
                        }
                    }

                    return null;
                };
                if ($goneIds) {
                    $count('product categories moved to the default cost head', $c->table('categories')->whereIn('cogs_account_id', $goneIds)->update(['cogs_account_id' => null]));
                    $keepCats = $c->table('expense_voucher_lines')->distinct()->pluck('expense_category_id')->filter()->map(fn ($id) => (int) $id)->all();
                    $count('own expense categories removed', $c->table('expense_categories')->where('is_system', false)->whereNotIn('id', $keepCats ?: [0])->delete());
                    foreach ($c->table('expense_categories')->whereIn('account_id', $goneIds)->get(['id', 'account_id']) as $cat) {
                        $c->table('expense_categories')->where('id', $cat->id)->update(['account_id' => $systemAbove((int) $cat->account_id)]);
                        $count('expense category re-pointed to its system head', 1);
                    }
                    foreach ($c->table('expense_voucher_lines')->whereIn('account_id', $goneIds)->get(['id', 'expense_category_id']) as $line) {
                        $c->table('expense_voucher_lines')->where('id', $line->id)
                            ->update(['account_id' => $c->table('expense_categories')->where('id', $line->expense_category_id)->value('account_id')]);
                    }
                    foreach ($accounts->filter(fn ($a) => in_array((int) $a->parent_id, $goneIds, true) && ! in_array((int) $a->id, $goneIds, true)) as $child) {
                        $c->table('accounts')->where('id', $child->id)->update(['parent_id' => $systemAbove((int) $child->id)]);
                    }
                    $count('own account heads removed', $c->table('accounts')->whereIn('id', $goneIds)->delete());
                    $report[] = '    ' . $gone->sortBy('code')->map(fn ($a) => $a->code . ' ' . $a->name)->implode(' · ');
                }

                $c->statement('SET FOREIGN_KEY_CHECKS=1');

                // 6. proof before commit
                $after = $fingerprint();
                if (json_encode($after) !== json_encode($before)) {
                    throw new RuntimeException('Sales changed (' . json_encode($before) . ' → ' . json_encode($after) . ') — rolled back, nothing written.');
                }
                $tb = $c->table('journal_lines')->selectRaw('ROUND(SUM(debit),2) d, ROUND(SUM(credit),2) c')->first();
                if (abs((float) $tb->d - (float) $tb->c) > 0.01) {
                    throw new RuntimeException("Trial balance does not balance ({$tb->d} / {$tb->c}) — rolled back, nothing written.");
                }
                $orphans = [
                    'journal lines on a removed head'       => $c->table('journal_lines')->whereNotIn('account_id', $c->table('accounts')->select('id'))->count(),
                    'journal lines without their entry'     => $c->table('journal_lines')->whereNotIn('journal_entry_id', $c->table('journal_entries')->select('id'))->count(),
                    'expense lines on a removed head'       => $c->table('expense_voucher_lines')->whereNotNull('account_id')->whereNotIn('account_id', $c->table('accounts')->select('id'))->count(),
                    'expense lines on a removed category'   => $c->table('expense_voucher_lines')->whereNotIn('expense_category_id', $c->table('expense_categories')->select('id'))->count(),
                    'expense categories on a removed head'  => $c->table('expense_categories')->whereNotNull('account_id')->whereNotIn('account_id', $c->table('accounts')->select('id'))->count(),
                    'product categories on a removed head'  => $c->table('categories')->whereNotNull('cogs_account_id')->whereNotIn('cogs_account_id', $c->table('accounts')->select('id'))->count(),
                    'cash/bank accounts on a removed head'  => $c->table('cash_bank_accounts')->whereNotNull('account_id')->whereNotIn('account_id', $c->table('accounts')->select('id'))->count(),
                    'heads under a removed parent'          => $c->table('accounts')->whereNotNull('parent_id')->whereNotIn('parent_id', $c->table('accounts')->select('id'))->count(),
                    'non-sale journals left'                => $c->table('journal_entries')->whereRaw("NOT ({$saleJournal})")->count(),
                    'short-cash expenses not draft'         => $c->table('expense_vouchers')->where('status', '!=', 'draft')->count(),
                ];
                if (array_sum($orphans) > 0) {
                    throw new RuntimeException('Left inconsistent: ' . json_encode(array_filter($orphans)) . ' — rolled back, nothing written.');
                }
                $report[] = '  proof: sales identical ' . json_encode($after) . '; trial balance ' . $tb->d . ' = ' . $tb->c . '; nothing left dangling.';
                foreach ($c->table('cash_bank_accounts')->whereNotNull('account_id')->get(['name', 'account_id', 'current_balance']) as $acc) {
                    $gl = round((float) $c->table('journal_lines')->where('account_id', $acc->account_id)->sum(DB::raw('debit - credit')), 2);
                    $report[] = sprintf('  check %-28s cash book %16s   GL head %16s%s', $acc->name, number_format((float) $acc->current_balance, 2),
                        number_format($gl, 2), abs($gl - (float) $acc->current_balance) > 0.01 ? '   ← differ' : '');
                }

                if (! $apply) {
                    throw new RuntimeException(self::DRY_RUN);
                }
            });
        } catch (RuntimeException $e) {
            $c->statement('SET FOREIGN_KEY_CHECKS=1');
            foreach ($report as $line) {
                $this->line($line);
            }
            if ($e->getMessage() !== self::DRY_RUN) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->info('Dry run — rolled back. Run again with --yes to write.');

            return self::SUCCESS;
        }

        foreach ($report as $line) {
            $this->line($line);
        }
        $this->info('Done. Sales untouched; everything else starts again from zero.');

        return self::SUCCESS;
    }
}
