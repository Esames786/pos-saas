# Client feedback, 15 September — chaar cheezen

**Tenant:** kashifkitchen (live) · **Researched:** 2026-09-15 against `5376989`
**Source:** WhatsApp — Tabish Jawaid (6:10, 6:11, 6:24 PM) aur Kashif Food (7:23 PM)

Ye asli client/UAT feedback hai, is liye catering ka park rule is par lagoo nahi
hota. Har cheez code parh kar dekhi gayi hai — kya hai, kyun hai, aur kitna kaam
hai. **Abhi tak in me se kuch banaya NAHI gaya.**

---

## Aik cheez jo chaaron ke peechay hai

Teen me se do masle ek hi jar se nikalte hain, aur wo jar pehle bhi mil chuki
hai (`docs/reference/kashif-booking-status-and-editability-2026-09-15.md`):

> **DO status machine hain, ek nahi.**
> **BOOKING** — `inquiry → draft → quoted → confirmed → …`
> **QUOTATION** — `draft → sent → accepted`
>
> Screen dono saath dikhati hai, magar kahin nahi likha ke ye alag cheezen hain.

Item 1 aur item 2 dono isi wajah se hain: ek document sirf quotation ko dekhta
hai, doosra sirf booking ko — aur client dono ko ek hi cheez samajhta hai.

---

## 1. "Booking hone p estimate ki jagh booking likha ana chahye pdf me"

### Aaj kya hota hai

`documents/partials/estimate-body.blade.php:24` —

```blade
<h2>{{ $estimate->isDraft() ? 'DRAFT ESTIMATE' : 'ESTIMATE' }}</h2>
```

Aur `documents/estimate.blade.php:12` me `<title>… — Estimate</title>`.

**Document sirf ye janta hai ke quotation draft hai ya nahi. Booking ka status
wo dekhta hi nahi** — `grep "event->status"` us file me **0** deta hai.

To booking confirm ho jaye, customer accept kar le — kaghaz phir bhi "ESTIMATE"
hi chhapta hai. Client bilkul theek keh raha hai.

### Asal masla

Ek hi document teen alag kaam kar raha hai:

| Kab | Wo cheez kya hai | Aaj chhapta hai |
|---|---|---|
| quotation draft | andaruni masauda | `DRAFT ESTIMATE` ✅ |
| customer ko bheja | **offer** | `ESTIMATE` — theek-thaak |
| customer ne maan liya / booking confirm | **booking confirmation** | `ESTIMATE` ❌ **ghalat** |

### Tajweez

Heading ko dono status se banao, sirf estimate se nahi:

```
quotation draft                    → DRAFT ESTIMATE / مسودہ تخمینہ
quotation sent, booking not confirmed → QUOTATION     / کوٹیشن
quotation accepted OR booking confirmed → BOOKING CONFIRMATION / بکنگ کنفرمیشن
```

⚠️ Urdu alfaz client se pooch kar final karna — "بکنگ کنفرمیشن" mera tarjuma
hai, un ka rozmarra ka lafz alag ho sakta hai.

⚠️ `<title>` bhi badle — PDF ka file naam wahin se banta hai.

⚠️ **Purane PDF nahi badlenge** aur na badalne chahiye — document mojooda
halat par bana hai, snapshot nahi. Agar client chahta hai ke jo kaghaz us waqt
diya gaya tha wo waisa hi rahe, to ye alag aur bara kaam hai (document
snapshotting), aur us par alag faisla chahiye.

**Kaam:** chhota — ek partial, do satrein, plus Urdu. **~1 ghanta** tests ke saath.

---

## 2. "Customer acceptance p confirmed k tab me ana chahye calender me"

### Aaj kya hota hai

`CateringCalendarService:232` — calendar ka rang **sirf BOOKING ke status** se
banta hai:

```php
match ($event->status) {
    CONFIRMED, PRODUCTION_READY, RELEASED => 'confirmed',
    QUOTED                                => 'quoted',
    default                               => 'draft',
}
```

