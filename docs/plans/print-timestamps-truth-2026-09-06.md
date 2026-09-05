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

## 7. P1 — KOT ka waqt ✅ HO CHUKA (`fad960b`, deploy baqi)

Naya `App\Support\KotTicketTime` — dono raaste (ESC/POS bytes + preview blade) wahin
se waqt lete hain:

- `TIME:` ab us **round** ka waqt (`kot_batches.created_at`, payload ke `kot_batch_id`
  se; reprint bhi wohi le jaata hai). Batch na ho to `sales_orders.created_at` —
  `sale_date` **nahi**, kyunke wo payment par dobara likha jaata hai.
- Duplicate par `REPRINT: <waqt>` apni alag line me. Kitchen ko dono chahiye.
- Raaste me ek aur khamosh farq bhi band hua: blade me **timezone tha hi nahi**, is
  liye preview sarwar ka UTC dikhata tha jabke parchi Karachi ka waqt. Ab dono ek.

Guard `KotTicketTimeMySqlTest` — **dono raaston par**. Sabit kiya ke kaat-ta hai:
sirf blade wapas `now()` ki to theek us par RED aaya. Printing ke saare guards 165/165.

---

## 8. ⚠️ TASHEEH — P2 ka jo khatra maine likha tha, wo hai hi nahi

Section 7 ke purane khaake me maine likha tha: *"P2 ke baad journal ki tareekh badal
jayegi, owner ko batana zaroori hai."*

**Ye ghalat tha.** Maine dar pehle likh diya, ginti baad me ki — ulta karna chahiye
tha. Prod par ginne ke baad:

```
sale_date kitna khisakta hai:   sirf MINUTE, din nahi
    Kashif   2,754 orders  →  DIN badla:  0
    Khatri   6,507 orders  →  DIN badla:  1     (9,261 me se ek)

GL journals (sales_order_paid):     2,713
    jinki entry_date business_date se nahi milti:   0
```

Yani P2 ka **hisab se koi taalluq nahi**. Wo sirf customer ki receipt par ek line hai.

---

## 9. Owner ka sawal: "12 ke baad sab kuch business date par jaana chahiye — abhi
kya ho raha hai?"

**Jawab: pehle se wohi ho raha hai.** Kashif par kal raat 12 ke baad 182 orders punch
hue, sab par pichle din ka business date:

```
#2764  punch 06-Sep 01:29 AM   →  business_date = 2026-09-05
#2763  punch 06-Sep 01:27 AM   →  business_date = 2026-09-05
#2762  punch 06-Sep 01:21 AM   →  business_date = 2026-09-05
```

`sales_orders`, `sales_returns` aur `shifts` — teeno apna `business_date` rakhte hain,
aur reports wohi parhti hain.

### Lekin GL apni tareekh business_date se SEEDHI nahi leta

`JournalPostingService` entry ki tareekh `sale_date` ke **din** se banata hai
(`($sale->sale_date ?? now())->toDateString()` — teen jagah), `business_date` se nahi.

Aaj tak dono barabar rahe hain (2,713 me se 0 farq) — magar **ittefaq se**:

- Waqt UTC me store hota hai; Karachi UTC se +5 hai.
- Restaurant ka din dopehar 12 se raat 3 baje tak = UTC me 07:00 se 22:00.
- Poora business day **ek hi UTC tareekh** ke andar aa jaata hai, jo business date ke
  barabar nikalti hai.

⚠️ Ye ittefaq **toot sakta hai**: jis raat koi shift subah **5 baje (Karachi)** se aage
chali jaye, UTC ki tareekh badal jayegi aur us bill ka GL business date se alag din par
chala jayega. Kashif par shiftein 3–4 baje tak chalti hain — faasla sirf ek ghanta hai.

---

## 10. Do kaam bache hain — dono ikhtiyari, dono alag

### P2 — receipt par order ka waqt
`SalesOrderController` ke `$saleFields` se `'sale_date' => now()` nikle; sirf naye order
par lage. Payment ka waqt `completed_at` me pehle se mehfooz hai (order #2729 par dono
bilkul barabar the — yehi saboot hai ke `sale_date` par payment ka waqt likha gaya).

**Asar:** sirf receipt ki `Date:` line. Koi report, koi GL, koi hindsa nahi.
**Khatra:** koi nahi (upar ki ginti).

### P3 — GL ki tareekh business_date se
`JournalPostingService` ki teen jagah `sale_date` ki bajaye `business_date` parhein
(fallback wohi purana, taake business_date na hone par bartaao na badle).

**Asar:** aaj koi hindsa nahi hilega (2,713 me se 0 farq). Ye **mustaqbil ka bandobast**
hai — taake GL ittefaq par nahi, usool par chale.
**Khatra:** kam, magar ye FINANCE ka code hai — guard sakht chahiye: ek bill jo subah
6 baje (Karachi) pay ho, uska GL bhi business date par baithe.

### P4 — purana data (5,223 orders ka sale_date)
P2 ke baad bhi purane orders ki receipt reprint par payment ka waqt dikhayegi.
`created_at` se theek kiya ja sakta hai. Journals pehle se likhe ja chuke hain, wo is se
nahi hilenge; reports business_date parhti hain, wo bhi nahi hilengi.
**Alag qadam, alag ijazat.**

---

## 11. Kya haath NAHI lagana

- **Reminder** — theek hai, aur iska snapshot wala tareeqa hi baaqi parchiyon ki misaal.
- **`business_date`** — shift ke saath jama hota hai, reports ki buniyad hai.
- **`completed_at`** — payment ka sacha waqt; P2 iski wajah se hi mumkin hai.

## 12. Kaise sabit hoga

- **P2:** held order banao, 30 minute baad pay karo — `sale_date` na hile, aur receipt
  punch wala waqt dikhaye (dono raaste: bytes + blade).
- **P3:** ek bill jo business date ke agle UTC din me pay ho — uska `entry_date`
  business date par aaye. Guard ko pehle RED hona chahiye.
- **P4:** backfill se pehle aur baad me har business date ka jama ek jaisa rahe (0.00 farq).
