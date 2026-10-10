# Event ka din guzarne se pehle kuch jamta nahi

**Tareekh:** 10 October 2026
**Tenant jahan se shuru hua:** kashifkitchen
**Halat:** design — kaam shuru

---

## Malik ne kya kaha

> "Jab tak event ka din na guzar jaye tab tak koi invoice freeze na ho, na hi
> final ho. Order edit ho sake, aur agar order edit ho raha ho to kitchen
> release sheet ya invoice sheet sab auto update ho. Aisa na ho ke main order
> update kar doon aur baqi jagahon par purana data aa raha ho."

Aur:

> "Jo order ab complete ho gaye hain before date, un ki invoices ko proforma
> mein change karna hoga taake wo order edit ho sake."

---

## Masla kahan se aaya

`EV-20261009-0219` — event **10 Oct**, final invoice **9 Oct** ko ban gaya.
Invoice bante hi:

1. Booking `completed` ho gayi
2. `completed` par `isCommerciallyOpen()` jhoot ho jata hai → **"Create Revision"
   ka button chhup gaya** → order edit karne ka koi raasta nahi bacha
3. Invoice khud jam gaya — model har update par `throw` karta hai
4. Do journal entries khaton me chali gayin (aamdani + advance ka itlaaq)

### Pehra bana tha, magar GALAT DARWAZE par

8 Oct ko `CATERING-CLOSE-AFTER-EVENT-1` bana: "booking apne din se pehle
**close** nahi ho sakti." Wo `closeLocked()` me baitha hai.

Magar asal tala **close par nahi, INVOICE par** lagta hai —
`CateringFinalInvoiceService::issue()` khud event ko `completed` kar deta hai
(line ~138), aur us raaste par din ki **koi jaanch nahi** hai.

Yani: darwaza B par taala laga diya, aur log darwaze A se andar aate rahe.

### Kitna phaila hua hai (prod par naapa gaya, 10 Oct)

33 final invoices me se **3 apne din se pehle** bane:

| Booking | Event | Bill bana | Kitne din pehle | Halat |
|---|---|---|---|---|
| EV-20260909-0002 | 15 Oct | 9 Sep | **36** | closed |
| EV-20261004-0157 | 9 Oct | 6 Oct | 3 | closed |
| EV-20261009-0219 | 10 Oct | 9 Oct | 1 | completed |

Upar ki do wohi hain jin ke number `closeLocked()` ke comment me likhe hain —
yani wo close-pehra banne ki wajah banin. Pehra close ko rok to raha hai, magar
invoice ka raasta khula raha. **0219 us ke baad ka pehla case hai.**

---

## Naya qaida

### Hadd: event ka din **GUZAR** jaye

Malik ne saaf kaha: "aaj 10 hai, aaj complete nahi hone dena tha." Yani event ke
din bhi nahi — **us ke baad.**

```
Final Invoice sirf tab: event_date < aaj   (TenantClock se, server ke waqt se nahi)
```

⚠️ **`closeLocked()` abhi `event_date > today` par rokta hai**, yani event ke din
close hone deta hai. Dono darwazon ka qaida **ek jaisa** hona chahiye, warna phir
wohi masla doosri shakl me aayega. Ye is kaam me shamil hai.

⚠️ **Tareekh ki STRING par muqabla, lamhon par nahi.** `event_date` ek DATE hai
(UTC ki aadhi raat) jabke TenantClock ki aadhi raat Karachi ki — 19:00 UTC
pichhle din. Lamhe milane par event ka apna din bhi "abhi aaya hi nahi" nikalta
hai. Ye ghalti `closeLocked()` me ek baar ho chuki hai aur us par comment likha
hua hai.

### Din guzarne se pehle

| Cheez | Kya hoga |
|---|---|
| Order | **edit hota rahega** — booking `completed` nahi hogi |
| Kitchen sheet | ✅ **pehle se taza** (`ad1436d1`, 9–10 Oct) |
| Bill wala kaghaz | **Proforma** — maujooda order se banta hua |
| Paisa | **advance** ke taur par khaton me (ye pehle se theek hai) |

### Din guzarne ke baad

Final Invoice ka button aata hai. Tab bill jamta hai, aamdani khaton me jati
hai, advance us par lagta hai, booking `completed` hoti hai.

### Accounting ke lehaz se ye zyada durust hai

Aamdani tab ginni chahiye jab khana **ja chuka** ho. Pehle liya hua paisa
advance hai. System advances ko pehle hi sahi handle karta hai
(`posting_type`: `advance` → Cr 2300, `settlement` → Cr 1300). Yani ye badlav
system ko sahi tareeqe ke **qareeb** le ja raha hai.

---

## Proforma: ek alag RECORD nahi, sirf ek ROOP

🚨 **Database me "draft invoice" NAHI banegi.**

Malik ne kaha tha "draft ho ya kuch bhi". Main alag draft record ke khilaf hoon,
aur wajah tajurbe ki hai: phir **do cheezein** ho jati hain jo alag ho sakti hain
(order kuch kahe, draft invoice kuch aur), aur unhein milate rehna khud ek nayi
kharabi hai. **Aaj ka poora masla isi shakl ka hai** — kitchen sheet ek jami hui
nakal par chal raha tha aur order aage nikal gaya tha.

