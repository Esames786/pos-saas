# RATE BOOK — "Chkn rate kese update honge daily?"

**Tareekh:** 2026-09-24
**Halat:** **RESEARCH ONLY — kuch bana nahi, kuch badla nahi, prod par kuch nahi chhua**
**Tenant:** Kashif Kitchen (live trial), sab aankre 24 Sep ko prod se **sirf parh kar** liye gaye
**Sawal:** client (WhatsApp, 23 Sep): *"Chkn rate kese update honge daily"*
**Maang (owner, 24 Sep):** modal me **history** dikhe — kal ka rate dekh kar dobara lagaya ja sake,
nayi tareekh ke saath; aur **current event par apply** karne ka raasta saaf ho

---

## 1. Khulasa — teen jumlon me

1. **Daily update ka poora mechanism pehle se bana hua hai**, aur achha bana hua hai: house rate
   record karo → review dekho → jo chahiye us par apply karo. Ye 20 Aug ko bana tha.
2. **Aaj wo chalega nahi.** Kashif Kitchen ke **1,124 me se 1,124 cost block `manual` hain** — koi
   dish house rate follow karta hi nahi. Rate record karne par review screen **khali** aayegi.
3. **Material (cost) wala screen commercial wale se bohat peeche hai** — usi bimari ka shikar hai jis
   se commercial screen ko jaan-boojh kar bachaya gaya tha. Teen asli kharabiyan, §4 me saboot ke
   saath.

---

## 2. Do rate book — farq pehle saaf kar lein

| | **Material Cost Rate** | **Commercial Charge Rate** |
|---|---|---|
| screen | `/catering/material-rates` | `/catering/commercial-rates` |
| table | `catering_material_rates` | `catering_material_commercial_rates` |
| sawal | material hume **kitne ka parta hai** | customer se **kya liya jata hai** |
| kis ke liye | munafa / costing | quotation ka rate |
| Chicken par abhi | **775.00, 23 Aug 2025 se** — 13 mahine se nahi badli | **0 entry — kabhi istemaal hi nahi hui** |

Client ka sawal **doosri** wali ka hai. Pehli wali munafe ke liye ahem hai magar customer ke rate par
asar nahi karti.

---

## 3. Jo pehle se bana hua hai (aur theek bana hua hai)

`CateringCommercialRateController` (224 lines) ka poora silsila:

```
Set a rate  →  rate MEHFOOZ (kuch reprice NAHI hota)
               ↓
            Impact screen
               ├── "Dishes that follow the house rate"      → chun kar apply
               ├── "Not following the house rate"            → sirf khabar
               ├── "Quotations priced at the old rate"       → chun kar apply  ← DRAFT
               └── sent quotation                            → "Create Revision & Apply"
```

Jo baatein is design me pehle se durust hain (aur inhe todna nahi chahiye):

- **Append-only.** Ek hi din ka doosra rate bhi naya row banata hai — subah ka faisla shaam tak
  mehfooz rehta hai.
- **"Current" ka matlab aaj nafiz.** `effective_from <= aaj` me se sab se nayi. Agle peer wali rate
  "scheduled" me alag dikhti hai, current nahi banti. Code me is ki wajah likhi hui hai: *"a rate
  dated next Monday is a decision already taken, but it is not what anybody is charged this
  morning."*
- **Unit lazmi hai.** *"A rate of 120 means nothing until it says 120 per what."* Dish tabhi follow
  kar sakta hai jab wo usi unit me naapta ho.
- **Koi "sab par lagao" button nahi** — jaan-boojh kar. Premium counter aur shaadi package ka alag
  hona maqsood hai; bulk button unhe chupke se barabar kar deta.
- **Bheji hui quotation jagah par kabhi nahi badalti** — uska naya version banta hai, purana
  superseded.
- Har amal `catering_commercial_rate_applications` me likha jata hai (kis ne, kab, purana→naya).

**Owner ka sawal "current event par kaise apply karein" ka jawab: ye pehle se mojood hai.**
Draft quotation → "Apply to selected quotations". Sent quotation → "Create Revision & Apply".
Banane ki zaroorat nahi; **istemaal** ki zaroorat hai.

---

## 4. Jo aaj kaam nahi karega — saboot ke saath

### 4a. 🔴 Koi dish house rate follow karta hi nahi — is liye impact khali aayegi

```
active cost blocks            : 1,124   → sab "manual"
sirf CHICKEN wale             :   111   → sab "manual"
commercial rate book          :     0 entry
kabhi apply hua               :     0 baar
```

`commercial_rate_source` ki do halaten hain: `manual` aur `commercial_book`. **Ek bhi block
`commercial_book` par nahi hai.**

