# WHATSAPP-REPORT-CHANNEL-1 — report WhatsApp par, aur "Email" ki jagah "Send"

**Tareekh:** 2026-09-30
**Halat:** PLAN — koi code nahi likha, prod par koi tabdeeli nahi

---

## 1. Maqsad

Malik ko report WhatsApp par pohanche — aur ye faisla AIK JAGAH ho, har button me alag alag nahi.

Malik ki baat: *"email ki jaga send to owner kardo, bs jo bhi channel option ho us se jaye, filhal
WhatsApp open kardo."* Yani button ka naam channel ka naam na ho — button kahe **"Send to owner"**,
aur kaun se raaste se jayega ye setting tay kare.

**Ye email ki jagah nahi le raha.** Shuru me samjha gaya tha ke email toota hua hai; jaanch par nikla
ke nahi:

| tenant | 28-29 Sep ki run | last_failure |
|---|---|---|
| khatribiryani | last_success_at == last_run_at | khaali |
| kashiffood | last_success_at == last_run_at | khaali |

MAIL_MAILER=smtp (log nahi). **Email chal raha hai.** Jo waqai toota hai wo alag hai —
pos_tenant_kashifkitchen.jobs table maujood nahi, is liye catering ki *queued* emails queue hi nahi hotin.

Asli wajah sada hai: **malik WhatsApp par rehte hain, email nahi kholte.**

---

## 2. Report TEEN jagah se jati hai — teenon badalni hain

Ye plan ka sab se aham inkishaf hai. Shuru me sirf cron nazar aaya tha; dekhne par teen nikle:

| # | jagah | kaun dabata hai | abhi |
|---|---|---|---|
| 1 | PosQuickReportController:161 | POS ka **Quick Report -> "Email to owner"** | Mail::to(recipients) |
| 2 | SalesReportCenterController:334 | Report Center ka email button | Mail::to(recipient) — owner_email |
| 3 | ReportScheduleService:133 | **cron** (rozana 02:30) | Mail::to(recipients) |

Teenon SalesReportMail bhejte hain, aur teenon apna recipient alag tareeqe se nikalte hain.

**Sirf aik ya do badalna sab se buri soorat hogi:** malik ko kuch reports WhatsApp par aur kuch email par
milengi, aur wajah kisi ko samajh nahi aayegi. Isi liye teenon aik hi `ReportDispatcher` se guzrenge.

---

## 3. Channel ka faisla kahan rahega — BRANCH pehle, TENANT fallback

Malik ki hidayat: *"branch level pe setting daldo."* Data bhi is ke haq me hai — `branches` par `phone`
aur `email` pehle se maujood hain.

Magar aik pabandi hai jo design tay karti hai: **`report_schedules` par `branch_id` hai hi nahi.** Cron
wali report poore tenant ki hai. To branch-level setting cron par lag hi nahi sakti.

Is liye do satah, branch ko tarjeeh:

```
channels  =  branch.report_channels  ??  tenant.report_channels  ??  ["email"]
whatsapp  =  branch.report_whatsapp  ??  tenant.owner_whatsapp
email     =  branch.email            ??  tenant.owner_email
```

```
branches: + report_channels   (json, nullable — NULL matlab tenant se lo)
          + report_whatsapp   (json, nullable — KAI numbers)

tenants:  + report_channels   (json, default ["email"])
          + report_whatsapp   (json, nullable — KAI numbers)
```

**Numbers KAI hote hain, aik nahi.** Malik ne bataya: *"har tenant ke 2-3 numbers honge."* Is liye ye
json hai, string nahi — bilkul jaise `report_schedules.recipient_emails` pehle se json hai. Agar isay
aik string rakh liya jata to baad me doosri migration chahiye hoti.

