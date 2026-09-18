# MANAGER-APPROVAL-COMBO-VOID-1 — "Manager approval does not authorize this action"

**Tareekh:** 2026-09-19
**Halat:** RESEARCH + PLAN — **koi code nahi badla, prod par kuch nahi chhua**
**Tenant:** `kashiffood` (Kashif Food, branch 1) — LIVE
**Shikayat:** cashier deal ki quantity kam kare ya remove kare, manager PIN daale — aur error:
*"Manager approval does not authorize this action."* Bill aage nahi barhta.

---

## 1. Asal sabab — ek jumle me

**Client aur server do alag sawaalon par faisla karte hain, aur ek soorat me un ka jawab alag
ho jata hai.**

| | faisla kis par | kahan |
|---|---|---|
| **POS (browser)** | *"ye combo hai ya sadi line?"* — combo ho to **hamesha** `void_kot_items` (jama) | [`pos/index.blade.php:3276`](../../resources/views/tenant/pos/index.blade.php#L3276) |
| **Server** | *"kitni lines cancel hueen?"* — **1** ho to `void_kot_item` (**wahid**) | [`KotCancellationService.php:159`](../../app/Services/Sales/KotCancellationService.php#L159) |

To **jis combo me sirf EK line cancel hoti hai**: browser **jama** wala approval banwata hai,
server **wahid** maangta hai. `consume()` par pehli shart hi toot jati hai:

```php
if ($approval->action_type !== $actionType || …) {
    throw new RuntimeException('Manager approval does not authorize this action.');
}
```

⚠️ Agar `action_type` theek bhi kar diya jaye to **payload ki shakl bhi alag hai** —
wahid `{sales_order_id, sales_order_line_id, quantity}`, jama `{sales_order_id, cancellations:[…]}`.
Is liye sirf naam badalna kaafi nahi; hal dono ka khayal rakhe.

---

## 2. Prod ka saboot — ye andaza nahi

Kashif Food ke `manager_approvals` (read-only census, 19 Sep):

### Har action type ka consume rate

| action_type | kul | consumed | |
|---|---|---|---|
| `void_kot_item` (wahid) | 406 | 352 | 87% |
| `cancel_held_order` | 68 | 68 | **100%** |
| `sales_return` | 63 | 63 | **100%** |
| **`void_kot_items` (jama)** | **46** | **14** | **30%** ❌ |

### Aur jama walon ko payload ki lines par kaat kar dekhein

| payload me lines | kul | consumed | |
|---|---|---|---|
| **1** | **29** | **0** | **0%** ❌ |
| 2 | 4 | 4 | 100% ✅ |
| 3 | 10 | 7 | 70% ✅ |
| 4 | 2 | 2 | 100% ✅ |
| 5 | 1 | 1 | 100% ✅ |

**29 me se 29 nakaam. Sifar istisna.** Ek se zyada lines wale sab chale.
(3-line walon me se 3 jo nahi chale, wo manager ke dialog band kar dene wali surat hai —
approval ban kar istemal na hona bilkul mukhtalif cheez hai.)

Kal raat ka silsila (18 Sep): `#572` 20:14 → `#573` 20:15 → `#574` 20:16 → `#576` 20:18 →
`#579` 20:30 → `#580` 20:30 → `#581` 20:31 → `#582` 20:32 — **aath baar** manager ne PIN daala,
aath baar server ne rad kiya. Beech me `#578 cancel_held_order` consumed=YES — yani aakhir
cashier ne poora order hi cancel kar diya. **Yehi wo "order close kardiya" hai.**

---

## 3. 🚨 Ye pichle kaam se NAHI toota

`void_kot_items` **3 August** ko aaya tha (`bb98b28`, "Stabilize POS table KOT integrity and
cancellation controls") — dono taraf ka ikhtilaf **paidaishi** hai.

Kashif Food par pehla approval **30 August** ka hai, yani jis din us branch par line-cancellation
ke liye manager PIN on hua. Us din se aaj tak **teen hafte** ye khaamoshi se tootti rahi.

Pichle hafte ka koi kaam (STEAK-SIDE-MODIFIER-1, BILL-PREVIEW-*, DASHBOARD-8DAY-1,
KF-PULAO-SUBCAT-1, ORDER-TYPE-PERCENT-1) is file ko chhoota tak nahi. **Is ka ilzam naye kaam
par daalna ghalat hoga.**

---

## 4. Blast radius — kaun tootta hai, kaun mehfooz hai

`requestComboQuantity()` un components ki fehrist banata hai jin ki kitchen-bheji quantity nayi
quantity se zyada hai. Ye fehrist **ek** tab banti hai jab:

**(a) Combo ka component hi ek ho.** Kashif Food par aise **12 deals** hain (75 me se) —
**ye hamesha tootenge:**

```
2 Pcs Crispy Fried Chicken (Midnight)        Chicken Malai Tikka (Chest) (Midnight)
2 Pcs Crispy Fried Chicken (Spicy) (Midnight) Chicken Zinger Burger (Midnight)
5 Pcs Crispy Fried Chicken + Fries            Family Deal 1 - 9 Pcs Crispy Fried Chicken
Bar-B-Que Sandwich (Midnight)                 Singaporean Rice (Regular) (Midnight)
Beef Burger (Midnight)                        Thrill Zinger Burger (Spicy) (Midnight)
Chicken Club Sandwich (Midnight)              Chicken Crunch Burger (Midnight)
```

> **`Singaporean Rice (Regular) (Midnight)` — bilkul wohi cheez jo maalik ke screenshot ke cart
> me hai.** Ye ittefaq nahi.

**(b)** Kai components wale deal me se **sirf ek** kitchen ja chuka ho (adhoora KOT).
**(c)** Quantity itni kam ki jaye ke sirf ek component apni bheji hui hadd se neeche aaye.

### Har raasta — alag alag

| raasta | action_type | tootta hai? | kyun |
|---|---|---|---|
| **Sadi line** ki quantity kam / remove | `void_kot_item` | ✅ theek | dono taraf wahid |
| **Combo** ki quantity kam / remove — **1 line bane** | `void_kot_items` | ❌ **TOOTTA HAI** | client jama, server wahid |
| **Combo** — 2+ lines banen | `void_kot_items` | ✅ theek | dono taraf jama |
| **Poora order cancel** | `cancel_held_order` | ✅ theek | koi wahid/jama taqseem nahi |
| **Return — ek item** | `sales_return` | ✅ theek | ↓ |
| **Return — poora / bulk** | `sales_return` | ✅ theek | approval **`refund_amount`** se bandha hai, per-line nahi — is liye ek item ho ya bees, payload ki shakl ek hi rehti hai. **Yahi wo design hai jo KOT wale raaste par hona chahiye tha.** |
| **Manual discount** | `manual_discount` | (Kashif par istemal hi nahi — 0 rows) | |

**Item "remove" bhi isi me aata hai:** remove `requestItemQuantity(i, 0)` par jata hai
([:3392](../../resources/views/tenant/pos/index.blade.php#L3392),
[:3444](../../resources/views/tenant/pos/index.blade.php#L3444)), aur combo_header ho to wohi
`requestComboQuantity()`. Yani **"ek component wala deal remove karna" bhi hamesha tootta hai.**

### Reports par asar

**Koi nahi.** Nakaam approval ka matlab hai cancellation **hui hi nahi** — na `sales_order_lines`
badli, na `sales_order_line_cancellations` me row bani, na KOT gaya, na koi journal. Report wohi
dikhati hai jo waqai hua. **Paisa kahin ghalat nahi gaya; kaam bas ruk gaya.**

Nuqsan amli hai, hisaabi nahi: cashier phans gaya, aur (kal ki tarah) majboor ho kar poora order
cancel karna para.

---

## 5. Ek alag, chhota nuqs jo isi tehqeeq me nikla

[`AuditReportController:30`](../../app/Http/Controllers/Tenant/Reports/AuditReportController.php#L30)
ka filter dropdown ye chaar naam deta hai:

```php
$actionTypes = ['manual_discount', 'void_item', 'refund', 'override'];
```

Magar system me banne wale action types ye hain: `void_kot_item`, `void_kot_items`,
`cancel_held_order`, `sales_return`, `manual_discount`.

Yani `void_item` / `refund` / `override` **kabhi banti hi nahi**, aur jo chaar waqai banti hain
un me se teen dropdown me hain hi nahi. **Dropdown se koi bhi option chunein — report khali
aayegi**, jabke Kashif Food par 583 approvals maujood hain.

Achhi baat: fehrist khud **bila filter theek chalti hai** (filter sirf tab lagta hai jab chuna
jaye). Is liye ye P2 hai, P1 nahi — magar manager-approval ka audit filter is waqt be-kaar hai.

---

## 6. Hal — teen soortein, aur meri sifarish

### ❌ Option A — client theek karo (combo me 1 line ho to wahid maango)

Dekhne me sab se chhota, magar **bunyadi tor par ghalat** hai:

1. **Browser server ki ginti ka andaza laga hi nahi sakta.** Server `$resolved` ko sale ki asli
   `kot_sent_quantity` se, **lock ke andar**, dobara ginta hai. Doosre counter par ek second
   pehle kuch punch ho jaye, ya browser ka data purana ho — aur browser ki ginti 2 ho jabke
   server ki 1. Bug lauta aayega, bas kam baar.
2. **Har counter par Ctrl+F5 lazmi** — aur abhi cashiers ke browsers me purana page khula hai.

### ❌ Option B — server hamesha jama wala contract use kare

Taqseem khatam ho jati hai, magar phir **client ko bhi badalna parega** (sadi line ka raasta
abhi wahid bhejta hai — 406 rows). Dono taraf badalne ka matlab phir wohi: **stale browser
tootega.**

### ✅ Option C — **server approval ki APNI shakl dekh kar tasdeeq kare** ← **SIFARISH**

`consume` se pehle server dekhe ke jo approval aaya hai wo **khud kis shakl ka hai**, aur usi
shakl ka payload verify kare:

- approval `void_kot_item` ka hai → wahid payload se tasdeeq
  (`sales_order_id` + `sales_order_line_id` + `quantity`)
- approval `void_kot_items` ka hai → jama payload se tasdeeq
  (`sales_order_id` + `cancellations[{line_id, quantity}]`, chahe us me ek hi line ho)

**Kyun yehi behtar hai:**

| | |
|---|---|
| **Stale browser bhi foran theek** | Sirf server badalta hai — kisi counter par Ctrl+F5 ki zaroorat nahi. Ye faisla-kun hai: abhi cashiers kaam ke darmiyan hain. |
| **Faisla ek hi jagah** | Ab ye is par nahi ke client aur server ek hi ginti par pahunchein — jo wo kabhi yaqeeni tor par nahi kar sakte. |
| **Bandish bilkul kamzor nahi hoti** | Dono shaklein wohi cheez pin karti hain: order + theek wohi line(s) + theek wohi quantity. Aur `consume()` ki baaqi chaar zamanatein — **single-use, 10 minute, wohi cashier, payload ka pura milna** — jyun ki tyun rehti hain. |
| **Diff chhota** | Ek method, `KotCancellationService` me. Na koi migration, na naya route, na nayi permission, na koi blade. |

**Jo NAHI badlega:** return ka raasta, `cancel_held_order`, `manual_discount`, KOT ka content,
printing, reports, aur mojooda 406 wahid approvals ka bartaao.

---

## 7. Guards (jo likhne hain)

1. **Ek component wala combo** — asli HTTP par: approval `void_kot_items` (1 line ka payload) se
   banaya jaye aur cancellation **kaamyab** ho. Ye **aaj RED hai** — yehi asal guard hai.
2. **Sadi line** `void_kot_item` se ab bhi chale (406 rows ka raasta na tootay).
3. **Kai lines wala combo** `void_kot_items` se ab bhi chale.
4. 🚨 **Bandish qaayam** — sabotage: approval ki quantity badal do → **rad ho** (RED). Doosre
   cashier ka approval → rad. 10 minute purana → rad. Dobara istemal → rad.
   **Ye chaaron guard hone chahiyen, warna "hal" dar-asal darwaza khol dega.**
5. Ek order ke approval se doosre order ki line cancel na ho sake.

---

## 8. Abhi is waqt ka raasta (maalik ka faisla)

Agar cashier ko **abhi** chalna hai, Kashif Food ki branch setting
`held_kot_line_cancellation_approval_mode` ko `auto_approve` karne se ye poora raasta bypass ho
jata hai (abhi `manager_required` hai).

⚠️ **Magar us ka matlab hai: line cancel par manager ki manzoori bilkul khatam** — sadi line par
bhi, jahan abhi theek chal rahi hai (352 kaamyab manzooriyan). Ye policy ka faisla hai, aur mera
mashwara **nahi** hai: hal khud chhota hai, aur usi din lag sakta hai.

---

## 9. Risk — 4 chalti hui businesses

| khatra | haqeeqat |
|---|---|
| paisa | ye raasta koi journal nahi likhta; cancellation stock/GL `KotCancellationService` ke apne raaste se jati hai jo **bilkul nahi badal raha**. `tb_diff` deploy se pehle aur baad me check hoga. |
| doosre tenants | Khatri / Tawakal / Kashif Kitchen par yehi code hai magar un ki branch setting alag ho sakti hai — un par bartaao **behtar hi hoga, badtar nahi** (jo abhi rad hota hai wo qubool hone lagega, jo abhi qubool hota hai wo qubool hi rahega). |
| manzoori kamzor hona | sab se asal khatra — isi liye §7 ke guard 4 me chaaron zamanat par sabotage test lazmi hai. |
| purani 29 nakaam approvals | un ko chhera nahi jayega. Wo `consumed_at = NULL` hi rahengi — sacha record hai ke manzoori mili magar amal nahi hua. |
