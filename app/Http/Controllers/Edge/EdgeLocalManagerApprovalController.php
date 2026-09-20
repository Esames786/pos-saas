<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Edge\Concerns\ResolvesEdgePosContext;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgeLocalPosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * W0c (Team 4 owns — permissions) — manager re-auth on the Branch Server. Split out of EdgeLocalPosController.
 *
 * The manager presents THEIR OWN Edge-local credential (employee code + local password — never a Cloud manager PIN,
 * which does not exist on an appliance) and receives a single-use approval consumed by the action that needs it.
 * The cashier session is untouched.
 */
class EdgeLocalManagerApprovalController extends Controller
{
    use ResolvesEdgePosContext;

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly EdgeLocalPosService $pos,
    ) {
    }

    public function verifyManagerApproval(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessCan('tenant.api.manager-approvals.verify', 'Requesting a manager approval needs the Manager Approval permission.')) {
            return $denied;
        }
        $data = $request->validate([
            'manager_employee_code' => ['required', 'string', 'max:64'],
            'manager_credential' => ['required', 'string', 'max:255'],
            'action_type' => ['required', 'string', 'max:80'],
            'payload' => ['nullable', 'array'],
        ]);
        try {
            $approval = $this->pos->verifyManagerApproval(
                $data['manager_employee_code'], $data['manager_credential'], $data['action_type'],
                auth('tenant')->user(), $data['payload'] ?? null
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['approval_id' => $approval->id, 'approval_no' => $approval->approval_no, 'approval_uuid' => $approval->approval_uuid], 201);
    }
}
