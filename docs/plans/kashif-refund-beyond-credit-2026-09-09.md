# Refund beyond credit — CATERING-REFUND-BEYOND-CREDIT-1

**Tenant:** kashifkitchen (live) · **Date:** 2026-09-09
**Asked for by the owner:** *"yes allow negative entry also"* — after being told
that a minus can currently only hand back money taken *above* the bill.

---

## 1. Aaj kya hota hai

Ek refund sirf **credit** wapas kar sakta hai — yaani wo paisa jo bill se **zyada**
liya gaya ho.

```
refundable = customer_credit = max(net_received − billed, 0)
```

`CateringRefund` ke model guard me yeh cap lagta hai, is liye har raasta usi
se guzarta hai — controller, console, import, sab.

Booking EV-20260908-0001 par:

| | |
|---|---|
| billed (quotation se) | 447,920.00 |
| received | 5,000.00 |
| customer_credit | **0.00** |
| refundable | **0.00** |

To 5,000 wapas nahi ho sakta. Wajah code me likhi hui hai aur theek hai:

> *"refunding out of an unpaid booking would just recreate the balance due, on
> the customer's money."*

Aur wo sach hai — 5,000 wapas karne se balance due 442,920 se wapas 447,920 ho
jayega. Lekin **wo hona bhi chahiye**, agar paisa waqai wapas kiya gaya hai.
Ledger ka kaam yeh batana hai ke kya hua, na ke us se bachna.

Aaj ka **wahid raasta**: booking **cancel** karo → `billed()` 0 return karta hai
→ poora paisa credit ban jata hai → Refund button aa jata hai. Owner ne sardi
se kaha: **"without cancelling the order"**.

---

## 2. Naya asool

Do chhaten hongi, ek narm aur ek pathar ki.

| Chhat | Kitni | Kaun paar kar sakta hai |
|---|---|---|
| **Narm** — `refundable` (credit) | `max(received − billed, 0)` | koi bhi, jise refund ka haq hai |
| **Pathar** — `refund_ceiling` | `net_received` | **koi nahi** |

Yaani: jo paisa mila hi nahi, wo kabhi wapas nahi ho sakta. Yeh cap kabhi narm
nahi hoga. Narm chhat paar karne ke liye **permission + wajah** chahiye.

---

## 3. Ledger — asli mushkil yahan hai

Aaj har refund seedha `Dr 2300 / Cr cash` karta hai, aur code me likha hai
*"Always 2300, because credit can only ever be sitting there."* Naye asool ke
baad **yeh jhoot ho jata hai**.

Sochiye: 5,000 advance liya (Cr 2300), phir invoice issue hui — invoice
`advance_applied` ko `Dr 2300 / Cr 1300` karti hai, to **2300 khali ho jata
hai**. Ab agar 5,000 refund karen aur phir bhi 2300 ko debit karen, to 2300
ka balance **manfi** ho jayega — ek liability account par debit balance, jo
saaf ghalat hai. Aur 1300 kam reh jayega: customer par 447,920 wapas chadhna
chahiye tha, magar AR 442,920 hi dikhayega.

### Sahi taqseem

Refund ko wahi shakal chahiye jo `postCateringSplitReceipt` ki hai, ulti taraf:

```
held_as_advance = has_invoice ? customer_credit : net_received

from_2300 = min(refund, held_as_advance)      // jo paisa kisi bill par laga hi nahi
from_1300 = refund − from_2300                // jo bill chuka raha tha → qarz wapas
```

```
Dr  2300 Customer Advances      from_2300      (0 ho to line hi na ho)
Dr  1300 Accounts Receivable    from_1300      (0 ho to line hi na ho)
    Cr  cash / bank             poora refund
```

**Kyun `has_invoice` par?** Kyunki GL me advance sirf **invoice ke waqt** apply
hota hai. Invoice se pehle, chahe `position()` kuch bhi kahe, poora paisa 2300
me para hai. Is liye:

- **Invoice se pehle** → `held_as_advance = net_received` → poora refund 2300 se
  → 1300 ko chhua tak nahi jata (aur us booking ka 1300 hai bhi nahi). ✔
- **Invoice ke baad** → 2300 me sirf overpay wala credit bacha hai, aur wo
  theek `customer_credit` ke barabar hai. ✔

> **Tasdeeq (invoice ke baad 2300 == customer_credit):** advance 150,000, bill
> 100,000 → invoice `advance_applied = 100,000` → 2300 me 50,000 bacha;
> `customer_credit = 50,000`. ✔ Phir 20,000 aur mila → `balance_due` 0 hai to
> split receipt poora 20,000 **2300** par daalta hai → 2300 = 70,000;
> `customer_credit = 70,000`. ✔

