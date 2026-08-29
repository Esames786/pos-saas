<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeTableReservation;
use App\Models\Tenant\Customer;
use App\Models\Tenant\RestaurantTable;
use App\Models\Tenant\RestaurantTableSession;
use App\Support\EdgeRuntime;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * OFFLINE EDGE — ONLINE POS PARITY: reservation Local-Mode -> Cloud HANDBACK.
 *
 * When a branch returns reservation authority to the Cloud, the appliance's Edge-owned active reservations
 * (edge_local_table_reservations) must become canonical Cloud reservation state (restaurant_tables.reserved_*)
 * — the shape the online POS reads. This is a controlled, atomic projection, NOT last-write-wins:
 *
 *   - only CURRENT ACTIVE Edge reservations project (cancelled / seated / already-handed-back are audit
 *     history and are skipped);
 *   - an OCCUPIED table or one with an OPEN session is never projected as reserved;
 *   - the customer is resolved by canonical customer_uuid -> Cloud Customer.id (never by name/phone/local-id
 *     guess); an unknown customer_uuid on an attached-customer reservation FAILS CLOSED; a walk-in projects
 *     its name/phone snapshot with a null customer_id;
 *   - a CONFLICT (the target table already carries a Cloud reservation) FAILS CLOSED — the Cloud truth is
 *     never overwritten;
 *   - the whole projection is ONE transaction: any failure rolls it all back and the appliance KEEPS
 *     reservation authority (nothing half-projected);
 *   - it is IDEMPOTENT: a completed reservation is marked handed_back, so a replay finds nothing active.
 *
 * The projection target is the canonical restaurant_tables reserved_* shape on the tenant connection; the
 * transport of that state to the Cloud tenant DB reuses the established sync/authority-transfer channel.
 */
class EdgeReservationHandbackService
{
    public function __construct(private readonly EdgeBranchContext $context)
    {
    }

    /**
     * Project active Edge reservations into canonical Cloud reserved_* state, atomically. Returns a summary.
     * Throws (rolling everything back, Edge authority retained) on any conflict / unknown customer / incoherence.
     */
    public function handback(): array
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('HANDBACK_NOT_BRANCH_SERVER: reservation handback runs on the Branch Server.');
        }
        $branchId = (int) $this->context->requireCurrent()->branch_id;

        return DB::connection('tenant')->transaction(function () use ($branchId) {
            $active = EdgeTableReservation::on('tenant')
                ->where('branch_id', $branchId)
                ->where('status', EdgeTableReservation::STATUS_ACTIVE)
                ->orderBy('id')->lockForUpdate()->get();

            $projected = 0;
            foreach ($active as $r) {
                $table = RestaurantTable::on('tenant')->where('id', $r->restaurant_table_id)->lockForUpdate()->first();
                if (! $table) {
                    throw new RuntimeException('HANDBACK_TABLE_MISSING: reservation references a table that no longer exists.');
                }

                // An active reservation on an open/occupied table is incoherent (concurrency prevents it);
                // never project such a table as reserved.
                if (RestaurantTableSession::on('tenant')->where('restaurant_table_id', $table->id)->whereIn('status', ['open', 'bill_requested'])->exists()) {
                    throw new RuntimeException('HANDBACK_TABLE_OPEN: an active reservation sits on an open table — resolve it before handback.');
                }
                if ($table->status === 'occupied') {
                    throw new RuntimeException('HANDBACK_TABLE_OCCUPIED: an active reservation sits on an occupied table.');
                }

                // Conflict: the Cloud table already carries a reservation — never overwrite it.
                if ($table->status === 'reserved' && $table->reserved_at !== null) {
                    throw new RuntimeException('HANDBACK_CONFLICT: the Cloud table already has a reservation; refusing to overwrite.');
                }

                // Resolve the customer by canonical uuid; walk-ins keep name/phone with a null id.
                $customerId = null;
                if ($r->customer_uuid) {
                    $customer = Customer::on('tenant')->where('customer_uuid', $r->customer_uuid)->first();
                    if (! $customer) {
                        throw new RuntimeException('HANDBACK_UNKNOWN_CUSTOMER: the reserved customer_uuid does not resolve to a Cloud customer.');
                    }
                    $customerId = (int) $customer->id;
                }

                $table->update([
                    'status' => 'reserved',
                    'reserved_customer_id' => $customerId,
                    'reserved_name' => $r->customer_name,
                    'reserved_phone' => $r->customer_phone,
                    'reserved_for' => $r->reserved_for,
                    'reservation_note' => $r->note,
                    'reserved_by_user_id' => $r->reserved_by_user_id,
                    'reserved_at' => $r->reserved_at,
                ]);
                $r->update(['status' => 'handed_back', 'handed_back_at' => now()]);
                $projected++;
            }

            return ['projected' => $projected, 'branch_id' => $branchId];
        });
    }
}
