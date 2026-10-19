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

        // CUSTOMER-SEARCH-PUNCT-1 (1 Oct) — milan ab NUQTE aur SPACE se azaad.
        //
        // Client: "mr ( dot ) ke baad adnan search nahi ho raha." Wajah yahan
        // thi: milan harf-ba-harf tha, is liye operator ko wohi nuqta, wohi
        // comma aur wohi space likhna parta tha jo record me para hai.
        //
        // Aur record me wo ek jaisa hai hi nahi. Kashif Kitchen ke 4,880
        // graahak me "MR" chaar shaklon me likha hua hai — MR, (2,836),
        // MR. (1,117), MR (582), aur baqi. Isi liye jo likha jata tha wo
        // natija badal deta tha: "MR. adnan" par 10 natije, "MR.adnan" par 7,
        // "mr adnan" par sirf 2 — jab ke Adnan 45 hain. Operator ko yehi
        // dikhta tha ke graahak hai hi nahi, aur wo naya bana deta.
        //
        // Dono taraf se nishan hata kar teenon shaklein ek hi jagah pahunchti
        // hain (naapa gaya: teenon par 39).
        //
        // `[[:punct:][:space:]]` jaan-boojh kar chuna gaya, `[^a-z0-9]` nahi:
        // doosra URDU naam ko poora mita deta aur phir har Urdu naam khali
        // string ban kar ek doosre se match karne lagta. Aaj aise naam sifar
        // hain, magar ye kharabi us din khamoshi se shuru hoti jis din pehla
        // Urdu naam darj hota.
        $nameKey = mb_strtolower(preg_replace('/[\p{P}\p{Z}\s]+/u', '', $q) ?? '');
        $phoneKey = preg_replace('/\D+/', '', $q) ?? '';

        $nameExpr = "REGEXP_REPLACE(LOWER(name), '[[:punct:][:space:]]+', '')";
        $phoneExpr = "REGEXP_REPLACE(phone, '[^0-9]+', '')";

        $customers = Customer::with(['addresses' => fn ($query) => $query->orderByDesc('is_default')->orderBy('id')])
            ->where('status', 'active')
            ->when($q !== '', function ($query) use ($nameKey, $phoneKey, $nameExpr, $phoneExpr) {
                $query->where(function ($inner) use ($nameKey, $phoneKey, $nameExpr, $phoneExpr) {
                    if ($nameKey !== '') {
                        $inner->whereRaw("{$nameExpr} LIKE ?", ['%'.$nameKey.'%']);
                    }
                    if ($phoneKey !== '') {
                        $inner->orWhereRaw("{$phoneExpr} LIKE ?", ['%'.$phoneKey.'%']);
                    }
                });
            })
            // Exact customer/phone first, then prefix matches, then ordinary
            // contains matches. On a large customer book alphabetical order
            // made an exact "Tabish" appear below unrelated stale-looking
            // results and operators could attach the wrong phone to a booking.
            // Tarteeb bhi usi normalised shakl par, warna "theek yehi naam"
            // wala graahak us shakl ki wajah se neeche chala jata jo us ke
            // naam me likhi hai.
            ->when($q !== '', fn ($query) => $query->orderByRaw(
                "CASE WHEN {$nameExpr} = ? THEN 0 WHEN {$phoneExpr} = ? THEN 1"
                ." WHEN {$nameExpr} LIKE ? THEN 2 WHEN {$phoneExpr} LIKE ? THEN 3 ELSE 4 END",
                [$nameKey, $phoneKey, $nameKey.'%', $phoneKey.'%']
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
