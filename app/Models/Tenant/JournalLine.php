<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'branch_id',
        'counterparty_type',
        'supplier_id',
        'description',
        'debit',
        'credit',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'debit'  => 'decimal:4',
            'credit' => 'decimal:4',
        ];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * SUPPLIER-FINANCE-DIRECT-1 — jis satar ne Accounts Payable hilaya, wo KIS supplier ka tha.
     * Purani satrein aur system ke banaye journals par null rehta hai; sirf manual/interactive
     * AP posting par lazmi hai (ManualJournalController us shart ko lagata hai).
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
