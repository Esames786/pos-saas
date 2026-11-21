<?php

namespace App\Services\Printing;

use App\Models\Tenant\PrintAgent;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\Printer;
use App\Models\Tenant\Terminal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * PRINT-OFFLINE-HERE-1 (20 Nov 2026) — "kya is counter ki parchi printer tak pahunch sakti hai?"
 *
 * Network thermal parchi sirf ek raaste se chhapti hai: dukaan ke kisi PC par chalta Bingoo Print Agent
 * server se parchi uthata hai aur LAN printer ko bhejta hai. Agent na ho to parchi `queued` hi rehti hai —
 * na `failed` hoti hai (wo sirf agent likhta hai), na kisi ko pata chalta hai — aur 60 minute baad
 * `printing:autoclose-stuck` use chup chaap band kar deta hai. Ek live restaurant, 19 Nov: agent 17:46 par gaya, raat
 * ki 81 parchiyan isi tarah gayab hui.
 *
 * Ye service wohi sawal poochhti hai jo agent ka `pending()` poochhta hai — kaun sa agent is branch / is
 * counter ki parchi utha sakta hai — aur dekhti hai ke un mein se koi pichle 15 second mein bola ya nahi.
 * Agent har ~3 s heartbeat aur har 1–3 s poll karta hai, dono `last_seen_at` likhte hain; 15 s = paanch
 * baar chup. Na bola to is counter ki parchiyan "yahin print" ho jati hain (POS khud kholta hai).
 *
 * Sirf PARHTI hai — kuch likhti nahi.
 */
class PrintHealthService
{
    /** Agent itne second chup rahe to us ki parchiyan printer tak nahi pahunch saktin. */
    public const AGENT_SILENT_SECONDS = 15;

    /** Agent ka claim itni der tak "chhap rahi hai" maana jata hai (PrintAgentApiController::pending ka lease). */
    public const CLAIM_LEASE_MINUTES = 2;

    /** Wo document jinhein counter ka browser dikha aur chhaap sakta hai (PrintDocumentController::preview). */
    public const PRINTABLE_HERE = ['kot', 'reminder', 'receipt'];

    /**
     * Is counter ki haalat.
     *
     * @return array{state: string, threshold: int, silent_seconds: ?int, agent: ?array, waiting: list<array>}
     *   state: ok — koi agent zinda; offline — koi zinda nahi (parchi yahin); none — is branch par koi
     *   network printer hi nahi (batti dikhane ki zaroorat nahi).
     */
    public function forTerminal(?int $branchId, ?string $terminalId): array
    {
        $base = ['threshold' => self::AGENT_SILENT_SECONDS, 'silent_seconds' => null, 'agent' => null, 'waiting' => []];

        if (! $this->branchHasNetworkPrinter($branchId)) {
            return ['state' => 'none'] + $base;
        }

        $agents = $this->eligibleAgents($branchId, $terminalId);
        $latest = $agents->filter(fn (PrintAgent $a) => $a->last_seen_at !== null)
            ->sortByDesc(fn (PrintAgent $a) => $a->last_seen_at->getTimestamp())
            ->first();

        $agent = $latest ? [
            'name'         => $latest->name,
            'device'       => $latest->device_name ?: ($latest->paired_device_name ?? null),
            'last_seen_at' => $latest->last_seen_at->toIso8601String(),
            'last_seen'    => $this->human($latest->last_seen_at),
        ] : null;

        $silent = $latest ? (int) $latest->last_seen_at->diffInSeconds(now(), true) : null;

        if ($latest && $this->isOnline($latest)) {
            return ['state' => 'ok', 'silent_seconds' => $silent, 'agent' => $agent] + $base;
        }

        return [
            'state'          => 'offline',
            'threshold'      => self::AGENT_SILENT_SECONDS,
            'silent_seconds' => $silent,
            'agent'          => $agent,
            'waiting'        => $this->waitingJobs($branchId, $terminalId)->map(fn (PrintJob $j) => $this->jobRow($j))->values()->all(),
        ];
    }

    /** Is counter ki terminal id se branch nikaal kar wohi haalat. */
    public function forTerminalId(?string $terminalId, ?int $fallbackBranchId = null): array
    {
        $branchId = $terminalId ? Terminal::whereKey((int) $terminalId)->value('branch_id') : null;

        return $this->forTerminal($branchId ? (int) $branchId : $fallbackBranchId, $terminalId);
    }

    /** Kya koi agent jo is job ko utha sakta hai, abhi zinda hai? */
    public function jobCanReachPrinter(PrintJob $job): bool
    {
        return $this->eligibleAgents($job->branch_id ? (int) $job->branch_id : null, $job->terminal_id !== null ? (string) $job->terminal_id : null)
            ->contains(fn (PrintAgent $a) => $this->isOnline($a));
    }

