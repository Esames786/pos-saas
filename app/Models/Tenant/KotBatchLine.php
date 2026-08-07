<?php

namespace App\Models\Tenant;

use App\Models\Concerns\HasCanonicalIdentity;
use Illuminate\Database\Eloquent\Model;

class KotBatchLine extends Model
{
    use HasCanonicalIdentity;

    protected $connection = 'tenant';

    /** EDGE-IDENTITY-1: canonical cross-system identity of the KOT snapshot line (immutable; not mass-assignable). */
    protected string $canonicalIdentityColumn = 'kot_line_uuid';

    protected $fillable = [
        'kot_batch_id', 'sales_order_line_id', 'product_id', 'product_variant_id',
        'product_name', 'variant_name', 'unit_code', 'line_kind', 'combo_id',
        'quantity', 'modifiers',
        'kitchen_note',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'modifiers' => 'array'];
    }
}
