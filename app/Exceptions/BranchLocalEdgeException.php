<?php

namespace App\Exceptions;

use App\Models\Tenant\Branch;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * BRANCH-OPERATING-MODE-1: thrown when a sale-side mutation is attempted on the
 * wrong instance for a Local POS branch — either the cloud instance mutating an
 * active Local POS branch, or a Branch Server mutating a branch it is not bound
 * to. Self-renders a friendly 409 (JSON or redirect-back) — never a 500.
 */
class BranchLocalEdgeException extends RuntimeException
{
    public const CODE_ACTIVE            = 'BRANCH_LOCAL_EDGE_ACTIVE';
    public const CODE_NOT_BOUND         = 'BRANCH_SERVER_NOT_BOUND';
    public const CODE_TENANT_NOT_BOUND  = 'BRANCH_SERVER_TENANT_NOT_BOUND';
    public const CODE_BRANCH_NOT_BOUND  = 'BRANCH_SERVER_BRANCH_NOT_BOUND';
    public const CODE_INVALID_TRANSITION = 'BRANCH_LOCAL_EDGE_INVALID_TRANSITION';
    public const CODE_FEATURE_DISABLED  = 'BRANCH_LOCAL_EDGE_FEATURE_DISABLED';
    // EDGE-SPLITBRAIN-STOCK-1: a Branch Server tried to post OFFICIAL stock (FEFO/costing/valuation).
    // Stricter than a sale: official stock is Cloud authority even for the server's own bound branch —
    // the server tracks provisional quantity only, and Cloud-side sync ingestion posts the official row.
    public const CODE_BRANCH_SERVER_OFFICIAL_STOCK = 'BRANCH_SERVER_OFFICIAL_STOCK';

    public function __construct(
        public readonly ?Branch $branch = null,
        public readonly string $reasonCode = self::CODE_ACTIVE,
        ?string $overrideMessage = null,
    ) {
        parent::__construct($overrideMessage ?? $this->defaultMessage());
    }

    public function friendlyMessage(): string
    {
        return $this->getMessage();
    }

    private function defaultMessage(): string
    {
        return match ($this->reasonCode) {
            self::CODE_NOT_BOUND,
            self::CODE_TENANT_NOT_BOUND,
            self::CODE_BRANCH_NOT_BOUND   => 'This Branch Server is not configured for this branch.',
            self::CODE_INVALID_TRANSITION => 'This Local POS mode change is not allowed.',
            self::CODE_FEATURE_DISABLED   => 'Local POS setup is not enabled for this installation.',
            self::CODE_BRANCH_SERVER_OFFICIAL_STOCK => 'Official stock valuation is managed by the cloud. The Branch Server records provisional stock only; it will be reconciled on sync.',
            default                       => 'This branch is operating through Bingoo Local POS. Create and manage sales from the Branch Server.',
        };
    }

    public function render(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->friendlyMessage(),
                'code'    => $this->reasonCode,
            ], 409);
        }

        return back()->withErrors(['branch_local_edge' => $this->friendlyMessage()]);
    }
}
