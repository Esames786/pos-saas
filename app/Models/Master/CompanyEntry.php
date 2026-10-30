<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MASTER-HISAB-1 — hisab ki ek entry: paisa aaya (in), kharcha (out), ya ek khate se doosre me (transfer).
 *
 * Ek entry ke kai hisse ho sakte hain (lines): Kashif Kitchen ke 1,50,000 do khaton me, ya Khatri ke 25,886
 * me mahana fee aur WhatsApp alag. Delete chhupata hai (deleted_at + wajah), mitata nahi.
 */
class CompanyEntry extends Model
{
    public const IN = 'in';

    public const OUT = 'out';

    public const TRANSFER = 'transfer';

    public const TYPES = [self::IN, self::OUT, self::TRANSFER];

    /** WhatsApp ka card wala kharcha — is par Meta ka bill ($), bank fee aur messages likhe jate hain. */
    public const WHATSAPP_COST = 'whatsapp_meta';

    /** WhatsApp ki aamdani (client se). */
    public const WHATSAPP_INCOME = 'whatsapp';

    /**
     * Kis cheez ka paisa. Label saada zaban me; icon har jagah saath. Nayi cheez "Aur" me note ke saath.
     *
     * @var array<string, array<string, array{label: string, icon: string}>>
     */
    public const CATEGORIES = [
        self::IN => [
            'monthly_fee' => ['label' => 'Mahana fee', 'icon' => '📅'],
            'setup_fee'   => ['label' => 'One-time fee', 'icon' => '🧩'],
            'whatsapp'    => ['label' => 'WhatsApp', 'icon' => '💬'],
            'website'     => ['label' => 'Website 1%', 'icon' => '🌐'],
            'other_in'    => ['label' => 'Aur aamdani', 'icon' => '💰'],
        ],
        self::OUT => [
            'ai_tools'      => ['label' => 'Claude / AI', 'icon' => '🤖'],
            'hosting'       => ['label' => 'Hosting / Server', 'icon' => '🖥️'],
            'whatsapp_meta' => ['label' => 'WhatsApp (Meta)', 'icon' => '💬'],
            'domain'        => ['label' => 'Domain / SSL', 'icon' => '🔒'],
            'marketing'     => ['label' => 'Marketing', 'icon' => '📣'],
            'salary'        => ['label' => 'Tankhwah', 'icon' => '👤'],
            'other_out'     => ['label' => 'Aur kharcha', 'icon' => '🧾'],
        ],
        self::TRANSFER => [
            'transfer' => ['label' => 'Ek khate se doosre mein', 'icon' => '🔁'],
        ],
    ];

    protected $connection = 'master';

    protected $table = 'company_entries';

    protected $fillable = [
        'entry_no', 'entry_date', 'type', 'tenant_id', 'note',
        'usd_amount', 'bank_fee', 'message_count',
        'created_by', 'updated_by', 'deleted_at', 'deleted_by', 'delete_reason',
    ];

    protected $casts = [
        'entry_date'    => 'date',
        'usd_amount'    => 'decimal:4',
        'bank_fee'      => 'decimal:2',
        'message_count' => 'integer',
        'deleted_at'    => 'datetime',
    ];

    public static function categoryLabel(string $category): string
    {
        foreach (self::CATEGORIES as $list) {
            if (isset($list[$category])) {
                return $list[$category]['icon'].' '.$list[$category]['label'];
            }
        }

        return $category;
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('deleted_at');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CompanyEntryLine::class, 'company_entry_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(CompanyEntryLog::class, 'company_entry_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(CentralUser::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(CentralUser::class, 'updated_by');
    }

    public function total(): float
    {
        return round((float) $this->lines->sum('amount'), 2);
    }

    /** Card wale WhatsApp kharche ka hisaab: bank ne bill ka kitna kata, dollar rate, tax %, fi message. */
    public function cardFigures(): ?array
    {
        if ($this->type !== self::OUT || $this->usd_amount === null || (float) $this->usd_amount <= 0) {
            return null;
        }

        $total = $this->total();
        $fee = (float) ($this->bank_fee ?? 0);
        $bill = round($total - $fee, 2);

        return [
            'usd'         => (float) $this->usd_amount,
            'bill'        => $bill,
            'fee'         => $fee,
            'total'       => $total,
            'rate'        => $bill > 0 ? round($bill / (float) $this->usd_amount, 2) : null,
            'tax_percent' => $bill > 0 ? round($fee / $bill * 100, 2) : null,
            'messages'    => $this->message_count,
            'per_message' => $this->message_count ? round($total / $this->message_count, 2) : null,
        ];
    }
}
