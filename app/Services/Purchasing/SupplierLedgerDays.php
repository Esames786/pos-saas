<?php

namespace App\Services\Purchasing;

use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\PurchaseReturn;
use App\Models\Tenant\SupplierPayment;
use Illuminate\Support\Facades\DB;

/**
 * SUPPLIER-LEDGER-DAILY-1 — a supplier's account (or all suppliers') read by the DATE OF THE DOCUMENT, day by day.
 *
 * The client (3 Nov): "ledger roz check karna hai — aaj ye invoice ki, us ka total, ye payment, aur balance; invoice ki
 * date se, posting date se nahi." Each row sits on its `entry_date` (the invoice / payment / return / journal date);
 * a row the backfill could not date uses the day it was typed. Rows of one day keep the order they were written in.
 *
 * The running balance is COMPUTED here in that order, from the balance before From — the stored `balance_after` stays
 * as it was (it is in typing order, and VoidPurge rewrites it); a back-dated invoice therefore changes the balance of
 * every later day, which is what the client asked to see.
 *
 * Sign: as the ledger stores it, direction 'debit' = the payable went UP (invoice, opening, payment voided) and 'credit'
 * = it went DOWN (payment, return). The page shows them as the supplier's Credit / Debit (SUPPLIER-LEDGER-DRCR-LABELS-1).
 */
class SupplierLedgerDays
{
    /** The day a row is shown on. */
    private const DAY = 'COALESCE(l.entry_date, DATE(l.created_at))';

    private const SIGNED = "CASE WHEN l.direction = 'debit' THEN l.amount ELSE -l.amount END";

    /** Plain words for each kind of row. */
    public const KINDS = [
        'opening_balance'        => 'Opening balance',
        'purchase_bill'          => 'Invoice',
        'purchase_bill_revision' => 'Invoice edited',
        'purchase_bill_void'     => 'Invoice voided',
        'return_netted'          => 'Return deducted on invoice',
        'payment'                => 'Payment',
        'payment_void'           => 'Payment voided',
        'purchase_return'        => 'Goods returned',
        'journal_adjustment'     => 'Journal',
        'journal_reversal'       => 'Journal reversed',
    ];

    /**
     * @return array{opening: float, closing: float, credit: float, debit: float,
     *               rows: list<object>, days: list<array{day:string, opening:float, credit:float, debit:float, closing:float, rows:int}>}
     */
    public function build(?int $supplierId, string $from, string $to): array
    {
        $db = DB::connection('tenant');
        $scope = fn () => $db->table('supplier_ledgers as l')
            ->when($supplierId, fn ($q) => $q->where('l.supplier_id', $supplierId));

        $opening = round((float) $scope()->whereRaw(self::DAY . ' < ?', [$from])->sum(DB::raw(self::SIGNED)), 4);

        $rows = $scope()
            ->leftJoin('suppliers as s', 's.id', '=', 'l.supplier_id')
            ->leftJoin('users as u', 'u.id', '=', 'l.created_by_user_id')
            ->whereRaw(self::DAY . ' BETWEEN ? AND ?', [$from, $to])
            ->orderByRaw(self::DAY)->orderBy('l.id')
            ->get(['l.*', DB::raw(self::DAY . ' as day'), 's.name as supplier_name', 'u.name as user_name']);

        $running = $opening;
        $credit = 0.0;
        $debit = 0.0;
        $days = [];
        foreach ($rows as $row) {
            $amount = round((float) $row->amount, 4);
            $up = $row->direction === 'debit';
            $dayOpening = $running;
            $running = round($running + ($up ? $amount : -$amount), 4);

            $row->running = $running;
            $row->kind = self::KINDS[$row->entry_type] ?? ucfirst(str_replace('_', ' ', (string) $row->entry_type));
            $row->url = self::urlFor($row);

            $day = (string) $row->day;
            if (! isset($days[$day])) {
                $days[$day] = ['day' => $day, 'opening' => $dayOpening, 'credit' => 0.0, 'debit' => 0.0, 'closing' => $running, 'rows' => 0];
            }
            $days[$day][$up ? 'credit' : 'debit'] = round($days[$day][$up ? 'credit' : 'debit'] + $amount, 4);
            $days[$day]['closing'] = $running;
            $days[$day]['rows']++;

            if ($up) {
                $credit += $amount;
            } else {
                $debit += $amount;
            }
        }

        return [
            'opening' => $opening,
            'closing' => $running,
            'credit'  => round($credit, 4),
            'debit'   => round($debit, 4),
            'rows'    => $rows->all(),
            'days'    => array_values($days),
        ];
    }

    private static function urlFor(object $row): ?string
    {
        if (! $row->reference_id) {
            return null;
        }

        return match ($row->reference_type) {
            PurchaseBill::class    => url('/purchase-bills/' . $row->reference_id),
            SupplierPayment::class => url('/supplier-payments/' . $row->reference_id),
            PurchaseReturn::class  => url('/purchase-returns/' . $row->reference_id),
            JournalEntry::class    => null,
            default                => null,
        };
    }
}
