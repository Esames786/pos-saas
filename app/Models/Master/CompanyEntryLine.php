<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MASTER-HISAB-1 — entry ka ek hissa: kis khate me / se, kis cheez ka, kitna.
 */
class CompanyEntryLine extends Model
{
    protected $connection = 'master';

    protected $table = 'company_entry_lines';

    protected $fillable = ['company_entry_id', 'account_id', 'to_account_id', 'category', 'amount'];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(CompanyEntry::class, 'company_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CompanyAccount::class, 'account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyAccount::class, 'to_account_id');
    }
}
