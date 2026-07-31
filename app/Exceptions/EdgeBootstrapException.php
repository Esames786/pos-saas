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
    public const NOT_ALLOWED          = 'EDGE_BOOTSTRAP_NOT_ALLOWED';
    public const DEVICE_REVOKED       = 'EDGE_BOOTSTRAP_DEVICE_REVOKED';
    public const BRANCH_NOT_PENDING   = 'EDGE_BOOTSTRAP_BRANCH_NOT_PENDING';
    public const SNAPSHOT_NOT_FOUND   = 'EDGE_BOOTSTRAP_SNAPSHOT_NOT_FOUND';
    public const SNAPSHOT_EXPIRED     = 'EDGE_BOOTSTRAP_SNAPSHOT_EXPIRED';
    public const HASH_MISMATCH        = 'EDGE_BOOTSTRAP_HASH_MISMATCH';
    public const SCHEMA_UNSUPPORTED   = 'EDGE_BOOTSTRAP_SCHEMA_UNSUPPORTED';
    // HARDEN-1: build lifecycle + complete-download
    public const BUILD_IN_PROGRESS    = 'EDGE_BOOTSTRAP_BUILD_IN_PROGRESS';
    public const BUILD_FAILED         = 'EDGE_BOOTSTRAP_BUILD_FAILED';
    public const SOURCE_CHANGED       = 'EDGE_BOOTSTRAP_SOURCE_CHANGED';
    public const INCOMPLETE           = 'EDGE_BOOTSTRAP_INCOMPLETE';
    public const SECTION_HASH_MISMATCH = 'EDGE_BOOTSTRAP_SECTION_HASH_MISMATCH';

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
            self::DEVICE_REVOKED       => 'This device is no longer authorised.',
            self::BRANCH_NOT_PENDING   => 'The branch is not in a pairing (pending) state.',
            self::SNAPSHOT_NOT_FOUND   => 'Bootstrap snapshot not found.',
            self::SNAPSHOT_EXPIRED     => 'Bootstrap snapshot has expired. Request a new one.',
            self::HASH_MISMATCH        => 'Bootstrap manifest hash does not match.',
            self::SCHEMA_UNSUPPORTED   => 'Unsupported bootstrap schema version.',
            self::BUILD_IN_PROGRESS    => 'Bootstrap snapshot is still being prepared. Retry shortly.',
            self::BUILD_FAILED         => 'Bootstrap snapshot build failed. Request a new one.',
            self::SOURCE_CHANGED       => 'Source data changed during build. Request a new snapshot.',
            self::INCOMPLETE           => 'Not all required sections have been downloaded and verified.',
            self::SECTION_HASH_MISMATCH => 'A downloaded section hash does not match the manifest.',
            default                    => 'Bootstrap is not available for this device.',
        };
    }

    public function httpStatus(): int
    {
        return match ($this->bootstrapCode) {
            self::DEVICE_REVOKED    => 401,
            self::NOT_ALLOWED       => 403,
            self::SNAPSHOT_NOT_FOUND => 404,
            self::BRANCH_NOT_PENDING, self::INCOMPLETE, self::SOURCE_CHANGED => 409,
            self::SNAPSHOT_EXPIRED  => 410,
            self::BUILD_IN_PROGRESS => 202,
            self::BUILD_FAILED      => 503,
            default                 => 422, // hash mismatch / section-hash mismatch / unsupported schema
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
