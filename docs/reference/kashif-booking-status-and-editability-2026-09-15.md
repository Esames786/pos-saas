# Booking status, quotation status, and "till what point can I edit"

**Tenant:** kashifkitchen (live) · **Researched:** 2026-09-15 against prod `af6755d`
**Owner ka sawal:** *"i dont understand what status we can go back .. and how far
we can go back.. and can we edit till what point"*

Ye document code parh kar likha gaya hai — har transition ka source line diya
gaya hai — aur prod ke asli data se tasdeeq shuda hai. Andaza kahin nahi.

---

## 0. Asal uljhan: DO status hain, ek nahi

Poori ghalat-fehmi yahin se shuru hoti hai. Screen par do cheezen hain aur dono
ka apna status hai:

```
BOOKING (event)
  inquiry → draft → quoted → confirmed → [production_ready] → released → completed → closed
                                                                                   ↘ cancelled

QUOTATION (estimate)
  draft → sent → accepted
              ↘ superseded   (jab revision banti hai)
```

`EV-20260915-0003 Confirmed` = **booking**.
`Q2 Accepted` = **quotation**.

> **Sab se ahem jumla is document ka:**
> **Items edit karna SIRF quotation ke status par munhasir hai — booking ke
> status par bilkul nahi.**

Booking ko peeche le jane se quotation khud ba khud nahi khulti. Ye wo baat hai
jo screen par kahin nahi likhi.

---

## 1. Kaun sa qadam kaun likhta hai (source ke saath)

### Booking

| Se → Tak | Kis se hota hai | Kahan |
|---|---|---|
| — → `inquiry` | booking banate hi | `CateringEstimateService:56` |
| `inquiry` → `draft` | **khud ba khud**, pehli dafa items save karte hi | `CateringEstimateService:230` |
| `draft` → `quoted` | quotation finalize/send | `:288` |
| `quoted` → `confirmed` | Confirm Booking | `:339` |
| koi bhi → `cancelled` | Cancel Booking (wajah lazmi) | `:384` |
| → `released` | Release Production | `CateringProductionReleaseService:123` |
| → `completed` | **Issue Final Invoice** | `CateringFinalInvoiceService:138` |
| → `closed` | Complete & Close | `:197` |

### Quotation

| Se → Tak | Kis se hota hai | Kahan |
|---|---|---|
| — → `draft` | booking ke saath banti hai | `CateringEstimateService:63` |
| `draft` → `sent` | Finalize / Send | `:284` |
| `sent` → `accepted` | Accept — **sirf `sent` se**, aur kahin se nahi | `:304` |
| `sent`/`accepted` → `superseded` | Create Revision | `:425` |
| koi → `superseded` | Restore (purani version wapas) | `:468` |
| — → `draft` (nayi version) | Create Revision ka nateeja | `:487` |
| `sent` → `draft` | **Move Back** (quoted→draft) | `CateringEventStatusService:215` |

---

## 2. 🔎 Tehqeeq ka pehla nateeja: `production_ready` ek MURDA status hai

Grep se sabit:

```
STATUS_PRODUCTION_READY  →  13 jagah PARHA jata hai
                            0 jagah LIKHA jata hai
```

Koi service, koi controller, koi migration ise set nahi karti. Yaani **koi
booking kabhi is halat me pahunch hi nahi sakti.**

Is ka nateeja: ye shartein kabhi sach nahi hotin —

- `CateringProductionReleaseService:36` — release `[confirmed, production_ready, quoted]` se
- `CateringFinalInvoiceService:59` — invoice `[confirmed, production_ready, released]` se
- Calendar ki 3 jagah, aur 6 blade references

**Nuqsan koi nahi** (ek aisi shart jo kabhi match na kare, sirf mara hua code
hai), magar:

- status ki list padhne wala samajhta hai ke aisa koi marhala hai — hai nahi
- `released → confirmed` wapsi ka mansooba (plan §8, faisla 3) is halat ka
  zikr karta hai jo wujood hi nahi rakhti

**Tajweez:** ya to ise set karne wala qadam banao, ya constant + saari shartein
hata do. Abhi ye teesri soorat hai — na zinda, na dafan.

---

## 3. Items KAB edit ho sakte hain

Sirf ek shart, blade me (`show.blade.php:136` aur `:435`):

```php
$isDraft = $current && $current->isDraft();
@if($isDraft && $event->isOpen())   ← editable table sirf yahan
```

Aur model khud bhi rokta hai: non-draft estimate par commercial field badalne
par —

> *"commercial field [x] is immutable. Create a revision instead."*

| Quotation | Edit? | Kaise |
|---|---|---|
| **draft** | ✅ seedha | table me likho |
| **sent** | ✅ | **Move Back** (quoted→draft) quotation kholta hai · ya Create Revision |
| **accepted** | ❌ jagah par nahi | **sirf Create Revision** → nayi draft Q(n+1) |
| **superseded** | ❌ kabhi nahi | wo tareekh hai |

> **Ek satar ka jawab:**
> **Customer ke "haan" (accept) tak khul kar edit karo. Us ke baad har tabdeeli
> ko nayi version number milta hai.**

Ye asool **theek hai**. Accept ho chuki quotation ko jagah par badal dena us
baat ka wahid saboot mita deta hai jis par customer razi hua tha. Jab customer
kahe *"aap ne 28,877.50 kaha tha"* — Q2 mojood hona chahiye.

