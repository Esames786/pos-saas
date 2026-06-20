<?php

namespace App\Http\Controllers\Concerns;

trait GeneratesSequentialCode
{
    /**
     * Build the next zero-padded sequential code for a prefixed column, e.g.
     * MFG-CUST-0001, PROD-000001, BOM-000001.
     *
     * Robust to mixed-width / legacy data: it parses the numeric part *after*
     * the prefix, strips any stray non-digit characters, and takes the maximum
     * numerically (not lexically). This avoids the classic bugs where:
     *   - orderByDesc('code') sorts "MFG-CUST-003" above "MFG-CUST-0001", and
     *   - substr($code, -4) on a 3-digit code captures the "-" dash, yielding a
     *     negative number and an output like "MFG-CUST-00-2".
     *
     * @param  class-string  $modelClass  Eloquent model to scan
     * @param  string        $column      column holding the code
     * @param  string        $prefix      fixed prefix, e.g. "MFG-CUST-"
     * @param  int           $width       zero-pad width of the numeric part
     */
    protected function nextSequentialCode(string $modelClass, string $column, string $prefix, int $width): string
    {
        $max = $modelClass::query()
            ->where($column, 'like', $prefix . '%')
            ->pluck($column)
            ->map(function ($code) use ($prefix) {
                $suffix = substr((string) $code, strlen($prefix));
                $digits = preg_replace('/\D/', '', $suffix);
                return $digits === '' ? 0 : (int) $digits;
            })
            ->max() ?? 0;

        return $prefix . str_pad((string) ($max + 1), $width, '0', STR_PAD_LEFT);
    }
}
