<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * KASHIF-CATERING-RATE-IMPACT-1 — the record of a price being moved.
 *
 * Written once, never updated. Every row answers the same question from a
 * different angle: somebody decided that this material's house rate should now
 * apply to this dish / this draft / this revision, and here is what it was
 * before and what it became.
 *
 * The rate book says what the house charges. This says what was DONE about it.
 */
class CateringCommercialRateApplication extends Model
{
    protected $connection = 'tenant';

    /** A new house rate was recorded in the book. Reprices nothing by itself. */
    public const ACTION_RATE_RECORDED = 'rate_recorded';

    /** A cost block was pointed at the book, or taken off it, by an operator. */
    public const ACTION_BLOCK_LINKED = 'block_linked';

    public const ACTION_BLOCK_UNLINKED = 'block_unlinked';

    /** The house rate was applied to a dish — changes FUTURE quotations only. */
    public const ACTION_PRODUCT_APPLIED = 'product_applied';

    /** The house rate was applied to a draft quotation, repricing that one. */
    public const ACTION_DRAFT_APPLIED = 'draft_applied';

    /** A sent quotation was revised, and the new version took the house rate. */
    public const ACTION_REVISION_APPLIED = 'revision_applied';

    public const TARGET_COMMERCIAL_RATE = 'commercial_rate';

    public const TARGET_PRODUCT_BLOCK = 'product_block';

    public const TARGET_ESTIMATE_SNAPSHOT = 'estimate_snapshot';

    protected $fillable = [
        'material_product_id',
        'material_name',
        'action',
        'target_type',
        'target_id',
        'target_label',
        'catering_estimate_id',
        'old_commercial_rate',
        'new_commercial_rate',
        'old_calculated_rate',
        'new_calculated_rate',
        'performed_by_user_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'old_commercial_rate' => 'decimal:4',
            'new_commercial_rate' => 'decimal:4',
            'old_calculated_rate' => 'decimal:2',
            'new_calculated_rate' => 'decimal:2',
        ];
    }

    public function material()
    {
        return $this->belongsTo(Product::class, 'material_product_id');
    }

    public function performedBy()
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }
}
