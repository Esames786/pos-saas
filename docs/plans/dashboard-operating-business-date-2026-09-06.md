# Dashboard ka "Aaj" — khuli shift ka business date

**Tareekh:** 2026-09-06 · **Tenant:** Kashif Food (#348) — masla har tenant par hai
**Halat:** research mukammal, code likhna baqi

## Kya hua

Raat 12:16 baje (Karachi) owner ne dashboard khola. Saari tiles **0.00**:

```
Net Sales Today  0.00      Orders Today  0      Cash Today  0.00
Open Shifts      4                              Card/Bank   0.00
```

Lekin usi safhe ki neeche wali table sach bata rahi thi:

```
Sat, 05 Sep    455 orders    866,730.00
Sun, 06 Sep      —               —
```

Yani kaam ho raha hai, char shiftein khuli hain, magar tiles khali hain.

## Wajah

Ye bug nahi, do alag tareekhon ka takra hai.

- POS har bill par shift ka **frozen `business_date`** likhta hai. 5 September ko
  khuli shiftein abhi band nahi huin, is liye raat 12:16 par punch hone wala bill
  bhi `business_date = 2026-09-05` hi hai. Ye bilkul theek hai — raat ka kaam usi
  din ka hai jis din shift khuli.
- Dashboard "aaj" ke liye `TenantClock::currentBusinessDate()` poochta hai, jo
  sirf **ghadi** dekhta hai: Karachi me 6 September ho chuka hai, to wo
  `2026-09-06` deta hai.

Ghadi aage barh gayi, kaam peeche hi hai. Tiles us din ko dhoondti hain jis par
abhi tak ek bhi bill nahi.

Yehi cheez **har raat 12 baje** hoti hai aur subah shift band hone tak rehti hai.

### Header bhi ghalat hai (alag keera)

`dashboard.blade.php:13` par `now()->format('l, d M Y')` — ye **sarwar ka UTC**
hai, kisi ka business timezone nahi. Screenshot me isi liye "Saturday, 05 Sep"
likha tha jabke Karachi me 6 September ho chuka tha. Ittefaq se ye sahi din dikha
raha tha, lekin sirf itni der ke liye jitna Karachi UTC se aage hai.

## Faisla (owner ka diya hua usool)

> Jis khuli shift ka business date sab se naya hai, dashboard wohi din dikhaye.
> Koi shift khuli na ho to us branch ke timezone ka aaj.

Ye system ke apne usool se mel khata hai: bill apni tareekh **shift** se leta hai
(`businessDateForSale`), ghadi se nahi. Dashboard ab wohi karega.

`MAX(business_date)` lena zaroori hai, `MIN` ya "pehli" nahi — Kashif par shift
#24 teen din tak khuli reh gayi thi. `MAX` aisi bhooli hui shift ko dashboard ko
peeche kheenchne nahi deta.

## Kahan badlega (sirf dashboard, aur kahin nahi)

`TenantClock` me **do naye** method — purane chhue bagair, kyunke unhein bill
stamp karne aur shift kholne wala raasta bhi parhta hai:

| naya | kya karta hai |
|---|---|
| `operatingBusinessDate(?Branch)` | khuli shifton ka `MAX(business_date)`, warna `currentBusinessDate()` |
| `operatingBusinessDatesByBranch()` | wohi, har active branch ke liye |

Phir sirf ye **paanch** jagah naya method poochengi — teeno dashboard ke apne hain:

1. `DashboardController` — `$applyToday` (cash/card tiles + khuli bills)
2. `DashboardController:178` — `$todayBusinessDate` (7-din ki window ka anchor)
3. `SalesReportService::currentBusinessDay()` — `private`, iska **ek hi** caller
   `todayStats()` hai, aur `todayStats()` ka **ek hi** caller dashboard hai
4. `SalesReportService::todayStats()` — returns wala hissa (do jagah)
5. `dashboard.blade.php:13` — header ab `$todayBusinessDate` dikhaye, `now()` nahi

## Kya NAHI badlega

- `businessDateForSale()` / `businessDateForOpening()` — bill aur shift ki apni
  tareekh. Inhein chhedna poore system ka hisab hila deta.
- `currentBusinessDate()` khud — Daily Closing, reports aur Shifts ka date filter
  isi ko parhte hain. Nayi soorat sirf dashboard maangi gayi hai.
- Koi migration, koi data. Sirf parhne ka tareeqa badal raha hai.

## Khula sawal (owner se)

Shifts ki list ka **Today** button abhi bhi `currentBusinessDate()` par hai. Raat
12 baje wo `06 Sep` kholega aur list khali aayegi, jabke kaam `05 Sep` par ho raha
hai — bilkul wahi shikayat. Isay bhi operating date par le aaon?

## Kaise sabit hoga

Guard: ek branch, ek khuli shift jiska `business_date` **kal** ka ho, aur us din
ka ek paid bill. Dashboard ko wo bill dikhna chahiye. Shift band karke dobara —
ab fallback chalna chahiye aur tile 0 par aani chahiye.