Is liye: **ek hi sach — order.** Proforma us ka ek chhapai ka roop hai.

- Route: `/catering/documents/proforma-invoice/{cateringEvent}`
- Final invoice jaisa layout, magar lines/totals **maujooda quotation** se
- Upar saaf band: ye abhi final nahi hai
- Koi number kharch nahi hota, koi status nahi hilta, koi GL nahi

⚠️ **NAYA ROUTE = NAYI PERMISSION.** `deploy.sh` sirf Owner ko deta hai. Manager
aur baqi roles ko additively (`givePermissionTo`) dena hoga, phir
`system:clear-tenant-permission-cache`.

---

## "Auto update" ka matlab, aur us ka pehra

Malik: *"aisa na ho ke main order update kar doon aur baqi jagahon par purana
data aa raha ho."*

Teen kaghaz hain. Har ek ko **maujooda order** se bannna chahiye:

| Kaghaz | Kahan se banta hai | Halat |
|---|---|---|
| Kitchen sheet | `sheetFor()` → maujooda quotation | ✅ ho chuka |
| Proforma | maujooda quotation | banana hai |
| Quotation | khud maujooda hai | pehle se |

**Pehra ek hi hona chahiye aur teenon par chalna chahiye:** order me ek dish
joro, phir **teenon kaghaz** render karo aur dekho ke wo dish teenon par hai.
Alag alag test likhne par ek din koi ek kaghaz chup-chaap peeche reh jayega —
bilkul waise hi jaise kitchen sheet reh gaya tha.

---

## Jo bookings pehle hi phans chuki hain

Malik: "un ki invoices ko proforma me change karna hoga taake wo order edit ho
sake."

Ye sab se nazuk hissa hai, kyunke **paisa khaton me ja chuka hai.**

### Kaun si bookings

Wo jin ka final invoice un ke event ka din guzarne se **pehle** bana. Upar wali
teen. Un me se:

- `EV-20260909-0002` — event **15 Oct, abhi aaya hi nahi** → kholna lazmi
- `EV-20261009-0219` — event **10 Oct, aaj** → naye qaide par abhi nahi guzra → kholna
- `EV-20261004-0157` — event **9 Oct, guzar chuka** → ab ye jaiz taur par
  completed hai. **Ise chherne ki zaroorat nahi** (magar malik ka faisla).

### Kaise

Tayyar aur aazmaya hua raasta mojood hai — naya nahi likha ja raha:

- `JournalService::reverse(JournalEntry, reason, userId)`
- `JournalPostingService::reverseForSource(sourceType, sourceId, reason, userId)`

Qadam:

1. Invoice ki **dono** journal entries ulti post hon (aamdani + advance ka
   itlaaq). `tb_diff` 0 rehna chahiye — ye deploy ke baad sabit karna hai.
2. Invoice ka row **mite nahi** — `voided_at` lag jaye. Number ki qatar me
   sooraakh chhorna aur gawahi mita dena, dono ghalat hain.
3. Advance wapas **advance** ban jaye (`posting_type`), yani graahak ka paisa
   us ke naam par mehfooz rahe.
4. Booking wapas khul jaye (`confirmed`/`released`), taake order edit ho sake.

### Is me jo abhi hal nahi hua

🔴 **`$event->finalInvoice` har jagah parha jata hai** — `billedFrom()`,
`position()`, Customer Balances, calendar ka balance filter. Voided invoice ko
in sab se chhupana parega, warna booking par bill "mojood" rahega jab ke wo void
ho chuka. Sab se mehfooz tareeqa relation par ek scope hai (jaise advances par
`notVoided` pehle se hai) — magar us ke **har pukarne wale** ko dekhna hoga.

🔴 **Invoice ka model har update par `throw` karta hai.** `voided_at` ko
`WRITE_ONCE_LINKAGE` me shamil karna hoga — soch samajh kar, kyunke wohi pehra
is dastavez ko mehfooz rakhta hai.

---

## Kaam ki tarteeb

| # | Kaam | Size | Kyun is tarteeb me |
|---|---|---|---|
| 1 | Event ke din se pehle invoice par rok (service + button) + `closeLocked()` ka qaida barabar | chhota | **khoon rukta hai** — aage se ye masla nahi hoga |
| 2 | Proforma Invoice ka kaghaz + teenon kaghazon ka sanjha pehra | darmiyana | din guzarne se pehle graahak ko dene ko kuch ho |
| 3 | Phansi hui bookings kholna (void + GL ulta + relation scope) | **bara, paison wala** | sab se nazuk — akela, apne test aur apni smoke ke saath |

1 aur 2 **aage** ke liye hain. 3 sirf un teen bookings ke liye hai jo pehle hi
phans chuki hain.

🚨 **3 alag deploy me jayega.** Us me GL ulta hota hai; usay 1 aur 2 ke saath
milana iska matlab hai ke agar kuch galat ho to pata hi na chale kis se hua.
