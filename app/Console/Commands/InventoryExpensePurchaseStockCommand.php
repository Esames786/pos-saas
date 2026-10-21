<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Models\Tenant\Branch;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductVariant;
use App\Services\Finance\JournalService;
use App\Services\Finance\NonStockCostAccounts;
use App\Services\Inventory\InventoryService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * KHATRI-STOCK-TO-PNL-1 — items a business buys and uses the same day (cooked food from its kitchen,
 * containers) but never counts: make them purchase-only, the way cold drinks already are.
 *
 * Bought as stock, such an item's bills debited 1400 Inventory, and nothing ever credits it again (no
 * recipe, no stock sale), so its cost never reached the P&L. Per product, in ONE transaction:
 *
 *   1. GL — for every posted bill's line of the product, its share of what that bill put into 1400
 *      (the same value split postPurchaseBill() used) moves to the product's category cost head:
 *      Dr cost head / Cr 1400, DATED THE BILL DATE (bought and used that day: September's cost stays in
 *      September). Source grn_line_stock_expense + GRN line id, so a line is moved at most once.
 *   2. Stock — what is left on hand is written out (movement expensed_to_cost, at average cost) through
 *      InventoryService; no row is edited or deleted. Its value is already in step 1.
 *   3. The product stops counting stock (is_stock_tracked = 0; a 'stock_item' consumption becomes
 *      'none', as the product form does), so later receipts and bills go straight to the cost head.
 *
 * A product is REFUSED, never guessed, when: its category maps to no cost head; a receipt of it is not
 * billed yet (its later bill would put it into 1400 again); its stock moved any way other than purchase
 * and adjustments (a sale, return or transfer may have touched the books); its stock is below zero; or
 * the bills' 1400 share and the receipts' stock value differ by more than one rupee.
 *
 * DRY RUN by default: prints everything and writes nothing. --yes applies. On prod, as www-data:
 *   sudo -u www-data php artisan inventory:expense-purchase-stock <tenant> --all-purchased
 */
class InventoryExpensePurchaseStockCommand extends Command
{
    protected $signature = 'inventory:expense-purchase-stock {tenant_code}
        {--products= : product ids or SKUs, comma-separated}
        {--all-purchased : every stock-tracked product received on a posted GRN}
        {--except= : product ids or SKUs to leave exactly as they are}
        {--yes : apply (without it nothing is written)}';

    protected $description = 'Make bought-and-used stock items purchase-only: cost from 1400 to the category cost head on each bill date, stock left written out. Dry run unless --yes.';

    public const SOURCE = 'grn_line_stock_expense';
    public const MOVEMENT = 'expensed_to_cost';

    /** Stock movements that never touch the GL — anything else and the product is left for a person. */
    private const ALLOWED_MOVEMENTS = ['purchase', 'adjustment_in', 'adjustment_out'];

    public function handle(TenancyManager $tenancy, JournalService $journal, InventoryService $inventory): int
    {
        $tenant = Tenant::where('tenant_code', (string) $this->argument('tenant_code'))->first();
        if (! $tenant) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }
        if (! $this->option('products') && ! $this->option('all-purchased')) {
            $this->error('Say which products: --products=<ids or SKUs> or --all-purchased.');

            return self::FAILURE;
        }
        $tenancy->activate($tenant);
        $c = DB::connection('tenant');
        $apply = (bool) $this->option('yes');

        $type = (string) ($c->selectOne("SHOW COLUMNS FROM stock_ledgers WHERE Field = 'movement_type'")->Type ?? '');
        if ($apply && ! str_contains($type, "'" . self::MOVEMENT . "'")) {
            $this->error('stock_ledgers has no "' . self::MOVEMENT . '" movement yet — run the tenant migrations first.');

            return self::FAILURE;
        }
        $inventoryId = (int) $c->table('accounts')->where('code', '1400')->value('id');
        if (! $inventoryId) {
            $this->error('This chart has no 1400 Inventory account.');

            return self::FAILURE;
        }

        $this->line(($apply ? '<comment>APPLYING</comment>' : '<info>DRY RUN</info> — nothing is written; pass --yes to apply.') . ' Tenant ' . $tenant->tenant_code);
        $this->line('1400 Inventory before: ' . $this->money($this->balance($inventoryId)));
        $this->newLine();

        $codes = $c->table('accounts')->pluck('code', 'id')->all();
        $costs = new NonStockCostAccounts;
        $byHeadMonth = [];
        $done = $refused = 0;

