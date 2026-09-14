# TABLE-BILL-PREVIEW-PARITY-1 — Table Bill Preview ko Cart Preview jaisa banana

**Tareekh:** 2026-09-14
**Halat:** RESEARCH + PLAN — koi code nahi badla, koi deploy nahi hua
**Maalik ka mutalba:** "why both preview are different… Current Cart Preview is recommended for
any preview… or transform table preview exactly like cart whatever is easy"

---

## 1. Dono preview alag kyun dikhte hain

Ye do bilkul alag alag code raaste hain. Ek hi modal me khulte hain, magar unka document
banane wala alag hai.

| | **Current Cart Preview** | **Table Bill Preview** |
|---|---|---|
| kaun banata hai | server — `POSController::billPreview()` ([POSController.php:626](../../app/Http/Controllers/Tenant/POSController.php#L626)) | server — `RestaurantTableSessionController::billPreview()` ([:290](../../app/Http/Controllers/Tenant/RestaurantTableSessionController.php#L290)) |
| kaun sa template | **`tenant/printing/documents/receipt.blade.php`** — yani wohi asli receipt jo printer par nikalti hai | **`tenant/pos/partials/table-bill-preview.blade.php`** — alag se haath se likha partial |
| document ki shakal | poora HTML document (`<!DOCTYPE html>`, apna `@page`, apni CSS) | sirf ek `<div>` + scoped `<style>` |
| modal me kaise jata hai | `<iframe id="bill-preview-frame" srcdoc="...">` | seedha `body.innerHTML = data.html` |
| unwaan | `BILL PREVIEW` + `** DINE IN **` | `TABLE BILL` |
| item columns | **Item / Qty / Rate / Amount** (chaar alag columns) | Naam / Qty / Amount (**Rate nahi**) |
| upar ki satarein | Order, Date, Cashier, Table, Waiter | Table, Check, Waiter, Guests, Date |
| kitne bill | ek | har held round ka apna block (`HS-…`), phir `OPEN CHECK` |
| layout settings | poori — logo, tax no, order type, column dividers, `item_font_size`, font-size bands | aadhi — logo, branch naam/pata/phone, header/footer, `show_table_info` |

Asal wajah: **11 Aug ko `BILL-PREVIEW-PARITY-1` sirf cart wale raaste par laga tha.** Us se pehle
cart preview bhi JavaScript me haath se bana HTML tha aur asli bill se alag hota tha. Us din wo
theek kar ke asli `receipt.blade.php` par laga diya gaya — **magar table wala raasta chhoot gaya.**
Table Bill Preview aaj tak apne purane haath se likhe partial par hi chal raha hai.

Yani aap ne jo pakra hai wo ek adhoora kaam hai, koi design faisla nahi.

---

## 2. Hal — table preview ko usi receipt template par le aana

### Buniyadi pecheedgi

`receipt.blade.php` **ek sale** ka document banata hai. Ek table ke paas **kai held rounds** ho
sakte hain (`HS-…-221`, `HS-…-2` waghera). To N rounds ko ek receipt document me kaise dikhayein?

Aur `receipt.blade.php` poora HTML document hai (`<!DOCTYPE>`, `@page`) — N document ko jor kar
ek srcdoc me daalna galat hoga.

### Chuna gaya raasta — ek jama-shuda (combined) transient sale

Table ke **saare held rounds ki lines ek hi ghair-mehfooz (unsaved) `SalesOrder`** me jama kar ke,
usi `receipt.blade.php` se ek document banaya jaye.

Ye wohi tarkeeb hai jo cart wale raaste par **pehle se live hai aur aazmaai hui hai** —
`POSController::billPreview()` bhi memory me ek `SalesOrder` banata hai, relations set karta hai,
render karta hai, aur phenk deta hai. Kuch save nahi hota, koi number issue nahi hota.

Is se milta kya hai:
- Ek document → ek iframe → **bilkul cart jaisa**
- Browser ka "Print here" bhi bina kisi tabdeeli ke chal jayega (wo `#bill-preview-frame` dhoondta
  hai aur mil jane par iframe hi print karta hai — [index.blade.php:5941](../../resources/views/tenant/pos/index.blade.php#L5941))
- Ek table = **ek bill**, teen alag parchiyan nahi (screen aur browser-print par)
- `receipt.blade.php` ki saari layout settings khud-ba-khud mil jati hain

### Jo badlega

| # | file | tabdeeli |
|---|---|---|
| 1 | `RestaurantTableSessionController::billPreview()` | held rounds ko ek transient `SalesOrder` me jama kar ke `receipt.blade.php` render kare. `sale_no` = session ka check number. Purana partial sirf non-JSON (standalone page) raaste par rahe. |
| 2 | `pos/index.blade.php` → `showTableBillPreview()` | `body.innerHTML = data.html` ki jagah cart jaisa `<iframe id="bill-preview-frame" srcdoc=…>` |
| 3 | `receipt.blade.php` | **sirf ek** additive block — `@isset($tableBill)` ke andar "Previously paid" / paid history. Koi aur raasta ye variable pass nahi karta, is liye asli receipt **byte-identical** rehti hai (sabit karunga). |

### Jo BILKUL nahi badlega

- Cart wala preview — ek harf nahi
- Asli chhapne wali receipt (`receipt.blade.php` ka normal output)
- ESC/POS thermal payload
- Koi migration, koi naya route, koi nayi permission
- `held_sale_ids` + `markPreviewMode` (kal ka `BILL-PREVIEW-WRONG-PRINT-1` fix) — jyun ka tyun

---

## 3. ⚠️ Ek eemandaar khaami — "Send to network"

Ye batana zaroori hai.

**Screen par:** ek jama-shuda bill (teen round ho to bhi ek).
**Browser "Print here":** wohi ek jama-shuda bill. ✅ mutabiq.
**"Send to network" (thermal, print agent se):** **abhi bhi har held round ki alag parchi.**

Wajah: thermal payload ek **mehfooz (saved) order** se banta hai — har round ka apna number, apna
ESC/POS payload, apni print-job identity. Ek sacha jama-shuda table bill thermal par bhejne ke
liye naya `document_type = 'table_bill'` + apna payload builder chahiye — **wo alag sprint hai.**

Amli tor par: **jis table par ek hi round hai (aksar yehi hota hai, aur aapke screenshot me bhi
yehi hai), wahan teenon bilkul mutabiq hain.** Farq sirf multi-round table par aata hai.

Do raaste:
- **(a) abhi ke liye qubool karein** — single-round par mukammal parity, multi-round par network
  par N parchiyan. Koi risk nahi, aaj ho sakta hai.
- **(b) alag sprint** — sacha `table_bill` document type, thermal par bhi ek parchi. Bara kaam:
  naya document_type, payload builder, print job reference, reprint semantics.

**Meri sifarish: (a) abhi, (b) alag se jab aap kahein.** Aap ne kaha "whatever is easy" — (a) hi
aasan hai aur (b) ko baad me karne se koi cheez zaya nahi hoti.

---

## 4. Risk — 4 chalti hui businesses

| khatra | kyun kam hai |
|---|---|
| asli receipt bigarna | `receipt.blade.php` me tabdeeli sirf `@isset($tableBill)` ke andar; koi aur caller ye variable pass hi nahi karta. Normal receipt ka output **byte-identical** sabit karunga (before/after snapshot). |
| paisa | kuch save nahi hota — transient sale, `sale_no = 'PREVIEW'`/check number. Koi journal, koi stock, koi number issue nahi. `tb_diff` check har tenant par. |
| purana standalone page | `tenant/restaurant/table-sessions/bill-preview.blade.php` (non-JSON raasta) jyun ka tyun — wahi partial use karta rahega. |
| jo abhi theek kiya | `held_sale_ids` aur `markPreviewMode` ko haath nahi lagta; unka guard bhi wahin rahega. |
| totals galat jama hona | har round ka `subtotal`/`discount`/`tax`/`service_charge`/`grand_total` alag se jama, aur test me held rounds ke sum se milaya jayega. |

---

## 5. Guards (jo likhne hain)

1. Table preview ka HTML `receipt.blade.php` se aata hai — `BILL PREVIEW` maujood, purana
   `TABLE BILL` gayab. Sabotage: purana partial wapas → RED.
2. Do held rounds wali session → ek hi document, aur us ka total dono rounds ke `grand_total`
   ke sum ke barabar.
3. **Paid round kabhi jama nahi hota** — sirf `held`. Ek paid + ek held wali session par total
   sirf held ka. (Ye paise ka guard hai — sabotage par RED hona chahiye.)
4. Normal receipt ka output byte-identical — `@isset($tableBill)` block bina us variable ke kuch
   nahi chhapta.
5. Modal iframe par jata hai: `id="bill-preview-frame"` maujood, taake "Print here" wohi iframe
   print kare.
6. Kal wala fix qaayam — `held_sale_ids` aur `markPreviewMode('session', …)` abhi bhi maujood
   (`BillPreviewPrintTargetMySqlTest` + `PosFrontendRegressionTest` pehle se green rehne chahiye).

---

## 6. Khula sawaal

Modal ka unwaan: abhi cart par "Current Cart Preview", table par "Table Bill Preview". Document
to ab ek jaisa hoga, magar **unwaan alag rakhna mufeed hai** — cashier ko pata rehta hai ke wo
cart dekh raha hai ya table ka bill. Mera mashwara: unwaan alag hi rehne dein. Agar aap dono par
ek hi lafz chahte hain to bata dein.
