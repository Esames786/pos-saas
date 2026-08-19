<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CateringEstimateLine;
use App\Models\Tenant\CateringEstimateLineCostBlock;
use App\Services\Catering\CateringLineCostBlockService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * KASHIF-CATERING-LINE-SNAPSHOT-1 — adjusting one booking, not the dish.
 *
 * Two decisions an operator makes about a single quotation and nothing else:
 * how much material tonight actually needs, and what price is actually being
 * quoted. Neither touches the product, its cost blocks, or any other booking —
 * that separation is the whole reason the line carries its own copy.
 *
 * Both refuse on a sent quotation. Its costing is history; the way to change a
 * sent price is to revise it, which is what revisions are for.
 */
class CateringLineCostController extends Controller
{
    public function __construct(private readonly CateringLineCostBlockService $lineBlocks) {}

    /** "This event needs 3 KG of chicken, not the 2.5 the recipe implies." */
    public function updateMaterial(Request $request, CateringEstimateLineCostBlock $costBlock)
    {
        $data = $request->validate([
            'event_material_qty' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $this->lineBlocks->overrideMaterialQuantity($costBlock, (float) $data['event_material_qty']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['cost_block' => $e->getMessage()]);
        }

        return $this->backToEvent($costBlock->line, "{$costBlock->label} updated for this booking only.");
    }

    /** Put it back on the dish's own ratio. */
    public function resetMaterial(CateringEstimateLineCostBlock $costBlock)
    {
        try {
            $this->lineBlocks->resetMaterialQuantity($costBlock);
        } catch (RuntimeException $e) {
            return back()->withErrors(['cost_block' => $e->getMessage()]);
        }

        return $this->backToEvent($costBlock->line, "{$costBlock->label} is back on the dish's own quantity.");
    }

    /**
     * Quote something other than the calculated rate.
     *
     * The reason is required by the service, not merely by this form: a price
     * that differs from the calculation with nothing beside it is
     * indistinguishable from a mistake once everyone has forgotten.
     */
    public function quoteRate(Request $request, CateringEstimateLine $cateringEstimateLine)
    {
        $data = $request->validate([
            'quoted_rate' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $this->lineBlocks->overrideQuotedRate(
                $cateringEstimateLine,
                (float) $data['quoted_rate'],
                $data['reason'],
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['cost_block' => $e->getMessage()]);
        }

        return $this->backToEvent($cateringEstimateLine, 'Quoted rate updated for this line.');
    }

    /** Put the line back on the price its own blocks calculate. */
    public function useCalculatedRate(CateringEstimateLine $cateringEstimateLine)
    {
        try {
            $this->lineBlocks->useCalculatedRate($cateringEstimateLine);
        } catch (RuntimeException $e) {
            return back()->withErrors(['cost_block' => $e->getMessage()]);
        }

        return $this->backToEvent($cateringEstimateLine, 'Line is back on its calculated rate.');
    }

    /**
     * Tenant routes carry a {subdomain} parameter, so route() would bind the
     * model to the subdomain and throw after the change had already been made.
     * A path avoids that entirely.
     */
    private function backToEvent(?CateringEstimateLine $line, string $status)
    {
        $eventId = $line?->estimate?->catering_event_id;

        return $eventId
            ? redirect()->to('/catering/events/'.$eventId)->with('status', $status)
            : back()->with('status', $status);
    }
}
