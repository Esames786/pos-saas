<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CateringEstimate;
use App\Services\Catering\CateringEstimateService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * CATERING-SLICE-1: draft estimate editing + lifecycle (send/accept/revise).
 * Sent estimates are immutable; the model guard backs these checks up.
 */
class CateringEstimateController extends Controller
{
    public function __construct(private readonly CateringEstimateService $estimates) {}

    /** Replace draft lines + charges (the estimate builder posts everything). */
    public function update(Request $request, CateringEstimate $cateringEstimate)
    {
        $data = $request->validate([
            'lines' => ['array'],
            // Stable identity, so saving the form updates a line rather than
            // replacing it and discarding the costing decisions on it.
            'lines.*.line_uuid' => ['nullable', 'string', 'max:26'],
            'lines.*.product_id' => ['nullable', 'exists:products,id'],
            'lines.*.item_name' => ['required', 'string', 'max:255'],
            'lines.*.item_name_ur' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'lines.*.unit_id' => ['nullable', 'exists:units,id'],
            'lines.*.rate' => ['required', 'numeric', 'min:0'],
            // A block-costed line deliberately ignores the ordinary rate field.
            // The punch bar therefore posts an explicit pricing intent when the
            // operator changes Customer Rate, so Save Estimate can use the same
            // audited authority as the inline "Apply rate" control.
            'lines.*.rate_action' => ['nullable', Rule::in(['override', 'calculated'])],
            'lines.*.rate_override_reason' => ['nullable', 'string', 'max:255'],
            'lines.*.instructions' => ['nullable', 'string', 'max:2000'],
            // KASHIF-CATERING-INSTRUCTIONS-1: managed vocabulary selections,
            // saved beside the free note (which stays the additional note).
            'lines.*.instruction_ids' => ['nullable', 'array'],
            'lines.*.instruction_ids.*' => ['integer', 'exists:catering_instructions,id'],
            // KASHIF-ORDER-PUNCH §B3: the punch bar's material settings travel
            // WITH the save. Nothing the operator types is written until they
            // save — and then it is written in one act, through the same block
            // authorities the Cost Details panel uses.
            'lines.*.materials' => ['nullable', 'array'],
            'lines.*.materials.*.label' => ['required_with:lines.*.materials', 'string', 'max:120'],
            'lines.*.materials.*.kg' => ['nullable', 'numeric', 'min:0'],
            'lines.*.materials.*.rate' => ['nullable', 'numeric', 'min:0'],
            'lines.*.materials.*.cust' => ['nullable', 'numeric', 'min:0'],
            'service_charge_amount' => ['nullable', 'numeric', 'min:0'],
            'other_charge_label' => ['nullable', 'string', 'max:255'],
            'other_charge_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_type' => ['nullable', Rule::in(['none', 'fixed', 'percent'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $lines = collect($data['lines'] ?? [])->map(function (array $line) {
            if (! empty($line['unit_id'])) {
                $line['unit_code'] = \App\Models\Tenant\Unit::whereKey($line['unit_id'])->value('code');
            }

            return $line;
        })->all();

        // CATERING-RESTORE-IN-PLACE-1 (31 Oct) — a RESTORED version is shown
        // editable, but it is the paper the customer already holds, so it is
        // never written to. Its first Save that actually changes something
        // becomes the next revision (Q3); a Save that changes nothing makes
        // nothing. Only through this one door — the inline per-line controls
        // stay closed on it (they would each need their own copy-first step).
        $target = $cateringEstimate;
        $fromRestored = $this->estimates->isRestoredVersion($cateringEstimate);

        // Thrown to undo the whole attempt when the Save changed nothing; the
        // transaction rethrows this exact object, so it is told apart by
        // identity from every real refusal.
        $unchanged = new RuntimeException('unchanged');

        try {
            \Illuminate\Support\Facades\DB::connection('tenant')->transaction(function () use ($cateringEstimate, &$target, &$lines, $data, $fromRestored, $unchanged, $request) {
                if ($fromRestored) {
                    // revise() re-checks everything under its lock — superseded
                    // meanwhile (a second tab), invoiced, released without the
                    // permission — and moves the booking exactly as Create
                    // Revision always has.
                    $lineMap = [];
                    $target = $this->estimates->revise($cateringEstimate, $request->user()?->id, $lineMap);

                    // The form was drawn from the restored version's lines;
                    // point each at its copy, so every decision on it (supply
                    // split, agreed rate, instructions) is kept, not re-made.
                    $lines = array_map(function (array $line) use ($lineMap) {
                        $uuid = $line['line_uuid'] ?? null;
                        if ($uuid !== null && isset($lineMap[$uuid])) {
                            $line['line_uuid'] = $lineMap[$uuid];
                        }

                        return $line;
                    }, $lines);
                }

                $this->estimates->saveDraftLines($target, $lines, $data);
                $this->applyPunchedMaterials($target->refresh(), $lines);
                $this->applyPunchedQuotedRates($target->refresh(), $lines);

                if ($fromRestored && $this->estimates->sameContent($cateringEstimate->refresh(), $target->refresh())) {
                    throw $unchanged;
                }
            });
        } catch (RuntimeException $e) {
            if ($e === $unchanged) {
                return back()->with('status',
                    "Kuch nahi badla — Q{$cateringEstimate->version_no} hi main quotation hai, koi nayi revision nahi bani.");
            }

            return back()->withErrors(['estimate' => $e->getMessage()])->withInput();
        }

        // KASHIF-EVENT-HISTORY-1: every meaningful save becomes a checkpoint
        // the operator can come back to.
        app(\App\Services\Catering\CateringEventHistoryService::class)
            ->record($target->refresh()->event, $fromRestored ? 'restored_revised' : 'lines_saved', $request->user()?->id);

        if ($fromRestored) {
            return back()->with('status',
                "Aap ne wapas laayi gayi Q{$cateringEstimate->version_no} badli — ab Q{$target->version_no} nayi revision main hai. "
                ."Q{$cateringEstimate->version_no} waisi hi mehfooz hai.");
        }

        return back()->with('status', 'Estimate saved.');
    }

    /**
     * KASHIF-ORDER-PUNCH §B3 — apply the punch bar's per-material settings.
     *
     * The lines are already saved and snapshotted; each setting now goes
     * through the SAME authority the panel's own control uses — quantity,
     * booking-only rate, customer split — so locks, refusals (party OFF) and
     * repricing behave identically whichever surface typed them. A payload
     * line matches its saved line by sort_order, which saveDraftLines assigns
     * from the same array index.
     */
    private function applyPunchedMaterials(CateringEstimate $estimate, array $lines): void
    {
        $blocks = app(\App\Services\Catering\CateringLineCostBlockService::class);
        $saved = $estimate->lines()->get()->keyBy('sort_order');

        foreach (array_values($lines) as $index => $line) {
            $materials = $line['materials'] ?? [];
            if ($materials === [] || ! ($savedLine = $saved->get($index))) {
                continue;
            }

            $snapshots = $blocks->snapshotsFor($savedLine)->keyBy('label');
            foreach ($materials as $material) {
                $snapshot = $snapshots->get($material['label'] ?? '');
                if (! $snapshot || ! $snapshot->isMaterial()) {
                    continue;
                }

                if (isset($material['rate']) && round((float) $material['rate'], 4) !== round((float) $snapshot->refresh()->rate, 4)) {
                    $blocks->setChargedRate($snapshot, (float) $material['rate']);
                }
                if (isset($material['kg'])) {
                    $total = round((float) $material['kg'], 4);
                    $party = round(min((float) ($material['cust'] ?? 0), $total), 4);
                    $ours = round(max(0, $total - $party), 4);
                    $current = $snapshot->refresh();
                    if ($total !== round($current->physicalRequirement(), 4)
                        || $party !== round($current->suppliedQty(), 4)) {
                        $blocks->setSupplySplit($current, $ours, $party);
                    }
                }
            }
        }
    }

    /** Apply only the explicit Customer Rate decision staged by the punch bar. */
    private function applyPunchedQuotedRates(CateringEstimate $estimate, array $lines): void
    {
        $blocks = app(\App\Services\Catering\CateringLineCostBlockService::class);
        $saved = $estimate->lines()->get()->keyBy('sort_order');

        foreach (array_values($lines) as $index => $line) {
            $action = $line['rate_action'] ?? null;
            $savedLine = $saved->get($index);
            if (! $action || ! $savedLine || ! $savedLine->costBlocks()->exists()) {
                continue;
            }

            if ($action === 'calculated') {
                $blocks->useCalculatedRate($savedLine);

                continue;
            }

            $blocks->overrideQuotedRate(
                $savedLine,
                (float) $line['rate'],
                (string) ($line['rate_override_reason'] ?? ''),
            );
        }
    }

    /**
     * KASHIF-EVENT-HISTORY-2 → CATERING-RESTORE-IN-PLACE-1 — a superseded
     * version comes back as ITSELF; the current one steps aside. No new
     * version is made (see CateringEstimateService::restoreVersion).
     */
    public function restoreVersion(Request $request, CateringEstimate $cateringEstimate)
    {
        try {
            $restored = $this->estimates->restoreVersion($cateringEstimate, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['estimate' => $e->getMessage()]);
        }

        app(\App\Services\Catering\CateringEventHistoryService::class)
            ->record($restored->event, 'version_restored', $request->user()?->id);

        return back()->with('status',
            "Q{$restored->version_no} wapas main quotation hai — koi nayi revision nahi bani. "
            .($restored->isDraft()
                ? 'Ye draft hai, seedha edit ho sakti hai.'
                : 'Badal kar Save karenge tab nayi revision banegi.'));
    }

    /** Recompute the draft's material costing from the current rate book (CATERING-SLICE-2). */
    public function reprice(Request $request, CateringEstimate $cateringEstimate)
    {
        try {
            // KASHIF-CATERING-COSTING-SOURCE-1: the dispatcher, which refuses to
            // record a cost it cannot stand behind. It used to be possible to
            // persist a number derived from an incomplete rate book; the
            // operator now gets the reason instead of a figure they would have
            // had no way of doubting.
            $snapshot = app(\App\Services\Catering\CateringEstimateCostingService::class)
                ->snapshot($cateringEstimate, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['estimate' => $e->getMessage()]);
        }

        $warnings = collect($snapshot->breakdown['warnings'] ?? []);
        $message = 'Material cost recalculated: '.number_format((float) $snapshot->total_material_cost, 2).'.';
        if ($warnings->isNotEmpty()) {
            $message .= ' '.$warnings->count().' warning(s): '.$warnings->first();
        }

        return back()->with('status', $message);
    }

    public function send(Request $request, CateringEstimate $cateringEstimate)
    {
        // CATERING-ADOPT-PUNCHED-RATE-1 — a dish with no cost block stopped this
        // quotation dead, and the only way round it was to leave the booking,
        // add a block by hand and come back. What operators actually added was
        // an empty block at rate 0, purely to open the door. The screen now
        // offers the rate this quotation already carries, and the yes lands here.
        //
        // Deliberately part of SEND rather than a route of its own: the
        // authority being exercised IS "send this quotation", so it needs no
        // second permission — and a new route would mean granting one to every
        // non-Owner role by hand after the deploy.
        //
        // Only the dish IDs come from the request. Every rate is read from the
        // line by the service, so this cannot be used to set a price.
        $adopt = array_values(array_filter(array_map('intval', (array) $request->input('adopt_punched_rate', []))));

        try {
            if ($adopt !== []) {
                $adopted = app(\App\Services\Catering\CateringPunchedRateAdoptionService::class)
                    ->adopt($cateringEstimate, $adopt, $request->user()?->id);

                if ($adopted !== []) {
                    app(\App\Services\Catering\CateringEventHistoryService::class)
                        ->record($cateringEstimate->event, 'cost_block_adopted', $request->user()?->id);
                }
            }

            $this->estimates->markSent($cateringEstimate);
        } catch (RuntimeException $e) {
            return back()->withErrors(['estimate' => $e->getMessage()]);
        }

        app(\App\Services\Catering\CateringEventHistoryService::class)
            ->record($cateringEstimate->event, 'finalized', request()->user()?->id);

        // CATERING-SLICE-3: email the quotation (idempotent per version; skipped
        // gracefully when the event has no customer email).
        $emailType = $cateringEstimate->version_no > 1
            ? \App\Mail\Catering\CateringCustomerMail::TYPE_QUOTATION_REVISED
            : \App\Mail\Catering\CateringCustomerMail::TYPE_QUOTATION_SENT;
        $emailResult = app(\App\Services\Catering\CateringMailService::class)
            ->send($emailType, $cateringEstimate->event, $cateringEstimate);

        $message = "Estimate {$cateringEstimate->displayNo()} marked sent — it is now locked.";
        $message .= match ($emailResult) {
            'sent' => ' Quotation emailed to the customer.',
            'skipped_no_recipient' => ' No customer email on file, so nothing was emailed.',
            'failed' => ' Customer email could not be sent (logged for retry).',
            default => '',
        };

        return back()->with('status', $message);
    }

    public function accept(CateringEstimate $cateringEstimate)
    {
        try {
            $this->estimates->markAccepted($cateringEstimate);
        } catch (RuntimeException $e) {
            return back()->withErrors(['estimate' => $e->getMessage()]);
        }

        // CATERING-ACCEPT-CONFIRMS-1: acceptance now carries the booking with
        // it when the costing allows. Read the event's OWN status rather than
        // assuming either outcome — the service deliberately does not force it.
        $event = $cateringEstimate->event?->refresh();
        $confirmed = $event && $event->status === \App\Models\Tenant\CateringEvent::STATUS_CONFIRMED;

        return back()->with('status', $confirmed
            ? "Estimate {$cateringEstimate->displayNo()} accepted — booking {$event->event_no} is now confirmed."
            : "Estimate {$cateringEstimate->displayNo()} accepted. The booking could not be confirmed automatically — "
                .'finish the costing, then press Confirm Booking.');
    }

    public function revise(Request $request, CateringEstimate $cateringEstimate)
    {
        try {
            $revision = $this->estimates->revise($cateringEstimate, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['estimate' => $e->getMessage()]);
        }

        return back()->with('status', "Revision Q{$revision->version_no} created as a new draft.");
    }
}
