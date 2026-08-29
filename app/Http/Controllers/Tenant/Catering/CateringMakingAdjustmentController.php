<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Services\Catering\CateringMakingAdjustmentService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * KASHIF-CATERING-MAKING-1 — the Making Adjustment screen.
 *
 * Separate from Material Cost Rates (what materials COST) and Commercial
 * Charge Rates (what materials are CHARGED at): Making is the labour charge on
 * the dish itself. The screen previews first and applies only what the
 * operator ticks; everything else — and every other kind of charge — stays
 * exactly where it was. Previewing writes nothing and audits nothing.
 */
class CateringMakingAdjustmentController extends Controller
{
    public function __construct(private readonly CateringMakingAdjustmentService $making) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'proposed_rate' => ['nullable', 'numeric', 'min:0'],
            'mode' => ['nullable', 'in:set,increase,decrease'],
        ]);

        $proposed = isset($data['proposed_rate']) ? (float) $data['proposed_rate'] : null;
        $mode = $data['mode'] ?? 'set';

        return view('tenant.catering.making-adjustment.index', [
            'preview' => $this->making->preview($proposed, $mode),
        ]);
    }

    public function applyToProducts(Request $request)
    {
        $data = $request->validate([
            'proposed_rate' => ['required', 'numeric', 'min:0'],
            'block_ids' => ['required', 'array', 'min:1'],
            'block_ids.*' => ['integer'],
            'mode' => ['required', 'in:set,increase,decrease'],
        ]);

        try {
            $applied = $this->making->applyToProducts(
                (float) $data['proposed_rate'],
                array_map('intval', $data['block_ids']),
                $request->user()?->id,
                $data['mode']
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['making' => $e->getMessage()]);
        }

        return redirect()
            ->to('/catering/making-adjustment?proposed_rate='.$data['proposed_rate'].'&mode='.$data['mode'])
            ->with('status', "Making updated on {$applied} ".str('dish')->plural($applied)
                .'. Existing quotations keep their own snapshots — adjust drafts below if they should follow.');
    }

    public function applyToDrafts(Request $request)
    {
        $data = $request->validate([
            'proposed_rate' => ['required', 'numeric', 'min:0'],
            'snapshot_ids' => ['required', 'array', 'min:1'],
            'snapshot_ids.*' => ['integer'],
            'mode' => ['required', 'in:set,increase,decrease'],
        ]);

        try {
            $applied = $this->making->applyToDrafts(
                (float) $data['proposed_rate'],
                array_map('intval', $data['snapshot_ids']),
                $request->user()?->id,
                $data['mode']
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['making' => $e->getMessage()]);
        }

        return redirect()
            ->to('/catering/making-adjustment?proposed_rate='.$data['proposed_rate'].'&mode='.$data['mode'])
            ->with('status', "Making applied to {$applied} draft ".str('line')->plural($applied)
                .'. Sent quotations were not touched — create a revision to move one.');
    }
}
