# Teen khatray, aur un ka hal — CATERING-THREE-RISKS-1

**Tenant:** kashifkitchen (live) · **Date:** 2026-09-10
**Owner ka kehna:** *"the things you said i need to worry three things you
mentioned please provide a solution for it"*

Yeh teen cheezen kaam ke doran samne aayin. Ek zinda ghalti hai, ek hisaab ka
dhoka hai, aur ek gandagi hai jo aaj hi saaf honi chahiye.

---

## Khatra 1 — Cancelled booking jo paisa daba kar baithi hai

### Kya hota hai

Cancel **kisi paisay ko haath nahi lagata** (`CateringFinanceMySqlTest` is ko
sabit karta hai). Bill 0 ho jata hai, to jo advance bill chuka raha tha wo
**customer ka credit** ban jata hai — yaani ek liability jo hum us ke wapas
karne ke paband hain.

Ab masla yeh hai: **koi us ke peeche nahi parta.**

`close()` credit par saaf rukta hai —

> *"still owes the customer X — refund the credit before closing"*

— magar **cancelled booking kabhi close tak pahunchti hi nahi.** Wo darwaza is
raaste par hai hi nahi. To 2300 me paisa para rehta hai, ledger sach bolta hai,
aur screen par koi nahi batata.

### Kitna bara hai

Ginti (prod, 2026-09-10, read-only):

```
bookings owing money back : 0
total owed                : 0.00
```

**Abhi nuqsan sifar hai** — koi cancelled booking paisa daba kar nahi baithi.
Magar yeh wo cheez hai jo waqt ke saath barhti hai aur jab pakri jati hai to
mahine purani hoti hai. Isi liye ise **abhi** banana theek hai, jab list khali
hai — baad me nahi, jab list me kisi ka paisa para ho.

### Hal — "Money owed to customers" list

Ek screen: **har wo booking jis ka `customer_credit > 0`**, chahe kisi bhi
status me ho.

| Column | Kyun |
|---|---|
| Booking no + customer | pehchaan |
| Status | cancelled / completed / jo bhi |
| Credit | kitna wapas karna hai |
| **Umar** | kitne din se para hai — **yehi asli column hai** |
| Action | seedha Refund (mojooda darwaza, koi naya nahi) |

Dashboard par ek chhota card bhi: *"3 bookings · 47,500 wapas karna hai"*.

**Data pehle se mojood hai** — `position()['customer_credit']`. Koi migration
nahi, koi naya khata nahi. Sirf ek sawal jo aaj koi nahi poochta.

> ⚠️ Har event par `position()` chalana N+1 hai. Query aise likhi jaye ke
> `advances − refunds > billed` pehle SQL me chhante, phir sirf un chand par
> `position()` chale.

**Owner ka faisla: HAAN, banao.** (2026-09-09)

---

## Khatra 2 — COGS aur revenue alag mahine me

### Kya hota hai

Do alag lamhe, do alag dastawez:

| Kaam | Kab post hota hai | Kya |
|---|---|---|
| **Material issue** | jab kitchen ko maal nikalta hai | `postCateringCogs` — **kharch** |
| **Final invoice** | jab bill banta hai | `Dr 1300 / Cr 4160` — **bikri** |

Shadi 28 tareekh ko hai. Maal **27** ko nikla. Bill **2** tareekh ko bana.

→ **Kharch September me, bikri October me.**

September ka P&L bila wajah kamzor, October bila wajah acha. Dono mahine jhoot
bolte hain, aur saal ke aakhir par barabar ho jate hain — jo tab tak bemani hai.

### Yeh nuqs NAHI hai

Dono posting apni jagah bilkul theek hain. COGS tab lagta hai jab maal
sachmuch nikla; revenue tab jab bill sachmuch bana. **Koi ghalat entry nahi
hai.** Masla sirf yeh hai ke do sahi tareekhen mahine ki lakeer ke do taraf gir
sakti hain.

### Hal — teen darjay, owner chune

**(a) Sirf batao — sab se sasta, aur shayad kaafi**

Month-end par ek chhoti report: *"in bookings ka maal is mahine nikla, bill agle
mahine banega"* — raqam ke saath. Koi code ka bara badlaav nahi, koi posting
nahi badalti. Owner ko farq **maloom** ho jata hai, aur accountant khud faisla
kar leta hai.

**(b) Bill ki tareekh event ki tareekh ho**

