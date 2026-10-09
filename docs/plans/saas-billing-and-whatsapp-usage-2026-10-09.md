# SaaS billing zinda karna + WhatsApp usage ki alag invoice

**Tareekh:** 2026-10-09 · **Haalat:** manzoori ka muntazir — **abhi tak koi row nahi likhi gayi**

Ye paison ka hisaab hai. Is liye pehle plan, phir aap ki haan, phir code. Neeche har aankRa prod se
parha gaya hai (read-only), farz kiya hua nahi.

---

## 1. Aaj ki asal haalat (prod se naapi gayi, 09-10-2026)

| cheez | haalat |
|---|---|
| subscriptions | **12** |
| invoices (poore system me, kabhi bhi) | **1** (PKR 7,500, `issued`, kabhi paid nahi) |
| payments | **0** |
| `price_snapshot` charon live tenants par | **khaali** |

Yani aap **PKR 105,000/mah** (25+25+20+35) wasool kar rahe hain aur system ka us se koi taalluq nahi.
Do mahine ka poora hisaab system ke bahar hai.

### `current_period_ends_at` mahana billing ke liye be-maani hai

Charon par `billing_period = monthly` likha hai, magar period khatam hone ki tareekh:

```
khatribiryani   2027-08-11   (~1 saal aage)
kashiffood      2027-08-24   (~1 saal)
kashifkitchen   2031-08-15   (PAANCH saal)
tawakalkashif   2027-09-05   (~1 saal)
```

Period kabhi khatam nahi hota → invoice kabhi due nahi hota → `saas:subscriptions-expire` ka roz ka
sweep kuch nahi karta. Ye shayad onboarding ke waqt "activate kar do" ke liye aage dhakel di gayi thin.

---

## 2. Chaar tenants ki shartein (malik ne 09-10 ko batayin)

| tenant | mahana | **invoice banegi** | adaegi (pehle bataya) | account chalu hua | pehla asli karobar | ab tak paid |
|---|---|---|---|---|---|---|
| **khatribiryani** | 25,000 | **15** | 20 | 09-08-2026 09:33 | pehla order **11-08** | Aug + Sep (**2**) |
| **kashifkitchen** | 25,000 | **20** | 20 | 15-08-2026 09:55 | pehla catering event **08-09** | **1** |
| **kashiffood** | 35,000 | **25** | 1 (agle mahine) | 24-08-2026 10:48 | pehla order **30-08** | 2 × 35,000 |
| **tawakalkashif** | 20,000 | **10** | 20 | 05-09-2026 23:31 | pehla order **06-09** | *malik ne nahi bataya* |

⚠️ **"invoice banegi" wale din malik ne 09-10 ko alag se bheje** (15 / 20 / 25 / 10) aur ye un
tareekhon se mukhtalif hain jo usi din pehle batayi gayi thin (20 / 20 / 1 / 20). Dono mil kar aik
maqool shakl bante hain — **invoice adaegi se kuch din pehle bana karti hai**:

```
khatribiryani   15 ko bane  →  20 ko adaegi   (5 din)
kashifkitchen   20 ko bane  →  20 ko adaegi   (usi din)
kashiffood      25 ko bane  →   1 ko adaegi   (~6 din, agla mahina)
tawakalkashif   10 ko bane  →  20 ko adaegi   (10 din)
```

**Ye abhi tasdeeq-talab hai (section 9, sawal 5).** Agar ghalat samjha to har tenant ka invoice ghalat
din banega — aur jab wo khud-kar ho jaye to ghalti har mahine dohraegi.

`kashifkitchen` par account chalu hone aur asli kaam me **24 din** ka farq tha — malik ne faisla kar
diya ke billing **20 September** se shuru hogi, yani asli istemal se. Likh liya gaya.

---

## 3. 🚨 Jo pehle se bana hua hai — ise DOBARA MAT BANAO

Ye is poore plan ka sab se ahem hissa hai. Billing ka bara hissa likha ja chuka hai.

### Live branch par (abhi prod par chal raha hai)

`app/Services/Saas/SubscriptionBillingService.php`:

