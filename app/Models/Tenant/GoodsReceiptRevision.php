<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * PURCHASE-EDIT-1 — one edit or void of a posted receipt and its supplier invoice: what it was, what it became, the
 * plain-words change list the GRN page shows, and the journals it reversed and posted.
 */
class GoodsReceiptRevision extends Model
{
    public const EDIT = 'edit';

    public const VOID = 'void';

    protected $connection = 'tenant';

    protected $table = 'goods_receipt_revisions';

    protected $fillable = [
        'goods_receipt_id', 'purchase_bill_id', 'revision_no', 'action', 'before', 'after', 'changes', 'reason',
        'date_mode', 'reversed_journal_ids', 'journal_entry_id', 'created_by_user_id',
    ];

    protected $casts = [
        'before'               => 'array',
        'after'                => 'array',
        'changes'              => 'array',
        'reversed_journal_ids' => 'array',
    ];

    public function goodsReceipt()
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