⚠️ **Number ki shakl:** Meta `923001234567` maangta hai — bina `+`, bina `0`, bina space ya dash.
Log likhte `0300-1234567` hain. Is liye save karte waqt aik normaliser lagega (space/dash hatao,
shuru ka `0` -> `92`, `+` hatao). Bina is ke number theek lagta hai magar message chupchap girta
rehta hai — aur ye wo nakami hai jo kisi screen par nazar nahi aati.

| rasta | branch maloom? | kis ki setting |
|---|---|---|
| Quick Report (POS) | haan — POS hamesha aik branch par hota hai | **branch**, phir tenant |
| Report Center | agar branch filter laga ho | **branch**, warna tenant |
| Cron | nahi — schedule tenant-wide hai | **tenant** |

Faida: **Tawakal** jaisa do-branch tenant har branch ki report alag number par bhej sakta hai; Khatri /
Kashif Food / Kashif Kitchen jaise single-branch par aik dafa tenant level par set kar dena kaafi hai.

`branches.phone` ko WhatsApp ke liye istemal NAHI kar rahe — wo branch ka aam rabta number hai, zaroori
nahi ke us par WhatsApp ho. Alag column saaf rakhti hai.

**Default `["email"]`** — jo branch/tenant kuch tay na kare, us ka bartaao aaj jaisa hi rahe (guard 3).

### Button ka naam
`Email to owner` -> **`Send to owner`** (pos/index.blade.php:1688), icon ti-mail se ti-send.
Report Center ka button bhi wohi.

---

## 4. Aik bug jo abhi maujood hai aur do channel par phategi

ReportScheduleService::runDue() (sirf cron wala rasta):

```php
$claimed = ...->insertOrIgnore(['report_schedule_id'=>..., 'period_key'=>...]);
if ($claimed === 0) return 'skipped_already_sent';   // unique(schedule, period)
try   { Mail::to($recipients)->send($mail); ... }
catch { ...->where(...)->delete();   // claim chhoR do taake agli tick retry kare
        ...update(['last_failure'=>...]); }
```

Aik channel par ye theek hai — yehi aaj double-send rokti hai. **Do channel par ulta paR jata hai:**

> Email chala gaya -> WhatsApp fail -> catch claim DELETE -> agli tick par **email DOBARA**.

Aur ye khamoshi se hoga: last_failure me WhatsApp ki ghalti likhi hogi, magar shikayat *"email do baar
aa rahi hai"* ki aayegi — do cheezein jo aapas me joRna mushkil hai.

**Is liye pehla kaam code nahi, schema hai:**

```
report_schedule_runs:  + channel  (email | whatsapp)
                       unique(report_schedule_id, period_key, channel)
```

Har channel apni claim le, apni nakami par sirf APNI claim chhoRe.
**Ye qadam sab se pehle.** Isay baad ke liye chhoRna is poore kaam ka sab se mehnga bug hai.

> Do button wale rastay (1 aur 2) is se azad hain — wahan malik ne khud dabaya hai, koi claim nahi.

Aik aur: recipients() abhi FILTER_VALIDATE_EMAIL se guzarta hai aur khaali par RuntimeException phenkta
hai — WhatsApp-only tenant wahin mar jayega.

---

## 5. Parchi me kya jayega: PDF nahi — khulasa + link

Teen wajah: phone par A4 PDF parhna takleef-deh hai; media template ki approval sakht hai; document
bhejna mehnga aur zyada nakaam hota hai.

```
{{1}} — {{2}}
Net sales: {{3}}
Orders: {{4}} · Cash: {{5}}
Poori report: {{6}}
```

> Kashif Food — 29 Sep 2026
> Net sales: 464,561
> Orders: 212 · Cash: 466,321
> Poori report: https://kashiffood.bingoopos.com/r/9f3a...

Category **Utility** (Marketing nahi — mehngi bhi, reject bhi zyada).

**Aik hi template teenon rastay chalayega.** Quick Report me malik jo categories chunta hai wo template
me nahi aa sakti (variables gine-chune hain) — wo tafseel LINK me jayegi, aur link me wohi filters honge
jo us ne chune the. Isi liye link wala design in teen rastay ke liye zyada munasib hai.

