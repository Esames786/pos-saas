# Parchiyon ka waqt — ek order, chaar mukhtalif jawab

**Tareekh:** 2026-09-06 · **Tenants:** Kashif Food (#348), Khatri Biryani (#212)
**Halat:** research mukammal (prod ke asli data par), code likhna baqi
**Shuruaat:** owner ne reminder reprint par ghalat tareekh dekhi

---

## 1. Owner ne kya kaha

> "reminder print jub maine reprint keya to us mai order date print date tamam date
> time latest agae kis time maine reprint keya.. may b yahe same problem tab b ati
> ho jub running order pe item punch k bad reminder jata hoga"

Owner ka mushahida **theek tha**, lekin ilzam ghalat jagah gaya: **reminder mehfooz
hai**. Jo cheez har reprint par aaj ki tareekh chhapti hai wo **KOT** hai.

---

## 2. Asli saboot — order #2729 (Kashif, 5 Sep ki raat)

Ye order prod par mojood hai. Chaaron output maine khud render kar ke dekhe:

```
SACH:  ye order 12:18 AM par punch hua tha
       (sales_orders.created_at — ye kabhi nahi badalta)

KOT      →  TIME: 12:52 AM   Sun 06-Sep-2026    ❌  jis lamhe maine render kiya
RECEIPT  →  Date: 06/09/2026 12:41 AM           ❌  jis lamhe paisa diya (23 min baad)
REMINDER →  ORDER: 06/09/2026 12:18 AM          ✅  sahi
PREVIEW  →  hamesha "abhi"                      ❌  recall kiya order ho to jhoot
```

Aur usi order ka record:

```
created_at    2026-09-05 19:18:40   ← punch (sach)
sale_date     2026-09-05 19:41:32   ← 23 minute aage khisak gaya
completed_at  2026-09-05 19:41:32   ← payment ka waqt (sale_date isi ke barabar hai)
kot_batch #1  2026-09-05 19:18:40   ← round ka apna waqt (sach)
```

`sale_date` aur `completed_at` ka bilkul barabar hona hi saboot hai ke `sale_date`
par payment ka waqt likh diya gaya.

---

## 3. Kitne orders par ho chuka hai

```
KASHIF FOOD   2,742 orders  →  1,947 (71%) ka sale_date khisak chuka hai
                                sab se bara farq: 683 minute (11 ghante)

KHATRI        6,507 orders  →  3,276 (50%) ka sale_date khisak chuka hai
                                sab se bara farq: 1,314 minute (22 ghante)
```

**Aur ek ehem baat: dono tenants par `business_date` khali rows = 0.**
Har order par apni tareekh mojood hai, is liye `sale_date` theek karne se **kisi
report ka koi hindsa nahi hilega** — reports `business_date` parhti hain.

---

## 4. Har parchi ka hisab (reprint samet)

| Parchi | Waqt kahan se aata hai | Asli print | Reprint |
|---|---|---|---|
| **KOT** | `now()` — hardcoded | thek (abhi hi bani) | ❌ **aaj ki tareekh** |
| **Receipt** | `sale_date`, live parha jaata hai | ❌ payment ka waqt | ❌ payment ka waqt |
| **Bill Preview** | `now()` | ❌ recall kiya order ho to galat | — |
| **Reminder** | payload me **jama shuda** waqt | ✅ | ✅ |

Sirf reminder theek hai kyunke wohi ek apna waqt parchi ke andar snapshot karti hai.
Reminder reprint teen asli jodiyon par milaya — `order_time` / `updated_time` /
`generated_at`, teeno hu-ba-hu copy hote hain.

---

## 5. Do alag keere hain, ek nahi

### Keera A — KOT `now()` likhti hai

`EscPosPayloadService::kot()` aur `documents/kot.blade.php` dono:

```php
$now = now()->timezone($this->printTz($sale));
$out .= 'TIME: ' . $now->format('h:i A');
$out .= $now->format('D d-M-Y');
```

Order se poocha hi nahi jaata. Is liye 7 din purani KOT reprint karo, aaj chhapega.
Dono raaste (physical + preview) is par barabar kharab hain.

### Keera B — `sale_date` payment par dobara likh diya jaata hai

`SalesOrderController` me held order recall ho kar pay hota hai to
`$sale->update($saleFields)` chalta hai, aur `$saleFields` me:

```php
'sale_date' => now(),
```

Order ka apna waqt payment ka waqt ban jaata hai. Receipt yehi field live parhti
hai, is liye receipt jhoot bolti hai. Jo table jitni der baithi, utna bara farq.

---

## 6. Sahi waqt kahan se milega

| Parchi | Kya dikhna chahiye | Kahan se |
|---|---|---|
| KOT | jis round ka khana hai, us round ka waqt | `kot_batches.created_at` (payload me `kot_batch_id` pehle se hai), warna `sales_orders.created_at` |
| Receipt | order ka waqt | `sale_date` — bashart-e-ke use dobara na likha jaye |
| Preview | recall kiye order ka apna waqt | held sale ka `created_at` |
| Reminder | — | pehle se theek |

Reprint par waqt **chhupana nahi** — parchi par "DUPLICATE" ke saath reprint ka waqt
**alag line** me aaye. Kitchen ko dono chahiye: khana kab manga gaya, aur ye kaagaz
kab nikla.

---

## 7. Kaam ki tarteeb

**P1 — KOT ka waqt** (sab se zyada asar, data ka koi khatra nahi)
Sirf render badlega. `TIME:` ab round ka waqt, aur neeche `REPRINT: <waqt>` jab
duplicate ho. Dono raaste ek saath — warna preview aur printer alag bolenge.

**P2 — `sale_date` dobara na likha jaye**
`$saleFields` se `'sale_date' => now()` nikle; sirf naye order par lage. Payment ka
waqt `completed_at` me pehle se mehfooz hai.

⚠️ **Pehle ye dekhna zaroori hai:** `JournalPostingService` journal ka
`transaction_date` **`sale_date` se** banata hai (sirf tareekh, waqt nahi). Raat 12
baje ke baad pay hone wale order ka journal abhi agle din par jaata hai; P2 ke baad
wo punch wale din par jayega. Ye **behtar** hai (business_date se mel khayega), magar
ye faisla owner ko bata kar hona chahiye, chupke se nahi.

**P3 — Bill Preview**
Recall kiye hue order ka apna waqt. Naye (abhi bante) order par `now()` durust hai.

**P4 — purana data (alag manzoori)**
5,223 orders ka `sale_date` ghalat hai. `created_at` se theek kiya ja sakta hai.
Journals pehle se likhe ja chuke hain, wo is se nahi hilenge; reports `business_date`
parhti hain, wo bhi nahi hilengi. Phir bhi ye alag qadam hai, alag ijazat ke saath.

---

## 8. Kya haath NAHI lagana

- **Reminder** — ye theek hai, aur iska snapshot wala tareeqa hi baaqi parchiyon ke
  liye misaal hai.
- **`business_date`** — shift ke saath jama hota hai, reports ki buniyad hai.
- **`completed_at`** — payment ka sacha waqt; P2 iski wajah se hi mumkin hai.

---

## 9. Kaise sabit hoga

- KOT: ek purana order lo, uski KOT reprint karo — parchi par **us din ka** waqt aaye,
  aaj ka nahi. Guard dono raaston par (bytes + blade), warna wohi purani ghalti
  dohrayegi jahan guard sirf ek raasta parhta tha.
- Receipt: held order banao, 30 minute baad pay karo — `sale_date` na hile aur receipt
  punch wala waqt dikhaye.
- Reminder: jaisa hai waisa hi rahe — mojooda guard sabit karta rahe.