Aur `CateringEstimateService:304` — accept karne se **sirf quotation** badalti
hai:

```php
$estimate->forceFill(['status' => ACCEPTED, 'accepted_at' => now()])->save();
```

**Accept booking ko haath nahi lagata.** To customer haan keh de, quotation
`accepted` ho jaye — calendar par booking phir bhi **"quoted"** hi rehti hai,
jab tak koi alag se Confirm Booking na dabaye.

Yehi wo do-machine wala masla hai, doosri shakal me.

### Do raaste — owner ka faisla chahiye

**(a) Accept karte hi booking bhi confirm ho jaye**
Seedha, aur client ki tawaqqo se milta hai: customer ne haan kaha = booking
pakki. Magar `confirmEvent()` ke apne guard hain (costing mukammal ho, quotation
draft na ho) — accept ke andar unhein chalana hoga, warna do raaste alag alag
soch ke saath chalenge.

**(b) Calendar accepted quotation ko `confirmed` rang de**
Chhota aur mehfooz: sirf rang ka masla hai, booking ka status nahi badalta.
Magar phir calendar aur booking screen do alag baatein kahenge — **wohi
gharbar jo poora masla paida karti hai.**

**Meri raaye: (a).** Kyunki asal sawal ye hai ke "customer ne haan kaha" aur
"booking confirmed" do alag cheezen honi chahiye ya nahi. Client ke nazdeek ek
hi hain. Rang badal dena us sawal ka jawab nahi, us par parda hai.

⚠️ Agar (a) chuna jaye: accept ke waqt costing adhoori ho to kya ho? Mana karna
ya accept kar ke booking quoted chhor dena — ye faisla karna paregi.

**Kaam:** (b) ~1 ghanta · (a) **~aadha din** guards aur tests ke saath.

---

## 3. "Event banaty v, departure time bh add krna hain"

### Aaj kya hota hai

`catering_events` par **`service_time` hai** (`2026_08_13_100002:39`), aur form
par bhi hai (`event-form-fields.blade.php:122`) — masking aur keyboard ke saath
(EVENT-FORM-KEYBOARD-2/3 me theek kiya gaya tha).

**`departure_time` naam ka koi column nahi.** Grep saaf khali.

### Kaam ki fehrist (infra linkage — sab ek hi pass me)

| # | Kahan | Kya |
|---|---|---|
| 1 | migration | `departure_time` nullable time, `service_time` ke baad — **additive** |
| 2 | `CateringEvent` | `$fillable` + `$casts` |
| 3 | form | field, `service_time` wali masking ke saath |
| 4 | controller | validation |
| 5 | event screen | header chip me dikhe |
| 6 | **documents** | kitchen sheet par — rawangi ka waqt kitchen ka sawal hai |
| 7 | production release snapshot | `header` me, warna purani release par ghayab |
| 8 | History snapshot | warna tabdeeli timeline par nahi aayegi |

⚠️ **7 aur 8 bhoolna aasan hai.** Release snapshot me `service_time` pehle se
hai (`CateringProductionReleaseService:60`) — departure bhi wahin jana chahiye,
warna kitchen sheet purani release par khali rahega.

**Kaam:** ~aadha din, kyunki ye 8 jagah ka silsila hai — ek nahi.

---

## 4. "Address complete nahi araha … pura fill address show ho"

> ### ⚠️ TASHEEH (2026-09-16) — is section ka nisf hissa GHALAT tha
>
> Neeche (a) me likha hai ke form ghalat pata uthata hai. **Wo ghalat hai.**
> `CustomerLookupController:20` addresses ko `orderByDesc(is_default)` ke
> saath eager-load karta hai, is liye `addresses[0]` **pehle se default hi
> hai** — form tak pahunchne se pehle. Maine `Customer::addresses()` par
> ordering na hone se natija nikala (jo sach hai) aur wo endpoint parha hi
> nahi jo data deta hai.
>
> Client ne kaha tha **"show"**, "ghalat" nahi — aur wo theek keh rahe the.
> Asli masla sirf (b) tha: single-line box. `9d370fb` me theek ho gaya.
>
> Us ki jagah owner ki apni tajweez bani: **ek se zyada pate hon to chhota
> modal** jis se pata chun kar booking par lagaya ja sake
> (CATERING-ADDRESS-PICKER-1, `2b06799`).