### Link par kya khulega — safha AUR download, dono

Malik ki farmaish: *"link esi bejna, click karte he download ho jaye aur open bhi ho saath saath."*

Aik HTTP jawab ya dikhata hai ya download karta hai — dono aik saath nahi. Magar nateeja wohi diya ja
sakta hai:

1. **Safha khulta hai** — mobile ke liye bana, bare aankray foran parhe jayen. (A4 PDF phone par kholna
   takleef-deh hai: harf itne chhote ke zoom kiye baghair kuch nazar nahi aata. Isi liye safha pehle.)
2. **PDF ka download** safha khulte hi khud shuru ho jaye.
3. **Aur aik baRa "Download PDF" button** bhi — hamesha.

⚠️ Teesra nuqta **fallback nahi, zaroorat hai.** iOS Safari aur kuch Android browsers khud-ba-khud
shuru hone wale download ko rok dete hain ya ijazat maangte hain. Sirf auto par bharosa keya jaye to
kuch maalikaan ke phone par kuch bhi na hoga — aur unhe pata bhi na chalega ke kuch reh gaya. Button
hone se har soorat me aik tap me PDF mil jata hai.

PDF ka apna raasta bhi **usi signed link** ke peeche rahega — warna safha to mehfooz hota aur PDF khula
paRa hota.

### Link signed aur expiring hona LAZMI hai
Warna jis ke paas link chala jaye — forward, screenshot, purana chat — wo us tenant ki poori bikri dekh
lega. URL::temporarySignedRoute(), muddat 48 ghante.

---

## 6. Code ka naqsha

| file | kaam |
|---|---|
| migration (master) | tenants.owner_whatsapp, tenants.report_channels |
| migration (tenant) | report_schedule_runs.channel + naya unique |
| ReportDispatcher | **teenon rastay ka aik darwaza** — channels par loop |
| ReportChannel (interface) | send(recipient, payload) |
| EmailChannel | jo abhi teen jagah bikhra hai, wo yahan |
| WhatsAppChannel | template + parameters |
| WhatsAppClient | akela Http::post(), retry, saaf ghalti |
| PosQuickReportController | Mail::to(...) -> ReportDispatcher |
| SalesReportCenterController | wohi |
| ReportScheduleService::runDue() | wohi + per-channel claim |
| pos/index.blade.php:1688 | "Email to owner" -> **"Send to owner"** |
| signed route | /r/{token} — 48 ghante |

.env (prod):
```
WHATSAPP_PHONE_NUMBER_ID=1335895766273271
WHATSAPP_TOKEN=...      <- sirf yahan. Kabhi chat/email/WhatsApp par nahi.
```

---

## 7. Guards

1. **Aik channel fail, doosra kamyab -> kamyab wala DOBARA na jaye** (§4 ka jaal — sab se ahem).
2. **Nakaam channel agli tick par dobara koshish kare** — retry marna nahi chahiye.
3. **Jis tenant ne report_channels set nahi ki, us ka bartaao byte ke barabar wohi rahe.** Chaaron live
   tenants par aaj yehi chal raha hai; is kaam ka un par koi asar nahi paRna chahiye.
4. **Teenon rastay aik hi dispatcher se guzren** — report ke liye koi Mail::to(...) baaqi na rahe. Guard
   ise grep se sabit karega, warna aik rasta chhoot jayega aur malik ko aadhi reports email par aayengi —
   theek wo soorat jis se bachna hai.
5. **WhatsApp-only tenant email ki validation par na mare** (§4 ka FILTER_VALIDATE_EMAIL).
6. **Signed link** bina signature 403, aur 48 ghante baad bhi 403.
7. **Aik tenant ka link doosre tenant ka data na dikhaye.**
8. **Token kabhi log me na aaye** — na exception me, na last_failure me. Meta ki ghalti ka jawab poora
   request echo karta hai; usay seedha log karna token leak kar dega.

