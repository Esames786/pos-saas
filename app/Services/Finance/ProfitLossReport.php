<?php

namespace App\Services\Finance;

use App\Models\Tenant\Account;

/**
 * PNL-DETAIL-1 / FINANCE-PDF-DOWNLOADS-1 — the Profit & Loss as an accountant reads it, built ONCE for the screen, the
 * CSV and the PDF so the three can never disagree. Display only: every total stays ProfitLossService::statement()'s.
 *
 *  R1  Each part of the statement — revenue, sales discounts, cost of sales, operating expenses — is laid out with the
 *      Trial Balance's own tree (TrialBalanceLayout, TB-SECTION-HEADS-1); no second grouping. A parent is a bold head
 *      equal to itself + its shown descendants, its own postings a "<name> (direct)" row, its children indented. Each
 *      part goes in on its own, so Gross Revenue, Net Revenue, Gross and Net Profit are exactly statement()'s.
 *  R2  Under each cost / expense account (never under a head) its parts for the period, from the Trial Balance's party
 *      split (TrialBalancePartyService) — expense category, supplier, or "Other" — so the P&L's detail and the Trial
 *      Balance's are the same numbers. They add up to the account: a remainder goes on "Other", never dropped.
 *      Largest first, "Other" last. An account whose only part would be "Other" for all of it shows no detail: one line
 *      repeating the account says nothing. Revenue: no detail in v1.
 */
class ProfitLossReport
{
    public const OTHER = 'Other';

    /** The statement's parts, in order, with the rows statement() returns for each. */
    public const PARTS = [
        'revenue'       => 'revenue_rows',
        'discounts'     => 'discount_rows',
        'cost_of_sales' => 'cogs_rows',
        'expenses'      => 'expense_rows',
    ];

    private const WITH_DETAIL = ['cost_of_sales', 'expenses'];

    public function __construct(
        private readonly TrialBalanceLayout $layout,
        private readonly TrialBalancePartyService $parties,
    ) {}

    /**
     * @param  array<string,mixed>  $pl  ProfitLossService::statement()
     * @return array{detail: bool, parts: array<string, list<array{kind:string, depth:int, account_id:int, code:string,
     *               name:string, amount:float, detail:list<array{label:string, amount:float}>}>>}
     */
    public function build(array $pl, ?array $branchIds, bool $detail): array
    {
        $accounts = Account::query()->get(['id', 'code', 'name', 'type', 'normal_balance', 'parent_id']);
        $typeOf = $accounts->pluck('type', 'id');

        $parties = [];
        if ($detail) {
            $ids = array_merge(array_column($pl['cogs_rows'], 'account_id'), array_column($pl['expense_rows'], 'account_id'));
            $parties = $ids ? $this->parties->partiesFor($pl['period']['from'], $pl['period']['to'], $branchIds, $ids) : [];
        }

        $parts = [];
        foreach (self::PARTS as $part => $rowsKey) {
            // The layout sums debit and credit separately; one side per row keeps every head = the rows under it.
            $rows = array_map(fn (array $r) => [
                'account_id' => (int) $r['account_id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'],
                'type' => (string) ($typeOf[$r['account_id']] ?? 'expense'),
                'opening_debit' => 0.0, 'opening_credit' => 0.0,
                'period_debit' => max((float) $r['amount'], 0.0), 'period_credit' => max(-(float) $r['amount'], 0.0),
                'closing_debit' => 0.0, 'closing_credit' => 0.0,
            ], $pl[$rowsKey]);

            $lines = [];
            foreach ($this->layout->sections($rows, $accounts) as $section) {
                foreach ($section['lines'] as $l) {
                    $amount = round((float) $l['period_debit'] - (float) $l['period_credit'], 4);
                    $lines[] = [
                        'kind' => $l['kind'], 'depth' => (int) $l['depth'], 'account_id' => (int) $l['account_id'],
                        'code' => (string) $l['code'], 'name' => (string) $l['name'], 'amount' => $amount,
                        'detail' => $detail && $l['kind'] !== 'head' && in_array($part, self::WITH_DETAIL, true)
                            ? $this->detailOf($parties[$l['account_id']] ?? [], $amount) : [],
                    ];
                }
            }
            $parts[$part] = $lines;
        }

        return ['detail' => $detail, 'parts' => $parts];
    }

    /**
     * An account's parts for the period, adding up to it exactly.
     *
     * @param  list<array<string,mixed>>  $parties  TrialBalancePartyService rows for one account
     * @return list<array{label:string, amount:float}>
     */
    private function detailOf(array $parties, float $amount): array
    {
        $lines = [];
        foreach ($parties as $p) {
            $v = round((float) $p['period_debit'] - (float) $p['period_credit'], 4);
            // The service's catch-alls ("~…") join the remainder below as one "Other".
            if (abs($v) < 0.005 || str_starts_with((string) $p['key'], '~')) {
                continue;
            }
            $lines[] = ['label' => (string) $p['label'], 'amount' => $v];
        }
        if (! $lines) {
            return [];
        }
        usort($lines, fn ($a, $b) => [$b['amount'], $a['label']] <=> [$a['amount'], $b['label']]);
        $rest = round($amount - array_sum(array_column($lines, 'amount')), 4);
        if (abs($rest) >= 0.005) {
            $lines[] = ['label' => self::OTHER, 'amount' => $rest];
        }

        return $lines;
    }
}
