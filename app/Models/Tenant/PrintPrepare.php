<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRINT-PREPARE-1 — "screen khulte hi parcha tayyar". Agent is ka PDF bana kar apne paas rakhta hai aur
 * CHHAPTA NAHI; Send aaye to wohi PDF foran printer ko. Asli parcha `print_jobs` me hi rehta hai.
 *
 * `fingerprint` = sha256(document ka HTML, "chhapne ka waqt" hata kar, + kaghaz). Send ka parcha isi se milaya jata
 * hai, is liye badla hua order kabhi purani PDF se nahi chhapta.
 */
class PrintPrepare extends Model
{
    protected $connection = 'tenant';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    /** Kaghaz par chhapne ka waqt PDF ke andar hai — is se purani PDF Send par istemal nahi hoti (agent 10 min rakhta hai). */
    public const FRESH_MINUTES = 9;

    /** Claim ke itne baad bhi tayyar na hui to doosra poll dobara de sakta hai (agent band ho gaya ho). */
    public const CLAIM_LEASE_SECONDS = 90;

    protected $fillable = [
        'printer_id', 'branch_id', 'doc_key', 'fingerprint', 'paper', 'html',
        'status', 'claimed_by_agent_id', 'claimed_at', 'ready_at', 'error',
    ];

    protected $casts = [
        'claimed_at' => 'datetime',
        'ready_at' => 'datetime',
    ];

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    /** Ek din se purani rows — asli parche nahi, server par jama na hon. `printing:autoclose-stuck` (har 15 min) bulata hai. */
    public static function purgeOlderThanADay(bool $dryRun = false): int
    {
        $old = static::query()->where('updated_at', '<', now()->subDay());
        $count = (clone $old)->count();
        if ($count > 0 && ! $dryRun) {
            $old->delete();
        }

        return $count;
    }

    /** Tayyar, aur itni taaza ke kaghaz ka waqt sahi rahe. */
    public function isFreshReady(): bool
    {
        return $this->status === self::STATUS_READY
            && $this->ready_at !== null
            && $this->ready_at->greaterThan(now()->subMinutes(self::FRESH_MINUTES));
    }
}
