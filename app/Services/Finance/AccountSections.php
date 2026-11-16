<?php

namespace App\Services\Finance;

/**
 * TB-SECTION-HEADS-1 — which head of the books an account sits under. One rule for the Trial Balance
 * and the Profit & Loss, so the two reports can never disagree about what is Cost of Sales.
 *
 *   asset → Assets · liability → Liabilities · equity → Equity · income → Revenue (contra revenue such
 *   as 4200 Sales Discounts included) · expense with a code starting "5" → Cost of Sales · any other
 *   expense → Operating Expenses. A type this list does not know goes to "Other" — never dropped.
 */
final class AccountSections
{
    public const ASSETS = 'assets';
    public const LIABILITIES = 'liabilities';
    public const EQUITY = 'equity';
    public const REVENUE = 'revenue';
    public const COST_OF_SALES = 'cost_of_sales';
    public const OPERATING_EXPENSES = 'operating_expenses';
    public const OTHER = 'other';

    /** In the order the Trial Balance shows them. */
    public const LABELS = [
        self::ASSETS             => 'Assets',
        self::LIABILITIES        => 'Liabilities',
        self::EQUITY             => 'Equity',
        self::REVENUE            => 'Revenue',
        self::COST_OF_SALES      => 'Cost of Sales',
        self::OPERATING_EXPENSES => 'Operating Expenses',
        self::OTHER              => 'Other',
    ];

    /** The P&L's Cost of Sales test (FIN-9): an expense whose code starts with "5". */
    public static function isCostOfSales(?string $type, ?string $code): bool
    {
        return $type === 'expense' && str_starts_with((string) $code, '5');
    }

    public static function of(?string $type, ?string $code): string
    {
        return match ($type) {
            'asset'             => self::ASSETS,
            'liability'         => self::LIABILITIES,
            'equity'            => self::EQUITY,
            'income', 'revenue' => self::REVENUE,
            'expense'           => self::isCostOfSales($type, $code) ? self::COST_OF_SALES : self::OPERATING_EXPENSES,
            default             => self::OTHER,
        };
    }
}