    /** Branch ke agents jin ki koi bhi pichle 15 s mein bola ho — Printers page ki patti ke liye. */
    public function branchOfflineAgents(): array
    {
        $agents = PrintAgent::query()->where('is_active', true)->whereNotNull('paired_at')->get();
        if ($agents->isEmpty() || $agents->contains(fn (PrintAgent $a) => $this->isOnline($a))) {
            return [];
        }

        return $agents->filter(fn (PrintAgent $a) => $a->last_seen_at !== null)
            ->sortByDesc(fn (PrintAgent $a) => $a->last_seen_at->getTimestamp())
            ->map(fn (PrintAgent $a) => [
                'name'         => $a->name,
                'device'       => $a->device_name,
                'last_seen_at' => $a->last_seen_at,
                'last_seen'    => $this->human($a->last_seen_at),
            ])->values()->all();
    }

    public function isOnline(PrintAgent $agent): bool
    {
        return $agent->is_active
            && $agent->last_seen_at !== null
            && $agent->last_seen_at->gte(now()->subSeconds(self::AGENT_SILENT_SECONDS));
    }

    /**
     * Wo agents jo is branch / counter ki parchi utha sakte hain — bilkul `PrintAgentApiController::pending()`
     * ki shart: active, branch NULL ya yehi, counter NULL ya yehi. Is se alag shart ka matlab hota ke batti
     * hari ho aur parchi phir bhi koi na uthaye (ya ulta).
     */
    public function eligibleAgents(?int $branchId, ?string $terminalId): Collection
    {
        return PrintAgent::query()
            ->where('is_active', true)
            ->when($branchId, fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('branch_id')->orWhere('branch_id', $branchId)))
            ->when($terminalId !== null && $terminalId !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('terminal_id')->orWhere('terminal_id', (int) $terminalId)))
            ->get();
    }

    /**
     * Is counter ki wo parchiyan jo network printer ke liye line mein hain aur kisi ne nahi chhapin. Agent ki
     * `pending()` wali shart (active network printer jis ka IP ho) — magar sirf is counter ki, aur sirf wo
     * jo browser dikha sakta hai. Jo agent abhi utha chuka ho (lease ke andar) wo "chhap rahi hai" — nahi.
     */
    public function waitingJobs(?int $branchId, ?string $terminalId): Collection
    {
        if ($terminalId === null || $terminalId === '') {
            return collect();
        }

        $order = array_flip(self::PRINTABLE_HERE);

        return PrintJob::query()
            ->with('printer')
            ->where('print_status', 'queued')
            ->whereIn('document_type', self::PRINTABLE_HERE)
            ->whereNotNull('printer_id')
            ->whereHas('printer', fn (Builder $q) => $q->where('is_active', true)
                ->where('printer_type', 'network')->whereNotNull('ip_address'))
            ->when($branchId, fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('branch_id')->orWhere('branch_id', $branchId)))
            ->where('terminal_id', (string) $terminalId)
            ->where(fn (Builder $q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subMinutes(self::CLAIM_LEASE_MINUTES)))
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(40)
            ->get()
            // Order apni pehli parchi ke waqt se aate hain; ek order ke andar pehle kitchen (KOT, reminder),
            // phir bill — wohi tarteeb jo counter par hoti hai.
            ->groupBy(fn (PrintJob $j) => $j->reference_id ? 'sale-' . $j->reference_id : 'job-' . $j->id)
            ->flatMap(fn (Collection $group) => $group->sortBy([
                fn (PrintJob $a, PrintJob $b) => ($order[$a->document_type] ?? 9) <=> ($order[$b->document_type] ?? 9),
                fn (PrintJob $a, PrintJob $b) => $a->id <=> $b->id,
            ])->values())
            ->values();
    }

    public function jobRow(PrintJob $job): array
    {
        return [
            'id'            => $job->id,
            'job_no'        => $job->job_no,
            'document_type' => $job->document_type,
            'printer_name'  => $job->printer?->name,
            'reference_no'  => $job->reference_no,
            'created_at'    => $job->created_at?->toIso8601String(),
            'created'       => $job->created_at ? $this->human($job->created_at) : null,
            'preview_url'   => url('/printing/documents/' . $job->id . '/preview'),
        ];
    }

    private function branchHasNetworkPrinter(?int $branchId): bool
    {
        return Printer::query()
            ->where('is_active', true)
            ->where('printer_type', 'network')
            ->whereNotNull('ip_address')
            ->when($branchId, fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('branch_id')->orWhere('branch_id', $branchId)))
            ->exists();
    }

    /** "5:46 PM" aaj ke liye, "19 Nov 5:46 PM" purane ke liye — branch ke waqt mein. */
    private function human(Carbon $at): string
    {
        $tz = app(\App\Support\TenantClock::class)->displayTimezone(auth('tenant')->user());
        $local = $at->copy()->setTimezone($tz);

        return $local->isSameDay(now($tz)) ? $local->format('g:i A') : $local->format('j M g:i A');
    }
}
