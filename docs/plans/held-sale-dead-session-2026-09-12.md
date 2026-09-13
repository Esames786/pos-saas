# HELD-SALE-DEAD-SESSION-1 — band table par bill hold ho jata hai, phir kabhi pay nahi hota

**Tareekh:** 2026-09-12 (tehqeeq), 2026-09-13 (design owner ki manzoori ke baad)
**Tenant jahan pakra gaya:** `kashiffood` (Kashif Food), branch 1
**Haalat:** tehqeeq mukammal · design manzoor · **code abhi nahi likha, deploy nahi hua**

> Owner ki hidayat: *"4 running business kam kar rahe hain is sensitive screen pe, mai koi risk nhi le sakta —
> research karlo phr fix apply kardena."* Is liye ye doc pehle, code baad me.

---

## 1. Kya hua

Cashier ne table 9 (First Floor) ka bill pay karne ki koshish ki:

```
Cannot complete sale
No query results for model [App\Models\Tenant\RestaurantTableSession] 2147
```

Bill: `HS-20260912170828-631` (order #5408), **Rs 2,465.00**, terminal 4, shift 66 (DTQ Floor T4).

Waqt ki tarteeb — **yehi poora masla hai** (sab PKT):

```
22:07:33   session 2147 table 9 par khuli
22:08:03   session 2147 BAND kar di gayi          (30 sec baad, user #3)
22:08:28   cashier ne Hold dabaya
           -> bill #5408 us BAND session par likh diya gaya    (close ke 25 sec BAAD)
23:27      table 9 par nayi session 2174 khuli — naye mehmaan
```

Cashier order punch kar raha tha; us dauran table band ho gayi. POS ke safhe ke URL me purani session ka
number (`table_session_id=2147`) pada tha, aur Hold ne usay bila-chon-o-chara qabool kar liya.

Bill ek **mari hui session** se chipak gaya: na pay hota hai, na table board par nazar aata hai
(kyunke us table par ab nayi session chal rahi hai).

---

## 2. Asal sabab — Hold aur Pay alag shartein lagate hain

| Raasta | File:line | Session ka status check |
|---|---|---|
| **Hold**, jab session id saaf di gayi ho | `HeldSaleController.php:419-423` | ❌ **koi nahi** |
| Hold, jab table se khud dhoondhe | `HeldSaleController.php:424-430` | ✅ `open` / `bill_requested` |
| **Pay** | `SalesOrderController.php:303-308` | ✅ `open` / `bill_requested` |

```php
// HeldSaleController.php:419-423
if (!empty($data['restaurant_table_session_id'])) {
    $tableSession = RestaurantTableSession::with('table')
        ->where('branch_id', $data['branch_id'])
        ->lockForUpdate()
        ->find($data['restaurant_table_session_id']);   // <- status ki koi shart NAHI
    $data['order_type'] = 'dine_in';
}
```

Theek 6 satar neeche (`:429`) wohi shart **mojood** hai. Yani ye **bhool hai, iraada nahi**.

Pay wala raasta `findOrFail` par `ModelNotFoundException` phenkta hai, aur uska raw paighaam cashier tak
pahunch jata hai. Usi function me `:323` par ek theek paighaam mojood hai, magar wo sirf doosre branch me
chalta hai.

### Close ka guard PEHLE SE theek hai

`RestaurantTableSessionController::close()` (`:231-250`) transaction + lock ke andar dekhta hai ke us
session par koi `held`/`draft` order to nahi — ho to close rad: *"This table has an open order now."*

Is liye bill sirf us **tang daur** me anaath hota hai jab **hold, close ke BAAD** pahunche. Yehi 12 Sep ko hua.

---

## 3. Blast radius — prod ka asal census (12 Sep)

| Tenant | Sessions | Din | Dine-in orders | Abhi held | Race hua |
|---|---|---|---|---|---|
| khatribiryani | 3,999 | 27 | 3,987 | 0 | **0** |
| kashiffood | 2,207 | 14 | 2,190 | 17 (13 table wale) | **2** |
| kashifkitchen | 0 | — | 0 | 0 | 0 |
| tawakalkashif | 455 | 7 | 443 | 0 | **0** |

6,661 sessions / 48 din ke kaam me race **2 bar** laga (~0.03%):

| Bill | Tareekh | Table | Raqam | Anjaam |
|---|---|---|---|---|
| #3521 | 7 Sep | 12 | Rs 4,300 | **cancel** karna para |
| #5408 | 12 Sep | 9 | Rs 2,465 | haath se bachaya (§5) |

**Khatri sab se ziyada dine-in karta hai aur wahan kabhi nahi hua** — close-guard kaam kar raha hai.
Ye kam hota hai, magar jab hota hai to poora bill phansta hai aur customer khara rehta hai.

**Edge band hai** (`APP_ROLE` aur `EDGE_FEATURE_ENABLED` dono khali → `EdgeRuntime::isCloudSafe() = true`),
is liye `EdgeLocalPosService` wala parallel raasta abhi so raha hai. §7 dekhein.

---

## 4. Manzoor shuda design — cashier ko wahin hal milega

Owner ki tajweez (meri asal "sirf inkaar kar do" se behtar): recall ya Review & Pay par popup, jis me
cashier **wohi table dobara khole** ya **koi khali table chun le**.

### Cashier ko kya dikhega

```
┌─────────────────────────────────────────────────────────┐
│  ⚠  Ye table band ho chuki hai                          │
│                                                         │
│  Table 9  ·  band ki: Floor T4 Counter  ·  aaj 10:08 PM │
│  Bill: HS-20260912170828-631        Rs 2,465.00         │
│                                                         │
│  [ Table 9 dobara kholein ]     <- sirf jab wo khali ho │
│  [ Doosri table chunein  ▾ ]    <- sirf KHALI tables    │
│  [ Abhi nahi ]                                          │
└─────────────────────────────────────────────────────────┘
```

| Us table ka haal | Popup kya offer karega |
|---|---|
| Table 9 abhi **khali** | "Table 9 dobara kholein" — ek click |
| Table 9 par **naye mehmaan** *(12 Sep ko yehi tha)* | Wo button **hoga hi nahi** — sirf khali tables ki list |

"band ki: **Floor T4 Counter**" muft me mil jata hai — `closed_by_user_id` pehle se mehfooz hota hai.

### Pehchan — koi extra query nahi chahiye

`POSController:44-72` pehle se dono resolve karta hai. Anaath bill ka signature bilkul saaf hai:

```php
$heldSale !== null
&& $heldSale->restaurant_table_session_id !== null   // bill kehta hai "meri table hai"
&& $tableSession === null                            // magar khuli session mili nahi
```

`$heldSale` ke sath `restaurantTableSession` **bina status filter** ke eager-load hota hai (`:47-52`),
is liye band session ka `closed_at` / `closed_by_user_id` / table sab pehle se haath me hai.
Sirf `closedBy` ka naam eager-load me jorna hoga.

### Table picker — naya endpoint NAHI chahiye

`POSController::loadBoardFloors()` (`:462`) POS page par pehle se saari floors + tables +
`openSession` bhejta hai. Picker usi data se banega. **Kam surface, kam khatra.**

### Do jagah trigger

1. **Recall** (asal jagah) — POS page load par, upar wale signature se
2. **Review & Pay** (safety net) — agar page khulne ke baad table band ho jaye. Iske liye
   `SalesOrderController:303-308` ka `findOrFail` hatana hoga aur uski jagah ek **structured 422**
   dena hoga jise POS wohi popup bana sake

---

## 5. Abhi kya kiya gaya (haath se bachao — 12 Sep, ~23:46 PKT)

Code me kuch nahi badla. Owner ki hidayat par ("move to table 20") sirf us ek bill ko qabil-e-adaigi banaya:

1. Table 20 (`restaurant_tables.id = 68`) **khali** thi — tasdeeq ki
2. Us par **nayi** session `#2182` banai (shift 66, business_date 2026-09-12)
3. Bill #5408 ko us nayi session + table 20 par point kiya
4. Session 2147 **bilkul asli haalat** me wapas — table 9, `closed`, wohi `closed_at 17:08:03`,
   wohi `closed_by_user_id 3`

Table 9 par naye mehmaan ki session 2174 **chhui tak nahi** gayi.

### ⚠️ Pehli koshish NAKAAM hui — aur us se ek asli bug mila

Pehle maine purani session 2147 ko table 20 par **khiska** diya tha. Data theek tha, magar board ne
table **khali** dikhaya. Wajah:

```php
// RestaurantTable.php:59-64
public function openSession()
{
    return $this->hasOne(RestaurantTableSession::class)
        ->whereIn('status', ['open', 'bill_requested'])
        ->latestOfMany();
}
```

`latestOfMany()` andar `MAX(id)` ka subquery banata hai aur **upar wali `whereIn('status')` us subquery
par nahi lagti**. Yani:

> "table ki **sab se nayi** session utha kar phir dekho khuli hai ya nahi"
> — na ke "sab se nayi **khuli** session".

Table 20 par 2155 (band, bara id) pari thi, us ne 2147 (khuli, chhota id) ko chhupa diya.
**Durust shakl:**

```php
return $this->hasOne(RestaurantTableSession::class)->ofMany(
    ['id' => 'max'],
    fn ($query) => $query->whereIn('status', ['open', 'bill_requested'])
);
```

Isi liye reattach hamesha **nayi session** banayega, purani zinda nahi karega — nayi ka id hamesha sab se
bara hota hai, to ye jaal lag hi nahi sakta. Sath hi purani session ka "band hua" record sach raha jata hai.

---

## 6. Nazeer pehle se mojood hai — `merge()` ki nakal karo

`RestaurantTableSessionController::merge()` (`:500-535`) **bilkul yehi kaam** karta hai. Naya raasta
banane ke bajaye uska tareeqa copy hoga:

```php
// transaction ke ANDAR dobara parh kar tasdeeq
if ($activeTargetSessions->count() !== 1 || (int) $activeTargetSessions->first()->id !== (int) $target->id) {
    throw new \RuntimeException('The destination table session changed. Refresh and try again.');
}

$activeSales = SalesOrder::where('restaurant_table_session_id', $source->id)
    ->whereIn('status', ['held', 'draft'])->lockForUpdate()->get();

// Paid fiscal history intentionally remains attached to the original session/table.
SalesOrder::whereIn('id', $activeSales->pluck('id'))->update([
    'restaurant_floor_id'         => $targetTable->restaurant_floor_id,   // <- ye mai bhool sakta tha
    'restaurant_table_id'         => $targetTable->id,
    'restaurant_table_session_id' => $target->id,
    'restaurant_waiter_id'        => $target->restaurant_waiter_id,
]);

$source->update([... 'notes' => '... Active check merged into session X; paid history retained.']);
```

Teen cheezein yahan se lena:

1. **`restaurant_floor_id` bhi update hota hai** — bill floor bhi carry karta hai
2. **Sirf `held`/`draft` chalte hain** — "Paid fiscal history intentionally remains attached"
3. **Audit `notes` me likha jata hai** — yani **nayi audit table ki zaroorat NAHI**;
   is tenant me koi activity-log table hai bhi nahi

---

## 7. Har wo cheez jo `restaurant_table_session_id` parhti hai — aur uska asar

| Jagah | Kya karti hai | Reattach ka asar |
|---|---|---|
| `RestaurantTableSessionController:241` | close se pehle held/draft ka guard | ✅ Nayi session held bill ke sath band nahi hogi |
| `:401` (move), `:512-525` (merge) | orders ko doosri session par | ✅ Wohi nazeer — §6 |
| `SplitBillController:94, :240` | split sale parent ka session copy karta hai | ✅ Reattach ke baad split naye session par banega |
| `SalesService:288-299` | **payment ke baad session khud band** karta hai | ✅ Nayi session bhi khud band hogi |
| `KotCancellationService:96-108` | cancel par session ki zindagi | ✅ Wohi |
| `SaleIdempotencyService:46` | fingerprint me `restaurant_table_session_id` **shamil hai** | ⚠️ §8 |
| `EdgeLocalPosService:556-631, :997` | poora parallel offline raasta | ⚠️ §8 |

---

## 8. Khatre aur un ki tadbeer

| # | Khatra | Tadbeer |
|---|---|---|
| 1 | **Occupied table par bill bhej dena** — do customers ka bill ek check me. **Ye sab se bara khatra hai** | Picker me sirf khali tables. Server par bhi transaction ke andar `lockForUpdate` se dobara tasdeeq (merge ki nakal) |
| 2 | **Naya route = naya permission.** `deploy.sh` sirf **Owner** ko deta hai → cashiers ko 403 | Deploy ke baad har cashier role ko **additive** `givePermissionTo` (kabhi `syncPermissions` nahi) + `system:clear-tenant-permission-cache`. Ye deploy checklist ka hissa hai, baad ki soch nahi |
| 3 | **KOT purani table ke naam se chhap chuki hai** — kitchen ke paas "Table 9" ki parchi hai, bill ab table 20 par | Popup me saaf likha ho: *"Kitchen ki parchi par purani table (9) likhi hai."* Dobara KOT **nahi** chhapegi — khana ban chuka hai |
| 4 | **Idempotency fingerprint badal jata hai** (session id us me shamil hai) | Nuqsan nahi: pehli koshish nakaam thi, koi sale bani hi nahi. Purani session ke sath koi latki hui retry aaye to wo **fail-closed** ho jayegi (session band hai). Guard me qaid karna |
| 5 | **Edge ka parallel raasta** (`EdgeLocalPosService:556-565`) wohi shart alag jagah lagata hai | Edge abhi **band** hai (cloud). P1 wahan bhi lagana hai warna Edge chalu hote hi wohi bug wapas. Doc me darj, is release ka hissa nahi |
| 6 | `openSession()` badalna **poore table board** ko chhoota hai | Guard 7 (§9) + deploy ke baad board ka **asli render** dekhna |
| 7 | Cashier popup ko samajh na paye | Har button par agla qadam likha ho; "Abhi nahi" hamesha mojood |
| 8 | Reattach se koi paid bill hile | Sirf `held`/`draft` — merge ki tarah. Guard 6 |

---

## 9. Guards — test ke baghair kuch deploy nahi

`tests/MySql/HeldSaleDeadSessionMySqlTest.php` — asli HTTP routes par, query dobara likh kar nahi.

| # | Guard | RED hona chahiye agar... |
|---|---|---|
| 1 | Band session ki id ke sath Hold → 422 **aur koi `sales_order` na bane** | P1 ki satar hata do |
| 2 | Khuli session par Hold → 200, bill bane | — |
| 3 | `bill_requested` session par Hold → 200 (ye bhi zinda haalat hai) | shart ghalat likh do |
| 4 | Band session wala bill recall → POS par popup ka data aaye (band table, kis ne band ki, waqt) | detection palat do |
| 5 | Band session par Pay → **structured 422**, raw `No query results` nahi | P2 palat do |
| 6 | Reattach: sirf `held`/`draft` chalen; **paid bill na hile** | filter hata do |
| 7 | Jis table par khuli session ho aur us se naye id wali band session bhi, `openSession()` **khuli** wali laut-aye | P4 palat do |
| 8 | **Occupied table par reattach → 422** | target ka lock-check hata do |
| 9 | Reattach ke baad bill ka `restaurant_floor_id` bhi naye table ka ho | floor ko update se nikal do |
| 10 | Reattach ke baad payment → sale bane **aur nayi session khud band ho** | — |
| 11 | Bill ki session **khuli** ho to reattach 422 (wo `move()` ka kaam hai) | shart hata do |

⚠️ Guard 1 me sirf status code mat dekho — `sales_orders` ki **ginti** pehle aur baad me barabar ho.
Warna bill banta rahega aur test pass karta rahega.

⚠️ Guard 7 ke liye fixture me id ki tarteeb jaan-boojh kar **ulti** banani paregi (pehle khuli session,
phir band) — warna wo kabhi RED nahi hoga.

⚠️ **Har guard ko RED hote dekhna hai.** Pichli bar ek guard likha tha jo be-maani nikla kyunke jis
cheez ko wo test kar raha tha wo kahin aur thi. Fix hata kar test chalao; na gire to guard ghalat hai.

---

## 10. Kaam ki fehrist

| # | Kaam | Size | Kyun |
|---|---|---|---|
| **P1** | `HeldSaleController:419-423` me `whereIn('status', [...])` | **1 satar** | Bimari rokta hai — anaath bill banega hi nahi |
| **P2** | `SalesOrderController:303-308` ka `findOrFail` → structured 422 | ~15 satar | Cashier ko insani paighaam + popup ka trigger |
| **P3** | Naya endpoint `POST /held-sales/{sale}/reattach-table` + 6 server-side validations | ~80 satar | Ilaj — cashier khud nikal sake |
| **P4** | `RestaurantTable::openSession()` → `ofMany()` | 4 satar | Warna reattach kiya hua bill board par gayab |
| **P5** | Popup + table picker (POS blade + JS) | UI | Cashier ka safha |
| **P6** | Recall par pakarna (`POSController` me flag) | ~10 satar | Trigger 1 |
| **P7** | Guards (§9) | test | — |

**P1 aur P3 dono chahiyen.** P1 *bimari* rokta hai (hold usi waqt rad, cashier 10 sec me table dobara
khol kar save kar le), P3 *ilaj* hai us tang daur ke liye jo phir bhi lag jaye.

---

## 11. Jo NAHI karna

- ❌ Band session milte hi **chup-chaap** us table ki maujooda khuli session par switch — wahan naye
  mehmaan ho sakte hain. Ye masle se **bura** hoga. Saaf poochna hai.
- ❌ Picker me **occupied** tables dikhana.
- ❌ Purani session ko **zinda** karna — `latestOfMany()` ka jaal (§5). Hamesha nayi session.
- ❌ **Paid** bills ko hilana — merge ka usool: "paid fiscal history stays".
- ❌ Reattach par KOT **dobara** chhapna — khana ban chuka hai.
- ❌ Canonical `pos-saas` me likhna — kaam `pos-saas-hideamounts` worktree,
  branch `fix/held-sale-dead-session-v1` (`8740f51` se).

---

## 12. Deploy

- Koi migration **nahi**, koi data tabdeeli **nahi**
- **Ek naya route** → §8 #2 ka permission wala kaam **lazmi**, warna cashier 403 khayega
- Blade badlegi → deploy ke baad cashiers ko **Ctrl+F5**
- Deploy ke baad smoke: (a) dine-in hold + pay, (b) table board ka asli render, (c) ek band session
  bana kar popup dekhna, (d) `tb_diff = 0.00` chaaron tenants par
- Rollback: sab code-only hai, `git revert` kaafi

**Owner ki saaf ijazat ke baghair deploy nahi.**
