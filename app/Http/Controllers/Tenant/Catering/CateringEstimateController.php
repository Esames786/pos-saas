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
            'lines.*.instructions' => ['nullable', 'string', 'max:2000'],
            // KASHIF-CATERING-INSTRUCTIONS-1: managed vocabulary selections,
            // saved beside the free note (which stays the additional note).
            'lines.*.instruction_ids' => ['nullable', 'array'],
            'lines.*.instruction_ids.*' => ['integer', 'exists:catering_instructions,id'],
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

        try {
            $this->estimates->saveDraftLines($cateringEstimate, $lines, $data);
        } catch (RuntimeException $e) {
            return back()->withErrors(['estimate' => $e->getMessage()])->withInput();
        }

        return back()->with('status', 'Estimate saved.');
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

    public function send(CateringEstimate $cateringEstimate)
    {
        try {
            $this->estimates->markSent($cateringEstimate);
        } catch (RuntimeException $e) {
            return back()->withErrors(['estimate' => $e->getMessage()]);
        }

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

        return back()->with('status', "Estimate {$cateringEstimate->displayNo()} accepted.");
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