Nateeja: client aaj chicken ka house rate 450 record kare, to rate kitaab me likh jayega aur
**review screen khali aayegi** — kisi dish ka rate nahi badlega.

Ye code ki kharabi **nahi** hai. Ye setup ka adhoora reh jana hai: har dish ka rate haath se type
hua (Karahi Chicken 535, Chilli Chicken 775, Broast Chicken 535…), aur haath se likha rate kitaab
ko follow nahi karta.

### 4b. 🔴 Material wala "Impact" button is tenant ke liye mara hua hai

`CateringRateImpactService` **recipes** par chalta hai:

```php
Recipe::where('is_active', true)
    ->whereHas('ingredients', fn ($q) => $q->where('product_id', $productId))
```

Magar Kashif Kitchen **recipes se price nahi karta**:

```
costing_mode = blocks        : 912 products  (aur koi mode hai hi nahi)
active recipes               :  15
CHICKEN kitni recipes me hai :   0
CHICKEN ke cost blocks       : 111
```

Yani Material Cost Rates par chicken ka "Impact" dabayen to wo **hamesha kuch nahi** dikhayega —
is liye nahi ke koi asar nahi, balke is liye ke wo **ghalat jagah** dekh raha hai.

### 4c. 🟡 Material screen ka "current rate" costing se jhagarta hai

| kaun | "current" kaise chunta hai |
|---|---|
| Material Cost Rates **screen** | `max(id)` per product — **tareekh dekhta hi nahi** |
| **Costing** (`CateringMaterialRate::effectiveFor`) | `effective_from <= aaj`, phir sab se nayi |

Yani agle hafte ki tareekh wali rate **abhi** screen par "current" dikhne lagegi, jab ke costing
abhi bhi purani wali istemaal karegi. Screen jhoot bolega.

Commercial screen ne ye masla **jaan-boojh kar hal kiya hua hai** (scheduled alag dikhata hai).
Material screen ne nahi — halanke `effectiveFor()` model par pehle se mojood hai (line 44), sirf
istemaal nahi hota.

### 4d. 🟡 Material screen kisi bhi cheez par rate lagne deta hai

```php
// Material (cost)
'product_id' => ['required', 'exists:products,id'],          // KOI bhi product

// Commercial
'product_id' => ['required', Rule::exists('products','id')->where(
    fn ($q) => $q->whereIn('product_kind', self::MATERIAL_KINDS))],   // sirf material
```

Uska picker bhi generic `/ajax/products` istemaal karta hai, is liye dropdown me
**"946 — 2.5/4 Table"** aur **"233 — Aaloo Chana Chat"** jaisi cheezein material bann kar aati hain.

**Prod par ye ho bhi chuka hai:** `Chatni` (id 1076, `product_kind = sale_item` — yani ek DISH) par
cost rate lagi hui hai — 1.00 PH, 22 Sep 2026. Usi file me likha hua hai: *"A dish is not a
material."*

### 4e. 🟡 Material screen par unit marzi ki cheez hai

`'unit_id' => ['nullable', …]` — jab ke commercial par `required` + active hona lazmi.
Unit ke baghair rate ka matlab adhoora hai, aur wohi baat commercial modal khud likhti hai.

---

## 5. Jo maanga gaya: modal me history

**Abhi:** commercial screen `history` (aakhri 30) controller se le kar aata hai aur **safhe ke neeche
ek table** me dikhata hai (line 152). Modal me kuch nahi. Material screen par history sirf
`?product_id=` wale page reload se milti hai.

**Maang:** modal ke andar — material chunte hi uski pichli rates dikhen, kisi purani par "ye wapas
lagao" ho, aur tareekh nayi ho.

**Tajweez (saada aur mehfooz):**

- Modal me material chunne par ek chhota `GET /catering/commercial-rates/{product}/history`
  (JSON, aakhri 10) — tareekh, rate, unit, note, kis ne likha.
- Har row par **"Use this"** — sirf `rate` aur `unit` form me bhar de. **`effective_from` ko HAATH
  NAHI lagana**: wo aaj hi rahe, kyunki maqsad hi "purana rate, nayi tareekh se" hai. Purani tareekh
  chup chaap wapas aa jana sab se aasan ghalti hai.
- Ek line ka jumla: *"Ye sirf khana bhar raha hai — record karne tak kuch nahi hota."*
- **Naya row banega, purana row nahi badlega.** Append-only usool qaayam.

Yehi cheez Material screen par bhi honi chahiye, magar **pehle §4c/4d theek ho** — warna history
un rates ki dikhayenge jo kisi dish par lagi hui hain.

---

## 6. Tajweez — tarteeb ke saath

