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
    public const CODE          = 'SALE_IDEMPOTENCY_CONFLICT';
    public const CODE_UNVERIFIABLE = 'SALE_IDEMPOTENCY_UNVERIFIABLE';
    public const CODE_PENDING   = 'SALE_IDEMPOTENCY_PENDING';

    public function __construct(
        public readonly ?int $existingSaleId = null,
        public readonly string $idempotencyCode = self::CODE,
    ) {
        parent::__construct($this->defaultMessage());
    }

    private function defaultMessage(): string
    {
        return match ($this->idempotencyCode) {
            self::CODE_UNVERIFIABLE => 'A sale already exists for this request key but its details cannot be verified. It was not re-posted.',
            self::CODE_PENDING      => 'This sale is still being processed. Please retry in a moment with the same key.',
            default                 => 'This sale request key was already used with different sale details.',
        };
    }

    /** PENDING is retryable (503); conflict/unverifiable are terminal (409). */
    private function httpStatus(): int
    {
        return $this->idempotencyCode === self::CODE_PENDING ? 503 : 409;
    }

    public function render(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'code'    => $this->idempotencyCode,
            ], $this->httpStatus());
        }

        return back()->withErrors(['sale' => $this->getMessage()]);
    }
}
