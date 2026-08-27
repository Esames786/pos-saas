<?php

namespace App\Http\Controllers\Tenant\Ajax;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Customer;
use Illuminate\Http\Request;

/**
 * CUSTOMER-UX-1: server-side POS customer search (name or phone) — replaces the
 * render-every-customer dropdown that degraded past a few hundred customers.
 */
class CustomerLookupController extends Controller
{
    public function __invoke(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $nameNeedle = mb_strtolower($q);

        $customers = Customer::with(['addresses' => fn ($query) => $query->orderByDesc('is_default')->orderBy('id')])
            ->where('status', 'active')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('name', 'like', "%{$q}%")
                        ->orWhere('phone', 'like', "%{$q}%");
                });
            })
            // Exact customer/phone first, then prefix matches, then ordinary
            // contains matches. On a large customer book alphabetical order
            // made an exact "Tabish" appear below unrelated stale-looking
            // results and operators could attach the wrong phone to a booking.
            ->when($q !== '', fn ($query) => $query->orderByRaw(
                'CASE WHEN LOWER(name) = ? THEN 0 WHEN phone = ? THEN 1 WHEN LOWER(name) LIKE ? THEN 2 WHEN phone LIKE ? THEN 3 ELSE 4 END',
                [$nameNeedle, $q, $nameNeedle.'%', $q.'%']
            ))
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json([
            'customers' => $customers->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'email' => $c->email,
                'addresses' => $c->addresses->map(fn ($a) => [
                    'id' => $a->id,
                    'label' => $a->label,
                    'address' => $a->address,
                    'is_default' => (bool) $a->is_default,
                ])->values(),
                // legacy single-address field acts as a fallback when the book is empty
                'legacy_address' => $c->address,
            ])->values(),
        ]);
    }
}
