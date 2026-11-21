<?php

namespace App\Services\Edge\Cloud;

use Illuminate\Http\Request;
use RuntimeException;

/**
 * EDGE 0.9 — a self-rendering, client-safe failure for the setup wizard / device endpoints (K2–K6). Never a 500 for an
 * EXPECTED failure; messages never disclose whether a tenant or branch exists.
 *
 *   422  invalid / expired / exhausted code, validation of a reported profile
 *   409  code already used, branch state conflict (live pairing code, device limit), release integrity
 *   403  entitlement / setup token missing, expired or out of scope
 */
class EdgeSetupException extends RuntimeException
{
    public const CODE_INVALID          = 'EDGE_SETUP_CODE_INVALID';
    public const CODE_EXPIRED          = 'EDGE_SETUP_CODE_EXPIRED';
    public const CODE_EXHAUSTED        = 'EDGE_SETUP_CODE_EXHAUSTED';
    public const CODE_USED             = 'EDGE_SETUP_CODE_USED';
    public const CODE_ALREADY_ACTIVE   = 'EDGE_SETUP_CODE_ALREADY_ACTIVE';
    public const BRANCH_NOT_ELIGIBLE   = 'EDGE_SETUP_BRANCH_NOT_ELIGIBLE';
    public const PAIRING_CODE_ACTIVE   = 'EDGE_SETUP_PAIRING_CODE_ACTIVE';
    public const PAIRING_UNAVAILABLE   = 'EDGE_SETUP_PAIRING_UNAVAILABLE';
    public const DEVICE_LIMIT          = 'EDGE_DEVICE_LIMIT_REACHED';
    public const ENTITLEMENT           = 'EDGE_ENTITLEMENT_REQUIRED';
    public const NOT_CONFIGURED        = 'EDGE_SETUP_NOT_CONFIGURED';
    public const TOKEN_INVALID         = 'EDGE_SETUP_TOKEN_INVALID';
    public const TOKEN_SCOPE           = 'EDGE_SETUP_TOKEN_SCOPE';
    public const ENROLLMENT_UNAVAILABLE = 'EDGE_SETUP_ENROLLMENT_UNAVAILABLE';
    public const DEVICE_NOT_CURRENT    = 'EDGE_DEVICE_NOT_CURRENT';
    public const LAN_PROFILE_INVALID   = 'EDGE_LAN_PROFILE_INVALID';
    public const RELEASE_UNAVAILABLE   = 'EDGE_RELEASE_UNAVAILABLE';
    // K2b — fresh pairing code for an install-mode setup session.
    public const NOT_INSTALL_SESSION   = 'EDGE_SETUP_NOT_AN_INSTALL_SESSION';
    public const DEVICE_ALREADY_PAIRED = 'EDGE_SETUP_DEVICE_ALREADY_PAIRED';
    public const PAIRING_CODE_STILL_VALID = 'EDGE_SETUP_PAIRING_CODE_STILL_VALID';
    public const PAIRING_CODES_EXHAUSTED = 'EDGE_SETUP_PAIRING_CODES_EXHAUSTED';

    public function __construct(
        public readonly string $setupCode,
        ?string $message = null,
        public readonly array $context = [],
    ) {
        parent::__construct($message ?? $this->defaultMessage());
    }

    public static function of(string $code, ?string $message = null, array $context = []): self
    {
        return new self($code, $message, $context);
    }

    private function defaultMessage(): string
    {
        return match ($this->setupCode) {
            self::CODE_EXPIRED         => 'This setup code has expired. Create a new one on the Offline Edge page.',
            self::CODE_EXHAUSTED       => 'Too many attempts for this setup code. Create a new one on the Offline Edge page.',
            self::CODE_USED            => 'This setup code has already been used. Create a new one on the Offline Edge page.',
            self::CODE_ALREADY_ACTIVE  => 'A setup code is already active for this branch. Cancel it before creating a new one.',
            self::BRANCH_NOT_ELIGIBLE  => 'This branch cannot be set up as a Branch Server right now.',
            self::PAIRING_CODE_ACTIVE  => 'A pairing code is already active for this branch. Cancel it on the Offline Edge page, then try again.',
            self::PAIRING_UNAVAILABLE  => 'A pairing code could not be issued for this branch.',
            self::DEVICE_LIMIT         => 'The licensed Edge device limit has been reached.',
            self::ENTITLEMENT          => 'Offline Branch Edge is not available for this account.',
            self::NOT_CONFIGURED       => 'Branch Server setup is not configured on the Cloud yet.',
            self::TOKEN_INVALID        => 'The setup token is missing, expired or not valid.',
            self::TOKEN_SCOPE          => 'The setup token does not belong to this Branch Server.',
            self::ENROLLMENT_UNAVAILABLE => 'User enrollment is not available for this Branch Server yet.',
            self::DEVICE_NOT_CURRENT   => 'This device is not the current Branch Server of its branch.',
            self::LAN_PROFILE_INVALID  => 'The reported LAN profile is not valid.',
            self::RELEASE_UNAVAILABLE  => 'The requested Branch Server release is not available.',
            self::NOT_INSTALL_SESSION  => 'This setup session updates an existing Branch Server; it has no pairing code.',
            self::DEVICE_ALREADY_PAIRED => 'A Branch Server is already paired to this branch.',
            self::PAIRING_CODE_STILL_VALID => 'The current pairing code is still valid; a new one is issued only in its last 3 minutes or after it expired.',
            self::PAIRING_CODES_EXHAUSTED => 'This setup session has issued its maximum number of pairing codes. Create a new setup code on the Offline Edge page.',
            default                    => 'Invalid setup code.',
        };
    }

    public function httpStatus(): int
    {
        return match ($this->setupCode) {
            self::CODE_USED, self::CODE_ALREADY_ACTIVE, self::PAIRING_CODE_ACTIVE, self::PAIRING_UNAVAILABLE,
            self::DEVICE_LIMIT, self::BRANCH_NOT_ELIGIBLE, self::ENROLLMENT_UNAVAILABLE, self::DEVICE_NOT_CURRENT,
            self::RELEASE_UNAVAILABLE, self::NOT_INSTALL_SESSION, self::DEVICE_ALREADY_PAIRED, self::PAIRING_CODE_STILL_VALID,
            self::PAIRING_CODES_EXHAUSTED => 409,
            self::ENTITLEMENT, self::TOKEN_INVALID, self::TOKEN_SCOPE => 403,
            self::NOT_CONFIGURED => 503,
            default => 422,
        };
    }

    public function render(Request $request)
    {
        return response()->json(array_merge([
            'message' => $this->getMessage(),
            'code'    => $this->setupCode,
        ], $this->context), $this->httpStatus());
    }
}
