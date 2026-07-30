<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;

/**
 * BRANCH-DEVICE-PAIRING-1 — a self-rendering, client-safe failure for the public
 * pairing exchange. Never a 500 for an EXPECTED pairing failure. Messages are
 * deliberately generic — they never disclose whether a tenant or branch exists.
 *
 *   422  invalid / expired / exhausted / format  (retryable with a new/correct code)
 *   409  code already used, or a device already bound (conflict)
 *   403  entitlement / rollout / feature denial
 *   429  rate limited (handled by the throttle middleware, not this class)
 */
class EdgePairingException extends RuntimeException
{
    public const CODE_INVALID            = 'PAIRING_CODE_INVALID';
    public const CODE_EXPIRED            = 'PAIRING_CODE_EXPIRED';
    public const CODE_EXHAUSTED          = 'PAIRING_CODE_EXHAUSTED';
    public const CODE_USED               = 'PAIRING_CODE_USED';
    public const CODE_ALREADY_ACTIVE     = 'PAIRING_CODE_ALREADY_ACTIVE';
    public const CODE_DEVICE_CONFLICT    = 'PAIRING_DEVICE_CONFLICT';
    public const CODE_ENTITLEMENT        = 'EDGE_ENTITLEMENT_REQUIRED';
    public const CODE_DEVICE_LIMIT       = 'EDGE_DEVICE_LIMIT_REACHED';

    public function __construct(
        public readonly string $pairingCode,
        ?string $message = null,
    ) {
        parent::__construct($message ?? $this->defaultMessage());
    }

    public static function of(string $code): self
    {
        return new self($code);
    }

    private function defaultMessage(): string
    {
        return match ($this->pairingCode) {
            self::CODE_EXPIRED         => 'This pairing code has expired. Generate a new one.',
            self::CODE_EXHAUSTED       => 'Too many attempts for this pairing code. Generate a new one.',
            self::CODE_USED            => 'This pairing code has already been used.',
            self::CODE_ALREADY_ACTIVE  => 'A pairing code is already active for this branch. Cancel it before generating a new one.',
            self::CODE_DEVICE_CONFLICT => 'A different device is already paired to this branch.',
            self::CODE_ENTITLEMENT     => 'Offline Branch Edge is not available for this account.',
            self::CODE_DEVICE_LIMIT    => 'The licensed Edge device limit has been reached.',
            default                    => 'Invalid pairing code.',
        };
    }

    public function httpStatus(): int
    {
        return match ($this->pairingCode) {
            self::CODE_USED, self::CODE_DEVICE_CONFLICT, self::CODE_DEVICE_LIMIT, self::CODE_ALREADY_ACTIVE => 409,
            self::CODE_ENTITLEMENT                                                                          => 403,
            default                                                                                        => 422, // invalid/expired/exhausted
        };
    }

    public function render(Request $request)
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code'    => $this->pairingCode,
        ], $this->httpStatus());
    }
}
