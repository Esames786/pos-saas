# CANCEL-FREES-TABLE-1 — a cancelled order must give its table back

**Status:** DIAGNOSIS + PLAN — abhi tak NAHI bana
**Date:** 2026-08-31
**Author:** Claude
**For:** Kashif Food (LIVE). Khatri Biryani bhi isi code par.
**Live on:** `4bdda60` — ye masla abhi bhi maujood hai.

---

## 1. Jo hua

30 Aug, raat 18:32 — Floor T4 par `HS-20260830183246-928` (Rs 550) khula, phir **poora order cancel**
hua. Order `cancelled` ho gaya, magar **Table 9 "Occupied" hi rahi**. Poore floor par sirf yehi ek
table is halat me thi:

```
table 9  (#57 occupied)  session #119  open  opened=18:32:28  sales[{"cancelled":1}]
                                                          ↑ wahid sale cancelled, phir bhi table held
```

Table Workspace par wo "Occupied · Total 0.00" dikhati rahi — koi us par baith nahi sakta tha, aur
staff ko samajh nahi aa raha tha ke kyun.

## 2. Kyun

`KotCancellationService::cancelHeldOrder()` ka aakhri hissa bas itna hai:

```php
$sale->update(['status' => 'cancelled']);
```

Bas. **Table session band nahi hoti, table free nahi hoti.**

Muqabla karein normal band hone se — `RestaurantTableSessionController::close()`:

```php
$restaurantTableSession->update([
    'status'            => $sessionStatus,     // closed | cancelled
    'closed_by_user_id' => Auth::id(),
    'closed_at'         => now(),
]);
$restaurantTableSession->table->update(['status' => 'available']);
```

Cancel ke raaste me ye qadam hai hi nahi. Order mar gaya, table zinda reh gayi.

## 3. Kitna aam

30 Aug ko **paanch** poore order cancel hue; un me se **ek** dine-in table par tha — aur wohi table
atak gayi. Yani jitni baar dine-in ka poora order cancel hoga, utni baar table atkegi.

Filhal Table 9 (aur ek aur, Table 14, jo bina kisi sale ke khuli reh gayi thi) **haath se free** ki
gayi hain.

## 4. Fix ki shakl

Cancel ke baad dekha jaye ke us session par koi aur **LIVE** sale bachi hai ya nahi:

```php
$sale->update(['status' => 'cancelled']);

if ($sale->restaurant_table_session_id) {
    $session = RestaurantTableSession::whereKey($sale->restaurant_table_session_id)
        ->lockForUpdate()->first();

    if ($session && in_array($session->status, ['open', 'bill_requested'], true)) {
        $stillLive = SalesOrder::where('restaurant_table_session_id', $session->id)
            ->whereIn('status', ['held', 'draft', 'paid', 'partially_returned'])
            ->exists();

        if (! $stillLive) {
            $session->update([
                'status' => 'cancelled', 'closed_by_user_id' => $requestingUserId, 'closed_at' => now(),
            ]);
            $session->table?->update(['status' => 'available']);
        }
    }
}
```

### Do baatein jo is me lazmi hain

**SPLIT BILL.** Ek session par kai sales ho sakti hain. Table tab hi free ho jab **koi bhi** live sale
na bache — warna ek bill cancel karne se poori table chhin jayegi jabke doosra bill abhi chal raha hai.
Yehi wajah hai ke shart `exists()` par hai, `count() === 1` par nahi.

**LOCK.** Poora check `lockForUpdate()` ke andar, usi transaction me jis me cancel ho raha hai. Warna
do counters ek saath cancel karein to race ho sakti hai.

## 5. Jo NAHI karna

- **Khali khuli table apne aap band na karein.** Table 14 aaj 97 minute se bina kisi sale ke khuli
  thi — wo jaayaz halat hai (waiter ne table kholi, order abhi punch nahi hua). Usay chhoona nahi.
  Sirf wo table free ho jiski sale **cancel hui**.
- **Line-item void par kuch na karein** — order zinda hai, table zinda rehni chahiye.

## 6. Test plan

`CancelFreesTableMySqlTest`:
- dine-in order + open session → poora cancel → **session cancelled, table available**
- **split bill:** ek session par do sales; ek cancel → **table occupied hi rahe**; doosri bhi cancel →
  ab free
- **line-item void** → table par koi asar nahi
- session ki table pehle se `available` ho (kisi aur ne band kar di) → koi error nahi, chup-chaap chhod de
- takeaway / delivery order cancel → koi session hai hi nahi, kuch na ho

Regression: Table, Restaurant, HeldSale, Cancel, POS suites (aakhri run **381/381**).

## 7. Deploy notes

Koi migration nahi, koi naya route nahi, koi permission nahi. `KotCancellationService` ka ek hissa.
Khatri bhi isi code par — deploy se pehle uska Σ total unchanged prove karna hai.
