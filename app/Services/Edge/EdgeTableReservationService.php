<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeTableReservation;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Customer;
use App\Models\Tenant\RestaurantTable;
use App\Models\Tenant\RestaurantTableSession;
use App\Models\Tenant\User;
use App\Support\EdgeRuntime;
use App\Support\EdgeUserAuthz;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * OFFLINE EDGE — ONLINE POS PARITY: table reservations, offline.
 *
 * Same operator behavior as the online POS (reserve / view / cancel + customer carry-over on open), but with
 * offline-correct authority and storage:
 *
 *   - AUTHORITY: reservations may be mutated here only on the Branch Server that is bound to the branch AND
 *     is holding sale authority (Local Mode active) — the same fence as sales (BranchOperatingModeService).
 *     While Local Mode is active the Cloud must not also mutate that branch's reservations (Cloud-side fence,
 *     the sales fence extended to reservations); this avoids a Cloud/Edge split-brain on the same table.
 *   - CONCURRENCY: reserve takes a `lockForUpdate` on the table row (the proven Edge pattern) and refuses if
 *     the table has an open session or an existing ACTIVE reservation — two terminals racing yield one winner.
 *   - STORAGE: state lives in the Edge-owned edge_local_table_reservations (backed up, recovery-safe), not on
 *     the shared config restaurant_tables row.
 *
 * The carry-over on open (seatOnOpen) is driven from EdgeLocalPosService::openTableSession inside its table
 * lock, so a reserved table's customer rides the new session and the first held sale.
 */
class EdgeTableReservationService
{
    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly BranchOperatingModeService $mode,
    ) {
    }

    /** Reserve a table. $data: customer_id?, customer_name?, customer_phone?, reserved_for?, note? */
    public function reserve(int $tableId, array $data, User $user): EdgeTableReservation
    {
        $branchId = $this->authorize($user);

        return DB::connection('tenant')->transaction(function () use ($tableId, $data, $user, $branchId) {
            $table = RestaurantTable::on('tenant')->where('id', $tableId)->where('branch_id', $branchId)
                ->where('status', '!=', 'inactive')->lockForUpdate()->first();
            if (! $table) {
                throw ValidationException::withMessages(['table' => 'Select an active table on this branch.']);
            }
            if (RestaurantTableSession::on('tenant')->where('restaurant_table_id', $table->id)->whereIn('status', ['open', 'bill_requested'])->lockForUpdate()->exists()) {
                throw new RuntimeException('Cannot reserve a table that is currently open.');
            }
            if (EdgeTableReservation::on('tenant')->where('restaurant_table_id', $table->id)->where('status', EdgeTableReservation::STATUS_ACTIVE)->lockForUpdate()->exists()) {
                throw new RuntimeException('This table already has an active reservation.');
            }

            [$customerId, $customerUuid, $name, $phone] = $this->resolveCustomer($data);
            $r = new EdgeTableReservation([
                'branch_id' => $branchId,
                'restaurant_table_id' => $table->id,
                'customer_id' => $customerId,
                'customer_uuid' => $customerUuid,
                'customer_name' => $name,
                'customer_phone' => $phone,
                'reserved_for' => $data['reserved_for'] ?? null,
                'note' => $data['note'] ?? null,
                'reserved_by_user_id' => $user->id,
                'status' => EdgeTableReservation::STATUS_ACTIVE,
                'reserved_at' => now(),
            ]);
            $r->reservation_uuid = (string) Str::ulid();
            $r->save();

            return $r->fresh();
        });
    }

    /** The active reservation for a table (for the "view" click), or null. */
    public function activeFor(int $tableId): ?EdgeTableReservation
    {
        $this->context->requireCurrent();

        return EdgeTableReservation::on('tenant')->where('restaurant_table_id', $tableId)
            ->where('status', EdgeTableReservation::STATUS_ACTIVE)->latest('id')->first();
    }

    /** Cancel the active reservation on a table. */
    public function cancel(int $tableId, User $user): EdgeTableReservation
    {
        $branchId = $this->authorize($user);

        return DB::connection('tenant')->transaction(function () use ($tableId, $branchId) {
            $r = EdgeTableReservation::on('tenant')->where('restaurant_table_id', $tableId)->where('branch_id', $branchId)
                ->where('status', EdgeTableReservation::STATUS_ACTIVE)->lockForUpdate()->latest('id')->first();
            if (! $r) {
                throw new RuntimeException('No active reservation to cancel on this table.');
            }
            $r->update(['status' => EdgeTableReservation::STATUS_CANCELLED, 'cancelled_at' => now()]);

            return $r->fresh();
        });
    }

    /**
     * Seat the active reservation onto a just-opened session — called from openTableSession INSIDE its table
     * lock. Returns the seated reservation (carrying the customer), or null when the table was not reserved.
     */
    public function seatOnOpen(int $tableId, int $sessionId): ?EdgeTableReservation
    {
        $r = EdgeTableReservation::on('tenant')->where('restaurant_table_id', $tableId)
            ->where('status', EdgeTableReservation::STATUS_ACTIVE)->lockForUpdate()->latest('id')->first();
        if (! $r) {
            return null;
        }
        $r->update(['status' => EdgeTableReservation::STATUS_SEATED, 'restaurant_table_session_id' => $sessionId, 'seated_at' => now()]);

        return $r->fresh();
    }

    /** Authority: Branch Server, bound branch, Local Mode active, user on the branch. Returns the branch id. */
    private function authorize(User $user): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('Reservations are managed on the Branch Server.');
        }
        $meta = $this->context->requireCurrent();
        $branchId = (int) $meta->branch_id;
        $branch = Branch::on('tenant')->find($branchId);
        if (! $branch || ! $this->mode->branchHandedToBranchServer($branch)) {
            throw new RuntimeException('This branch is not under Branch Server authority.');
        }
        if (! EdgeUserAuthz::isActive($user) || ! EdgeUserAuthz::isEdgeLoginEligible($user) || ! EdgeUserAuthz::mayOperateBranch($user, $branchId)) {
            throw new RuntimeException('This user is not authorized to manage reservations on this Branch Server.');
        }

        return $branchId;
    }

    /** @return array{0:?int,1:?string,2:?string,3:?string} [customer_id, customer_uuid, name, phone] */
    private function resolveCustomer(array $data): array
    {
        $customerId = isset($data['customer_id']) && $data['customer_id'] !== null && $data['customer_id'] !== '' ? (int) $data['customer_id'] : null;
        if ($customerId !== null) {
            $customer = Customer::on('tenant')->find($customerId);
            if ($customer) {
                return [$customerId, $customer->customer_uuid ?? null, $customer->name ?? ($data['customer_name'] ?? null), $customer->phone ?? ($data['customer_phone'] ?? null)];
            }
        }

        return [null, null, $data['customer_name'] ?? null, $data['customer_phone'] ?? null];
    }
}
