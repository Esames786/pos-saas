<?php

namespace App\Services\Catering;

use Illuminate\Support\Facades\DB;

/**
 * CATERING-SLICE-1: customer-facing document numbers using the locked
 * date+sequence pattern from JournalService::nextEntryNo() (lockForUpdate on
 * the max read inside the caller's transaction). The timestamp+random style
 * used for sale_no is NOT acceptable for customer-facing catering documents.
 */
class CateringNumberService
{
    public function nextEventNo(): string
    {
        return $this->next('catering_events', 'event_no', 'EV-');
    }

    public function nextProductionReleaseNo(): string
    {
        return $this->next('catering_production_releases', 'release_no', 'PR-');
    }

    public function nextFinalInvoiceNo(): string
    {
        return $this->next('catering_final_invoices', 'invoice_no', 'CI-');
    }

    public function nextRefundNo(): string
    {
        return $this->next('catering_refunds', 'refund_no', 'CR-');
    }

    public function nextMaterialIssueNo(): string
    {
        return $this->next('catering_material_issues', 'issue_no', 'MI-');
    }

    /** Must be called inside a tenant transaction so the lock holds until commit. */
    /**
     * KASHIF-CATERING-NUMBERING-1 — the counter runs for the YEAR, not the day.
     *
     * It used to reset every midnight, so the second booking of the year and the
     * second booking of a Tuesday in August were both 0001. The date stays in the
     * number because the operator reads it at a glance, but the sequence now
     * counts bookings for the year and resets on 1 January:
     *
     *   16 Aug 2026  EV-20260816-0001
     *   16 Aug 2026  EV-20260816-0002
     *   17 Aug 2026  EV-20260817-0003   <- continues, was 0001
     *    1 Jan 2027  EV-20270101-0001   <- resets
     *
     * The highest sequence is read across the whole year, so it does not matter
     * which day the previous booking fell on. Numbers already issued are never
     * rewritten; this rule applies from the day it goes live.
     */
    private function next(string $table, string $column, string $prefix): string
    {
        $datePart = now()->format('Ymd');
        $yearPrefix = $prefix.now()->format('Y');
        $fullPrefix = $prefix.$datePart.'-';

        // Ordering on the numeric tail rather than the whole string, because
        // '...-0010' sorts before '...-0009' once the day changes.
        $last = DB::connection('tenant')->table($table)
            ->where($column, 'like', $yearPrefix.'%')
            ->lockForUpdate()
            ->orderByRaw('CAST(SUBSTRING_INDEX('.$column.", '-', -1) AS UNSIGNED) DESC")
            ->value($column);

        $sequence = $last
            ? ((int) substr($last, strrpos($last, '-') + 1)) + 1
            : 1;

        return $fullPrefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
