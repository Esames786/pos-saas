<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringProductionRelease;
use App\Services\Catering\CateringProductionReleaseService;
use Illuminate\Http\Request;
use RuntimeException;

/** CATERING-SLICE-3: create + view immutable production releases. */
class CateringProductionReleaseController extends Controller
{
    public function __construct(private readonly CateringProductionReleaseService $releases) {}

    public function store(Request $request, CateringEvent $cateringEvent)
    {
        try {
            $release = $this->releases->release($cateringEvent, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['release' => $e->getMessage()]);
        }

        return redirect()
            ->route('tenant.catering.production-releases.show', $release)
            ->with('status', "Production release {$release->release_no} created.");
    }

    public function show(CateringProductionRelease $cateringProductionRelease)
    {
        $cateringProductionRelease->load(['lines', 'event', 'estimate']);

        $printJobs = \App\Models\Tenant\PrintJob::with('printer')
            ->where('document_type', 'catering_production')
            ->where('reference_type', 'catering_production_release')
            ->where('reference_id', $cateringProductionRelease->id)
            ->orderByDesc('id')
            ->get();

        // GO-LIVE §9: the issue state, so planned requirements never LOOK like
        // stock that already moved.
        $materialIssue = \App\Models\Tenant\CateringMaterialIssue::with('lines')
            ->where('catering_production_release_id', $cateringProductionRelease->id)
            ->first();

        return view('tenant.catering.releases.show', [
            'release' => $cateringProductionRelease,
            'printJobs' => $printJobs,
            'materialIssue' => $materialIssue,
        ]);
    }

    /** CATERING-V1-CLOSURE-1 (§6): queue physical tickets via the PrintJob transport. */
    public function print(Request $request, CateringProductionRelease $cateringProductionRelease, \App\Services\Catering\CateringProductionPrintService $printing)
    {
        if ($cateringProductionRelease->status !== CateringProductionRelease::STATUS_RELEASED) {
            return back()->withErrors(['print' => 'Only a released document can be printed.']);
        }

        $jobs = $printing->queueRelease($cateringProductionRelease, $request->user()?->id);

        if ($jobs->isEmpty()) {
            return back()->withErrors(['print' => 'No active catering printer mappings match this release — configure Catering Printers first.']);
        }

        return back()->with('status', $jobs->count().' production ticket(s) queued to '.$jobs->pluck('printer_id')->unique()->count().' printer(s). Retrying is safe — jobs are idempotent per printer.');
    }

    public function reprint(Request $request, CateringProductionRelease $cateringProductionRelease, \App\Services\Catering\CateringProductionPrintService $printing)
    {
        $data = $request->validate(['printer_id' => ['nullable', 'integer']]);

        $jobs = $printing->reprintRelease($cateringProductionRelease, $data['printer_id'] ?? null, $request->user()?->id);

        if ($jobs->isEmpty()) {
            return back()->withErrors(['print' => 'No mapped printers to reprint to.']);
        }

        return back()->with('status', $jobs->count().' reprint copy(ies) queued.');
    }
}
