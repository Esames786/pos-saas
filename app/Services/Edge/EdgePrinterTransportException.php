<?php

namespace App\Services\Edge;

use RuntimeException;

/**
 * EDGE 0.8.3 (F-3) — a classified print transport failure. `category()` is one of the EdgePrinterReachabilityProbe
 * statuses for a connect failure (refused | timeout | unreachable | unresolved | error) or write_timeout / write_failed
 * for a failure after the connection was accepted. The message starts with "[<category>]" so the delivery row's
 * last_error, the worker log and the operator UI all carry the classification verbatim.
 *
 * The bounded retry ladder (EdgeLocalPrintDeliveryService::BACKOFF_SECONDS) is applied by the caller to EVERY category:
 * a refused port and a powered-off printer both recover within the same minutes and both end terminal after the sixth
 * failure; the classification tells the operator WHAT to fix, not whether to retry.
 */
class EdgePrinterTransportException extends RuntimeException
{
    public const WRITE_TIMEOUT = 'write_timeout';
    public const WRITE_FAILED = 'write_failed';

    public function __construct(private readonly string $category, string $detail, private readonly ?int $errno = null)
    {
        parent::__construct('[' . $category . '] ' . $detail, 0);
    }

    public function category(): string
    {
        return $this->category;
    }

    public function errno(): ?int
    {
        return $this->errno;
    }

    public function isConnectFailure(): bool
    {
        return ! in_array($this->category, [self::WRITE_TIMEOUT, self::WRITE_FAILED], true);
    }
}
