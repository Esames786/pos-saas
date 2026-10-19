<?php

namespace App\Services\Finance;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PNL-COST-BY-CATEGORY-1 — which cost-of-sales account a purchase-only line belongs to.
 *
 * GRN-NON-STOCK-1 sent the whole purchase-only share of a bill to ONE account, 5100 Product COGS, so
 * Khatri's P&L showed cold drinks, mineral water and everything else bought without stock as one row.
 * A category may now name its own cost account (an active expense account coded 5…, not a group head);
 * a child category with none uses its nearest ancestor's; with none anywhere, 5100 — exactly as before.
 * Nothing changes until someone maps a category.
 *
 * Only purchase-only (non-stock) lines of bills and purchase returns read this. Sale-side COGS does not.
 */
class NonStockCostAccounts
{
    public const DEFAULT_CODE = '5100';

    private ?array $categories = null;      // id => ['parent' => ?int, 'account' => ?int]
    private ?array $allowed = null;         // account id => true
    private bool $defaultLoaded = false;
    private ?int $defaultId = null;
    private array $byProduct = [];

    /** Accounts a category may point at: active expense accounts coded 5…, excluding group heads. */
    public static function choices(): Collection
    {
        $c = DB::connection('tenant');
        $heads = $c->table('accounts')->whereNotNull('parent_id')->distinct()->pluck('parent_id')->all();

        return $c->table('accounts')
            ->where('is_active', true)->where('type', 'expense')->where('code', 'like', '5%')
            ->whereNotIn('id', $heads ?: [0])
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    public function isAllowed(?int $accountId): bool
    {
        $this->allowed ??= self::choices()->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();

        return $accountId !== null && isset($this->allowed[$accountId]);
    }

    /** 5100's id, or null when this tenant's chart has no 5100 (the caller then posts by code, as before). */
    public function defaultId(): ?int
    {
        if (! $this->defaultLoaded) {
            $id = DB::connection('tenant')->table('accounts')->where('code', self::DEFAULT_CODE)->value('id');
            $this->defaultId = $id ? (int) $id : null;
            $this->defaultLoaded = true;
        }

        return $this->defaultId;
    }

    public function forProduct(?int $productId): ?int
    {
        if (! $productId) {
            return $this->defaultId();
        }
        if (! array_key_exists($productId, $this->byProduct)) {
            $categoryId = DB::connection('tenant')->table('products')->where('id', $productId)->value('category_id');
            $this->byProduct[$productId] = $this->forCategory($categoryId ? (int) $categoryId : null);
        }

        return $this->byProduct[$productId];
    }

    /** The category's own account, else its nearest ancestor's, else 5100. A bad pointer is skipped, never trusted. */
    public function forCategory(?int $categoryId): ?int
    {
        $this->categories ??= DB::connection('tenant')->table('categories')->get(['id', 'parent_id', 'cogs_account_id'])
            ->mapWithKeys(fn ($c) => [(int) $c->id => [
                'parent'  => $c->parent_id ? (int) $c->parent_id : null,
                'account' => $c->cogs_account_id ? (int) $c->cogs_account_id : null,
            ]])->all();

        $seen = [];
        while ($categoryId && isset($this->categories[$categoryId]) && ! isset($seen[$categoryId])) {
            $seen[$categoryId] = true;
            $account = $this->categories[$categoryId]['account'];
            if ($account !== null && $this->isAllowed($account)) {
                return $account;
            }
            $categoryId = $this->categories[$categoryId]['parent'];
        }

        return $this->defaultId();
    }

    /**
     * The account a returned purchase-only line credits: the one its bill debited for that GRN line, so
     * a later re-mapping never moves a return to a head the bill never touched. A GRN line with no record
     * was billed before per-category posting (→ 5100) — unless it has not been billed yet, in which case
     * the bill to come will follow today's mapping, and so does the return.
     */
    public function forReturnLine($line): ?int
    {
        if (($line->source_line_type ?? null) === 'goods_receipt_line' && $line->source_line_id) {
            $grnLine = DB::connection('tenant')->table('goods_receipt_lines')
                ->where('id', $line->source_line_id)->first(['goods_receipt_id', 'cost_account_id']);
            if ($grnLine) {
                if ($grnLine->cost_account_id) {
                    return (int) $grnLine->cost_account_id;
                }
                if ($this->receiptBilled((int) $grnLine->goods_receipt_id)) {
                    return $this->defaultId();
                }
            }
        }

        return $this->forProduct($line->product_id ? (int) $line->product_id : null);
    }

    private function receiptBilled(int $goodsReceiptId): bool
    {
        $c = DB::connection('tenant');
        $billIds = $c->table('purchase_bills')->where('goods_receipt_id', $goodsReceiptId)->pluck('id')->all();

        return $billIds && $c->table('journal_entries')->where('source_type', 'purchase_bill')->whereIn('source_id', $billIds)->exists();
    }

    /**
     * Split $toCost across the purchase-only lines by their value, one total per account. The total is
     * GRN-NON-STOCK-1's own number (nonStockShare), so charges, discount and tax keep following value
     * pro-rata; this only decides which account each line's part lands on. Rounding is pushed onto the
     * largest account so the parts add up to $toCost exactly — the journal still balances to the paisa.
     *
     * @param  list<array{value: float, stock: bool, account: ?int}>  $lines
     * @return array<int, float>  account id (0 = 5100 by code, for a chart without one) => amount
     */
    public static function split(array $lines, float $toCost): array
    {
        $toCost = round($toCost, 4);
        $nonStock = array_values(array_filter($lines, fn ($l) => ! $l['stock']));
        if ($toCost <= 0 || ! $nonStock) {
            return [];
        }

        $value = array_sum(array_column($nonStock, 'value'));
        $out = [];
        foreach ($nonStock as $l) {
            $share = $value > 0 ? round($toCost * $l['value'] / $value, 4) : round($toCost / count($nonStock), 4);
            $key = (int) ($l['account'] ?? 0);
            $out[$key] = round(($out[$key] ?? 0) + $share, 4);
        }

        $residual = round($toCost - array_sum($out), 4);
        if ($residual != 0.0) {
            $largest = array_keys($out, max($out))[0];
            $out[$largest] = round($out[$largest] + $residual, 4);
        }

        return $out;
    }
}
