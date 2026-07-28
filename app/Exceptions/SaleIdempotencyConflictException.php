<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;

/**
 * SALE-IDEMPOTENCY-1: the same client_uuid was reused with materially different
 * sale details. We must not mutate anything — the original sale stands.
 * Self-renders 409 (JSON) / redirect-back (browser); never a 500.
 */
class SaleIdempotencyConflictException extends RuntimeException
{
    public const CODE = 'SALE_IDEMPOTENCY_CONFLICT';

    public function __construct(
        public readonly int $existingSaleId,
    ) {
        parent::__construct('This sale request key was already used with different sale details.');
    }

    public function render(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'code'    => self::CODE,
            ], 409);
        }

        return back()->withErrors(['sale' => $this->getMessage()]);
    }
}
