<?php

namespace App\Services\Central;

use App\Models\Master\Tenant;
use App\Models\Tenant\Branch;
use App\Services\Saas\TenantSubscriptionAccessService;
use App\Services\Tenancy\TenancyManager;
use App\Support\Business;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * MASTER-UNIVERSE-1 — a tenant's branches, seen (and added) from Bingoo Master.
 *
 * Adding a branch on the tenant's own Branches screen is one `Branch::create()` after the plan's
 * branch limit check, with fixed defaults for the approval modes (Tenant\BranchController::store /
 * validateBranch). This does exactly that and nothing more — no terminals, printers or users are made
 * (the tenant's screen does not make them either). Every add is recorded in tenant_setting_changes.
 */
class TenantBranchService
{
    public function __construct(
        private TenancyManager $tenancy,
        private TenantSubscriptionAccessService $access,
    ) {}

    /**
     * Read-only list with terminal and user counts, plus the plan's branch limit.
     *
     * @return array{ok:bool, branches:array<int,array<string,mixed>>, limit:?array, error?:string}
     */
    public function list(Tenant $tenant): array
    {
        $out = ['ok' => false, 'branches' => [], 'limit' => null];
        if (! $tenant->database || $tenant->database->migration_status !== 'completed') {
            $out['error'] = 'No database yet — provision the tenant first.';

            return $out;
        }

        try {
            $this->tenancy->activate($tenant);
            $c = DB::connection('tenant');
            $terminals = $c->table('terminals')->groupBy('branch_id')->selectRaw('branch_id, COUNT(*) AS n')->pluck('n', 'branch_id');
            $users = $c->getSchemaBuilder()->hasTable('branch_user')
                ? $c->table('branch_user')->groupBy('branch_id')->selectRaw('branch_id, COUNT(DISTINCT user_id) AS n')->pluck('n', 'branch_id')
                : collect();
            foreach ($c->table('branches')->orderBy('id')->get(['id', 'code', 'name', 'address', 'phone', 'status', 'business_type']) as $b) {
                $out['branches'][] = (array) $b + [
                    'terminals' => (int) ($terminals[$b->id] ?? 0),
                    'users'     => (int) ($users[$b->id] ?? 0),
                ];
            }
            $out['limit'] = $this->access->checkLimit($tenant, 'branches');
            $out['ok'] = true;
        } catch (Throwable $e) {
            $out['error'] = 'Could not read this tenant right now: ' . mb_substr($e->getMessage(), 0, 140);
        } finally {
            $this->tenancy->deactivate();
            DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        }

        return $out;
    }

    /** The branch type a new branch starts with, from the tenant's primary business. */
    public static function defaultType(?string $business): string
    {
        return match ($business) {
            Business::RESTAURANT => 'restaurant',
            Business::MART, Business::PHARMACY => 'store',
            default => 'hybrid',
        };
    }

    /**
     * Same insert as the tenant's own Branches screen. Throws RuntimeException with a plain reason.
     *
     * @param  array{name:string, code?:?string, address?:?string, phone?:?string, business_type?:?string, timezone?:?string}  $data
     */
    public function create(Tenant $tenant, array $data, ?int $centralUserId): string
    {
        if (! $tenant->database || $tenant->database->migration_status !== 'completed') {
            throw new RuntimeException('This tenant has no database yet — provision it first.');
        }

        $name = trim((string) $data['name']);
        $code = trim((string) ($data['code'] ?? '')) ?: null;

        try {
            $this->tenancy->activate($tenant);

            $limit = $this->access->checkLimit($tenant, 'branches');
            if (! $limit['allowed']) {
                throw new RuntimeException($limit['message'] ?: 'The plan does not allow another branch.');
            }
            if ($code !== null && Branch::where('code', $code)->exists()) {
                throw new RuntimeException('Branch code "' . $code . '" is already used by this tenant.');
            }
            if (Branch::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
                throw new RuntimeException('A branch named "' . $name . '" already exists.');
            }

            Branch::create([
                'code'          => $code,
                'name'          => $name,
                'business_type' => $data['business_type'] ?? self::defaultType($tenant->primary_business),
                'address'       => $data['address'] ?? null,
                'phone'         => $data['phone'] ?? null,
                'timezone'      => $data['timezone'] ?? ($tenant->timezone ?: 'Asia/Karachi'),
                'status'        => 'active',
                // Exactly the defaults Tenant\BranchController::validateBranch gives a new branch.
                'allow_negative_stock'        => false,
                'hide_amounts_from_operators' => false,
                'delivery_charge_locked'      => false,
                'default_delivery_charge'     => 0,
                'held_kot_cancellation_approval_mode'      => Branch::KOT_CANCELLATION_MANAGER_REQUIRED,
                'held_kot_line_cancellation_approval_mode' => Branch::KOT_CANCELLATION_MANAGER_REQUIRED,
                'manual_discount_approval_mode'            => Branch::MANUAL_DISCOUNT_MANAGER_REQUIRED,
                'sales_return_approval_mode'               => Branch::SALES_RETURN_AUTO_APPROVE,
            ]);
        } finally {
            $this->tenancy->deactivate();
            DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        }

        $label = $name . ($code ? ' (' . $code . ')' : '');
        DB::connection('master')->table('tenant_setting_changes')->insert([
            'tenant_id' => $tenant->id, 'setting' => 'branch:add', 'old_value' => null, 'new_value' => $label,
            'changed_by_central_user_id' => $centralUserId, 'note' => 'Added from Bingoo Master',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $label;
    }
}
