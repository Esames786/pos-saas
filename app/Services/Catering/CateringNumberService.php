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

    /** Must be called inside a tenant transaction so the lock holds until commit. */
    private function next(string $table, string $column, string $prefix): string
    {
        $datePart = now()->format('Ymd');
        $fullPrefix = $prefix.$datePart.'-';

        $last = DB::connection('tenant')->table($table)
            ->where($column, 'like', $fullPrefix.'%')
            ->lockForUpdate()
            ->orderByDesc($column)
            ->value($column);

        $sequence = $last ? ((int) substr($last, strlen($fullPrefix))) + 1 : 1;

        return $fullPrefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
