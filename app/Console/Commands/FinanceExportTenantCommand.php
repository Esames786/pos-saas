<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Services\Finance\CashShortageExpenseService;
use App\Services\Tenancy\TenancyManager;
use App\Support\SimpleXlsx;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * FINANCE-RESTART-1 — one Excel workbook with a business's finance, purchase, expense and stock records, a tab each,
 * so the owner keeps every figure in a file he can open before those records are taken off
 * (`finance:restart-keep-sales`). Sales are not exported (they stay in the system). READ-ONLY: writes only the file.
 *
 * Tabs: Summary · Chart of Accounts · Expenses · Expense Categories · GRN · Supplier Invoices · Invoice Lines ·
 * Supplier Payments · Supplier Ledger · Suppliers · Journal Entries (everything but sales) · Opening Balances ·
 * Stock Ledger · Cash Book (non-sale) · Short Cash (closing).
 */
class FinanceExportTenantCommand extends Command
{
    protected $signature = 'finance:export-tenant {tenant_code} {--out= : Where to write the .xlsx (default storage/app/private/exports)}';

    protected $description = 'Write one Excel file with every finance / purchase / expense / stock record of a tenant (sales excluded). Read-only.';

    public function handle(TenancyManager $tenancy): int
    {
        $tenant = Tenant::where('tenant_code', (string) $this->argument('tenant_code'))->first();
        if (! $tenant) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }
        $tenancy->activate($tenant);
        $c = DB::connection('tenant');
        $x = new SimpleXlsx;
        $H = fn (array $cols) => array_map(fn ($v) => ['v' => $v, 's' => SimpleXlsx::HEADER], $cols);
        $M = fn ($v) => ['v' => round((float) $v, 2), 's' => SimpleXlsx::MONEY];
        $D = fn ($v) => $v ? substr((string) $v, 0, 10) : '';
        $counts = [];

        $tab = function (string $name, array $cols, iterable $rows, callable $map, array $widths = []) use ($x, $H, &$counts) {
            $s = $x->sheet($name, $widths ?: array_fill(0, count($cols), 16), 1);
            $x->row($s, $H($cols));
            $n = 0;
            foreach ($rows as $r) {
                $x->row($s, $map($r));
                $n++;
            }
            $counts[$name] = $n;
        };

        $summary = $x->sheet('Summary', [34, 22], 0);

        // Chart of accounts with what is on each head (all entries, sales included — the heads' full history).
        $use = $c->table('journal_lines')->selectRaw('account_id, COUNT(*) n, SUM(debit) dr, SUM(credit) cr')->groupBy('account_id')->get()->keyBy('account_id');
        $codes = $c->table('accounts')->pluck('code', 'id');
        $headNames = $c->table('accounts')->pluck('name', 'id');
        $tab('Chart of Accounts', ['Code', 'Name', 'Type', 'System head', 'Active', 'Parent', 'Entries', 'Debit', 'Credit', 'Balance (Dr − Cr)'],
            $c->table('accounts')->orderBy('code')->get(), fn ($a) => [$a->code, $a->name, $a->type, $a->is_system ? 'yes' : 'no', $a->is_active ? 'yes' : 'no',
                $a->parent_id ? ($codes[$a->parent_id] ?? '') : '', (int) ($use[$a->id]->n ?? 0), $M($use[$a->id]->dr ?? 0), $M($use[$a->id]->cr ?? 0),
                $M(((float) ($use[$a->id]->dr ?? 0)) - ((float) ($use[$a->id]->cr ?? 0)))], [10, 34, 10, 8, 8, 10, 9, 16, 16, 16]);

