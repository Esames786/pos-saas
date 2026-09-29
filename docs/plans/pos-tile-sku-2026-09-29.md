# PRODUCT-TILE-SKU-1 — POS tile par SKU sirf tab jab wo kuch kehti ho

**Tareekh:** 2026-09-29
**Halat:** PLAN — code draft ho chuka hai magar **commit nahi**, deploy to bilkul nahi
**Screen:** POS product grid (`resources/views/tenant/pos/index.blade.php`)

---

## 1. Maalik ka khayal, aur jaanch ka nateeja

> *"in all the tenants i feel 100% of products name and sku name the same so you can hide
> sku name in pos screen please verify?"*

Jaanch li. **Khayal us tenant par bilkul sach hai jo maalik dekh rahe the — poore system par nahi.**

Naam aur SKU ko sirf harf-o-adad par laa kar milaya (kyunke `Biryani (1/2 kg)` ka SKU
`BIRYANI-12-KG` banta hai; seedha milaan ghalat farq dikhata):

| tenant | products | naam == SKU |
|---|---|---|
| **khatribiryani** | 66 | **66 — 100%** |
| **kashiffood** | 212 | 205 → ab **212** (§2) |
| **tawakalkashif** | 112 | **0** |
| **kashifkitchen** | 918 | **0** |
| demos (7) | 249 | 1 |
| **kul** | **1,557** | **272 — 17%** |

Tawakal ke SKU `KF-001`, `KF-002`… hain. Kashif Kitchen ke `RM-CHICKEN`, `RM-BEEF`,
`RM-MUTTON`. `retaildemo` ke to **barcode** hain (`890100000001`).

> **Is liye global hide nahi ho sakta** — wo do zinda tenants ka asli maal chhupa deta,
> aur aik demo par scan hone wala barcode.

---

## 2. Jo pehle hi theek kar diya (prod par, 29 Sep)

Jaanch ne Kashif Food par 7 aisi satarein nikaali jahan **naam badla gaya magar SKU
peechay reh gaya**. Ye asli code nahi the, drift thi — aik me spelling ki ghalti bhi:

| id | SKU pehle | SKU ab |
|---|---|---|
| 196 | `GRILLED-CHICKEN-AL-FAHAM` | `GRILL-CHICKEN-AL-FAHAM-HALF` |
| 204 | `RICE` | `PLAIN-RICE` |
| 205 | `DHAKA-CHICKEN-COMPLIMENTORY` | `DHAKA-CHICKEN-COMPLIMENTARY` |
| 206 | `SINGAPOREAN-RICE-KHAAS-BBQ` | `RICE-OF-KHAAS` |
| 208 | `CRISPY-FRIED-CHICKEN-5-PCS` | `5-PCS-CRISPY-FRIED-CHICKEN` |
| 209 | `KF-GARLIC-RICE` | `GARLIC-RICE` |
| 210 | `KF-SHASHLIK-BBQ` | `SHASHLIK-BAR-B-QUE` |

**Naam ko haath nahi lagaya** — naam customer ke saamne wali sach hai, SKU andar ka
label. Badalne se pehle jaanch liya tha ke ye SKUs code me kahin bandhe hue nahi:
onboarding commands products ko SKU se dhoondte hain (`OnboardTawakalKashifCommand:499`,
`CateringBootstrapClientMenuCommand`) magar **Kashif Food ka command aisa nahi karta**,
aur repo me in saat ka koi zikr nahi. Sale ki purani lines `product_name` rakhti hain,
SKU nahi — to tareekh par koi asar nahi.

Ab kashiffood **212/212** par hai.

---

## 3. Teen raaste, aur mera mashwara

| | |
|---|---|
| **(a) Global hide** | §1 ne rad kar diya — 1,030 products ka asli code gum ho jata |
| **(b) Per-product checkbox** (maalik ka mashwara) | nayi column + migration; 1,557 products par tick; aur **drift** — aaj tick lagao, kal naam badal jaye to flag jhooti ho jati hai |
| **(c) Per-product khud-kar usool** ✅ | koi setting nahi, koi migration nahi, hamesha sach |

### Kyun (c), aur (b) kyun nahi

Checkbox **manual sach** hai, aur manual sach ko koi chalata rehna paRta hai. Maslan
`Plain Rice` ka SKU aaj chhupa dein; kal koi usay `Chawal` kar de — checkbox ab bhi
"chhupao" kehti rahegi, halanke ab SKU farq bata raha hai. Aur nayi column ka apna
bojh hai: memory ka usool — nayi product column har tenant par tab hi lagti hai jab us
tenant ka seeder dobara chale.