```
createInvoice()                       recordTenantProofPayment()
recordPayment()                       verifyPayment()
refreshInvoicePaymentState()          rejectPayment()
activateSubscriptionFromPaidInvoice() voidInvoice()   nextInvoiceNo()
```

Tenant ka portal — `routes/tenant.php:563-566`:

```
GET  /billing                                        invoice ki list
GET  /billing/invoices/{invoice}                     aik invoice
POST /billing/invoices/{invoice}/payments            SCREENSHOT UPLOAD
GET  /billing/invoices/{invoice}/payments/{p}/proof  screenshot download
```

Ye jaan boojh kar "always-allowed" prefix me hain — subscription lapse ho jaye tab bhi tenant bill
dekh aur de sakta hai. **Ise mat toRo.**

Admin ki taraf `app/Http/Controllers/Central/InvoiceController.php`.
Tables: `subscription_invoices`, `subscription_payments` (`proof_path`, `proof_original_name`,
`proof_uploaded_by_user_id`, `proof_uploaded_at`, `verified_by_user_id`, `verified_at`),
`subscription_change_requests`, `plan_prices`.

### Unmerged branch par: `feat/cloud-billing-onboarding-v1`

8 commits, 53 files, **3,279 lines**, 7 billing test files:

```
CLOUD-BILLING-1A          admin-editable manual payment directory + proof UX
CLOUD-BILLING-1A-HARDEN   payment-method lifecycle + HTTP authorization matrix
CLOUD-BILLING-1B          trial-safe AUTOMATIC FIRST INVOICE + deterministic activation
CLOUD-BILLING-2           MONTHLY/YEARLY BILLING PERIOD, END TO END
CLOUD-BILLING-3A          transactional billing email foundation
CLOUD-BILLING E2E         real monthly + yearly signup lifecycle
```

**"Har mahine invoice khud khul jaye" — wo yahan hai.** Memory ke mutabiq ye branch code ki wajah se
nahi, **payment account** ki wajah se ruki thi. Ab wo rukawat nahi rahi.

### Jo waqai nahi hai

1. **Mahana invoice khud banane wala cron** — `routes/console.php` me sirf `saas:subscriptions-expire`
   (roz 00:10) hai, jo sirf expire karta hai, banata kuch nahi.
2. **Invoice ki lines** — `subscription_invoices` par sirf aik `total_amount` hai. WhatsApp ki
   "per day cost" dikhani hai to lines chahiyen.
3. **WhatsApp usage ka register** — kahin darj nahi ke kis tenant ne kitne message bheje.
4. **WhatsApp ka webhook** — delivery ki khabar aati hi nahi.

---

## 4. 🔴 WhatsApp: bill "delivered" par, "accepted" par nahi

Ye baareek baat nahi — **08/09 October ki raat isi ki misaal hai.** Malik ka card decline hua. Humne
14 message bheje, Meta ne sab **"accepted"** kahe, aur **koi nahi pohancha**. Hamare
`report_schedule_runs` me dono channel `sent` likhe hain aur `last_failure` khaali hai.

Agar us raat billing chaalu hoti to do tenants ko **PKR 138 us cheez ka bill jata jo kabhi nahi
pohanchi** — aur kisi ko pata na chalta.

Meta khud sirf delivered par paisa leta hai (Insights: 84 sent, **78** delivered, charge sirf 78 ka).
Hum bhi delivered par hi lein. Us ke liye webhook **shart** hai, sahulat nahi.

### Aur: mojooda data se bill ban hi nahi sakta

Maine `report_schedule_runs × mojooda numbers` se takhmeena lagaya: **112 messages**.
Meta ka apna meter: **97 sent / 90 delivered**.

**15 ka farq** — kyunke numbers beech me badle (khatri 5 se 7 hue) aur `report_schedule_runs` sirf
"is raat whatsapp chala" likhta hai, "kitne numbers par chala" nahi. Aaj bill banayein to har tenant
se **~15% zyada** wasool hoga, aur sabit karne ka koi zariya nahi hoga.

---

## 5. Laagat aur rate

