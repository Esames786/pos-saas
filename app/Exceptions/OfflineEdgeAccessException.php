<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;

/**
 * OFFLINE-EDGE-ENTITLEMENT-1 — three independent gates protect Offline Branch Edge:
 *   1. tenant module entitlement (offline_edge) — enforced by the subscription
 *      middleware, rendered as the existing "module-disabled" page (NOT this class).
 *   2. rollout flag (EDGE_FEATURE_ENABLED) — this class, code FEATURE_DISABLED.
 *   3. installer availability (a real signed BingooEdgeSetup.exe) — this class,
 *      code INSTALLER_NOT_AVAILABLE.
 *
 * Self-renders 403 (feature disabled) / 503 (installer not available) for JSON, and a
 * friendly page / redirect-back for browsers. Never a 500. Nothing here comes from
 * request input — the gate states are read from config/subscription only.
 */
class OfflineEdgeAccessException extends RuntimeException
{
    public const CODE_FEATURE_DISABLED        = 'EDGE_FEATURE_DISABLED';
    public const CODE_INSTALLER_NOT_AVAILABLE = 'EDGE_INSTALLER_NOT_AVAILABLE';

    public function __construct(
        public readonly string $edgeCode = self::CODE_FEATURE_DISABLED,
    ) {
        parent::__construct($this->defaultMessage());
    }

    public static function featureDisabled(): self
    {
        return new self(self::CODE_FEATURE_DISABLED);
    }

    public static function installerNotAvailable(): self
    {
        return new self(self::CODE_INSTALLER_NOT_AVAILABLE);
    }

    private function defaultMessage(): string
    {
        return match ($this->edgeCode) {
            self::CODE_INSTALLER_NOT_AVAILABLE => 'The Bingoo Edge installer is not available in this release yet.',
            default                            => 'Offline Branch Edge is not released yet. It will be enabled for your account soon.',
        };
    }

    private function httpStatus(): int
    {
        return $this->edgeCode === self::CODE_INSTALLER_NOT_AVAILABLE ? 503 : 403;
    }

    public function render(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'code'    => $this->edgeCode,
            ], $this->httpStatus());
        }

        // Reuse the friendly module-disabled shell for a consistent, non-technical page.
        return response()->view('tenant.errors.module-disabled', [
            'message'   => $this->getMessage(),
            'reason'    => $this->edgeCode,
            'moduleKey' => 'offline_edge',
            'module'    => null,
        ], $this->httpStatus());
    }
}
