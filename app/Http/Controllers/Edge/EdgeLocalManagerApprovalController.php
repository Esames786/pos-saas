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
        // R4.1 PAYLOAD VALIDATION parity: the SAME payload rules as Online ManagerApprovalController@verify — the keys an
        // approval is bound to are type-checked BEFORE a credential is verified or an approval row is minted (a malformed
        // binding is a 422, never a stored approval that can never match). Only the manager identity differs: the manager's
        // own Edge credential (employee code + local password) instead of a Cloud manager PIN, which never ships to an appliance.
        $data = $request->validate([
            'manager_employee_code' => ['required', 'string', 'max:64'],
            'manager_credential' => ['required', 'string', 'max:255'],
            'action_type' => ['required', 'string', 'max:80'],
            'amount' => ['nullable', 'numeric'],
            'reason' => ['nullable', 'string'],
            'payload' => ['nullable', 'array'],
            'payload.sales_order_id' => ['nullable', 'integer'],
            'payload.sales_order_line_id' => ['nullable', 'integer'],
            'payload.branch_id' => ['nullable', 'integer', 'exists:tenant.branches,id'],
            'payload.client_uuid' => ['nullable', 'string', 'max:36'],
            'payload.discount_type' => ['nullable', 'in:fixed,percent'],
            'payload.discount_value' => ['nullable', 'numeric', 'gt:0'],
            'payload.discount_amount' => ['nullable', 'numeric', 'gt:0'],
            'payload.quantity' => ['nullable', 'numeric', 'gt:0'],
            'payload.refund_method' => ['nullable', 'string', 'max:30'],
            'payload.refund_amount' => ['nullable', 'numeric', 'min:0'],
            'payload.cancellations' => ['nullable', 'array', 'min:1'],
            'payload.cancellations.*.line_id' => ['required_with:payload.cancellations', 'integer'],
            'payload.cancellations.*.quantity' => ['required_with:payload.cancellations', 'numeric', 'gt:0'],
        ]);
        try {
            $approval = $this->pos->verifyManagerApproval(
                $data['manager_employee_code'], $data['manager_credential'], $data['action_type'],
                auth('tenant')->user(), $request->input('payload')
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['approval_id' => $approval->id, 'approval_no' => $approval->approval_no, 'approval_uuid' => $approval->approval_uuid], 201);
    }
}
