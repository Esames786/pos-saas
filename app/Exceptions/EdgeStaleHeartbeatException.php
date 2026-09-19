<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * P5C HOME LAB (19 Sep 2026) — a heartbeat whose sequence is BEHIND the Cloud's lease. Carries the Cloud's current
 * sequence so the refusal can tell the appliance where the Cloud stands; the message stays the wire failure code.
 */
class EdgeStaleHeartbeatException extends RuntimeException
{
    public function __construct(public readonly int $cloudSeq)
    {
        parent::__construct('STALE_HEARTBEAT');
    }
}