| | |
|---|---|
| Meta ka meter | $1.17 ÷ 78 = **$0.015** per delivered message |
| PKR me (card par 378.66 ÷ $1.35 = 280.5/USD) | **PKR ~4.21** |
| Tax / bank FX samet (378.66 + 157 = 535.66) | **PKR ~5.95** |
| **Malik ka tay-shuda rate** | **PKR 9.85** |
| Margin | PKR ~3.90 (~40%) |

⚠️ Laagat har usage row par likhi jayegi, farz nahi ki jayegi — dollar ya tax hile to margin hilta hai
aur wo nazar aana chahiye.

⚠️ **Meri pehli ginti ghalat thi** — maine PKR 2.79 kaha tha (Meta ke shaya-shuda rate card se). Asal
all-in **PKR 5.95** hai, yani do guna se zyada. Rate 9.85 par theek baithta hai, magar record me rahe.

---

## 6. Faisla: subscription aur WhatsApp ke **ALAG** invoice

Malik ne tay kiya: cycle wohi, magar invoice alag.

```
INV-xxxx  khatribiryani  Plan: 20 Oct – 19 Nov            PKR 25,000
INV-yyyy  khatribiryani  WhatsApp usage: 20 Sep – 19 Oct  PKR  4,137   (420 × 9.85)
```

Ye jaiz hai aur is ki apni wajah hai: **plan pakka hai, usage badalta hai.** Agar usage par koi
ikhtelaf ho to plan ki adaegi us me nahi phansti — aur plan hi asal paisa hai (105,000 vs ~8,000).

Qeemat ye hai ke mahine me **do screenshot aur do verification** — malik ne ye qabool kiya.

- Subscription invoice: `invoice_type = subscription`
- WhatsApp invoice: `invoice_type = addon` ← enum me pehle se mojood hai
- **Dono ki cycle tareekh aik** (khatri / kashifkitchen / tawakal = 20, kashiffood = 1)
- WhatsApp usage hamesha **guzre** mahine ka (arrears) — jo kharch ho chuka
- Plan **aane wale** mahine ka (advance) — malik ki mojooda aadat yehi hai

### WhatsApp ki invoice **khuli** rehti hai aur roz barhti hai

Malik ne kaha: *"jab se tenant ka WhatsApp add howa tab se us ki aik separate invoice ban gayi aur
usme data add hota rahe"* — aur saath me *"ya jo bhi best solution ho"*. Ye wo behtar shakl hai:

```
WhatsApp ON hua        →  us period ki aik DRAFT invoice khul gayi (invoice_type = addon)
har raat report ke baad →  us invoice me US DIN ki AIK LINE juR gayi, total barh gaya
                           (tenant roz dekh sakta hai ke ab tak kitna bana)
invoice_day aaya       →  wo invoice BAND (draft → issued), due date lagi,
                           aur agle period ki NAYI draft khul gayi
tenant ne screenshot bheja → verify → paid
```

Yani **data roz, invoice mahine me aik.** Do ghaltiyan is se bachti hain:

- **Aik invoice jo kabhi band na ho, kabhi qabil-e-adaegi nahi hoti.** Na due date, na overdue, na
  "ye dena hai" ka lamha. Aur agar adaegi ke baad bhi barhti rahe to `paid_amount` kabhi
  `total_amount` ke barabar nahi aayega — hisaab hamesha adhoora dikhega.
- **Har din ki alag invoice** = mahine me 30 invoice per tenant — yehi wo "bht sari invoice" hai jis se
  malik bachna chahte hain.

`status = draft` enum me **pehle se mojood** hai aur `createInvoice()` use sahara deta hai
(`$status = ($data['status'] ?? 'issued') === 'draft' ? 'draft' : 'issued'`). Nayi haalat banane ki
zaroorat nahi.

### 🔴 Peechhe se WhatsApp ka bill banana MUMKIN NAHI hai

Malik ne kaha "jab se WhatsApp add howa tab se". Ye ho nahi sakta, aur wajah data ki nahi, Meta ki hai:

1. Guzre dinon ka **per-message record kahin hai hi nahi** (yehi to P4 bana raha hai).
2. **Meta tenant ke hisaab se toR kar de hi nahi sakta** — saaray tenants aik hi phone number
   (`923182784982`) se jate hain. Meta ko tenant ka pata hi nahi.

