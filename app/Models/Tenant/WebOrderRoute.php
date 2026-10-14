<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ONLINE-ORDERING-1 — aik branch par aik website order type kis terminal par aaye (ghanti + draft).
 *
 * Terminal AUR order type dono, kyunke POS ke drafts/Held/shift terminal ke hisaab se chalte hain
 * aur KOT ka printer `category_printer_mappings` (terminal + order type) se chunta hai.
 */
class WebOrderRoute extends Model
{
    protected $connection = 'tenant';

    protected $table = 'web_order_routes';

    /** Website par jo order types milte hain (POS ke naam → customer ka naam). */
    public const ORDER_TYPES = ['delivery' => 'Delivery', 'takeaway' => 'Pick-Up'];

    protected $fillable = ['branch_id', 'order_type', 'terminal_id', 'backup_terminal_id'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function backupTerminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class, 'backup_terminal_id');
    }
}
