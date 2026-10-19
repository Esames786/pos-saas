<?php

namespace App\Http\Controllers\Tenant\OnlineOrdering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Services\OnlineOrdering\WebMenuService;
use Illuminate\Http\Request;

/**
 * ONLINE-ORDERING-1 — Online Ordering → Website Menu (sirf DEKHNA, koi edit nahi).
 *
 * Malik: alag product/category screen nahi. Website par kya aur kahan dikhe ye product, deal aur
 * category ki mojooda screens ke "Website" hisse se tay hota hai; ye safha sirf dikhata hai ke customer
 * ko menu kaisa nazar aayega, aur kaun si bikne wali cheez abhi website par nahi.
 */
class WebsiteMenuController extends Controller
{
    public function index(Request $request, WebMenuService $menu)
    {
        $branches = Branch::where('status', 'active')->orderBy('name')->get();
        $branch = $branches->firstWhere('id', (int) $request->query('branch')) ?? $branches->first();

        return view('tenant.online-ordering.menu', [
            'branches' => $branches,
            'branch' => $branch,
            'menu' => $branch ? $menu->menu($branch->id, null, preview: true) : [],
            'notOnWebsite' => $menu->notOnWebsite(),
        ]);
    }
}
