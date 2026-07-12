<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * PURCHASE-RETURNS-1 — supplier purchase return document.
 * Posting reduces official branch stock + supplier payable; posted docs are immutable.
 */
class PurchaseReturn extends Model
{
    protected $connection = 'tenant';

    public const REASON_CODES = [
        'damaged',
        'expired',
        'wrong_item',
        'over_supply',
        'quality_issue',
        'price_dispute',
        'other',
    ];

    protected $fillable = [
        'branch_id',
        'supplier_id',
        'purchase_order_id',
        'goods_receipt_id',
        'return_no',
        'return_date',
        'status',
        'subtotal',
        'tax_total',
        'discount_total',
        'grand_total',
        'reason_code',
        'notes',
        'journal_entry_id',
        'posted_by',
        'posted_at',
        'cancelled_by',
        'cancelled_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'return_date'    => 'date',
            'subtotal'       => 'decimal:4',
            'tax_total'      => 'decimal:4',
            'discount_total' => 'decimal:4',
            'grand_total'    => 'decimal:4',
            'posted_at'      => 'datetime',
            'cancelled_at'   => 'datetime',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function goodsReceipt()
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function lines()
    {
        return $this->hasMany(PurchaseReturnLine::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function postedBy()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function canEdit(): bool
    {
        return $this->isDraft();
    }
}
