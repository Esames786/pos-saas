<?php

namespace App\Services\Edge;

use RuntimeException;

/**
 * OFFLINE-SYNC-ENGINE-1C — an internal, deterministic ingestion refusal/exception carrying a stable code.
 * EdgeInboundSaleIngestionService catches it and turns it into a refused/exception ACK (never a 500).
 * The stable code is exposed as refusalCode (Exception::$code is reserved for the numeric code).
 */
class IngestionRefusal extends RuntimeException
{
    public function __construct(public readonly string $refusalCode, string $message)
    {
        parent::__construct($message);
    }
}