        foreach ($this->products() as $product) {
            $plan = $this->plan($product, $costs, $inventoryId);
            $title = sprintf('%s (#%d, %s)', $product->name, $product->id, $product->sku);
            if ($plan['refuse']) {
                $refused++;
                $this->warn('  SKIPPED ' . $title);
                foreach ($plan['refuse'] as $why) {
                    $this->line('      · ' . $why);
                }

                continue;
            }

            $done++;
            $head = $codes[$plan['account']] ?? ('#' . $plan['account']);
            $this->line(sprintf('  %s → %s', $title, $head));
            if ($plan['note'] ?? null) {
                $this->line('      · ' . $plan['note']);
            }
            $months = [];
            foreach ($plan['moves'] as $m) {
                $months[substr($m['date'], 0, 7)] = round(($months[substr($m['date'], 0, 7)] ?? 0) + $m['amount'], 4);
            }
            foreach ($months as $month => $amount) {
                $byHeadMonth[$head][$month] = round(($byHeadMonth[$head][$month] ?? 0) + $amount, 4);
            }
            $this->line('      cost moved by bill month: ' . (collect($months)->map(fn ($v, $k) => $k . ' Dr ' . $this->money($v))->implode(' · ') ?: 'none')
                . sprintf('  (%d bill lines)', count($plan['moves'])));
            $this->line(sprintf('      bills put %s into 1400; its receipts brought %s into stock (difference %s)',
                $this->money($plan['gl']), $this->money($plan['ledger_in']), $this->money($plan['gl'] - $plan['ledger_in'])));
            $this->line(sprintf('      stock written out: %s (value %s)', rtrim(rtrim(number_format($plan['qty'], 3), '0'), '.') . ' ' . ($product->unit?->name ?? ''), $this->money($plan['onhand_value'])));

            if ($apply) {
                $c->transaction(fn () => $this->apply($product, $plan, $journal, $inventory));
            }
        }

        $this->newLine();
        $this->line("Products made purchase-only: {$done} · skipped: {$refused}" . ($apply ? '' : ' (dry run)'));
        foreach ($byHeadMonth as $head => $months) {
            ksort($months);
            $this->line(sprintf('  %s  %s', $head, collect($months)->map(fn ($v, $k) => $k . ' Dr ' . $this->money($v))->implode(' · ')));
        }
        $this->line('1400 Inventory ' . ($apply ? 'after: ' : 'after (if applied): ')
            . $this->money($apply ? $this->balance($inventoryId) : $this->balance($inventoryId) - array_sum(array_map('array_sum', $byHeadMonth))));
        $tb = (float) $c->table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.status', 'posted')->selectRaw('ROUND(COALESCE(SUM(l.debit) - SUM(l.credit), 0), 2) v')->value('v');
        $this->line('tb_diff: ' . $this->money($tb));

