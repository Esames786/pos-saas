<?php

namespace App\Http\Controllers\Tenant\Ajax;

use App\Http\Controllers\Controller;
use App\Models\Tenant\ManufacturingCustomer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant-safe AJAX manufacturing-customer lookup for Select2 dropdowns.
 *
 * Runs on the tenant connection only. Active customers by default. Paginated so
 * large customer lists never load fully into a dropdown.
 */
class ManufacturingCustomerLookupController extends Controller
{
    private const PER_PAGE = 25;

    public function __invoke(Request $request): JsonResponse
    {
        $q    = trim((string) $request->input('q', ''));
        $page = max(1, (int) $request->input('page', 1));

        $query = ManufacturingCustomer::query()->select(['id', 'code', 'name']);

        if ($request->boolean('only_active', true)) {
            $query->where('status', 'active');
        }

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('code', 'like', "%{$q}%")
                  ->orWhere('name', 'like', "%{$q}%")
                  ->orWhere('company_name', 'like', "%{$q}%")
                  ->orWhere('contact_person', 'like', "%{$q}%")
                  ->orWhere('phone', 'like', "%{$q}%");
            });
        }

        $total   = (clone $query)->count();
        $records = $query->orderBy('name')
            ->forPage($page, self::PER_PAGE)
            ->get();

        $results = $records->map(fn (ManufacturingCustomer $c) => [
            'id'   => $c->id,
            'text' => $c->code ? ($c->code . ' — ' . $c->name) : $c->name,
        ]);

        return response()->json([
            'results'    => $results,
            'pagination' => ['more' => ($page * self::PER_PAGE) < $total],
        ]);
    }
}