To purana bill sirf **andaaza** ho sakta hai — aur nayi billing ki shuruaat andaazay se karna wo cheez
hai jo baad me jhagRa banti hai.

**Kitne ka maamla hai:** Meta ke meter par ab tak kul **97 messages** (dono tenants mila kar) = 97 ×
9.85 = **PKR 955**. Poore do mahine ka. Is ke liye aik mutnaza aankRa banana faida ka sauda nahi.

**Mashwara: WhatsApp ka meter us din se shuru ho jis din register zinda ho (P4).** PKR 955 chhoR dein.

### Tenant ke dashboard par

Abhi tenant ke dashboard par billing ka kuch nahi (sirf plan ki maloomat). Do cheezein aani chahiyen,
aur **nazar me alag dikhni chahiyen**:

| | kya | haalat |
|---|---|---|
| **Dena hai** | `issued` / `overdue` invoice — ginti, raqam, aur "adaegi" ka link | qabil-e-adaegi |
| **Ban raha hai** | is period ka chalta hua WhatsApp usage | abhi dena nahi |

Agar ye dono aik jaise dikhein to log ya ghalat wali ki adaegi karenge ya be-waja ghabrayenge. Jo
cheez abhi deni nahi, us par "due" ka lafz nahi aana chahiye.

---

## 7. Kaam ki tarteeb

### P1 — Sach system me daalo (sirf data, koi code nahi)

- charon par `price_snapshot` = 25,000 / 25,000 / 20,000 / 35,000
- `invoice_day` = 15 / 20 / 25 / 10 (khatri / kashifkitchen / kashiffood / tawakal) *(naya column)*
- `payment_due_day` = 20 / 20 / 1 / 20 — **agar sawal 5 ka jawab "do alag cheezein" hai**
- `current_period_ends_at` ko asal agle cycle par laao (1–5 saal nahi)
- **Koi invoice nahi banegi is qadam me.** Pehle aankRe theek, phir paisa.

### P2 — Guzre, adaegi-shuda invoice darj karo

`SubscriptionBillingService::createInvoice()` + `recordPayment()` se — **nayi service nahi**.

- khatribiryani: 2 × 25,000 → `paid`
- kashifkitchen: 1 × 25,000 (20 Sep se) → `paid`
- kashiffood: 2 × 35,000 → `paid`
- tawakalkashif: **malik ke jawab ka intezar** (neeche sawal 2)

Har invoice ke `notes` me likha jaye ke ye **peechhe se darj** kiya gaya hai aur asal adaegi system ke
bahar hui thi. Warna kal koi samjhega ke paisa portal se aaya tha.

### P3 — Mahana invoice khud khulna

`feat/cloud-billing-onboarding-v1` ko samjho, test chalao, merge karo — **naya mat likho.** Us me
"automatic first invoice" aur "monthly/yearly billing period end to end" dono hain.
Kami: roz ka cron jo due tenants ke invoice khole.

### P4 — WhatsApp usage ka register + webhook

- master me `whatsapp_messages`: `tenant_id`, `sent_at`, `template`, `to`, `wamid`, `status`,
  `provider_cost`, **`rate_charged`**, `billable`
- `WhatsAppChannel::send()` har number par aik row likhe (abhi loop me kuch darj nahi hota)
- webhook `sent / delivered / read / failed` bhar de
- **aadhi nakami ab chup nahi rahegi** — ye wohi khala hai jo 3 baar bataya gaya

### P5 — WhatsApp ki khuli invoice jo roz barhti hai

- `subscription_invoice_lines` (nayi table): `date`, `description`, `qty`, `unit_price`, `amount`
- **har din ki aik line** — malik ne yehi maanga ("per day ki cost")
- WhatsApp ON hote hi us period ki **`draft`** invoice khul jaye (`invoice_type = addon`)
- raat ka kaam report bhejne ke baad us din ki line joRe aur total nikale
- `invoice_day` par `draft → issued` + agle period ki nayi draft
- sirf `status = delivered` wali rows ginein
- `rate_charged` usage row se, settings se nahi — purane invoice kabhi na badlein
- **peechhe ka bill nahi banega** (section 6: Meta tenant ke hisaab se toR nahi sakta; kul PKR 955)