### Qadam 1 (sab se ahem, code ka kaam nahi) — tay karein ke kaun se dish house rate par chalenge

Bina is ke baqi sab bemani hai. 111 chicken blocks me se wo chunein jahan chicken **asal cheez** hai
(Karahi, Qorma, Biryani, Broast…) aur jahan wo sirf garnish hai use `manual` rehne dein.

Ye aik dafa ka kaam hai. Iske liye ek chhoti screen chahiye — "in dishes ko house rate par daal do"
— ya mojooda impact screen ka "Not following the house rate" section is ke liye istemaal ho sakta
hai (wahan pehle se list hai, sirf "put on house rate" ka amal chahiye).

> **Maine ye khud nahi kiya.** 111 dishes ka rate kis usool par chalega — ye karobari faisla hai,
> mera nahi. Aur ye live rates hain.

### Qadam 2 — modal me history (§5)

Chhota, saaf, low-risk. Dono screens par.

### Qadam 3 — Material screen ko commercial ke barabar laayen

1. picker `/ajax/products` se hata kar `product_kind` par chhanta hua (§4d)
2. `unit_id` required + active (§4e)
3. "current" `effectiveFor()` se, aur scheduled alag (§4c)
4. `Chatni` wali ghalat rate ka faisla — **owner ki ijazat ke baghair haath nahi lagaunga**

### Qadam 4 — Material ka Impact blocks bhi dekhe (§4b)

Abhi sirf recipes dekhta hai. Is tenant ke liye blocks lazmi hain. Ye sab se bara kaam hai —
`CateringRateImpactService` ko wohi karna paregi jo commercial wali service blocks ke liye karti
hai.

---

## 7. Jo NAHI banana chahiye

- **"Sab par lagao" button.** Code me is ki wajah likhi hui hai aur wo durust hai.
- **Bheji hui quotation ko jagah par badalna.** Revision ka raasta pehle se hai.
- **Rate ko khud-ba-khud roz badalna** (koi market feed / cron). Rate ek karobari faisla hai; kisi ko
  dabana parta hai.
- **Purani rate ki tareekh badalna.** Append-only toota to "us din kya rate tha" ka jawab chala
  jayega — aur wahi sawal quotation ke tanaze me poocha jata hai.

---

## 8. Khatre

| khatra | haqeeqat |
|---|---|
| paisa | koi bhi tajweez journal nahi likhti. Rate book GL ko nahi chhoota. |
| zinda quotations | 18 khuli (draft/sent) + 36 accepted. Apply **sirf chune hue** par chalta hai, aur sent par sirf naye version ke zariye. |
| Chatni wali ghalat rate | abhi nuqsan nahi de rahi (Chatni ki apni cost block rate 0 hai), magar ye galat data hai. |
| `audit/catering-rate-impact-cert-v2` | **origin par NAHI hai** — sirf is machine par. Us me `serialize every draft mutation with send` wala kaam hai. Kaam shuru karne se pehle push ho jana chahiye. |

---

## 9. Owner se sawal (inke baghair aage barhna andaza hoga)

1. **Chicken ka customer rate rozana badalta hai ya kabhi kabhi?** Agar rozana, to Qadam 1 lazmi hai.
   Agar mahine me do baar, to 111 dishes haath se bhi chal sakte hain.
2. **Kaun se dish house rate par chalenge?** (Qadam 1 ka asal sawal.)
3. **Chatni wali cost rate** — hata dein, ya waise hi rehne dein?
4. **Material cost rate (775, 13 mahine purani)** — kya ye bhi rozana chalni chahiye, ya wo alag
   raftaar se?
5. Jab house rate badle aur kisi dish ka rate us se **neeche** ho jaye (yani nuqsan), to system ko
   rokna chahiye ya sirf batana chahiye?

---

## 10. Jo maine dekha (taake koi dobara na khodhe)

- `CateringCommercialRateController` 224 · `CateringCommercialRateImpactService` 792 ·
  `CateringCommercialRateBookService` 124
- `CateringMaterialRateController` 68 · `CateringRateImpactService` 102
- blades: commercial index 254 · commercial impact 319 · material index 179 · rate-impact 200
- commits: feature 20 Aug 2026 ko bana (`f7fe179`, `f4fac92`, `f2c689e`, `1e2201c`, `4749ffb`),
  us ke baad **sirf ek** commit (`1914b85`, 23 Aug). Baqi catering bohat aage nikal gayi —
  cost blocks, course order, A4 fit — magar **ye screen wahin ki wahin hai.** Owner ka
  "ye screen purani hai" bilkul durust hai.
- prod aankre: sab read-only tinker se, koi mutation nahi.