        $cashNames = $c->table('cash_bank_accounts')->pluck('name', 'id');
        $catNames = $c->table('expense_categories')->pluck('name', 'id');
        $tab('Expenses', ['Voucher', 'Expense date', 'Payment date', 'Status', 'Payee', 'Paid from', 'Category', 'Head', 'Line', 'Amount', 'Tax', 'Line total', 'Voucher total', 'Notes', 'Posted at', 'Voided at', 'Void reason'],
            $c->table('expense_voucher_lines as l')->join('expense_vouchers as v', 'v.id', '=', 'l.expense_voucher_id')->orderBy('v.expense_date')->orderBy('v.id')->orderBy('l.id')
                ->get(['v.*', 'l.expense_category_id', 'l.account_id as line_account', 'l.description as line_desc', 'l.amount as line_amount', 'l.tax_amount as line_tax', 'l.line_total']),
            fn ($r) => [$r->voucher_no, $D($r->expense_date), $D($r->payment_date), $r->status, $r->payee_name, $cashNames[$r->cash_bank_account_id] ?? '',
                $catNames[$r->expense_category_id] ?? '', $codes[$r->line_account] ?? '', $r->line_desc, $M($r->line_amount), $M($r->line_tax), $M($r->line_total),
                $M($r->total_amount), $r->notes, (string) $r->posted_at, (string) $r->voided_at, $r->void_reason], [18, 12, 12, 9, 18, 18, 22, 9, 26, 13, 9, 13, 13, 24, 18, 18, 20]);

        $useByCat = $c->table('expense_voucher_lines')->selectRaw('expense_category_id, COUNT(*) n')->groupBy('expense_category_id')->pluck('n', 'expense_category_id');
        $tab('Expense Categories', ['Code', 'Name', 'Head', 'System', 'Active', 'Times used'], $c->table('expense_categories')->orderBy('sort_order')->get(),
            fn ($r) => [$r->code, $r->name, $codes[$r->account_id] ?? '', $r->is_system ? 'yes' : 'no', $r->is_active ? 'yes' : 'no', (int) ($useByCat[$r->id] ?? 0)], [22, 30, 10, 8, 8, 10]);

        $supNames = $c->table('suppliers')->pluck('name', 'id');
        $branchNames = $c->table('branches')->pluck('name', 'id');
        $products = $c->table('products')->get(['id', 'sku', 'name'])->keyBy('id');
        $tab('GRN', ['GRN', 'Receipt date', 'Status', 'Supplier', 'Branch', 'SKU', 'Product', 'Qty', 'Unit cost', 'Line total', 'Stock item', 'Extra charges', 'Note', 'Voided at'],
            $c->table('goods_receipt_lines as l')->join('goods_receipts as g', 'g.id', '=', 'l.goods_receipt_id')->orderBy('g.receipt_date')->orderBy('g.id')->orderBy('l.id')
                ->get(['g.grn_no', 'g.receipt_date', 'g.status', 'g.supplier_id', 'g.branch_id', 'g.extra_charges', 'g.voided_at', 'l.product_id', 'l.quantity_received', 'l.unit_cost', 'l.affects_stock', 'l.notes']),
            fn ($r) => [$r->grn_no, $D($r->receipt_date), $r->status, $supNames[$r->supplier_id] ?? '', $branchNames[$r->branch_id] ?? '', $products[$r->product_id]->sku ?? '',
                $products[$r->product_id]->name ?? '', (float) $r->quantity_received, $M($r->unit_cost), $M((float) $r->quantity_received * (float) $r->unit_cost),
                $r->affects_stock ? 'yes' : 'no', $M($r->extra_charges), $r->notes, (string) $r->voided_at], [24, 12, 9, 22, 20, 16, 28, 9, 12, 13, 8, 12, 26, 18]);

        $grnNos = $c->table('goods_receipts')->pluck('grn_no', 'id');
        $tab('Supplier Invoices', ['Invoice', 'Invoice date', 'Supplier invoice no.', 'Supplier', 'GRN', 'Status', 'Goods', 'Extra charges', 'Discount', 'Tax', 'Total', 'Paid', 'Due'],
            $c->table('purchase_bills')->orderBy('bill_date')->orderBy('id')->get(),
            fn ($r) => [$r->bill_no, $D($r->bill_date), $r->supplier_invoice_no, $supNames[$r->supplier_id] ?? '', $grnNos[$r->goods_receipt_id] ?? '', $r->status,
                $M($r->subtotal), $M($r->extra_charges ?? 0), $M($r->discount_total), $M($r->tax_total), $M($r->grand_total), $M($r->amount_paid), $M($r->balance_due)],
            [24, 12, 16, 22, 24, 9, 13, 12, 11, 11, 13, 13, 13]);
        $billNos = $c->table('purchase_bills')->pluck('bill_no', 'id');
        $tab('Invoice Lines', ['Invoice', 'SKU', 'Product', 'Qty', 'Unit cost', 'Line total'],
            $c->table('purchase_bill_lines')->orderBy('purchase_bill_id')->orderBy('id')->get(),
            fn ($r) => [$billNos[$r->purchase_bill_id] ?? '', $products[$r->product_id]->sku ?? '', $products[$r->product_id]->name ?? '', (float) $r->quantity,
                $M($r->unit_cost), $M($r->line_total)], [24, 16, 28, 9, 12, 13]);

