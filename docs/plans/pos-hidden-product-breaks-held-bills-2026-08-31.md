# HIDDEN-PRODUCT-HELD-BILL-1 — hiding a product must never make an open bill unpayable

**Status:** DIAGNOSIS + PLAN — abhi tak NAHI bana
**Date:** 2026-08-31
**Author:** Claude
**For:** Kashif Food (LIVE). Har tenant par lagta hai.
**Live on:** `4bdda60` — masla maujood hai.

---

## 1. Jo hua (30 Aug, live service ke darmiyan)

Owner ne kaha: `Singaporean Rice (Khass)` sirf combo ke andar bike, POS grid par nazar na aaye.
Maine do kaam kiye — dono **alag alag bilkul mehfooz**:

1. `#161` ko grid se hide kiya (`is_pos_visible = 0`)
2. Combo `#34` ko `#161` se hata kar naye `#206` par le gaya

**Saath milkar** in dono ne **paanch khule bills nakabil-e-adaigi** kar diye. Cashiers ne report kiya:
*"Review and pay nahi ho pa raha."*

```
sale #16    HS-20260830090302-103
sale #245   HS-20260830163616-405   table 15
sale #242   HS-20260830163259-847   table 16
sale #269   HS-20260830170853-468
sale #329   HS-20260830181429-883   table 5
```

Foran `#161` wapas visible karke service bahal ki.

## 2. Kyun

`POSController` products payload aise banata hai ([:135-145]):

```php
->where('is_sellable', true)
->where(function ($q) use ($comboComponentProductIds) {
    $q->where('is_pos_visible', true);
    if ($comboComponentProductIds->isNotEmpty()) {
        $q->orWhereIn('id', $comboComponentProductIds);   // ACTIVE combos ke components
    }
})
```

Yani ek **hidden** product payload me **sirf tab** rehta hai jab koi **ACTIVE combo** usay use karta ho.

`#161` ko hide bhi kiya **aur** uske combos bhi hata diye → wo kisi combo ka component na raha →
payload se ghayab. POS jab held order recall karta hai, wo har line ka product **isi payload me**
dhoondta hai. Product mila hi nahi → cart nahi banta → bill pay nahi ho sakti.

**Ye COMBO-COMPONENT-VISIBILITY (`bb5964e`) ka ulta pehlu hai.** Wo fix combo components ko payload me
laaya taake wo jhooti "out of stock" na dikhein. Magar us me **held orders ka khayal nahi** — ek
product jo kisi khuli bill me maujood hai, wo bhi payload me hona chahiye, chahe use koi combo use kare
ya na kare.

## 3. Ye dobara kab hoga

Jab bhi koi **UI se** (Catalog → Products → Edit) kisi product ko "Hidden From POS" kare, aur us
product wali koi bill khuli ho. **Koi warning nahi aati** — bill chup-chaap nakabil-e-adaigi ho jati hai
aur cashier ko sirf failure nazar aata hai.

Aaj maine har hide se pehle guard chalaya (live/draft lines + combo usage), magar wo **script ka guard
tha, system ka nahi**.

## 4. Fix ki shakl

`POSController` ke payload me **teesri shart** — wo products jo kisi LIVE order me hain:

```php
$liveOrderProductIds = SalesOrderLine::query()
    ->whereHas('salesOrder', fn ($q) => $q
        ->whereIn('status', ['held', 'draft'])
        ->where('branch_id', $selectedBranchId))
    ->distinct()->pluck('product_id')
    ->map(fn ($id) => (int) $id)->filter();

// payload where(): is_pos_visible OR combo-component OR IN $liveOrderProductIds
```

Aur `pos_grid_visible` pehle se aisa flag hai jo tay karta hai ke tile bane ya nahi — to ye products
payload me aayenge magar **grid par nazar nahi aayenge**. Bilkul wahi bartao jo combo components ka hai.

### Doosri (behtar) sharat — UI par rok

Product edit par "Hidden From POS" karte waqt agar us product wali khuli bills hon, to **saaf batayen**:

> *"3 khuli bills me ye item maujood hai. Hide karne ke baad wo bills recall/pay ho sakengi (payload me
> rahega), magar naye orders me nazar nahi aayega."*

Sirf ittila — rokna nahi, kyunki fix ke baad ye mehfooz ho jayega.

## 5. Kyun `is_sellable` KABHI 0 na karein

Ye alag aur zyada khatarnak galti hai — maine ye bhi ki thi (Dhaka Chicken par), aur teeno Classic
Platters foran **"Unavailable"** ho gaye.

`is_sellable = false` payload ki **pehli** shart hai — us ke baad wala `orWhereIn` chalta hi nahi. Yani
product har surat me ghayab, chahe use kitne hi combos use karein.

**Hide karne ka wahid mehfooz tareeqa `is_pos_visible = 0` hai.** `is_sellable` ko 1 hi rehne dein.

## 6. Test plan

`HiddenProductHeldBillMySqlTest`:
- held bill me ek product → product hide → **wo phir bhi payload me** ho (grid par nahi)
- wahi product **kisi combo me na ho** → phir bhi payload me (yehi 30 Aug wala case)
- bill pay ho jane ke baad product payload se nikal jaye (ab koi live order nahi)
- `is_sellable = 0` → payload se nikal jaye (mojooda bartao, jaan-boojh kar)
- draft orders bhi held ki tarah ginein

Regression: POS, HeldSale, Combo suites (aakhri run **381/381**).

## 7. Deploy notes

Koi migration nahi, koi naya route nahi. Ek query POSController me. Har tenant ko faida.
Khatri bhi isi code par — deploy se pehle uska Σ total unchanged prove karna hai.
