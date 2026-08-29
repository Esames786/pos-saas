<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * KASHIF-UAT-2 — the in-app Catering manual.
 *
 * Owner requirement: "when there is a guide button specially for catering module
 * explain whole software and where to manage".
 *
 * Deliberately a plain read-only page with no model access. It must render for a
 * brand-new tenant that has zero events, zero recipes and zero rates — a manual
 * that only works once you already have data is useless to the person who needs
 * it most. That also keeps it safe: nothing here can mutate anything.
 */
class CateringGuideController extends Controller
{
    public function index(Request $request)
    {
        // Urdu is a display choice, not a tenant setting — a manager and a
        // kitchen supervisor read the same screen in different languages.
        $lang = $request->query('lang') === 'ur' ? 'ur' : 'en';

        return view('tenant.catering.guide', ['lang' => $lang]);
    }
}