        return self::SUCCESS;
    }

    /** @return Collection<int, Product> */
    private function products(): Collection
    {
        $tokens = fn (?string $s) => collect(explode(',', (string) $s))->map(fn ($t) => trim($t))->filter()->values();
        $match = function ($q, Collection $t) {
            $ids = $t->filter(fn ($x) => ctype_digit($x))->map(fn ($x) => (int) $x)->all();
            $q->where(fn ($w) => $w->whereIn('id', $ids ?: [0])->orWhereIn('sku', $t->all() ?: ['']));
        };

        $query = Product::query()->with('unit');
        if ($this->option('products')) {
            $match($query, $tokens($this->option('products')));
        } else {
            // stock-tracked items — and items whose tracking was switched off with stock still left (the old form allowed it)
            $query->where(fn ($w) => $w->where('is_stock_tracked', true)
                ->orWhereIn('id', DB::connection('tenant')->table('stock_balances')->where('quantity_on_hand', '!=', 0)->select('product_id')))
                ->whereIn('id', DB::connection('tenant')->table('goods_receipt_lines as gl')
                ->join('goods_receipts as g', 'g.id', '=', 'gl.goods_receipt_id')->where('g.status', 'posted')
                ->where(fn ($w) => $w->whereNull('gl.affects_stock')->orWhere('gl.affects_stock', true))
                ->distinct()->select('gl.product_id'));
        }
        if ($this->option('except')) {
            $except = Product::query();
            $match($except, $tokens($this->option('except')));
            $query->whereNotIn('id', $except->pluck('id')->all() ?: [0]);
        }

        return $query->orderBy('name')->get();
    }

    /** Everything this product would get — reads only. */
    private function plan(Product $product, NonStockCostAccounts $costs, int $inventoryId): array
    {
        $c = DB::connection('tenant');
        $refuse = [];
        $note = null;
        if (! $product->is_stock_tracked) {
            // Tracking switched off while stock was left (Khatri #94, 21 Oct, through the old form): its stock and its
            // bills' share of 1400 stay behind for ever. Clean those up the same way; otherwise there is nothing to do.
            $left = abs((float) $c->table('stock_balances')->where('product_id', $product->id)->sum('quantity_on_hand'));
            $unmoved = $c->table('goods_receipt_lines as gl')->join('goods_receipts as g', 'g.id', '=', 'gl.goods_receipt_id')
                ->where('g.status', 'posted')->where('gl.product_id', $product->id)
                ->where(fn ($w) => $w->whereNull('gl.affects_stock')->orWhere('gl.affects_stock', true))
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('purchase_bills as b')->whereColumn('b.goods_receipt_id', 'g.id'))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('journal_entries as e')->where('e.source_type', self::SOURCE)->whereColumn('e.source_id', 'gl.id'))
                ->exists();
            if ($left <= 0.0005 && ! $unmoved) {
                return ['refuse' => ['already purchase-only (not counting stock) — nothing to do']];
            }
            $note = 'stock tracking was already switched off, but its stock and its cost in 1400 were left behind — cleaned up now';
        }

        $account = $this->explicitCostAccount($product, $costs);
        if (! $account) {
            $refuse[] = 'no cost head: give it a category mapped to a cost account (Catalog → Categories) first';
        }
        $other = $c->table('stock_ledgers')->where('product_id', $product->id)->whereNotIn('movement_type', self::ALLOWED_MOVEMENTS)
            ->distinct()->pluck('movement_type')->all();
        if ($other) {
            $refuse[] = 'its stock also moved by ' . implode(', ', $other) . ' — those may have touched the books; left for review';
        }
        if ($c->table('stock_balances')->where('product_id', $product->id)->where('quantity_on_hand', '<', 0)->exists()) {
            $refuse[] = 'its stock is below zero somewhere — count it first';
        }

        // The receipts that brought it into stock, and the posted (not reversed) bill of each.
        $grnIds = $c->table('goods_receipt_lines as gl')->join('goods_receipts as g', 'g.id', '=', 'gl.goods_receipt_id')
            ->where('g.status', 'posted')->where('gl.product_id', $product->id)
            ->where(fn ($w) => $w->whereNull('gl.affects_stock')->orWhere('gl.affects_stock', true))
            ->distinct()->pluck('g.id')->map(fn ($id) => (int) $id)->all();
        $billed = [];
        foreach ($c->table('purchase_bills')->whereIn('goods_receipt_id', $grnIds ?: [0])->get() as $bill) {
            $entry = JournalEntry::where('source_type', 'purchase_bill')->where('source_id', $bill->id)->where('is_reversal', false)
                ->where('status', 'posted')->first();
            if ($entry && ! JournalEntry::where('reversed_entry_id', $entry->id)->exists()) {
                $billed[(int) $bill->goods_receipt_id] = [$bill, $entry];
            }
        }
        $unbilled = array_diff($grnIds, array_keys($billed));
        if ($unbilled) {
            $refuse[] = 'received but not billed yet: ' . $c->table('goods_receipts')->whereIn('id', $unbilled)->orderBy('id')->pluck('grn_no')->implode(', ')
                . ' — bill (or void) these first, or their bill would put it into 1400 again';
        }
        if ($refuse) {
            return ['refuse' => $refuse];
        }

        $moves = [];
        $gl = 0.0;
        foreach ($billed as $grnId => [$bill, $entry]) {
            $inv = (float) $c->table('journal_lines')->where('journal_entry_id', $entry->id)->where('account_id', $inventoryId)
                ->sum(DB::raw('debit - credit'));
            $grn = GoodsReceipt::with('lines')->findOrFail($grnId);
            foreach ($this->shares($grn, round($inv, 4), round((float) $bill->grand_total, 4)) as $lineId => $share) {
                $line = $grn->lines->firstWhere('id', $lineId);
                if ((int) $line->product_id !== (int) $product->id || $share <= 0) {
                    continue;
                }
                $gl += $share;
                if (JournalEntry::where('source_type', self::SOURCE)->where('source_id', $lineId)->exists()) {
                    continue;   // moved already
                }
                $moves[] = ['line_id' => (int) $lineId, 'bill_no' => (string) $bill->bill_no, 'branch_id' => $bill->branch_id ? (int) $bill->branch_id : null,
                    'date' => substr((string) $bill->bill_date, 0, 10), 'amount' => $share];
            }
        }

        $ledgerIn = (float) $c->table('stock_ledgers')->where('product_id', $product->id)->where('movement_type', 'purchase')
            ->where('direction', 'in')->where('reference_type', GoodsReceipt::class)->whereIn('reference_id', array_keys($billed) ?: [0])
            ->sum('total_cost');
        if (abs($gl - $ledgerIn) > 1.0) {
            return ['refuse' => [sprintf('the books and the stock disagree: its bills put %s into 1400, its receipts brought %s into stock — left for review',
                $this->money($gl), $this->money($ledgerIn))]];
        }

        $onHand = $c->table('stock_balances')->where('product_id', $product->id)->where('quantity_on_hand', '>', 0)
            ->groupBy('branch_id', 'product_variant_id')
            ->selectRaw('branch_id, product_variant_id, SUM(quantity_on_hand) qty, SUM(quantity_on_hand * average_cost) value')->get();

        return ['refuse' => [], 'note' => $note, 'account' => $account, 'moves' => $moves, 'gl' => round($gl, 4), 'ledger_in' => round($ledgerIn, 4),
            'onhand' => $onHand, 'qty' => (float) $onHand->sum('qty'), 'onhand_value' => round((float) $onHand->sum('value'), 4)];
    }

    private function apply(Product $product, array $plan, JournalService $journal, InventoryService $inventory): void
    {
        foreach ($plan['moves'] as $m) {
            $journal->post(self::SOURCE, $m['line_id'], $m['bill_no'],
                'Purchase-only now, cost on the bill date: ' . $product->name . ' (' . $m['bill_no'] . ')', $m['date'], [
                    ['account_id' => $plan['account'], 'branch_id' => $m['branch_id'], 'description' => 'Bought and used: ' . $product->name, 'debit' => $m['amount'], 'credit' => 0],
                    ['account_code' => '1400', 'branch_id' => $m['branch_id'], 'description' => 'Out of inventory: ' . $product->name, 'debit' => 0, 'credit' => $m['amount']],
                ]);
        }
        // InventoryService takes stock out only of an item that counts stock: an item switched off with stock left counts it
        // again for this write-out, in memory only — it is saved as not counting stock just below, in the same transaction.
        $product->is_stock_tracked = true;
        foreach ($plan['onhand'] as $b) {
            $inventory->postOutFefo(
                Branch::findOrFail($b->branch_id),
                $product,
                $b->product_variant_id ? ProductVariant::findOrFail($b->product_variant_id) : null,
                (float) $b->qty,
                self::MOVEMENT,
                'inventory_expense',
                $product->id,
                'EXPENSED-' . $product->id,
                'inventory:expense-purchase-stock — its cost is already on the bill dates; purchase-only from now',
            );
        }
        $product->is_stock_tracked = false;
        if ($product->inventory_consumption_method === 'stock_item') {
            $product->inventory_consumption_method = 'none';   // as the product form does for an item that counts no stock
        }
        $product->save();
    }

    /**
     * What each stocked line of a receipt put into 1400 through its bill — postPurchaseBill()'s own split by
     * line value. A bill posted before purchase-only lines existed sent everything to 1400; then the base is
     * the whole receipt. Rounding goes to the largest line, so the parts add up to the bill's 1400 exactly.
     *
     * @return array<int, float> GRN line id => amount
     */
    private function shares(GoodsReceipt $grn, float $inv, float $grandTotal): array
    {
        $value = fn ($l) => (float) $l->quantity_received * (float) $l->unit_cost;
        $stock = $grn->lines->filter(fn ($l) => $l->affects_stock !== false);
        $stockValue = $stock->sum($value);
        $totalValue = $grn->lines->sum($value);
        $oldPosting = abs($inv - $grandTotal) < 0.01 && $stockValue < $totalValue - 0.0001;
        $base = $oldPosting ? $totalValue : $stockValue;
        if ($inv <= 0 || $base <= 0) {
            return [];
        }
        $out = [];
        foreach ($stock as $l) {
            $out[(int) $l->id] = round($inv * $value($l) / $base, 4);
        }
        if (! $oldPosting && $out) {
            $residual = round($inv - array_sum($out), 4);
            $largest = array_keys($out, max($out))[0];
            $out[$largest] = round($out[$largest] + $residual, 4);
        }

        return $out;
    }

    /** The category's own cost head, else its nearest ancestor's — never the 5100 fallback: that is a choice to make on screen. */
    private function explicitCostAccount(Product $product, NonStockCostAccounts $costs): ?int
    {
        $categories = DB::connection('tenant')->table('categories')->get(['id', 'parent_id', 'cogs_account_id'])->keyBy('id');
        $id = $product->category_id ? (int) $product->category_id : null;
        $seen = [];
        while ($id && isset($categories[$id]) && ! isset($seen[$id])) {
            $seen[$id] = true;
            $account = $categories[$id]->cogs_account_id ? (int) $categories[$id]->cogs_account_id : null;
            if ($account && $costs->isAllowed($account)) {
                return $account;
            }
            $id = $categories[$id]->parent_id ? (int) $categories[$id]->parent_id : null;
        }

        return null;
    }

    private function balance(int $accountId): float
    {
        return (float) DB::connection('tenant')->table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.status', 'posted')->where('l.account_id', $accountId)->sum(DB::raw('l.debit - l.credit'));
    }

    private function money(float $v): string
    {
        return number_format($v, 2);
    }
}
