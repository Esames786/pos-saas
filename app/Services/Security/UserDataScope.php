<?php

namespace App\Services\Security;

use App\Models\Tenant\User;
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
    /** Terminal ids this user is restricted to; empty = no terminal restriction. */
    public function terminalIds(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return $user->terminals()->pluck('terminals.id')->map(fn ($id) => (int) $id)->all();
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
        return $this->terminalIds($user) !== [] || $this->orderTypes($user) !== [];
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
        if ($terminals = $this->terminalIds($user)) {
            $requested = (int) ($filters['terminal_id'] ?? 0);
            $filters['terminal_id'] = in_array($requested, $terminals, true) ? $requested : $terminals[0];
        }
        if ($types = $this->orderTypes($user)) {
            $requested = (string) ($filters['order_type'] ?? '');
            $filters['order_type'] = in_array($requested, $types, true) ? $requested : $types[0];
        }

        return $filters;
    }
}
