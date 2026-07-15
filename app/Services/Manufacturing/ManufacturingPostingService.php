<?php

namespace App\Services\Manufacturing;

use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\ManufacturingPostingSetting;
use App\Models\Tenant\StockLedger;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Shared manufacturing posting-state and readiness support (MFG-FIN-B+).
 *
 * This service does not post journals or stock itself. It:
 *   - reads the posting settings,
 *   - validates document/settings readiness,
 *   - answers idempotency questions (does a journal / stock movement already
 *     exist for this source?),
 *   - and flips a document's posting-state columns when an event-specific
 *     posting service asks via markDocumentPosted / markDocumentReversed.
 *
 * Event services currently implement consumption and finished-goods posting;
 * scrap, rejection, rework and manufactured-FG COGS remain separate phases.
 */
class ManufacturingPostingService
{
    /** Canonical posting-state values (mirror HasManufacturingPostingStatus). */
    private const UNPOSTED = 'unposted';
    private const POSTED   = 'posted';
    private const REVERSED = 'reversed';

    /** Read the posting settings row for a branch (falls back to the tenant default). */
    public function settings(?int $branchId = null): ?ManufacturingPostingSetting
    {
        if ($branchId !== null) {
            $branch = ManufacturingPostingSetting::forBranch($branchId)->first();
            if ($branch) {
                return $branch;
            }
        }

        return ManufacturingPostingSetting::default()->first();
    }

    /**
     * Return the settings row only if posting could safely run (enabled + complete).
     * Throws a clear, specific exception otherwise. Reads only — posts nothing.
     */
    public function assertSettingsReady(?int $branchId = null): ManufacturingPostingSetting
    {
        $settings = $this->settings($branchId);

        if (! $settings) {
            throw new RuntimeException('Manufacturing posting settings are not configured.');
        }
        if (! $settings->is_enabled) {
            throw new RuntimeException('Manufacturing posting settings are disabled.');
        }
        if (! $settings->isComplete()) {
            throw new RuntimeException('Manufacturing posting settings are incomplete — required accounts are not mapped.');
        }

        return $settings;
    }

    /** Guard: a document must be unposted before it could be posted. */
    public function assertUnposted(Model $document): void
    {
        if (($document->posting_status ?? self::UNPOSTED) === self::POSTED) {
            throw new RuntimeException('Document is already posted.');
        }
    }

    /** Guard: a document must be posted before it could be reversed. */
    public function assertCanReverse(Model $document): void
    {
        if (($document->posting_status ?? self::UNPOSTED) !== self::POSTED) {
            throw new RuntimeException('Document is not posted and cannot be reversed.');
        }
    }

    // ── Idempotency / source-linking lookups (read-only) ────────────────────────

    /** True if a (non-reversal) posted journal already exists for this source. */
    public function alreadyHasJournal(string $sourceType, int $sourceId): bool
    {
        return $this->existingJournal($sourceType, $sourceId) !== null;
    }

    /** The existing posted (non-reversal) journal for this source, if any. Read-only. */
    public function existingJournal(string $sourceType, int $sourceId): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', 'posted')
            ->where('is_reversal', false)
            ->first();
    }

    /** True if a stock movement already exists for this source reference + type. Read-only. */
    public function alreadyHasStockMovement(string $referenceType, int $referenceId, string $movementType): bool
    {
        return StockLedger::query()
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('movement_type', $movementType)
            ->exists();
    }

    // ── Posting-state writers (flip document columns ONLY — no journal/stock) ────

    /**
     * Record that a document has been posted. Updates only posting-state
     * columns; the event service creates and passes the resulting journal.
     */
    public function markDocumentPosted(Model $document, JournalEntry $journalEntry, ?int $userId = null): void
    {
        $document->forceFill([
            'posting_status'    => self::POSTED,
            'posted_at'         => now(),
            'posted_by_user_id' => $userId,
            'journal_entry_id'  => $journalEntry->id,
        ])->save();
    }

    /** Record that a document has been reversed. Posting-state columns only. */
    public function markDocumentReversed(Model $document, ?int $userId = null, ?int $reversedOfId = null): void
    {
        $document->forceFill([
            'posting_status'    => self::REVERSED,
            'posted_by_user_id' => $userId ?? $document->posted_by_user_id,
            'reversed_of_id'    => $reversedOfId,
        ])->save();
    }

    /** Reset a document back to unposted (clears posting-state columns). */
    public function resetDocumentToUnposted(Model $document): void
    {
        $document->forceFill([
            'posting_status'    => self::UNPOSTED,
            'posted_at'         => null,
            'posted_by_user_id' => null,
            'journal_entry_id'  => null,
            'reversed_of_id'    => null,
        ])->save();
    }
}
