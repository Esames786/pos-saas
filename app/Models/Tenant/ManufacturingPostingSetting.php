<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Manufacturing posting settings (MFG-FIN-A, Phase A).
 *
 * Holds the account mapping + inventory policy a FUTURE posting layer will read.
 * This model stores configuration only — it does NOT post anything. `canPost()`
 * merely reports whether configuration is complete enough that posting *could*
 * run once the posting engine (Phase C+) exists.
 */
class ManufacturingPostingSetting extends Model
{
    protected $connection = 'tenant';

    /** Phase A allows exactly one safe value for each policy. */
    public const NEGATIVE_STOCK_POLICIES = ['block'];
    public const COSTING_METHODS = ['moving_average'];
    public const FG_COST_SOURCES = ['wip_actual'];

    /** Account mapping roles that MUST be set before posting can be enabled. */
    public const REQUIRED_ACCOUNTS = [
        'raw_material_inventory_account_id',
        'wip_inventory_account_id',
        'finished_goods_inventory_account_id',
        'scrap_expense_account_id',
        'rework_expense_account_id',
        'production_variance_account_id',
        'manufactured_cogs_account_id',
        'inventory_adjustment_account_id',
    ];

    /** Optional in Phase A (labour/overhead absorption is a later phase). */
    public const OPTIONAL_ACCOUNTS = [
        'manufacturing_overhead_account_id',
        'direct_labour_account_id',
    ];

    /** Mapping roles that must point at an ASSET account. */
    public const ASSET_ACCOUNTS = [
        'raw_material_inventory_account_id',
        'wip_inventory_account_id',
        'finished_goods_inventory_account_id',
        'manufacturing_overhead_account_id',
    ];

    /** Mapping roles that must point at an EXPENSE account. */
    public const EXPENSE_ACCOUNTS = [
        'direct_labour_account_id',
        'scrap_expense_account_id',
        'rework_expense_account_id',
        'production_variance_account_id',
        'manufactured_cogs_account_id',
        'inventory_adjustment_account_id',
    ];

    protected $fillable = [
        'branch_id',
        'is_enabled',
        'raw_material_inventory_account_id',
        'wip_inventory_account_id',
        'finished_goods_inventory_account_id',
        'manufacturing_overhead_account_id',
        'direct_labour_account_id',
        'scrap_expense_account_id',
        'rework_expense_account_id',
        'production_variance_account_id',
        'manufactured_cogs_account_id',
        'inventory_adjustment_account_id',
        'negative_stock_policy',
        'costing_method',
        'fg_cost_source',
        'labour_absorption_enabled',
        'overhead_absorption_enabled',
        'notes',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled'                  => 'boolean',
            'labour_absorption_enabled'   => 'boolean',
            'overhead_absorption_enabled' => 'boolean',
        ];
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** The single tenant-default row (no branch override). */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->whereNull('branch_id');
    }

    public function scopeForBranch(Builder $query, ?int $branchId): Builder
    {
        return $branchId === null
            ? $query->whereNull('branch_id')
            : $query->where('branch_id', $branchId);
    }

    // ── Readiness helpers (report only — they post nothing) ─────────────────────

    /** True when every REQUIRED account mapping is set. */
    public function isComplete(): bool
    {
        return $this->raw_material_inventory_account_id
            && $this->wip_inventory_account_id
            && $this->finished_goods_inventory_account_id
            && $this->scrap_expense_account_id
            && $this->rework_expense_account_id
            && $this->production_variance_account_id
            && $this->manufactured_cogs_account_id
            && $this->inventory_adjustment_account_id;
    }

    /** True only when enabled AND complete. Does NOT trigger any posting. */
    public function canPost(): bool
    {
        return (bool) $this->is_enabled && $this->isComplete();
    }

    /** @return array<int, string> required mapping keys still empty. */
    public function missingRequired(): array
    {
        return array_values(array_filter(
            self::REQUIRED_ACCOUNTS,
            fn (string $key) => empty($this->{$key})
        ));
    }

    // ── Relations ───────────────────────────────────────────────────────────────

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function rawMaterialInventoryAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'raw_material_inventory_account_id');
    }

    public function wipInventoryAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'wip_inventory_account_id');
    }

    public function finishedGoodsInventoryAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'finished_goods_inventory_account_id');
    }

    public function manufacturingOverheadAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'manufacturing_overhead_account_id');
    }

    public function directLabourAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'direct_labour_account_id');
    }

    public function scrapExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'scrap_expense_account_id');
    }

    public function reworkExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'rework_expense_account_id');
    }

    public function productionVarianceAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'production_variance_account_id');
    }

    public function manufacturedCogsAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'manufactured_cogs_account_id');
    }

    public function inventoryAdjustmentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'inventory_adjustment_account_id');
    }
}