`issue()` par `entry_date` = event ki tareekh, na ke aaj ki. Do din ki der se
mahina nahi badalta.
⚠️ Magar 28 September ki shadi ka bill 2 October ko banaya jaye to entry
September me girti hai — **band mahine me posting**. Yeh apna masla hai.

**(c) WIP — asli accounting jawab**

Material issue → `Dr 1420 WIP / Cr 1410 Raw Material` (kharch abhi nahi).
Invoice → `Dr 5xxx COGS / Cr 1420 WIP` + revenue.
Dono ek hi lamhe par milte hain. **Bilkul sahi**, aur sab se bara kaam — yeh
wahi shakal hai jo manufacturing me `manufacturing_posting_settings` ke peeche
hai.

### Ginti (prod, 2026-09-10, read-only)

Maine apni hi baat maani — andaza nahi, ginti:

```
material issues                       : 0
final invoices                        : 1
billed bookings with materials issued : 0
of those, straddling a month end      : 0
```

**Aaj tak ek dafa bhi nahi hua.** Kashif Kitchen par abhi tak kisi booking ka
maal issue hi nahi hua — production ka raasta live par chala hi nahi.

### Meri raaye

**Abhi kuch mat banao.** Yeh khatra asli hai, magar iski ginti sifar hai, aur
jab tak production chalna shuru nahi hota hum ye jaan hi nahi sakte ke saal me
do dafa hoga ya bees dafa.

Jab pehli 10-15 bookings ka maal nikal jaye, **wahi ginti dobara chalao**
(script mojood hai). Us waqt:

- 0-2 dafa saal me → **(a)** month-end par ek ittila, bas
- baar baar → **(c)** WIP, asli accounting jawab

Pehle se (c) banana ek aisi cheez par hafta lagana hai jo ho sakta hai kabhi na
ho. Aur (b) — bill ki tareekh badalna — band mahine me posting ka apna masla
kholta hai, is liye wo sab se aakhri chara hai.

**Owner ka faisla filhaal darkar NAHI — ginti ke baad darkar hoga.**

---

## Khatra 3 — Live books par test entry

### Kya hai

`kashifkitchen` par receipt **#3, 5,000.00**, booking EV-20260908-0001 par —
owner ne screen aazmate hue daala tha. Journal entry ban chuki hai
(`journals` 3 se 4 hui), cash/bank balance barh chuka hai.

**Yeh asli booking par asli entry hai.** Ledger ke lehaz se yeh test nahi hai —
paisa aaya, likha gaya, khaata hila.

### Hal — refund, delete nahi

Ab jab manfi raqam kaam karti hai:

1. Booking screen par **Refund** — 5,000
2. Wajah: *"Test entry — no money was actually received"*
3. Swal ("credit se aage ja rahe hain") — haan
4. Posting: `Dr 2300 5,000 / Cr Cash 5,000`

Nateeja: `balance_due` wapas 447,920. Cash/bank wapas apni jagah.

### Kyun delete nahi

Kyunki delete ka koi raasta hai hi nahi, aur yeh **jaan bujh kar** hai. Do
entriyan jo dono hui hain — wo sach hai. Ek entry jo khamoshi se mita di gayi —
wo sach nahi, aur us ke baad audit ke liye kuch bacha nahi.

To books par yeh dikhega: 5,000 aaya, 5,000 wapas gaya, wajah likhi hui hai.
**Yehi theek hai.** Koi bhi is ko dekh kar samajh jayega ke kya hua.

> Owner khud kar sakte hain (2 minute), ya kahen to mai kar don. **Meri
> tajweez: owner khud karen** — kyunki yeh naye refund raaste ka pehla asli
> istemal hai, aur is se behtar tareeqa nahi ke us ko chala kar dekh liya jaye.

---

## Tarteeb

| # | Kaam | Kitna | Kab |
|---|---|---|---|
| 1 | Test entry ka refund | 2 minute | **aaj** — owner khud |
| 2 | Money-owed list + dashboard card | ~aadha din | status roll-back ke baad |
| 3 | COGS/revenue — **kuch nahi**, ginti sifar hai | — | pehli 10-15 production bookings ke baad ginti dobara |

Pehla aaj ho sakta hai (owner khud). Doosra pehle se manzoor hai. Teesra abhi
filhaal **sirf likha hua khatra** hai — prod par ek dafa bhi nahi hua, is liye
abhi banane ka matlab us cheez par kaam karna hai jo shayad kabhi na ho. Ginti
ka script mojood hai; production chalne ke baad dobara chalao.
