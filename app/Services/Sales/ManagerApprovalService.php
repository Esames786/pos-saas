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
    /**
     * PHASE 3 approver-eligibility contract (owner §1, Cloud AND Edge — ONE map): the approver must hold the
     * permission the approved ACTION itself needs. Shared by the Cloud creator below and by
     * EdgeLocalPosService::MANAGER_ACTION_PERMISSIONS / EdgeLocalAuthService::verifyManager. An action type not
     * listed here carries no extra permission requirement on the Cloud (the Edge fails closed on it — no offline
     * approval contract exists for it).
     */
    public const ACTION_PERMISSIONS = [
        'manual_discount' => 'tenant.pos.store',                 // the discount lands on a POS sale
        'void_kot_item' => 'tenant.pos.void-kot-item',           // KotCancellationService demands it of the requester too
        'void_kot_items' => 'tenant.pos.void-kot-item',
        'cancel_held_order' => 'tenant.held-sales.cancel',       // the held-order cancel gate
        'sales_return' => 'tenant.sales-returns.store',          // RETURN-MANAGER-APPROVAL: the return itself
    ];

    public const NOT_AUTHORIZED_FOR_ACTION = 'This user is not authorized to approve that action.';

    /**
     * The Cloud permission check for an approver, independent of the request's auth guard: Spatie permissions of the
     * tenant application are `guard_name = tenant` (EnsureRoutePermission's can() resolves that guard inside a tenant
     * request; a service-level caller may run under no guard at all). A permission row that does not exist is "not held".
     */
    public static function holdsTenantPermission($user, string $permission): bool
    {
        // Authoritative: the tenant's Spatie tables on the USER's connection (direct grant or through a role). This is
        // independent of the registrar's cache/default connection, so a service-level caller (no tenant request, default
        // connection = master) answers the same as EnsureRoutePermission does inside a tenant request.
        $db = \Illuminate\Support\Facades\DB::connection($user->getConnectionName() ?: 'tenant');
        $permissionId = (int) $db->table('permissions')->where('name', $permission)->where('guard_name', 'tenant')->value('id');
        if ($permissionId <= 0) {
            return false; // the tenant never defined it, so nobody holds it
        }
        $types = array_values(array_unique([get_class($user), $user->getMorphClass()]));
        $direct = $db->table('model_has_permissions')->where('permission_id', $permissionId)
            ->where('model_id', $user->getKey())->whereIn('model_type', $types)->exists();
        if ($direct) {
            return true;
        }

        return $db->table('model_has_roles')
            ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'model_has_roles.role_id')
            ->where('role_has_permissions.permission_id', $permissionId)
            ->where('model_has_roles.model_id', $user->getKey())->whereIn('model_has_roles.model_type', $types)
            ->exists();
    }

    /**
     * CLOUD manager identity: resolve the manager by their manager PIN (Hash::check over active pins),
     * then mint the approval via the SHARED creator below. Behaviorally identical to the original.
     */
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

        // Owner §1 (Cloud identity path): "approver must still possess the permission required for the action being
        // approved" — a manager PIN is identity, not authority over the action. The Edge enforces the same map in
        // EdgeLocalAuthService::verifyManager (its permissions live in the flattened bootstrap graph, not Spatie).
        if ((int) $managerPin->user_id === $requestingUserId) {
            // self-approval is refused before anything else (the shared creator refuses it again — defence in depth)
            throw new RuntimeException('You cannot approve your own request. Ask another manager to approve.');
        }
        $actionPermission = self::ACTION_PERMISSIONS[$actionType] ?? null;
        if ($actionPermission !== null && ! self::holdsTenantPermission($managerPin->user, $actionPermission)) {
            throw new RuntimeException(self::NOT_AUTHORIZED_FOR_ACTION);
        }

        $managerPin->update(['last_used_at' => now()]);

        return $this->createApprovalForAuthenticatedManager($managerPin->user, $actionType, $requestingUserId, $payload);
    }

    /**
     * The ONE approval creator, shared by both manager-identity paths:
     *   - Cloud: verifyPin() above (manager_pins Hash::check);
     *   - Edge:  EdgeLocalAuthService::verifyManager() (the manager's own Edge-local credential —
     *     manager_pins NEVER ship to an appliance), which authenticates/authorizes the manager and then
     *     calls this with the proven identity.
     * The caller MUST have already authenticated the manager; this method owns the remaining approval
     * semantics: order-branch authorization, approval identity (approval_no + approval_uuid via the
     * model), action_type, requester/approver binding, payload binding, approved_at (10-min expiry and
     * single-use consumption are enforced by consume()).
     */
    public function createApprovalForAuthenticatedManager($manager, string $actionType, int $requestingUserId, ?array $payload = null): ManagerApproval
    {
        // Phase 3 approver-eligibility contract (Cloud AND Edge — this is the ONE creator): the approver must be a
        // different person from the requester, and must be an active user. Both are refused SERVER-SIDE with a
        // business message (never only hidden in the UI). Cloud verifyPin() already filters inactive users; this is
        // the authority of record for both runtimes.
        if ((int) $manager->id === $requestingUserId) {
            throw new RuntimeException('You cannot approve your own request. Ask another manager to approve.');
        }
        if (($manager->status ?? null) !== 'active') {
            throw new RuntimeException('This manager account is deactivated and cannot approve.');
        }
        $saleId = (int) ($payload['sales_order_id'] ?? 0);
        $branchId = (int) ($payload['branch_id'] ?? 0);
        if ($saleId > 0) {
            $sale = SalesOrder::find($saleId);
            if (!$sale) {
                throw new RuntimeException('The order for this approval was not found.');
            }
            $branchId = (int) $sale->branch_id;
        }

        if ($branchId > 0) {
            $hasBranchAccess = (int) $manager->default_branch_id === $branchId
                || $manager->branches()->where('branches.id', $branchId)->wherePivot('is_active', true)->exists();
            if (!$hasBranchAccess) {
                throw new RuntimeException('This manager is not authorized for the order branch.');
            }
        }

        return ManagerApproval::create([
            'approval_no'        => 'MA-' . now()->format('YmdHis') . '-' . Str::random(6),
            'action_type'        => $actionType,
            'requested_by_user_id' => $requestingUserId,
            'approved_by_user_id' => $manager->id,
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
        if ((int) $approval->approved_by_user_id === $requestingUserId) {
            // Phase 3: an approval can never be consumed by its own approver (defence in depth behind the creator rule).
            throw new RuntimeException('A manager approval cannot be used by the manager who granted it.');
        }

        $payload = $approval->payload ?? [];
        foreach ($expectedPayload as $key => $value) {
            if ($this->canonicalize($payload[$key] ?? null) !== $this->canonicalize($value)) {
                throw new RuntimeException('Manager approval does not match this action.');
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
