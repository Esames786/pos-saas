<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\ManagerApproval;
use App\Services\Sales\ManagerApprovalService;
use Illuminate\Http\Request;

class ManagerApprovalController extends Controller
{
    public function __construct(private readonly ManagerApprovalService $approvalService) {}

    public function verify(Request $request)
    {
        $data = $request->validate([
            'pin'           => ['required', 'string'],
            'action_type'   => ['required', 'string'],
            'amount'        => ['nullable', 'numeric'],
            'reason'        => ['nullable', 'string'],
            'payload'       => ['nullable', 'array'],
            'payload.sales_order_id' => ['nullable', 'integer'],
            'payload.sales_order_line_id' => ['nullable', 'integer'],
            'payload.branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'payload.client_uuid' => ['nullable', 'string', 'max:36'],
            'payload.discount_type' => ['nullable', 'in:fixed,percent'],
            'payload.discount_value' => ['nullable', 'numeric', 'gt:0'],
            'payload.discount_amount' => ['nullable', 'numeric', 'gt:0'],
            'payload.quantity' => ['nullable', 'numeric', 'gt:0'],
            'payload.cancellations' => ['nullable', 'array', 'min:1'],
            'payload.cancellations.*.line_id' => ['required_with:payload.cancellations', 'integer'],
            'payload.cancellations.*.quantity' => ['required_with:payload.cancellations', 'numeric', 'gt:0'],
        ]);

        try {
            $approval = $this->approvalService->verifyPin(
                $data['pin'],
                $data['action_type'],
                auth('tenant')->id(),
                $data['payload'] ?? null
            );

            return response()->json([
                'ok'           => true,
                'approval_id'  => $approval->id,
                'approval_no'  => $approval->approval_no,
            ]);
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
