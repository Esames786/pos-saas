# REMINDER-REPRINT-SNAPSHOT-1 — a reprinted reminder still shows the table the order has left

**Status:** DIAGNOSIS + PLAN — faisla darkaar
**Date:** 2026-08-31
**Author:** Claude
**For:** Kashif Food (LIVE). Khatri Biryani bhi isi code par.
**Confirmed still live on:** `4bdda60` — aaj ke kisi fix ne is raaste ko chhua nahi.

---

## 1. Jo hua (30 Aug ka asal record)

Floor T4 ne order punch kiya, us waqt wo **table 18** par tha. Baad me session **table 5** par move
hui. Uske baad reminder do baar **dobara print** hui — aur dono par **"TABLE: 18"** chhapa, jabke
receipt (jo baad me nikli) par theek **"Table: 5"** aaya.

```
job 2083  18:14:29  is_reprint=false  table=18   generated_at=18:14:29   ← asal
                    ── 18:17:52 par session 18 → 5 move hui ──
job 2093  18:18:03  is_reprint=TRUE   table=18   generated_at=18:14:29   ← reprint
job 2095  18:18:33  is_reprint=TRUE   table=18   generated_at=18:14:29   ← reprint
```

Teeno reprints ka `generated_at` wahi asal waqt hai — yani teeno **usi purane snapshot** se chhape.

## 2. Kyun

`PrintJobService::queueReminderReprint()` asal job ka **payload hu-ba-hu naql** karta hai:

```php
$payload = $source->payload;
$payload['copy_no']    = $copyNo;
$payload['is_reprint'] = true;
// aur kuch nahi badalta — table, waiter, items, waqt sab purane
```

Reminder ka payload snapshot hai (`reminderSnapshot()`), aur `table` us waqt ka
`$sale->restaurantTable?->table_no` hai. Reprint usay dobara nahi padhta.

**Ye jaan-boojh kar hai.** Reprint ka maqsad hi asal parchi ka **hu-ba-hu duplicate** dena hai —
isi liye us par `DUPLICATE` chhapta hai. Agar reprint live data padhe to wo "duplicate" nahi rahega,
aur jo cheez khoyi thi wo dobara nahi milegi.

## 3. To masla kya hai

Sirf **ek surat** me: jab reprint ke DARMIYAN order ki koi cheez badal jaye — table move, waiter
badla, item cancel hua. Tab reprint purana sach dikhata hai aur padhne wala samajhta hai ke ye **abhi**
ka sach hai.

Kitna aam hai: 30 Aug ko table move **ek baar** hui (session 113), aur usi order ki do reprints
mutasir hueen. Yani kam, magar jab hota hai to kitchen/counter ghalat table par khana bhej sakta hai.

## 4. Options

| | Kya | Asar |
|---|---|---|
| **A** | Reprint par **table aur waiter LIVE** padhein, baqi sab snapshot se | Duplicate ka maqsad barqarar (items, waqt, revision purane), magar table hamesha sahi. **Meri sifarish.** |
| **B** | Poora payload dobara banayein | Ab wo duplicate nahi raha — cancel ho chuke items ghayab ho jayenge, revision badal jayega. Reprint ka maqsad hi khatam. Sifarish nahi. |
| **C** | Parchi par saaf likhein `MOVED: 18 → 5` | Sab se ziyada maloomat, magar payload me purana + naya dono rakhne parenge; thoda zyada kaam. |
| **D** | Jaisa hai | Kabhi kabhi ghalat table par khana |

**A ki sifarish kyun:** reprint jis cheez ke liye maanga jata hai wo hai *"parchi phat gayi / chhapi
nahi, dobara do"* — us me items aur waqt purane hi chahiyen. Magar **table wo jagah hai jahan khana
jana hai**; wo hamesha maujooda hona chahiye. Do cheezein alag hain aur alag bartao maangti hain.

## 5. Fix ki shakl (A)

`queueReminderReprint()` me payload copy karne ke baad:

```php
$sale = SalesOrder::find($source->reference_id);
if ($sale) {
    $sale->loadMissing(['restaurantTable', 'restaurantWaiter']);
    $payload['table']  = $sale->restaurantTable?->table_no;
    $payload['waiter'] = $sale->restaurantWaiter?->name;
}
```

Sirf ye do field. Baqi payload — items, quantities, revision, `generated_at`, cancellations — bilkul
purana, taake parchi phir bhi asal ki naql rahe.

⚠️ **Agar sale mit chuki ho** (bahut purani reprint) to `$sale` null — us surat me purana snapshot hi
rehne dein, kabhi khali table na chhapein.

## 6. Test plan

- `ReminderReprintSnapshotMySqlTest`:
  - order table 18 par, reminder chhapi → session table 5 par move → **reprint par TABLE: 5**
  - usi reprint par **items, revision aur `generated_at` purane hi** rahen (duplicate ka saboot)
  - waiter badla ho to reprint par naya waiter
  - sale hi na mile → purana snapshot, koi khali field nahi
- Regression: printing + POS (aakhri run 384/384).

## 7. Deploy notes

Koi migration nahi, koi naya route nahi. Khatri bhi isi code par — deploy se pehle uska Σ total
unchanged prove karna hai.
