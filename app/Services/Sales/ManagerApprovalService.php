<?php

namespace App\Services\Sales;

use App\Models\Tenant\ManagerApproval;
use App\Models\Tenant\ManagerPin;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class ManagerApprovalService
{
    public function verifyPin(string $pin, string $actionType, int $userId, ?array $payload = null): ManagerApproval
    {
        $managerPin = ManagerPin::where('user_id', $userId)
            ->where('is_active', true)
            ->first();

        if (!$managerPin || !Hash::check($pin, $managerPin->pin_hash)) {
            throw new RuntimeException('Invalid manager PIN.');
        }

        $approvalNo = 'MA-' . now()->format('YmdHis') . '-' . Str::random(6);

        $managerPin->update(['last_used_at' => now()]);

        return ManagerApproval::create([
            'approval_no'        => $approvalNo,
            'action_type'        => $actionType,
            'approved_by_user_id' => $userId,
            'approved_at'        => now(),
            'payload'            => $payload,
        ]);
    }

    public function nextApprovalNo(): string
    {
        return 'MA-' . now()->format('YmdHis') . '-' . Str::random(6);
    }
}
