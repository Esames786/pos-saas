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
