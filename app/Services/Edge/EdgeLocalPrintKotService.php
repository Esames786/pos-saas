<?php

namespace App\Services\Edge;

use App\Models\Tenant\KotBatch;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesOrderLineCancellation;
use App\Models\Tenant\Terminal;
use App\Services\Printing\DirectPayPrintOrchestrator;
use App\Services\Printing\PrintJobService;
use App\Services\Sales\KotCancellationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * W5 (Team 5) — the kitchen-printing REQUEST path on the Branch Server, mirroring the Online
 * PrintJobController (queue KOT → plan Reminders → confirm / reprint Reminder) and the Online
 * line-void correction Reminder (HeldSaleController → KotCancellationService::queueCorrectionReminders).
 *
 * Every rule stays in the SHARED services — PrintJobService (delta, batches, logical keys, copy numbering,
 * reminder revision / ask-on-addition token), PrintRoutingService (category → printer with terminal
 * precedence), KotCancellationService (correction Reminders). This class only threads the appliance's
 * inputs into them: the operator's CURRENT terminal (RECALL-REPRINT-TERMINAL parity — Online passes the
 * POS terminal_id), the Edge authority gate, and the Edge document URLs. It never touches the outbox,
 * the envelope or any financial event.
 */
class EdgeLocalPrintKotService
{
    public function __construct(
        private readonly PrintJobService $printJobs,
        private readonly DirectPayPrintOrchestrator $orchestrator,
        private readonly KotCancellationService $cancellations,
    ) {
    }

    /**
     * Online `POST /printing/jobs/kot/{salesOrder}` parity: queue the unsent delta (or, with $reprint, a
     * DUPLICATE of every line) routed at the operator's current counter, then plan the Reminder round.
     *
     * Sent bookkeeping follows the shared service exactly as Online does: network routes are marked sent at
     * queue time (markKotLinesQueued); a browser/Print Here ticket is marked sent when the operator confirms
     * it printed (markPrinted) — never before.
     *
     * @return array{jobs: array<int, PrintJob>, reminder: array}
     */
    public function queueKot(SalesOrder $sale, Terminal $terminal, array $lineIds = [], bool $reprint = false): array
    {
        $lineIds = collect($lineIds)->map(fn ($id) => (int) $id)->filter()->values()->all();

        if ($reprint) {
            // A duplicate is a new print of an old event — no business mutation, so no authority gate (Online: same route).
            return ['jobs' => $this->printJobs->queueKot($sale, null, $lineIds, (string) $terminal->id, true), 'reminder' => $this->emptyReminder()];
        }

        $this->requireLocalAuthority();
        if (! in_array((string) $sale->status, ['held', 'paid'], true)) {
            throw ValidationException::withMessages(['sale' => 'No held or paid sale found for KOT.']);
        }
        // POS-DRAFT-1: Online skips the KOT for a draft in the browser; the appliance enforces it here.
        if ((bool) $sale->is_draft) {
            throw ValidationException::withMessages(['sale' => 'This order is saved as a DRAFT — hold it normally to send its KOT.']);
        }

        $jobs = $this->printJobs->queueKot($sale, null, $lineIds, (string) $terminal->id, false);

        return ['jobs' => $jobs, 'reminder' => $jobs ? $this->planReminders($sale->fresh(), $jobs) : $this->emptyReminder()];
    }

    /**
     * Reminder planning after an accepted KOT round (Online PrintJobController::queueKot :104-114): automatic
     * destinations are queued now; "Ask on addition" destinations come back with a server-bound token.
     * A planning failure never unwinds the KOT (Online returns the same warning).
     */
    public function planReminders(SalesOrder $sale, array $kotJobs): array
    {
        try {
            return $this->printJobs->planRemindersForKotJobs($sale, $kotJobs);
        } catch (\Throwable $e) {
            Log::warning('Edge reminder planning failed after KOT was queued.', ['sales_order_id' => $sale->id, 'error' => $e->getMessage()]);

            return $this->emptyReminder() + ['warning' => 'KOT was queued, but Reminder could not be queued.'];
        }
    }

    /**
     * Online `POST /printing/jobs/reminder/{salesOrder}/confirm` parity: the operator's Yes/No on
     * "Resend updated Reminder?". The shared token check binds sale, branch, user, round and routing.
     *
     * @return array{jobs: array<int, PrintJob>, declined: bool}
     */
    public function confirmReminders(SalesOrder $sale, string $token, string $decision = 'confirm'): array
    {
        $context = $this->printJobs->validateReminderConfirmation($sale, $token);
        if ($decision === 'decline') {
            $this->orchestrator->markReminderDecision($sale, (int) $context['batch']->id, 'declined');

            return ['jobs' => [], 'declined' => true];
        }
        $this->requireLocalAuthority();
        $jobs = $this->printJobs->queueConfirmedReminders($sale, $token);
        $this->orchestrator->markReminderDecision($sale, (int) $context['batch']->id, 'confirmed');

        return ['jobs' => $jobs, 'declined' => false];
    }

    /** Online `POST /printing/jobs/{printJob}/reminder-reprint` parity — "DUPLICATE n" of a network Reminder slip. */
    public function reprintReminder(PrintJob $job): PrintJob
    {
        return $this->printJobs->queueReminderReprint($job);
    }

    /**
     * D-06 — the correction Reminder Online prints after a LINE void (HeldSaleController :806-813): once the
     * revise that recorded the cancellation has committed, the shared KotCancellationService queues the
     * "CANCELLED / UPDATED ORDER" slip at the voiding counter. Idempotent (shared logical key
     * reminder:<cancel-event-uuid>:<printer>), so a repeated call never prints twice.
     *
     * Targets the cancel batch the revise just created: the newest cancel batch of this sale that carries
     * line-scope cancellations and is newer than $afterBatchId (the caller's pre-revise max batch id). When
     * the caller cannot pass that id, only a cancel batch from the last 10 minutes qualifies — an old void is
     * never re-printed.
     *
     * @return array<int, PrintJob>
     */
    public function queueLineVoidCorrectionReminders(SalesOrder $sale, ?int $terminalId, ?int $afterBatchId = null): array
    {
        $batch = KotBatch::on('tenant')->where('sales_order_id', $sale->id)->where('event_type', 'cancel')
            ->when($afterBatchId !== null, fn ($q) => $q->where('id', '>', $afterBatchId))
            ->when($afterBatchId === null, fn ($q) => $q->where('created_at', '>=', now()->subMinutes(10)))
            ->orderByDesc('id')->first();
        if (! $batch) {
            return [];
        }
        $isLineVoid = SalesOrderLineCancellation::on('tenant')->where('kot_batch_id', $batch->id)->get()
            ->contains(fn ($c) => data_get($c->policy_snapshot, 'scope') === 'line');
        if (! $isLineVoid) {
            return [];
        }
        $fresh = SalesOrder::on('tenant')->findOrFail($sale->id); // the revised lines (Online unsets the relation first)

        return $this->cancellations->queueCorrectionReminders($fresh, $batch, false, $terminalId ? (string) $terminalId : null);
    }

    public function emptyReminder(): array
    {
        return ['revision' => null, 'auto_jobs' => [], 'ask_printers' => [], 'confirmation_token' => null];
    }

    /** P0 BRANCH AUTHORITY: a standby appliance never creates kitchen events (same gate as every Edge mutation). */
    private function requireLocalAuthority(): void
    {
        try {
            app(EdgeAuthorityService::class)->assertLocalMutationAllowed();
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['authority' => $e->getMessage()]);
        }
    }
}