### `isOpen()` kya hai

`OPEN_STATUSES = [inquiry, draft, quoted, confirmed]` (`CateringEvent:51`).
Yaani `released` / `completed` / `closed` / `cancelled` par editable table
waise bhi nahi aata, chahe quotation draft hi kyun na ho.

---

## 4. Booking KITNA peeche ja sakti hai

`CateringEventStatusService` — ek qadam fi click:

```
confirmed → quoted → draft → inquiry
cancelled → jahan se cancel hui thi
```

`inquiry` aakhri hai — us se peeche kuch nahi.

### Do pathar ki deewarein

`canMoveBack()` — dono me se koi bhi ho to **Move Back ka button hi nahi aata**:

| Deewar | Kyun |
|---|---|
| **Final invoice ban chuki** | revenue 4160 aur receivable 1300 par post ho chuke |
| **Production release ho chuki** | kitchen ko pakane ka hukm ja chuka |

Aur `assertNothingPosted()` server par dobara rokta hai — screen ka faisla
akhri nahi.

**Kyun itni sakhti:** status badalna ledger ya store ke upar jhoot bolna hoga —
paisa aur maal phir bhi ja chuke hain. In ka jawab **doosri dastawez** hai
(refund, stock correction), status nahi.

`released` / `completed` / `closed` — wapsi bilkul nahi.

---

## 5. 🐞 Tehqeeq ka doosra nateeja: Move Back aisi jagah pesh hota hai jahan chal nahi sakta

**Yehi wo cheez hai jo owner ki screen par aaj ho rahi hai.**

`quoted → draft` sirf status nahi badalta — wo quotation bhi kholta hai. Magar
`reopenCurrentQuotation()` (`CateringEventStatusService:209`) sirf `sent` qubool
karta hai:

```php
if ($current->status !== CateringEstimate::STATUS_SENT) {
    throw new RuntimeException("Quotation Q{$n} is {$status} and cannot be reopened. Create a revision instead.");
}
```

`canMoveBack()` invoice aur release dekhta hai — **quotation khul bhi sakti hai
ya nahi, ye kabhi nahi poochta.**

### Owner ki booking par amali nateeja

`EV-20260915-0003` = `confirmed` + Q2 `accepted`, na invoice na release.

1. Move Back → `confirmed → quoted` ✅ **chal jata hai**
2. Move Back → `quoted → draft` ❌ **inkar** — Q2 `accepted` hai, `sent` nahi

Nateeja: booking be-wajah **un-confirmed** ho gayi, items **ab bhi band**, aur
Create Revision phir bhi karna paregi — jo `confirmed` se bhi ho sakti thi. Upar
se dobara confirm karna paregi aur History me ek aisa qadam likha ja chuka jis
ne kuch hasil nahi kiya.

**Paisa mehfooz hai** — transaction ke andar saaf inkar, kuch nahi hilta. Magar
ye wohi shakal hai jo release wale case me pehle theek ki gayi thi: *aisa button
jis ka wahid anjam error hai.*

### Hal (ek shart)

`canMoveBack()` me ye bhi poochho: agla qadam `draft` hai to kya mojooda
quotation khul sakti hai (`sent` hai ya pehle se `draft`)? Nahi khul sakti to
button na dikhao. **Sirf affordance ka masla hai — kaam karne ka tareeqa theek
hai.**

---

## 6. Prod ki asli halat (2026-09-15, read-only)

```
-- booking statuses --      -- quotation statuses --    -- walls --
   completed          1        accepted   1               final invoices      1
   confirmed          1        sent       2               production releases 1
   quoted             1        superseded 1               material issues     0
```

- **`production_ready` kahin nahi** — §2 ki tasdeeq, asli data par
- 1 booking par release ho chuki → us par Move Back ka button **aana hi nahi
  chahiye** (aur nahi aata)
- 1 booking `completed` → invoice ban chuki → wapsi band
- material issues abhi bhi **0** — stock live par ek dafa bhi nahi hila

---

## 7. Owner ke liye khulasa

| Sawal | Jawab |
|---|---|
| **Kya edit kar sakta hoon?** | Jab tak quotation `draft` hai |
| **Accept ke baad?** | Create Revision → nayi draft Q(n+1); purani mehfooz |
| **Booking kitna peeche?** | `confirmed → quoted → draft → inquiry`, ek qadam fi click |
| **Cancelled?** | Jahan se cancel hui thi, wahin wapas |
| **Kab bilkul nahi?** | Invoice ban gayi **ya** production release ho gayi |
| **Kyun?** | Us ke baad ledger/store me nishan par gaya hai — jawab doosri dastawez hai |

**Abhi is booking par:** Create Revision dabao. Move Back mat dabao.

---

## 8. Jo mila, jo nahi kiya

| # | Cheez | Darja | Halat |
|---|---|---|---|
| D1 | Move Back accepted quotation par pesh hota hai magar chalta nahi | **P2** — paisa mehfooz, operator phansa | **NAHI kiya** — catering parked |
| D2 | `production_ready` likha kabhi nahi jata, parha 13 jagah | **P3** — mara hua code | **NAHI kiya** |

Dono ka hal `pos-saas-catering` worktree me hoga, canonical me nahi — owner ka
standing rule. D1 ek shart hai; D2 ka faisla owner ka (zinda karo ya dafan).

**Is document ne code ka ek lafz nahi badla.**