        $tab('Supplier Payments', ['Payment', 'Date', 'Supplier', 'Amount', 'Method', 'Paid from', 'Invoice', 'Reference', 'Notes', 'Voided at', 'Void reason'],
            $c->table('supplier_payments')->orderBy('payment_date')->orderBy('id')->get(),
            fn ($r) => [$r->payment_no, $D($r->payment_date), $supNames[$r->supplier_id] ?? '', $M($r->amount), $r->payment_method, $cashNames[$r->cash_bank_account_id] ?? '',
                $billNos[$r->purchase_bill_id] ?? '', $r->reference_no, $r->notes, (string) ($r->voided_at ?? ''), $r->void_reason ?? ''], [24, 12, 22, 13, 12, 18, 24, 14, 24, 18, 20]);

        $tab('Supplier Ledger', ['Supplier', 'Date', 'Typed at', 'Type', 'Reference', 'Debit', 'Credit', 'Balance after (we owe +)', 'Notes'],
            $c->table('supplier_ledgers')->orderBy('supplier_id')->orderByRaw('COALESCE(entry_date, DATE(created_at))')->orderBy('id')->get(),
            fn ($r) => [$supNames[$r->supplier_id] ?? '', $D($r->entry_date ?? $r->created_at), (string) $r->created_at, $r->entry_type, $r->reference_no,
                $r->direction === 'credit' ? $M($r->amount) : '', $r->direction === 'debit' ? $M($r->amount) : '', $M($r->balance_after), $r->notes],
            [22, 12, 18, 18, 24, 13, 13, 16, 30]);

        $tab('Suppliers', ['Code', 'Name', 'Phone', 'Opening balance', 'Balance (we owe +)'], $c->table('suppliers')->orderBy('name')->get(),
            fn ($r) => [$r->code, $r->name, $r->phone ?? '', $M($r->opening_balance), $M($r->current_balance)], [14, 26, 16, 16, 18]);

        $tab('Journal Entries', ['Entry', 'Date', 'Source', 'Source no.', 'Description', 'Head', 'Head name', 'Debit', 'Credit', 'Line', 'Supplier', 'Reversal of'],
            $c->table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->whereRaw("COALESCE(e.source_type, '') NOT LIKE 'sales\_%'")
                ->orderBy('e.entry_date')->orderBy('e.id')->orderBy('l.id')
                ->get(['e.entry_no', 'e.entry_date', 'e.source_type', 'e.source_no', 'e.description', 'e.reversed_entry_id', 'l.account_id', 'l.debit', 'l.credit', 'l.description as line_desc', 'l.supplier_id']),
            fn ($r) => [$r->entry_no, $D($r->entry_date), $r->source_type, $r->source_no, $r->description, $codes[$r->account_id] ?? '',
                $headNames[$r->account_id] ?? '', $M($r->debit), $M($r->credit), $r->line_desc, $supNames[$r->supplier_id] ?? '',
                $r->reversed_entry_id ?: ''], [20, 12, 22, 24, 30, 9, 26, 14, 14, 26, 20, 10]);

