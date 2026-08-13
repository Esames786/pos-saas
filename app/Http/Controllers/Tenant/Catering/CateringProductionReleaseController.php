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

        return view('tenant.catering.releases.show', ['release' => $cateringProductionRelease]);
    }
}
