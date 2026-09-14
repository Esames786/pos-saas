# BILL-PREVIEW-WRONG-PRINT-1 — table ka bill preview hota hai, magar chhapta koi aur order hai

**Tareekh:** 2026-09-14
**Haalat:** tehqeeq mukammal · **code nahi badla, kuch deploy nahi hua**
**Owner ka bayan:** *"bill preview sahi khulta hai, magar print/send to network karne par jo order
background me attach hota hai us ka print jata hai — jis ka preview kiya tha us ka nahi."*

---

## 1. Purana kaam — `75dc5cf` ne masla HAL NAHI kiya tha

```
75dc5cf  2026-08-31  "Hide the Bill Preview button on the Table Workspace card"

  The owner asked for this one button to go: the card was crowded...
  Hidden in the UI only. The permission is deliberately left alone ... the other three
  Bill Preview buttons it gates are untouched. Deleting the d-none brings it back.
```

Wo **safai ka kaam tha, bug ka ilaj nahi**. Button `d-none` se chhupa, kharabi wahin rahi.

⚠️ **Aur wo kharabi aaj bhi ZINDA hai:** POS ke session bar wala Bill Preview
(`index.blade.php:458` → `#pos-session-bill-preview`) wohi modal kholta hai aur wo chhupa nahi.

---

## 2. Asal sabab — ek satar me

`billPreviewModal` **sanjha** hai (table session ka bill bhi, cart ka preview bhi), magar us ke
footer ka button **cart** se bandha hua hai:

```js
// showTableBillPreview() sirf HTML daal deta hai — kis cheez ka bill hai, ye YAAD NAHI rakhta
document.getElementById('bill-preview-modal-body').innerHTML = data.html;

// ...aur footer ka Send to network:
const saleId = currentReprintSaleId();    // <- CART ka order. Jo dikh raha hai wo NAHI.
```

Screen table 9 ka bill dikhati hai, button table 5 ka order bhej deta hai.

### Dono buttons EK jaise nahi

| Button | Kis par amal | |
|---|---|---|
| **Print here** | `bill-preview-modal-body` — jo **dikh raha hai** | ✅ theek |
| **Send to network** | `currentReprintSaleId()` — **cart ka order** | ❌ **ghalat** |

---

## 3. Hal — chhota hai, kyunke maloomat pehle se mojood hai

Pehle mujhe laga ke button hatana parega, kyunke session ka bill **kai orders ka jama** hota hai aur
print ka raasta **ek sale** par chalta hai. Magar server ka endpoint wo orders **pehle se load kar
raha hai** — bas un ki id bhejta nahi:

```php
// RestaurantTableSessionController::billPreview() — pehle se
'salesOrders' => fn ($q) => $q->whereIn('status', ['held', 'paid'])->with([...])

// wapas sirf:
return response()->json(['ok' => true, 'html' => ...]);     // <- id yahan nahi
```

To button hatane ki zaroorat nahi. Usay **sahi order par laga do**.

### Chaar chhoti tabdeeliyan

**1. Server — ek satar.** Preview ke sath us session ke UNPAID orders ki id bhi bhejo:

```php
return response()->json([
    'ok'             => true,
    'html'           => ...,
    // Sirf `held` — jo ada ho chuke un ki parchi dobara bhejne ka koi matlab nahi.
    'held_sale_ids'  => $restaurantTableSession->salesOrders
                            ->where('status', 'held')->pluck('id')->values(),
]);
```

**2. JS — modal ko yaad rahe ke wo kya dikha raha hai.**

```js
// session ka preview:
modal.dataset.mode        = 'session';
modal.dataset.heldSaleIds = JSON.stringify(data.held_sale_ids || []);

// cart ka preview (billPreview()):
modal.dataset.mode        = 'cart';
modal.dataset.heldSaleIds = '';
```

