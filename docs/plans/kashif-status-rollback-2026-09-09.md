# Status roll-back — CATERING-STATUS-ROLLBACK-1

**Tenant:** kashifkitchen (live) · **Date:** 2026-09-09
**Owner ka kehna:** *"currently when i confirm booking or cancel booking i cant
fall back to draft or inquiry"* aur *"those status can be reversible… at both
edit screen and datatable list in action button"*.

---

## 1. Asool: sirf wahan tak peeche, jahan tak kuch post nahi hua

Har status ko dekh kar nahi — **us ne kya kiya** us ko dekh kar faisla hota hai.

| Zone | Status | Kya post hua | Peeche ja sakte hain? |
|---|---|---|---|
| 🟢 | `inquiry` `draft` `quoted` `confirmed` | **kuch nahi** — na ledger, na stock | **haan** |
| 🟡 | `production_ready` `released` | dastawez aur kitchen sheet, magar **stock phir bhi nahi** | ho sakta tha — magar owner ne **mana** kiya (faisla 3) |
| 🔴 | materials issued · `completed` · `closed` | **stock nikal chuka** / **revenue aur COGS post ho chuke** | **nahi** |

🔴 wali cheezen status badal kar wapas nahi hotin. Un ka jawab sirf ek aur
**dastawez** hai — refund, stock correction, credit note. Yeh baat pehle se
system me hai: `cancelEvent()` `completed`/`closed` ko saaf mana kar deta hai,
aur guard `test_an_invoiced_booking_cannot_be_cancelled_at_all` us ko pin karta
hai.

---

## 2. Jo raaste banenge

```
confirmed  →  quoted        (confirm wapas lena)
quoted     →  draft         (bheji hui quotation dobara khulti hai)
draft      →  inquiry       (sirf iraada reh jata hai)
cancelled  →  jo pehle tha  (un-cancel)
```

Bas yehi chaar. 🟡 zone (`production_ready`, `released`) **shamil nahi** —
owner ka faisla 3. Technically mumkin tha (stock wahan bhi nahi hilta), magar
lakeer wahan khinchi ja rahi hai jahan **kuch bhi post nahi hua**, kyunki wo
lakeer samjhane aur guard karne me aasan hai. Jo cheez bilkul post nahi hui,
us ke peeche jaane me koi soorat-e-haal chhupi nahi ho sakti.

### Har roll-back par lazmi

1. **Wajah** — cancel ki tarah, khali nahi chalegi
2. **History row** — kis ne, kab, kyun, aur kahan se kahan
3. **Event lock** — wahi `locks->refreshEvent()` jo confirm/cancel leta hai
4. **Paisa chhua nahi jata** — ek bhi journal na bane, na mite

---

## 3. `quoted → draft` — asli faisla yahin hai

Yeh sirf status nahi badalta. Estimate `sent` se `draft` par wapas aata hai,
yaani **jo quotation customer ke haath me hai wo dobara qabil-e-tabdeeli ho
jati hai**.

Aaj is se bachne ka tareeqa **revision** hai: Q2 banta hai, Q1 jyun ka tyun
rehta hai, aur jab customer kahe *"aap ne mujhe 447,920 kaha tha"* to Q1 dikha
diya jata hai.

Owner ne revision button hata kar wapas laga liya, is liye **dono maujood
rahenge**:

- **Create Revision** — jab customer ko naya kaghaz dena ho
- **Reopen** — jab wohi kaghaz theek karna ho (typo, ek item, ek rate)

Reopen ki keemat: us kaghaz ki pehchaan (Q1) ab us ke andar ki tafseel se
mutabiq nahi rahegi. Is liye History row **shor machayegi**, khamoshi se nahi
guzregi:

> **Quotation reopened after being sent — 3 items changed, total 447,920 → 462,000**

Aur reopen ke liye apni ijazat: `tenant.catering.estimates.reopen`.

> ⚠️ `CateringEventHistoryService::revertTo()` abhi `revise()` ko bulata hai jab
> mojooda quotation immutable ho. Reopen aane ke baad bhi **wo raasta waisa hi
> rahega** — revertTo ko chhera nahi ja raha. Do alag cheezen, do alag darwaze.

---

## 4. `cancelled → wapas` — sab se zyada zaroori

Cancel har jagah se pahunchta hai magar wapsi ka koi raasta nahi. Aur cancel ek
khamoshi chhorta hai:

**Cancel kisi paisay ko haath nahi lagata.** Bill 0 ho jata hai (jab invoice na
ho), to jo advance bill chuka raha tha wo **customer ka credit** ban jata hai —
ek zinda liability jis ke peeche koi nahi parta. `close()` credit par rukta hai,
magar cancelled booking close tak pahunchti hi nahi.

Is liye:

- un-cancel **wohi status** wapas laye jo cancel se pehle tha
  (`status_before_cancel`, additive column — cancel ke waqt likha jaye)
