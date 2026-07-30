<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;

/**
 * BRANCH-BOOTSTRAP-SNAPSHOT-1 — self-rendering, client-safe bootstrap failures. Never a 500
 * for an expected failure; messages never disclose other tenants/branches.
 */
class EdgeBootstrapException extends RuntimeException
{
    public const NOT_ALLOWED        = 'EDGE_BOOTSTRAP_NOT_ALLOWED';
    public const DEVICE_REVOKED     = 'EDGE_BOOTSTRAP_DEVICE_REVOKED';
    public const BRANCH_NOT_PENDING = 'EDGE_BOOTSTRAP_BRANCH_NOT_PENDING';
    public const SNAPSHOT_NOT_FOUND = 'EDGE_BOOTSTRAP_SNAPSHOT_NOT_FOUND';
    public const SNAPSHOT_EXPIRED   = 'EDGE_BOOTSTRAP_SNAPSHOT_EXPIRED';
    public const HASH_MISMATCH      = 'EDGE_BOOTSTRAP_HASH_MISMATCH';
    public const SCHEMA_UNSUPPORTED = 'EDGE_BOOTSTRAP_SCHEMA_UNSUPPORTED';

    public function __construct(public readonly string $bootstrapCode, ?string $message = null)
    {
        parent::__construct($message ?? $this->defaultMessage());
    }

    public static function of(string $code): self
    {
        return new self($code);
    }

    private function defaultMessage(): string
    {
        return match ($this->bootstrapCode) {
            self::DEVICE_REVOKED     => 'This device is no longer authorised.',
            self::BRANCH_NOT_PENDING => 'The branch is not in a pairing (pending) state.',
            self::SNAPSHOT_NOT_FOUND => 'Bootstrap snapshot not found.',
            self::SNAPSHOT_EXPIRED   => 'Bootstrap snapshot has expired. Request a new one.',
            self::HASH_MISMATCH      => 'Bootstrap manifest hash does not match.',
            self::SCHEMA_UNSUPPORTED => 'Unsupported bootstrap schema version.',
            default                  => 'Bootstrap is not available for this device.',
        };
    }

    public function httpStatus(): int
    {
        return match ($this->bootstrapCode) {
            self::DEVICE_REVOKED     => 401,
            self::NOT_ALLOWED        => 403,
            self::SNAPSHOT_NOT_FOUND => 404,
            self::BRANCH_NOT_PENDING => 409,
            self::SNAPSHOT_EXPIRED   => 410,
            default                  => 422, // hash mismatch / unsupported schema
        };
    }

    public function render(Request $request)
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code'    => $this->bootstrapCode,
        ], $this->httpStatus());
    }
}
