<?php

namespace App\Models\Tenant\Concerns;

/**
 * Posting-state helpers for future-postable manufacturing documents (MFG-FIN-B).
 *
 * INFRASTRUCTURE ONLY. This trait adds the posting-state columns to the model's
 * fillable/casts and provides read-only status helpers + badge classes. It does
 * NOT post anything: no journal entries, no stock movements, no GL. The columns
 * stay 'unposted' until a future posting phase (C+) sets them via the
 * ManufacturingPostingService mark* helpers.
 */
trait HasManufacturingPostingStatus
{
    public const POSTING_STATUS_UNPOSTED = 'unposted';
    public const POSTING_STATUS_POSTED   = 'posted';
    public const POSTING_STATUS_REVERSED = 'reversed';

    public const POSTING_STATUSES = [
        self::POSTING_STATUS_UNPOSTED,
        self::POSTING_STATUS_POSTED,
        self::POSTING_STATUS_REVERSED,
    ];

    /** Auto-merge posting-state fields into fillable/casts for every using model. */
    public function initializeHasManufacturingPostingStatus(): void
    {
        $this->mergeFillable([
            'posting_status',
            'posted_at',
            'posted_by_user_id',
            'journal_entry_id',
            'reversed_of_id',
        ]);

        $this->mergeCasts([
            'posted_at' => 'datetime',
        ]);
    }

    public function isUnposted(): bool
    {
        return ($this->posting_status ?? self::POSTING_STATUS_UNPOSTED) === self::POSTING_STATUS_UNPOSTED;
    }

    public function isPosted(): bool
    {
        return $this->posting_status === self::POSTING_STATUS_POSTED;
    }

    public function isReversed(): bool
    {
        return $this->posting_status === self::POSTING_STATUS_REVERSED;
    }

    public function postingStatusLabel(): string
    {
        return ucfirst($this->posting_status ?? self::POSTING_STATUS_UNPOSTED);
    }

    public function postingStatusBadgeClass(): string
    {
        return match ($this->posting_status) {
            self::POSTING_STATUS_POSTED   => 'success',
            self::POSTING_STATUS_REVERSED => 'warning',
            default                       => 'secondary',
        };
    }

    /**
     * Marker that the posting-state infrastructure exists on this document.
     * Always true once this trait is applied; posting itself is a later phase.
     */
    public function markAsPostingInfrastructureReady(): bool
    {
        return true;
    }
}