### Event 1 par kya hoga

Invoice nahi hai. `held_as_advance = 5,000`. To:

```
Dr  2300 Customer Advances   5,000
    Cr  Cash                 5,000
```

2300 saaf 0 par aa jata hai, 1300 bilkul nahi chhua jata, aur `balance_due`
khud-ba-khud 447,920 ho jata hai kyunki `position()` refunds ko `received` se
ghata deta hai. **Koi naya khata nahi banana parta.**

---

## 4. Ijazat

Naya synthetic permission: **`tenant.catering.refunds.beyond-credit`**

Alag rakha ja raha hai, `advances.overpay` me mila kar nahi — wo *liability
banane* ka haq hai, yeh *bill chukane wala paisa wapas karne* ka. Code me pehle
se yehi asool likha hai: *"Money OUT is its own grant. Whoever may take a
payment does not automatically get to hand one back."*

Aur — 09-09 ko jo sabaq mila — routeless permission ko **teen jagah** wire karna
parta hai, warna deploy ke baad Owner tak use nahi kar sakta:

1. migration row banaye
2. **migration hi Owner ko de** (`deploy.sh` `route_catalogs` se chalta hai, is
   liye routeless naam use kabhi nazar nahi aata)
3. `TenantProvisioner::$tenantPermissions` me naam daale (naye tenant ke roles
   migrations ke **baad** bante hain)
4. `PermissionCatalogService`: `'beyond-credit'` → `SENSITIVE_ACTIONS`, aur
   feature ka naam **"Refund Beyond Credit"** — warna Permission Center par
   kacha route group `Catering Refunds` dikhega (guard pakar leta hai)

---

## 5. Screen

Checkbox nahi. Wahi tareeqa jo owner ne receipt ke liye maanga: **arithmetic
khud faisla kare, aur jis lamhe sach ho us waqt poochhe.**

- Refund button ab `refundable > 0` ke bajaye **`net_received > 0`** par dikhe
- Refund box ka `max` = `refund_ceiling` (yaani `net_received`)
- Jab typed amount `refundable` se barh jaye → swal:
  > *"Is booking par sirf X credit hai. Baqi Y wo paisa hai jo bill chuka raha
  > hai — wapas karne se balance due dobara barh jayega. Kya aap yaqeen se…"*
- Permission na ho to swal se pehle saaf inkar, submit hi na ho
- Wajah pehle se lazmi hai — koi nayi field nahi

Event screen ke Advance box me **manfi raqam** ka wahi raasta rahega: minus →
refund service → yehi naye asool.

---

## 6. Kya nahi badlega

- **Pathar ki chhat**: `net_received` se zyada kabhi nahi.
- **Refund ek dastawez hai, edit nahi.** Purani receipt aur uska journal
  jyun ka tyun rehta hai; refund uske pehlu me apni tareekh, number aur likhne
  wale ke saath khara hota hai.
- **Booking row lock** refunds ke darmiyan — do operator ek saath cap paar na
  kar saken.
- **Cash/bank movement lazmi** — paisa kahan se nikla, naam liye baghair refund
  nahi.
- **Replay guard** — wahi refund dobara post nahi hoga.

---

## 7. Kaam ki tarteeb

| # | Qadam | Khatra |
|---|---|---|
| 1 | `position()` me `held_as_advance` + `refund_ceiling` | koi nahi — sirf naye keys |
| 2 | `postCateringSplitRefund()` — 2300/1300 taqseem | **paisa** — guard pehle likho, tor kar dekho |
| 3 | `CateringRefund` model guard: pathar ki chhat + `allowBeyondCredit` | har raasta yahin se guzarta hai |
| 4 | `CateringRefundService::record()` flag aage bhejay | |
| 5 | Permission: migration + Owner grant + provisioner + catalog | ⚠️ chaaron warna Owner tak use nahi kar sakta |
| 6 | Controller gate + minus ka raasta | crafted post flag udha na le |
| 7 | Refund modal + swal | |
| 8 | Guards, har ek tor kar sabit | |

**Jo cheez sab se pehle red honi chahiye:** ek refund jo credit se aage jaye aur
1300 ko wapas na chadhaye. Wo khamoshi se AR kam kar dega — aur khamoshi se
ghalat hisaab hi wo cheez hai jis se yeh poora document bachne ke liye likha
gaya hai.