⚠️ Cart wale raaste par ye **saaf karna lazmi** hai. Modal sanjha hai — agar pichli haalat chipki
reh gayi to aaj wala bug ulta ho kar wapas aa jayega.

**3. JS — "Send to network" us par amal kare jo dikh raha hai.**

```js
if (modal.dataset.mode === 'session') {
    var ids = JSON.parse(modal.dataset.heldSaleIds || '[]');
    if (! ids.length) { toast('warning', 'Is table par koi unpaid order nahi.'); return; }
    // har round apni parchi — har round ek asli sale hai
    ids.forEach(send);
    toast('success', ids.length + ' receipt network printer par bhej di gayin.');
    return;
}
// cart wala raasta bilkul pehle jaisa — wahan currentReprintSaleId() SAHI jawab hai
```

**4. Card wala button wapas** — `d-none` hatana (commit me likha hai: *"Deleting the d-none brings
it back"*). **Ye owner ka faisla hai**, kyunke wo button kharabi ki wajah se nahi, **card ke bhare
hone** ki wajah se gaya tha.

### Kul mehnat

```
Server   : 1 satar
JS       : ~25 satar
Migration: NAHI     Naya route: NAHI     Naya document type: NAHI
Print engine chhua: NAHI     Cart ka bartaao badla: NAHI
```

---

## 4. Ek baat saaf rehni chahiye

Ek table par teen round hon to **teen parchiyan** jayengi, ek jama bill nahi. Har round apna asli
sale hai aur us ki apni receipt banti hai — ye jhoot nahi bolta, bas alag alag aata hai.

Agar owner ko **ek hi jama parchi** chahiye to wo alag kaam hai: naya `document_type = 'table_bill'`,
ESC/POS ka naya renderer, printer routing, reprint/dismiss ka intezam. **Wo asli feature hai, alag
sprint** — print ka nizam chaar zinda karobar par chal raha hai.

---

## 5. Guards

| # | Guard | RED hona chahiye agar... |
|---|---|---|
| 1 | Session ka preview → us SESSION ke held orders ki receipt jaye, cart ka order **na** jaye | `currentReprintSaleId()` wapas laga do |
| 2 | Cart ka preview → aaj jaisa hi chale (jo theek hai wo na toote) | cart wala raasta bhi session par bhej do |
| 3 | **Session → phir cart → modal ki pichli haalat chipke nahi** | `dataset` saaf karna hata do |
| 4 | Session par koi held order na ho → saaf paighaam, koi parchi na jaye | khali list par bhi bhej do |
| 5 | Teen held orders → teen jobs, aur ginti wali baat sach ho | ek hi bhej do |
| 6 | `held_sale_ids` me **paid** orders na aayen | filter se `paid` nikal do |
| 7 | "Print here" wohi chhapta rahe jo modal me hai | body ki jagah koi aur source lagao |

⚠️ **Guard 3 sab se ahem hai.** Modal sanjha hai — pichli haalat ka chipak jana hi is poore masle
ki jarr thi. Agar wo guard na ho to hum wohi bug doosri shakl me wapas le aayenge.

---

## 6. Jo NAHI karna

- ❌ Session me se **apni marzi se ek** order chun kar bhejna (jaise "pehla" ya "aakhri") — operator
  ko pata bhi nahi chalega ke aadha bill chhapa hai
- ❌ **Paid** orders ki parchi dobara bhejna
- ❌ Cart wale raaste ko chhedna — wahan `currentReprintSaleId()` bilkul theek jawab hai
- ❌ Card wala `d-none` fix se **pehle** hatana — bug wapas nazar me aa jayega
- ❌ Canonical `pos-saas` me likhna — kaam alag worktree me

---

## 7. Owner ke faisle

1. **Ye fix kar dun?** (chhota hai — server 1 satar, JS ~25)
2. Card wala Bill Preview button **wapas** chahiye, ya chhupa hi rehne dein?
3. Teen round ki **teen alag parchiyan** theek hain, ya ek jama bill chahiye (alag sprint)?
