<?php

namespace App\Services\Finance;

/**
 * OFFLINE EDGE — D1 (owner decision, 20 Oct 2026): the chart snapshot ManualJournalRules reads.
 *
 * Accounts (with their parent, so a group head is recognisable) and the cash/bank drawers (with the GL account each
 * is linked to). Pure data, no models, no connection: the Cloud builds it from the tenant `accounts` /
 * `cash_bank_accounts` tables (ManualJournalService), the Branch Server from its supplier-finance replica
 * (EdgeLocalSupplierFinanceService) — so the SAME rules run on both from the SAME shape and produce the SAME messages.
 * This file ships to the appliance; ManualJournalService (the Cloud posting authority) does not.
 */
final class ManualJournalChart
{
    /** @var array<int, array{id:int,code:string,name:string,parent_id:?int,is_active:bool}> keyed by id, in display order */
    private array $accounts = [];

    /** @var array<int, array{id:int,name:string,account_id:?int,is_active:bool}> keyed by id */
    private array $drawers = [];

    /** @var array<int, true> account ids that are somebody's parent (group heads) */
    private array $groupIds = [];

    /**
     * @param iterable<array> $accounts each: id, code, name, parent_id, is_active — in display order (sort_order, code),
     *                                  because the R3 hint names the FIRST active child in that order
     * @param iterable<array> $drawers  each: id, name, account_id (the linked GL account, null when unlinked), is_active
     */
    public function __construct(iterable $accounts, iterable $drawers)
    {
        foreach ($accounts as $a) {
            $a = (array) $a;
            $id = (int) ($a['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $parent = isset($a['parent_id']) && $a['parent_id'] !== null && (int) $a['parent_id'] > 0 ? (int) $a['parent_id'] : null;
            $this->accounts[$id] = [
                'id' => $id,
                'code' => (string) ($a['code'] ?? ''),
                'name' => (string) ($a['name'] ?? ''),
                'parent_id' => $parent,
                'is_active' => (bool) ($a['is_active'] ?? true),
            ];
            if ($parent !== null) {
                $this->groupIds[$parent] = true;
            }
        }
        foreach ($drawers as $d) {
            $d = (array) $d;
            $id = (int) ($d['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $this->drawers[$id] = [
                'id' => $id,
                'name' => (string) ($d['name'] ?? ''),
                'account_id' => isset($d['account_id']) && $d['account_id'] !== null && (int) $d['account_id'] > 0 ? (int) $d['account_id'] : null,
                'is_active' => (bool) ($d['is_active'] ?? false),
            ];
        }
    }

    /** @return array{id:int,code:string,name:string,parent_id:?int,is_active:bool}|null */
    public function account(int $id): ?array
    {
        return $this->accounts[$id] ?? null;
    }

    /** "1110 Main Cash Drawer", or the canonical fallback when the chart does not know the account. */
    public function label(int $id): string
    {
        $a = $this->account($id);

        return $a ? $a['code'] . ' ' . $a['name'] : 'this account';
    }

    /** R3 — an account that has accounts under it is a group head. */
    public function isGroup(int $id): bool
    {
        return isset($this->groupIds[$id]);
    }

    /** @return array{id:int,code:string,name:string,parent_id:?int,is_active:bool}|null the first ACTIVE child in display order */
    public function firstActiveChild(int $id): ?array
    {
        foreach ($this->accounts as $a) {
            if ($a['parent_id'] === $id && $a['is_active']) {
                return $a;
            }
        }

        return null;
    }

    /** @return array{id:int,name:string,account_id:?int,is_active:bool}|null */
    public function drawer(int $id): ?array
    {
        return $this->drawers[$id] ?? null;
    }

    /** @return list<array{id:int,name:string,account_id:?int,is_active:bool}> the ACTIVE drawers linked to this GL account */
    public function activeDrawersFor(int $accountId): array
    {
        $out = [];
        foreach ($this->drawers as $d) {
            if ($d['is_active'] && $d['account_id'] === $accountId) {
                $out[] = $d;
            }
        }

        return $out;
    }
}
