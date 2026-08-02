<?php

namespace App\Services\Sales;

use App\Models\Tenant\ManagerApproval;
use App\Models\Tenant\ManagerPin;
use App\Models\Tenant\SalesOrder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class ManagerApprovalService
{
    public function verifyPin(string $pin, string $actionType, int $requestingUserId, ?array $payload = null): ManagerApproval
    {
        $managerPins = ManagerPin::with('user')
            ->where('is_active', true)
            ->whereHas('user', fn ($query) => $query->where('status', 'active'))
            ->get();

        $managerPin = $managerPins->first(fn (ManagerPin $candidate) => Hash::check($pin, $candidate->pin_hash));

        if (!$managerPin) {
            throw new RuntimeException('Invalid manager PIN.');
        }

        $saleId = (int) ($payload['sales_order_id'] ?? 0);
        if ($saleId > 0) {
            $sale = SalesOrder::find($saleId);
            if (!$sale) {
                throw new RuntimeException('The order for this approval was not found.');
            }
            $manager = $managerPin->user;
            $hasBranchAccess = (int) $manager->default_branch_id === (int) $sale->branch_id
                || $manager->branches()->where('branches.id', $sale->branch_id)->wherePivot('is_active', true)->exists();
            if (!$hasBranchAccess) {
                throw new RuntimeException('This manager PIN is not authorized for the order branch.');
            }
        }

        $approvalNo = 'MA-' . now()->format('YmdHis') . '-' . Str::random(6);

        $managerPin->update(['last_used_at' => now()]);

        return ManagerApproval::create([
            'approval_no'        => $approvalNo,
            'action_type'        => $actionType,
            'requested_by_user_id' => $requestingUserId,
            'approved_by_user_id' => $managerPin->user_id,
            'approved_at'        => now(),
            'payload'            => $payload,
        ]);
    }

    public function consume(ManagerApproval $approval, string $actionType, int $requestingUserId, array $expectedPayload): ManagerApproval
    {
        $approval->refresh();

        if ($approval->consumed_at) {
            throw new RuntimeException('This manager approval has already been used.');
        }
        if ($approval->action_type !== $actionType || !$approval->approved_at || !$approval->approved_by_user_id) {
            throw new RuntimeException('Manager approval does not authorize this action.');
        }
        if ($approval->approved_at->lt(now()->subMinutes(10))) {
            throw new RuntimeException('This manager approval has expired. Request approval again.');
        }
        if ((int) $approval->requested_by_user_id !== $requestingUserId) {
            throw new RuntimeException('Manager approval belongs to another cashier request.');
        }

        $payload = $approval->payload ?? [];
        foreach ($expectedPayload as $key => $value) {
            if ($this->canonicalize($payload[$key] ?? null) !== $this->canonicalize($value)) {
                throw new RuntimeException('Manager approval does not match this order cancellation.');
            }
        }

        $approval->forceFill([
            'consumed_at' => now(),
            'consumed_by_user_id' => $requestingUserId,
        ])->save();

        return $approval;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return is_numeric($value) ? (string) (0 + $value) : $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn ($item) => $this->canonicalize($item), $value);
    }

    public function nextApprovalNo(): string
    {
        return 'MA-' . now()->format('YmdHis') . '-' . Str::random(6);
    }
}