- purani cancelled bookings me wo column khali hoga → `draft` par wapas, aur
  screen saaf kahe ke andaza lagaya gaya hai
- `cancel_reason` / `cancelled_at` **mitaye nahi jayenge** — history hain

---

## 5. Screen

Dono jagah, ek hi service, ek hi guard:

**Event screen** — `Cancel Booking` ke pehlu me ek `Move Back` dropdown, sirf
wo target dikhaye jo is waqt jaiz hain. Har target par swal:

> *Booking ko `confirmed` se `quoted` par wapas le jayen? Paisa aur stock waise
> hi rahenge — sirf booking ki halat badlegi.*

**Events list (datatable)** — jo `Actions` menu `EVENT-ACTIONS-1` me bana tha,
usi me `Move Back →`. Wo menu pehle se event screen ki authority ko POST karta
hai aur khud kabhi status nahi likhta; yeh usi tarteeb par chalega.

---

## 6. Kya kabhi nahi hoga

- 🔴 zone se koi wapsi — na UI se, na service se
- Paisa: ek bhi journal na bane, na mite, na badle
- `catering_advances` / `catering_refunds` / `catering_final_invoices` par koi
  likhai nahi
- Stock: `postOutFefo` ka koi ulta amal yahan se nahi
- Aur **material issue ho chuka release** kabhi peeche nahi ja sakta, chahe
  release ki halat kuch bhi ho

---

## 7. Kaam ki tarteeb

| # | Qadam | Khatra |
|---|---|---|
| 1 | `status_before_cancel` — additive nullable column | koi nahi |
| 2 | `CateringEventStatusService::moveBack()` — ek hi authority | yahin sab guard |
| 3 | Guards: har mana kiya raasta, aur paisa be-harkat | **pehle likho, tor kar dekho** |
| 4 | Permissions: `events.move-back`, `estimates.reopen` (routeless nahi — routes hain) | naya route = non-Owner roles check |
| 5 | Event screen: Move Back + swal | |
| 6 | Events list: wahi action, wahi service | |
| 7 | History rows + "reopened after being sent" ki tafseel | |

**Jo cheez sab se pehle RED honi chahiye:** ek roll-back jo invoice ho chuki
booking ko peeche le jaye. Wo revenue ko zinda rakhte hue booking ko dobara
qabil-e-tabdeeli kar dega — yaani wo kaghaz badla ja sakega jis par paisa liya
ja chuka hai.

---

## 8. Owner ke faisle (2026-09-09 raat)

| # | Sawal | Faisla |
|---|---|---|
| 1 | Bheji hui quotation dobara khule? | **Dono** — `Reopen` bhi, `Create Revision` bhi. Dono darwazay saath rahenge. |
| 2 | Un-cancel ke baad kahan? | **Jahan se cancel hui thi** — `status_before_cancel` column. Purani cancelled bookings `draft` par, screen saaf kahe ke andaza hai. |
| 3 | 🟡 zone (`released`) bhi? | **Nahi.** Sirf wahan tak jahan kuch post nahi hua — `confirmed` tak. |
| 4 | Jo paisa cancelled bookings me phansa hai? | **List banao** — "Money owed to customers", taake koi liability khamosh na rahe. |

### Faisla 1 ka matlab

Do darwazay, do maqsad — aur screen par saaf farq:

- **Create Revision** → customer ko **naya kaghaz** dena hai. Q2 banta hai, Q1
  mehfooz. Jab customer kahe *"aap ne 447,920 kaha tha"*, Q1 mojood hai.
- **Reopen** → **wohi kaghaz** theek karna hai. Typo, ek item, ek rate.

Reopen ki keemat wahi hai jo §3 me likhi hai: us kaghaz ki pehchaan andar ki
tafseel se mutabiq nahi rahegi. Is liye History row shor machayegi, aur reopen
ki apni ijazat hogi.

### Faisla 4: money-owed worklist

Aaj koi nahi poochta ke kaun sa paisa wapas karna hai. `close()` credit par
rukta hai, magar **cancelled booking close tak pahunchti hi nahi** — to wo
liability khamoshi se baith jati hai.

Ek screen: har booking jis ka `customer_credit > 0`, chahe kisi bhi status me
ho, raqam aur umar ke saath. Sirf padhne ke liye — refund wahin se, mojooda
Refund darwaze se.

---

## 9. Ab bhi khula sawal

- **Reopen kis role ko?** Filhaal Owner. Kashif Kitchen par sirf Owner role
  hai, is liye aaj farq nahi parta — magar jis din Manager banega, us din
  jaan bujh kar dena hoga (`givePermissionTo`, kabhi `syncPermissions` nahi).
- **COGS aur revenue alag mahine me** — material issue par COGS, invoice par
  revenue. Agar mahine ke aakhir par yeh bat jayen to P&L me kharch ek mahine
  me aur bikri doosre me. Nuqs nahi, magar owner ko maloom hona chahiye.
