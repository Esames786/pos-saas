# RIDER-RETURNS-1 — Rider aur Channel report par "kitna wapas aaya"

**Tareekh:** 2026-09-02
**Branch:** `feat/rider-returns`
**Halat:** built + green, **deploy nahi hua** (malik ki ijazat ka intezar)

---

## 1. Masla kya tha

Kal `LEGACY-REPORTS-POPULATION-1` deploy hua. Us ne chhe purani report queries ko wohi
population di jo Report Center istemal karta hai — `paid` + `partially_returned` + `returned`.
Un me `baseDeliveryQuery()` bhi thi, jo **do** screenon ko khilati hai:

- `/reports/sales/riders` — Rider Deliveries
- `/reports/sales/channels` — Sales by Channel

Ye theek tha: rider ne order pahunchaya, chahe baad me wo wapas hi kyun na aa gaya. Us se
pehle wala code returned bill ko poora ka poora gira deta tha — Khatri par 44 orders aur
63,610 rupay ghayab the.

Lekin theek karne ke baad **safha khud apni tabdeeli nahi samjhata tha**. Malik ne 2 September
ko rider report kholi:

```
28 deliveries    61,720.00
```

pehle jo yaad tha wo tha `25 / 57,250`. Safhe par kuchh nahi tha jo bataye ke farq
"3 returned orders ke 4,470" hai. Upar likha bhi ghalat ho chuka tha: *"Paid delivery
orders per rider"* — ab sirf paid nahi rahe.

Yehi shikayat aayi: **"explain rider report does we change anything recently?"** — aur
faisla: **"new column daldo"**.

---

## 2. Hal

Ek hi `Total Amount` column ko paanch me tor diya, dono screenon par:

| Column | Matlab |
|---|---|
| **Deliveries** | rider ne kitne order uthaye (returned bhi shamil) |
| **Returned** | un me se kitne wapas aaye (jazvi ya poore) |
| **Total Amount** | jitne ka bill bana |
| **Returns** | jitna wapas kiya gaya (sirf `posted` returns) |
| **Net Amount** | Total − Returns = jitna dukaan ke paas raha |

`Avg / Delivery` ab **Net** par bane ga, gross par nahi — rider ki aasat delivery wohi honi
chahiye jo asal me kamai hui.

Channel report par yehi paanch aate hain (`Net of Returns` naam se), aur **Commission
bilkul waise hi gross par rehta hai jaise pehle tha**. Ye jaan boojh kar hai: aggregator
apna commission wapas karta hai ya nahi, ye us ki statement me tay hota hai, is safhe par
nahi. Agar hum yahan chupke se net kar dete to ek aisa hindsa badal jata jise malik pehle
se milaan karta hai. Safhe ke neeche ye baat likh di gayi hai.

---

## 3. Fani tafseel

`SalesReportService` me ek naya helper:

```php
private function refundsPerOrder()
{
    return DB::connection('tenant')->table('sales_returns')
        ->where('status', 'posted')
        ->selectRaw('sales_order_id, COALESCE(SUM(grand_total), 0) as refunded')
        ->groupBy('sales_order_id');
}
```

`baseDeliveryQuery()` is ko `leftJoinSub` se joritee hai.

**Ye subquery kyun, seedha join kyun nahi?** Kyunke ek bill do dafa refund ho sakta hai.
`sales_returns` se seedha join karte to us order ki row **do dafa** aati aur us par har
column phool jata — count, gross, net, teenon. Subquery har order ki ek hi row deti hai,
is liye aggregate fan out ho hi nahi sakta. Test `test_a_bill_refunded_twice_neither_double_counts_nor_fans_out`
isi ko pakadta hai.

`returned_count` = `SUM(CASE WHEN rf.refunded IS NULL THEN 0 ELSE 1 END)` — orders ginta hai,
returns nahi. Do dafa refund hone wala bill phir bhi **ek** returned order hai.

---

## 4. Kahan kahan haath laga (blast radius)

