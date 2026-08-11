<?php

namespace App\Services\Security;

use App\Models\Tenant\User;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Terminal;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * USER-DATA-SCOPE-1 (Khatri go-live 2026-08-10): a counter operator may only ever see the sales
 * that belong to HIS terminal and HIS permitted order types.
 *
 * Two independent restrictions, both read from the user record (never from the request):
 *   - bound terminals  (terminal_user pivot)   → sales_orders.terminal_id must be one of them
 *   - allowed_order_types (subset of all)      → sales_orders.order_type must be one of them
 *
 * A user with no terminal binding and no order-type restriction is unscoped (owner/manager).
 * This is enforced in the QUERY, so a hand-edited filter in the URL cannot widen it.
 */
class UserDataScope
{
    /** Active branch assignments; empty means the user is not branch-restricted. */
    public function branchIds(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return $user->branches()
            ->wherePivot('is_active', true)
            ->pluck('branches.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function hasBranchAssignments(?User $user): bool
    {
        return (bool) ($user?->branches()->exists());
    }

    /** Terminal ids this user is restricted to; empty = no terminal restriction. */
    public function terminalIds(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return $user->terminals()->pluck('terminals.id')->map(fn ($id) => (int) $id)->all();
    }

    public function branchesForPos(?User $user)
    {
        $query = Branch::where('status', 'active');
        if ($this->hasBranchAssignments($user)) {
            $query->whereIn('id', $this->branchIds($user));
        } elseif ($terminalIds = $this->terminalIds($user)) {
            $query->whereIn('id', Terminal::whereIn('id', $terminalIds)->pluck('branch_id'));
        }

        return $query->orderBy('name')->get();
    }

    public function terminalsForPos(?User $user, array $branchIds = [])
    {
        $query = Terminal::where('status', 'active')->with('branch');
        if ($ids = $this->terminalIds($user)) {
            $query->whereIn('id', $ids);
        }
        if ($branchIds === [] && $this->hasBranchAssignments($user)) {
            $branchIds = $this->branchIds($user);
        }
        if ($branchIds !== [] || $this->hasBranchAssignments($user)) {
            $query->whereIn('branch_id', $branchIds);
        }

        return $query->orderBy('name')->get();
    }

    /** Guard every POS mutation against forged branch/terminal ids and cross-branch terminals. */
    public function assertPosSelection(?User $user, int $branchId, ?int $terminalId): void
    {
        $branch = Branch::findOrFail($branchId);
        abort_unless($branch->status === 'active', 422, 'The selected branch is inactive.');

        if ($this->hasBranchAssignments($user) && ! in_array($branchId, $this->branchIds($user), true)) {
            abort(403, 'Your account is not assigned to this branch.');
        }

        if (! $this->hasBranchAssignments($user) && ($terminalIds = $this->terminalIds($user))) {
            $terminalBranchIds = Terminal::whereIn('id', $terminalIds)
                ->pluck('branch_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->all();
            abort_unless(in_array($branchId, $terminalBranchIds, true), 403, 'Your account is not assigned to this branch.');
        }

        if ($terminalId === null) {
            return;
        }

        $terminal = Terminal::findOrFail($terminalId);
        abort_unless($terminal->status === 'active', 422, 'The selected terminal is inactive.');
        if ((int) $terminal->branch_id !== $branchId) {
            abort(422, 'The selected terminal does not belong to the selected branch.');
        }

        if (($terminals = $this->terminalIds($user)) && ! in_array($terminalId, $terminals, true)) {
            abort(403, 'Your account is not assigned to this terminal.');
        }
    }

    /** Order types this user is restricted to; empty = no order-type restriction. */
    public function orderTypes(?User $user): array
    {
        if (! $user) {
            return [];
        }
        $allowed = $user->allowed_order_types ?: [];
        $all = array_keys(User::ORDER_TYPES);

        // "all types selected" is not a restriction.
        return (count($allowed) > 0 && count(array_diff($all, $allowed)) > 0) ? array_values($allowed) : [];
    }

    public function isScoped(?User $user): bool
    {
        return $this->hasBranchAssignments($user) || $this->terminalIds($user) !== [] || $this->orderTypes($user) !== [];
    }

    /**
     * Constrain a sales_orders query (or any query joined to it) to the user's scope.
     *
     * @param  EloquentBuilder|QueryBuilder  $query
     * @param  string  $prefix  column prefix/alias of the sales_orders table in this query
     */
    public function applyToSales($query, ?User $user, string $prefix = '')
    {
        $column = fn (string $name) => $prefix === '' ? $name : $prefix . '.' . $name;

        if ($this->hasBranchAssignments($user)) {
            $query->whereIn($column('branch_id'), $this->branchIds($user));
        }
        if ($terminals = $this->terminalIds($user)) {
            $query->whereIn($column('terminal_id'), $terminals);
        }
        if ($types = $this->orderTypes($user)) {
            $query->whereIn($column('order_type'), $types);
        }

        return $query;
    }

    /** True when this specific sale is outside the user's scope (403 guard for show/print pages). */
    public function deniesSale(?User $user, $sale): bool
    {
        if ($this->hasBranchAssignments($user) && ! in_array((int) $sale->branch_id, $this->branchIds($user), true)) {
            return true;
        }

        $terminals = $this->terminalIds($user);
        $types = $this->orderTypes($user);

        if ($terminals && ! in_array((int) $sale->terminal_id, $terminals, true)) {
            return true;
        }

        return (bool) ($types && ! in_array((string) $sale->order_type, $types, true));
    }

    /**
     * Force report filters into the user's scope — a scoped user's Report Center (screen, print,
     * export, email) can only ever describe his own terminal and order types.
     */
    public function applyToReportFilters(array $filters, ?User $user): array
    {
        $branchFilterPresent = (bool) ($filters['_branch_filter_present'] ?? false);
        $terminalFilterPresent = (bool) ($filters['_terminal_filter_present'] ?? false);
        unset($filters['_branch_filter_present'], $filters['_terminal_filter_present']);

        $allowedBranches = $this->hasBranchAssignments($user)
            ? $this->branchIds($user)
            : (($terminalIds = $this->terminalIds($user))
                ? Terminal::whereIn('id', $terminalIds)->pluck('branch_id')->map(fn ($id) => (int) $id)->unique()->values()->all()
                : []);

        if ($allowedBranches !== []) {
            $requested = array_map('intval', $filters['branch_ids'] ?? []);
            if ($requested === [] && ! $branchFilterPresent && in_array((int) $user?->default_branch_id, $allowedBranches, true)) {
                $filters['branch_ids'] = [(int) $user->default_branch_id];
            } else {
                $filters['branch_ids'] = $requested === []
                    ? $allowedBranches
                    : array_values(array_intersect($requested, $allowedBranches));
            }
            if ($filters['branch_ids'] === []) {
                // A user with assignments but no active assignment must see no branch data.
                $filters['branch_ids'] = $allowedBranches ?: [-1];
            }
        }
        if ($terminals = $this->terminalIds($user)) {
            $requested = (int) ($filters['terminal_id'] ?? 0);
            if (! $terminalFilterPresent && in_array((int) $user?->default_terminal_id, $terminals, true)) {
                $filters['terminal_id'] = (int) $user->default_terminal_id;
            } else {
                $filters['terminal_id'] = in_array($requested, $terminals, true) ? $requested : null;
            }
            $filters['allowed_terminal_ids'] = $terminals;
        }
        if ($types = $this->orderTypes($user)) {
            $requested = (string) ($filters['order_type'] ?? '');
            $filters['order_type'] = in_array($requested, $types, true) ? $requested : null;
            $filters['allowed_order_types'] = $types;
        }

        return $filters;
    }
}