        $tab('Opening Balances', ['Batch', 'Date', 'Head', 'Cash/bank', 'Debit', 'Credit', 'Note'],
            $c->table('opening_balance_lines as l')->join('opening_balance_batches as b', 'b.id', '=', 'l.opening_balance_batch_id')->orderBy('b.id')->orderBy('l.id')->get(['b.batch_no', 'b.opening_date', 'b.status', 'l.account_id as head', 'l.cash_bank_account_id as cash', 'l.debit', 'l.credit', 'l.description as note']),
            fn ($r) => [$r->batch_no, $D($r->opening_date), $codes[$r->head] ?? '', $cashNames[$r->cash] ?? '', $M($r->debit), $M($r->credit),
                trim($r->status . ' ' . ($r->note ?? ''))], [16, 12, 10, 18, 14, 14, 30]);

        $tab('Stock Ledger', ['Typed at', 'Branch', 'SKU', 'Product', 'Movement', 'In/Out', 'Qty', 'Unit cost', 'Total', 'Balance after', 'Reference'],
            $c->table('stock_ledgers')->orderBy('id')->get(),
            fn ($r) => [(string) $r->created_at, $branchNames[$r->branch_id] ?? '', $products[$r->product_id]->sku ?? '', $products[$r->product_id]->name ?? '', $r->movement_type,
                $r->direction, (float) $r->quantity, $M($r->unit_cost), $M($r->total_cost), (float) $r->balance_after, $r->reference_no], [18, 18, 16, 28, 16, 7, 10, 12, 13, 12, 24]);

        $tab('Cash Book (non-sale)', ['Date', 'Typed at', 'Cash/bank', 'Type', 'In/Out', 'Amount', 'Balance after', 'Reference', 'Notes'],
            $c->table('cash_bank_account_transactions')->whereRaw("COALESCE(transaction_type, '') NOT LIKE 'sales\_%'")->orderBy('transaction_date')->orderBy('id')->get(),
            fn ($r) => [$D($r->transaction_date), (string) $r->created_at, $cashNames[$r->cash_bank_account_id] ?? '', $r->transaction_type, $r->direction,
                $M($r->amount), $M($r->balance_after), trim(($r->reference_type ?? '') . ' ' . ($r->reference_id ?? '')), $r->notes], [12, 18, 18, 28, 7, 14, 16, 20, 30]);

        $shortCat = $c->table('expense_categories')->where('code', CashShortageExpenseService::CATEGORY_CODE)->value('id');
        $tab('Short Cash (closing)', ['Voucher', 'Business date', 'Status', 'Amount', 'Notes', 'Typed at'],
            $c->table('expense_vouchers as v')->join('expense_voucher_lines as l', 'l.expense_voucher_id', '=', 'v.id')->where('l.expense_category_id', $shortCat ?: 0)
                ->orderBy('v.expense_date')->orderBy('v.id')->get(['v.voucher_no', 'v.expense_date', 'v.status', 'l.line_total', 'v.notes', 'v.created_at']),
            fn ($r) => [$r->voucher_no, $D($r->expense_date), $r->status, $M($r->line_total), $r->notes, (string) $r->created_at], [18, 12, 9, 14, 40, 18]);

        $x->row($summary, [['v' => 'Finance records of ' . ($tenant->business_name ?? $tenant->tenant_code), 's' => SimpleXlsx::TITLE]]);
        $x->row($summary, [['v' => 'Exported ' . now()->toDateTimeString() . ' UTC — sales are not in this file (they stay in the system).', 's' => SimpleXlsx::NOTE]]);
        $x->row($summary, []);
        $x->row($summary, $H(['Tab', 'Rows']));
        foreach ($counts as $name => $n) {
            $x->row($summary, [$name, $n]);
        }

        $tmp = $x->write();
        $out = (string) ($this->option('out') ?: storage_path('app/private/exports/' . $tenant->tenant_code . '-finance-' . now()->format('Ymd_His') . '.xlsx'));
        if (! is_dir(dirname($out))) {
            mkdir(dirname($out), 0775, true);
        }
        rename($tmp, $out);

        foreach ($counts as $name => $n) {
            $this->line(sprintf('  %-24s %d rows', $name, $n));
        }
        $this->info('Written: ' . $out . ' (' . number_format(filesize($out) / 1024, 1) . ' KB)');

        return self::SUCCESS;
    }
}
