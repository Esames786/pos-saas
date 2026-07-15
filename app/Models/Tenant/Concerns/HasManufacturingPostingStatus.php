<?php

namespace App\Models\Tenant\Concerns;

/**
 * Posting-state helpers for manufacturing documents (MFG-FIN-B+).
 *
 * This trait adds posting-state columns to fillable/casts and provides status
 * helpers. It performs no stock or journal writes; event services set the state
 * through ManufacturingPostingService after their posting transaction succeeds.
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
     * Marker that posting-state infrastructure exists on this document.
     */
    public function markAsPostingInfrastructureReady(): bool
    {
        return true;
    }
}