### P6 — Screens

- **Bingoo ka main account:** rate ki setting, har tenant ka chalta hua balance, screenshot dekh kar
  verify (`verifyPayment()` pehle se mojood)
- **Tenant `/billing`:** pehle se hai; us me is period ka chalta hua WhatsApp usage dikhana hai
- **Tenant dashboard:** "dena hai" (issued/overdue) aur "ban raha hai" (chalta hua usage) — do alag
  cheezein, alag shakl me. Abhi dashboard par billing ka kuch nahi hai.

---

## 8. 🚨 Jo ghalat nahi hona chahiye

1. **Naya route = non-owner roles check karo.** `deploy.sh` nayi permission sirf Owner ko deta hai.
   "Billing access" kis role ko milega ye malik tay karega — aur wo role **apna** hoga, kisi aur type
   ka nahi.
2. **Billing ki screen par zaati maloomat ka khayal.** WhatsApp ki settings wali screen par yehi
   ghalti ho chuki thi: 7 roles safha khol sakte thay aur card me malik ke zaati mobile numbers likhe
   thay. Billing me raqamein hain — gate pehle, screen baad me.
3. **Invoice number tarteeb-waar aur nazar aane wala hai.** Ghalat invoice banane ka matlab use
   `void` karna hai, mitana nahi — warna ginti me soorakh ho jayega.
4. **WhatsApp settings screen par rate dikhana lazmi hoga.** Abhi malik khud numbers daal sakta hai
   aur screen sirf kehti hai "har number ka alag message". 9.85 ke baad likha ho:
   *"7 numbers × PKR 9.85 = PKR 68.95 per report ≈ PKR 2,068/mah"*. Warna mahine ke aakhir me jhagRa
   hoga, aur **haq tenant ka hoga.**
5. **`tawakalkashif`: WhatsApp ON hai, 1 number para hai, magar koi active schedule nahi.** Wahan se
   kuch nahi jata. Billing ke baad ye aur uljhega (ON dikhega, bill sifar). Pehle tay ho.

---

## 9. Malik ke jawab ka intezar — in ke baghair P2 nahi chalega

1. **kashiffood ke do adaegi-shuda invoice kis mahine ke thay?** Account 24 Aug ko chala, cycle ab
   **1 tareekh** hai. Do 35,000 — 1 Sep aur 1 Oct? Ya Aug aur Sep? Aur cycle **kab** 1 tareekh bani?
2. **tawakalkashif ne ab tak kitne invoice diye?** Shart maloom hai (20,000, har 20 tareekh), adaegi
   ki tafseel nahi. Account 5 Sep ko chala — to 20 Sep wala invoice diya ya nahi?
3. **20 tareekh wala invoice kis muddat ka hai** — **aage** wale mahine ka (20 Oct – 19 Nov) ya
   **peechhe** wale ka (20 Sep – 19 Oct)? Ye har invoice ki muddat tay karta hai.
4. Adhoore pehle mahine par **pro-rata** ya poora mahina? (khatri 9 Aug ko chala, pehla invoice 20 Aug)
5. **Invoice banne ka din aur adaegi ka din — kya ye waqai do alag cheezein hain?** Malik ne 09-10 ko
   do set bheje: pehle 20/20/1/20, phir "ye wo tareekhein hain jab auto invoice banni chahiye"
   15/20/25/10. Agar dono sahi hain to invoice adaegi se 0–10 din pehle banti hai (section 2 ka
   naqsha). Agar naya set purane ki jagah leta hai to invoice aur adaegi dono usi din hain. **Is ke
   baghair cron ka din tay nahi ho sakta.**

---

## 10. Jo mai ne CHHUA NAHI

Is plan ko likhte waqt prod par **sirf parha** gaya. Koi invoice nahi bani, koi rate nahi likha, koi
tareekh nahi badli, `report_schedule_runs` ko haath nahi lagaya.

Jo mutations is session me hue wo alag the aur malik ne kahe the: WhatsApp template v2 par switch
(`.env`), aur 8 Oct wali report ka dobara bhejna (14/14 qubool).