---

## 8. Risk

| khatra | haqeeqat |
|---|---|
| duplicate email | §4 ka jaal — guard 1, 2, 3 |
| koi rasta chhoot jaye | guard 4 — sab se aasan ghalti, teen jagah hain |
| bikri ka data leak | signed + expiring link, guard 6 aur 7 |
| token leak | guard 8; token sirf .env me |
| paisa | Utility PKR 2.79/msg; ~120 msg/mah = PKR ~335. Koi subscription nahi |
| POS / stock / journal | **kuch nahi** — sirf report bhejne ka rasta; koi order, stock ya ledger nahi chhuta |
| malik ka jawab | number ab API par hai — reply kahin nahi pohanchega jab tak webhook na bane. Matn me likha jayega ke ye khud-kar report hai, aur rabte ka asli number diya jayega |

**Palatna:** tenant ki report_channels se whatsapp nikaal dein — sab kuch aaj jaisa. Migrations additive.

---

## 9. Tarteeb

1. **Schema:** per-channel claim (§4) + tenant ke do naye columns — pehle, apne guards ke saath
2. **ReportDispatcher + EmailChannel** — teenon rastay isi par laayein, bartaao bilkul na badle (guard 3, 4)
3. **WhatsAppClient + WhatsAppChannel**
4. **Signed report link** (guard 6, 7)
5. **Button ka naam** "Send to owner"
6. **Meta par daily_sales_report template** banana aur approve karwana
7. **Aik tenant par chala kar dikhana**, phir baqi

Qadam 2 ke baad bhi sab kuch bilkul aaj jaisa chalta rahega — WhatsApp qadam 3 par zinda hota hai.
Isi liye ye tarteeb: **har qadam ke baad system chalta hua rahe.**

**Qadam 6 malik ke faisle ka muntazir hai** — template approve hone ke baad matn badalna dobara approval
maangta hai, is liye §5 ka matn pehle pakka karna hai.

---

## 10. Rollout — kis tenant par kya

| tenant | report_channels | kitne numbers |
|---|---|---|
| **kashiffood** | `["email","whatsapp"]` | 2 |
| **khatribiryani** | `["email","whatsapp"]` | 4 |
| **tawakalkashif** | **BADALNA NAHI** | — |
| **kashifkitchen** | **BADALNA NAHI** | — |

🔒 **Asli numbers yahan JAAN BOOJH KAR nahi likhe.** Ye maalikaan ke zaati mobile numbers hain;
unhe git me daalna aur GitHub par bhej dena theek nahi. Wo alag, local jagah par mehfooz hain
(session memory: `reference_whatsapp_report_recipients`). Settings lagate waqt wahan se lena.

Malik ne saaf kaha: Tawakal aur Kashif Kitchen par **kuch nahi badalna**. Un ki `report_channels`
NULL rahegi, yani default `["email"]` — aaj jaisa, bilkul waisa.

⚠️ Ye prod par tabhi lagengi jab migrations chal jayen — us se pehle columns maujood hi nahi.

### Paise ka hisaab
WhatsApp har **wasool karne wale** ka alag message ginta hai: 6 numbers = 6 messages har report par.
Meta ke apne meter se **$0.01/message** (2 messages par $0.02 dikha) = ~180/mah = **PKR ~500/mah**.
BSP ki PKR 14,000/mah subscription se 28 guna kam — "Cloud API seedha" wala faisla qaayam.

### Ek baat jo bata deni chahiye
In par rozana **bikri ke aankray** jayenge. Maqsad yehi hai — magar iska matlab ye bhi hai ke jis ke
paas wo phone hai wo us tenant ki sales dekh sakta hai. Isi liye parchi me sirf khulasa jata hai aur
poori tafseel **signed + expiring link** ke peeche rehti hai (§5).
