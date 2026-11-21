<?php

namespace App\Services\Edge\Continuity;

use RuntimeException;

/** An adoption item that cannot be taken over as-is: recorded as `skipped` with this reason (the Cloud copy stays the truth). */
final class ContinuitySkip extends RuntimeException
{
    public function __construct(string $reason, public readonly ?int $localId = null)
    {
        parent::__construct($reason);
    }
}
