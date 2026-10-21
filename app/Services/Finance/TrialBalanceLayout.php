<?php

namespace App\Services\Finance;

use App\Models\Tenant\Account;
use Illuminate\Support\Collection;

/**
 * TB-SECTION-HEADS-1 — the Trial Balance as an accountant reads it: under the heads of the books
 * (AccountSections), each parent account a bold head with its children indented beneath, and a
 * subtotal per head. Display only: it takes FinancialExportService::trialBalancePeriod() rows as they
 * are and never changes a figure; the grand totals and the difference stay that method's.
 *
 * One place for the screen, the print and the CSV, so the three always show the same thing.
 *
 *   line kinds: head    — a parent: its own postings + every shown descendant, summed
 *               account — an account with postings and no shown children (the row as today)
 *               direct  — a parent's OWN postings, as "<name> (direct)", so a head equals the rows under it
 *
 * A parent shows whenever a descendant does, even with no postings of its own. A bad tree never
 * breaks the page: a parent that is the account itself, missing, under another head, part of a loop
 * or more than MAX_DEPTH deep makes that account a top-level row of its own section.
 */
class TrialBalanceLayout
{
    public const MAX_DEPTH = 10;

    /**
     * @param  list<array<string,mixed>>  $rows  trialBalancePeriod()['rows']
     * @param  Collection<int, Account>|null  $accounts  the chart (all of it); read when not given
     * @return list<array{key:string, label:string, lines:list<array<string,mixed>>, subtotal:array<string,float>}>
     */
    public function sections(array $rows, ?Collection $accounts = null): array
    {
        if (! $rows) {
            return [];
        }
        $accounts ??= Account::query()->get(['id', 'code', 'name', 'type', 'normal_balance', 'parent_id']);
        $byId = $accounts->keyBy('id');
        $own = [];
        foreach ($rows as $r) {
            $own[(int) $r['account_id']] = $r;
        }
        // An account on the TB that the chart no longer lists still shows, as a top-level row.
        foreach ($own as $id => $r) {
            if (! $byId->has($id)) {
                $byId->put($id, new Account(['code' => $r['code'], 'name' => $r['name'], 'type' => $r['type']]));
                $byId[$id]->id = $id;
            }
        }

        $section = fn (int $id): string => AccountSections::of($byId[$id]->type, (string) $byId[$id]->code);
        $children = [];
        $roots = [];
        foreach ($byId as $id => $a) {
            $p = (int) ($a->parent_id ?? 0);
            if ($p && $p !== $id && $byId->has($p) && $section($p) === $section($id)) {
                $children[$p][] = $id;
            } else {
                $roots[] = $id;
            }
        }

        // Walk down from the roots; whatever a walk never reaches (a loop) or reaches too deep becomes a root.
        $depthOf = [];
        $parentOf = [];
        $walk = function (int $id, int $depth, ?int $parent) use (&$walk, &$depthOf, &$parentOf, &$children, &$roots): void {
            if (isset($depthOf[$id])) {
                return;
            }
            if ($depth > self::MAX_DEPTH) {
                $roots[] = $id;

                return;
            }
            $depthOf[$id] = $depth;
            $parentOf[$id] = $parent;
            foreach ($children[$id] ?? [] as $c) {
                $walk($c, $depth + 1, $id);
            }
        };
        for ($i = 0; $i < count($roots); $i++) {
            $walk($roots[$i], 0, null);
            if ($i === count($roots) - 1) {
                foreach ($byId->keys() as $id) {
                    if (! isset($depthOf[$id])) {
                        $roots[] = $id;   // a loop: cut it here
                        break;
                    }
                }
            }
        }
        $kids = [];
        foreach ($parentOf as $id => $p) {
            if ($p !== null) {
                $kids[$p][] = $id;
            }
        }
        $byCode = fn (array $ids): array => collect($ids)->sortBy(fn ($id) => (string) $byId[$id]->code, SORT_STRING)->values()->all();

        // Shown = has postings, or has a shown descendant.
        $shown = [];
        $isShown = function (int $id) use (&$isShown, &$shown, &$kids, $own): bool {
            if (! isset($shown[$id])) {
                $shown[$id] = isset($own[$id]);
                foreach ($kids[$id] ?? [] as $c) {
                    $shown[$id] = $isShown($c) || $shown[$id];
                }
            }

            return $shown[$id];
        };

        $zero = ['opening' => 0.0, 'period_debit' => 0.0, 'period_credit' => 0.0, 'closing' => 0.0];
        $amountsOf = fn (array $r): array => [
            'opening'       => (float) $r['opening_debit'] - (float) $r['opening_credit'],
            'period_debit'  => (float) $r['period_debit'],
            'period_credit' => (float) $r['period_credit'],
            'closing'       => (float) $r['closing_debit'] - (float) $r['closing_credit'],
        ];
        $add = function (array $a, array $b): array {
            foreach ($a as $k => $v) {
                $a[$k] = $v + $b[$k];
            }

            return $a;
        };
        $line = function (string $kind, int $depth, int $id, string $name, array $m) use ($byId): array {
            [$od, $oc] = [max($m['opening'], 0.0), max(-$m['opening'], 0.0)];
            [$cd, $cc] = [max($m['closing'], 0.0), max(-$m['closing'], 0.0)];

            return [
                'kind' => $kind, 'depth' => $depth, 'account_id' => $id, 'code' => (string) $byId[$id]->code, 'name' => $name,
                'opening_debit' => round($od, 4), 'opening_credit' => round($oc, 4),
                'period_debit' => round($m['period_debit'], 4), 'period_credit' => round($m['period_credit'], 4),
                'closing_debit' => round($cd, 4), 'closing_credit' => round($cc, 4),
            ];
        };

        // Emit one account's lines; returns its summed amounts (own + shown descendants).
        $emit = function (int $id, int $depth, array &$out) use (&$emit, $isShown, &$kids, $own, $byId, $byCode, $zero, $amountsOf, $add, $line): array {
            $shownKids = array_values(array_filter($byCode($kids[$id] ?? []), $isShown));
            $mine = isset($own[$id]) ? $amountsOf($own[$id]) : $zero;
            if (! $shownKids) {
                $out[] = $line('account', $depth, $id, (string) $byId[$id]->name, $mine);

                return $mine;
            }
            $at = count($out);
            $out[] = null;   // the head, filled once its children are summed
            $sum = $mine;
            if (isset($own[$id])) {
                $out[] = $line('direct', $depth + 1, $id, $byId[$id]->name . ' (direct)', $mine);
            }
            foreach ($shownKids as $c) {
                $sum = $add($sum, $emit($c, $depth + 1, $out));
            }
            $out[$at] = $line('head', $depth, $id, (string) $byId[$id]->name, $sum);

            return $sum;
        };

        $bySection = [];
        foreach ($byCode(array_values(array_unique($roots))) as $id) {
            if ($isShown($id)) {
                $bySection[$section($id)][] = $id;
            }
        }

        $sections = [];
        foreach (AccountSections::LABELS as $key => $label) {
            if (empty($bySection[$key])) {
                continue;
            }
            $lines = [];
            $total = $zero;
            foreach ($bySection[$key] as $id) {
                $total = $add($total, $emit($id, 0, $lines));
            }
            $sub = $line('subtotal', 0, $bySection[$key][0], 'Total ' . $label, $total);
            $sections[] = ['key' => $key, 'label' => $label, 'lines' => $lines,
                'subtotal' => array_intersect_key($sub, array_flip(['opening_debit', 'opening_credit', 'period_debit', 'period_credit', 'closing_debit', 'closing_credit']))];
        }

        return $sections;
    }
}
