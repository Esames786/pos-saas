<?php

namespace App\Services\Finance;

use App\Models\Tenant\JournalEntry;
use RuntimeException;

/**
 * OFFLINE EDGE — D1: ManualJournalService::reverse() refused, with a stable reason the caller translates.
 *
 * The Online controller maps NOT_MANUAL_JOURNAL → 404, NOT_POSTED / IS_REVERSAL → 422 (the canonical abort codes) and
 * ALREADY_REVERSED → the canonical B3 redirect to the reversal that already exists ("nothing was posted again"). A
 * replayed reversal therefore never double-reverses: the service answers with the existing reversal and writes nothing.
 */
class ManualJournalReversalRefused extends RuntimeException
{
    public const NOT_MANUAL_JOURNAL = 'NOT_MANUAL_JOURNAL';
    public const NOT_POSTED = 'NOT_POSTED';
    public const IS_REVERSAL = 'IS_REVERSAL';
    public const ALREADY_REVERSED = 'ALREADY_REVERSED';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?JournalEntry $existingReversal = null,
    ) {
        parent::__construct($message);
    }
}
