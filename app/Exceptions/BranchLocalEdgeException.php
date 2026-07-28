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
    public const CODE_ACTIVE   = 'BRANCH_LOCAL_EDGE_ACTIVE';
    public const CODE_NOT_BOUND = 'BRANCH_SERVER_NOT_BOUND';

    public function __construct(
        public readonly ?Branch $branch = null,
        public readonly string $reasonCode = self::CODE_ACTIVE,
    ) {
        parent::__construct($this->friendlyMessage());
    }

    public function friendlyMessage(): string
    {
        return $this->reasonCode === self::CODE_NOT_BOUND
            ? 'This Branch Server is not configured for this branch.'
            : 'This branch is operating through Bingoo Local POS. Create and manage sales from the Branch Server.';
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
