<?php

namespace App\Models\Tenant;

use App\Models\Concerns\HasCanonicalIdentity;
use Illuminate\Database\Eloquent\Model;

/**
 * CATERING-SLICE-3: operational advance record for the event balance display.
 * V1 HARD RULE: no GL, no cash-bank, no shift mutation (spec §19). Future
 * finance posting will reuse JournalPostingService via a translator method.
 */
class CateringAdvance extends Model
{
    use HasCanonicalIdentity;

    protected $connection = 'tenant';

    protected string $canonicalIdentityColumn = 'advance_uuid';

    protected $fillable = [
        'catering_event_id',
        'amount',
        'received_date',
        'payment_method_id',
        'reference',
        'notes',
        'recorded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'received_date' => 'date',
        ];
    }

    public function event()
    {
        return $this->belongsTo(CateringEvent::class, 'catering_event_id');
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