| File | Kya |
|---|---|
| `app/Services/Reports/SalesReportService.php` | `refundsPerOrder()`, `baseDeliveryQuery()` join, aur teen aggregate (byRider totals, byRider daily, byChannel) |
| `app/Http/Controllers/Tenant/Reports/SalesReportController.php` | dono CSV export me naye column |
| `resources/views/tenant/reports/sales/riders.blade.php` | dono table (totals + per-day), footer, subtitle |
| `resources/views/tenant/reports/sales/channels.blade.php` | table, footer, subtitle, commission ka note |
| `tests/MySql/RiderReturnsMySqlTest.php` | naya guard, 6 test |

**Migration koi nahi. Naya route koi nahi. Nayi permission koi nahi.**

`byRider()`/`byChannel()` sirf yehi do controller actions bulate hain — grep se tasdeeq.
Report Center, Quick Report, cron email, thermal, PDF — koi bhi in do function ko nahi
chhoota, is liye un me kuchh nahi badla.

Report Center ka apna delivery hisaab alag jagah se aata hai (`SalesReportEngine`), wo
pehle hi returns ko theek se ghata raha tha.

---

## 5. Saboot

### Test — 6/6 green

- rider totals: 3 delivery / 2 returned / 1,800 total / 420 returns / 1,380 net
- per-day breakdown wohi kahani sunata hai jo totals table
- channel row: wohi paanch, aur commission ab bhi 180 (gross par)
- do dafa refund hua bill: 1 delivery, 1 returned, 1,000 total, 150 returns, 850 net
- `cancelled` return ginti me nahi aata
- **jis din koi return na ho, safha bilkul pehle jaisa parhta hai** (Net = Total)

### Dono safhe asal controller se render kiye

Blade compile hona kaafi nahi hota (`d746abe` ka sabaq) — dono action ko asal data ke
saath chala kar HTML nikala gaya. Headers aur footer dono theek:

```
riders   : Rider | Phone | Branch | Deliveries | Returned | Total Amount | Returns | Net Amount | Avg / Delivery
channels : Channel | Type | Orders | Returned | Gross Amount | Returns | Net of Returns | Commission % | Commission | Net After Commission
```

### Live data par (sirf SELECT, prod par kuchh nahi badla)

**Khatri — 2 September**, wohi din jis par shikayat aayi:

| Rider | Deliv | Returned | Total | Returns | Net |
|---|---:|---:|---:|---:|---:|
| hassan | 10 | 2 | 29,060.00 | 3,200.00 | 25,860.00 |
| madah | 9 | 0 | 15,870.00 | 0.00 | 15,870.00 |
| waseem | 8 | 1 | 10,290.00 | 1,270.00 | 9,020.00 |
| Zayan | 1 | 0 | 6,500.00 | 0.00 | 6,500.00 |
| **TOTAL** | **28** | **3** | **61,720.00** | **4,470.00** | **57,250.00** |

Yehi wo safha hai jo malik ne dekha tha. **Net column 57,250 nikalta hai — bilkul wohi
hindsa jo purane code me dikhta tha.** Yani safha ab khud apna farq samjhata hai: 28 gaye,
3 wapas aaye, jaib me 57,250 rahe.

Kashif Food 1 aur 2 September par koi delivery return nahi — un dinon Returns 0.00 aur
**Net = Total**, safha jyun ka tyun.

---

## 6. Jo jaan boojh kar nahi kiya

- **Commission ko net par nahi kiya.** Aggregator settlement ka mamla hai, report ka nahi.
- **`sales_returns` ke `refund_amount` ki bajaye `grand_total` liya** — wohi jo `SalesReportEngine`
  aur baqi tamam report leti hain. Do jagah do column lena hi wo bimari hai jo pehle paida hui thi.
- **Purani "Total Amount" ki qeemat nahi badli.** Sirf naye column laga kar bataya gaya ke us
  me kya shamil hai. Jo hindsa malik pehle se parh raha tha, wo apni jagah qaayam hai.
