<?php

namespace App\Support;

use App\Models\Tenant\KotBatch;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use Carbon\Carbon;

/**
 * KOT-TIME-TRUTH-1 — ek KOT par kaunsa waqt chhapna chahiye.
 *
 * Pehle dono raaste (ESC/POS bytes aur preview blade) seedha `now()` likhte the: parchi kabhi
 * order se poochti hi nahi thi ke wo kab aaya. Nateeja ye ke saat din purani KOT reprint karo to
 * aaj ki tareekh aur abhi ka waqt chhapta tha — owner ne yehi pakra tha.
 *
 * Ab dono raaste YAHIN se waqt lete hain. Ye class isi liye alag hai: pichli baar bhi ek raasta
 * theek tha aur doosra kharab, aur guard sirf ek ko parhta tha. Ek jagah rakh dene se wo faasla
 * dobara ban hi nahi sakta.
 */
class KotTicketTime
{
    /**
     * Jis waqt ye ROUND manga gaya — reprint par bhi wohi waqt.
     *
     * KOT ek poore bill ki nahi, ek round ki parchi hoti hai: pehla punch, phir har addition apni
     * alag parchi. Is liye sahi jawab us round ke batch ka apna waqt hai, na ke bill ka. Job ke
     * payload me `kot_batch_id` pehle se maujood hai (reprint bhi wohi le kar jaata hai).
     *
     * Jin purani KOTs ka koi batch nahi (batch se pehle ki), un ke liye order ka `created_at` —
     * `sale_date` NAHI, kyunke wo payment ke waqt dobara likh diya jaata hai aur jhoot bolta hai.
     */
    public static function orderedAt(?PrintJob $job, ?SalesOrder $sale): ?Carbon
    {
        $batchId = $job?->payload['kot_batch_id'] ?? null;

        if ($batchId) {
            $batch = KotBatch::find((int) $batchId);
            if ($batch?->created_at) {
                return $batch->created_at->copy();
            }
        }

        return $sale?->created_at?->copy() ?? $sale?->sale_date?->copy();
    }

    /**
     * Duplicate parchi kab nikli. Kitchen ko DONO chahiye — khana kab manga gaya, aur ye kaagaz
     * kab nikla — warna ek purani parchi taaza dikhti hai aur wohi ghalati wapas aa jaati hai.
     * Asli (pehli) parchi par ye NULL rehta hai: wahan dono waqt ek hi hain.
     */
    public static function reprintAt(?PrintJob $job): ?Carbon
    {
        if (! $job || empty($job->payload['is_reprint'])) {
            return null;
        }

        return $job->created_at?->copy();
    }
}