(c) me koi setting hi nahi:

> **SKU ka kaam farq batana hai. Jahan wo naam ki naql hai, wahan wo kuch nahi keh
> rahi — na dikhao. Jahan `KF-001` ya `RM-BEEF` ya barcode hai — dikhao.**

Ye heuristic nahi, theek wohi usool hai jis ke liye SKU maujood hai. Aur **khud ko
theek karta hai**: kal koi tenant apne SKU asli code me badal de, tile khud dikhane
lag jayegi. Kisi ko kuch yaad rakhna nahi paRta.

**Nateeja:**

| tenant | tile saaf | SKU barqarar |
|---|---|---|
| khatribiryani | 66 / 66 | — |
| kashiffood | 212 / 212 | — |
| tawakalkashif | — | 112 |
| kashifkitchen | — | 918 |

Agar kabhi kisi ko **zabardasti** sab chhupana ho (chahe SKU asli code ho), tab **aik
tenant-level switch** daal denge — 1,557 checkbox nahi, aik. Magar maang aane par.

---

## 4. Code — theek kya badalta hai

**Aik file, do jagah:** `resources/views/tenant/pos/index.blade.php`

```js
function skuSaysSomethingNew(product) {
    var flat = function (v) { return String(v || '').toUpperCase().replace(/[^A-Z0-9]/g, ''); };
    var sku = flat(product.sku);

    return sku === '' || sku !== flat(product.name);
}
```

aur tile me:

```js
(skuSaysSomethingNew(product)
    ? '<div class="text-muted small mb-2">' + escapeHtml(product.sku || 'No SKU') + '</div>'
    : '') +
```

- **Harf-o-adad par milaan** — warna `Biryani (1/2 kg)` vs `BIRYANI-12-KG` alag lagte hain
- **Khaali SKU par purana bartaao** — `No SKU` waise hi chhapta hai (abhi kisi product ka
  SKU khaali nahi, magar bartaao badalna be-wajah hota)

### Jo NAHI chhua ja raha
- **Search aur barcode scan** — `index.blade.php:2764` aur `1757` ab bhi `product.sku`
  parhte hain. **Chhupi hui SKU qabil-e-talash rahegi**, bas tile par likhi nahi hogi.
- Server, payload, koi DB column — kuch nahi. Ye sirf tile ka matn hai.

---

## 5. Risk

| khatra | haqeeqat |
|---|---|
| paisa / stock / KOT | koi nahi — sirf tile par aik satar |
| SKU se dhoondna band ho jaye | nahi, search ko chhua hi nahi (§4) |
| dusre tenants | Tawakal aur Kashif Kitchen par **aik tile bhi nahi badlegi** (un ka koi SKU naam ki naql nahi) |
| tile ki oonchai badal jaye | satar hatne se tile chhoti ho jayegi. Grid ka layout dekhna hoga — chhoti ho to behtar, magar dekh kar batana chahiye |

**Palatna:** commit revert. Koi migration nahi, koi data nahi.

---

## 6. Guards (jo likhne hain)

1. Jis product ka SKU naam ki naql hai — tile par SKU **na** aaye.
2. `KF-001` / `RM-BEEF` jaise asli code — **aayen**.
3. Barcode (`890100000001`) — **aaye**.
4. Punctuation ka farq gumrah na kare: `Biryani (1/2 kg)` + `BIRYANI-12-KG` = naql maani jaye.
5. Khaali SKU par `No SKU` purani tarah chhape.
6. **Search be-asar** — chhupi hui SKU se dhoondna ab bhi chale (yehi sab se ahem guard hai).

⚠️ Ye usool **JavaScript** me hai, aur harness JS chala nahi sakta. Jaisa
PRODUCT-FORM-ROLE-1 me hua, source par assert karne wale guards **hataye jane** ko
pakaRte hain, logic ki ghalti ko nahi — aur wahan mera pehla guard sabotage se bach
gaya tha. Is liye guards mechanism par pin honge, lafz par nahi, aur sabotage se
sabit kiye jayenge. Nuqta 4 ka milaan PHP me bhi aazmaya ja sakta hai (wohi normalise
jo §1 ki jaanch me chala), jo asli behavioural guard hoga.

---

## 7. Tarteeb

1. Ye MD maalik dekh lein (§3 ka faisla).
2. Guards + code, phir sabotage.
3. Grid ka layout aik nazar dekh lena (satar hatne ke baad).
4. Deploy sirf ijazat par — PRODUCT-FORM-ROLE-1 aur GRN ke 500 ke saath aik hi sprint me ho sakta hai.