Ye sab se dilchasp nikla — **do alag masle, dono asli.**

### (a) Ghalat pata uthaya jata hai 🔴

`event-form-support.blade.php:96` —

```js
const addr = (c.addresses && c.addresses.length) ? c.addresses[0].address : c.legacy_address;
```

`addresses[0]` — **pehla**, `is_default` wala nahi. Aur `Customer::addresses()`
par **koi ordering nahi hai** (`Customer.php:53-56`), to "pehla" wo hai jo MySQL
pehle de de — aam taur par jo pehle bana tha.

To jis customer ke do pate hain, form us ka **purana/ghair-default pata** bhar
sakta hai. Ye "adhoora" nahi, **ghalat** hai — aur zyada khatarnaak hai, kyunki
adhoora nazar aa jata hai, ghalat nahi.

### (b) Poora pata dikhta hi nahi 🟡

`event-form-fields.blade.php:67` — `<input type="text">`, aur `col-md-4` (tihai
chaurai). Column `string(500)` hai, yaani data poora mehfooz hai — **sirf nazar
nahi aata.** Screenshot me "L CHORANGI SONA KHANTA WALI" isi wajah se kata hua
lagta hai.

### Tajweez

1. **(a)** `addresses()` par ordering: `is_default DESC, id ASC` — ya JS me
   default dhoondo. Ordering behtar hai: har wo screen theek ho jayegi jo ye
   relation parhti hai, sirf catering ka form nahi.
   ⚠️ Isi liye ehtiyat bhi zyada — POS/delivery bhi yahi relation parhte hain.
   Wahan bhi dekhna paregi.
2. **(b)** `<input>` → `<textarea rows="2">`. Data nahi badalta, sirf nazar
   aata hai.

**Kaam:** (b) 15 minute · (a) ~1 ghanta, **plus** baqi screens ki jaanch.

---

## Khulasa

| # | Cheez | Darja | Jar | Kaam |
|---|---|---|---|---|
| 4a | Ghalat pata uthta hai | 🔴 **P1** | `addresses[0]`, koi ordering nahi | ~1 ghanta + jaanch |
| 1 | PDF "ESTIMATE" hi kehta hai | 🟡 P2 | document booking ka status dekhta hi nahi | ~1 ghanta |
| 2 | Accept par calendar nahi badalta | 🟡 P2 | do status machine | (b) 1 gh · **(a) aadha din** |
| 4b | Pata poora nazar nahi aata | 🟢 P3 | single-line input, tihai chaurai | 15 minute |
| 3 | Departure time nahi hai | 🟢 P3 | column hi nahi | ~aadha din, 8 jagah |

### Tarteeb jo mai tajweez karta hoon

1. **4a pehle** — ye ghalat data hai, aur sab se sasta P1 jo aaj mil sakta hai
2. **4b usi pass me** — wohi file, 15 minute
3. **1** — client ne sab se pehle yehi kaha, aur asar foran nazar aata hai
4. **2** — magar **pehle owner ka faisla**: (a) ya (b)
5. **3** — sab se bara, aur sab se kam faori

### Jo faisle darkar hain

- **Item 2:** accept booking ko confirm kare, ya sirf calendar ka rang badle?
- **Item 1:** Urdu alfaz kya hon? Aur kya purane PDF bhi badalne chahiye
  (= document snapshotting, alag aur bara kaam)?
- **Item 4a:** default pata sahi karna baqi screens (POS/delivery) ko bhi chhuta
  hai — wahan bhi theek karen ya sirf catering me?

**Is document ne code ka ek lafz nahi badla.**
