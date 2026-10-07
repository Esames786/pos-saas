# WEBSITE-I18N-GEO-1 — bingoopos.com: Arabic, mulk ke hisaab se qeemat, cookies, aur yearly ka bug

Date: 2026-10-07 · Scope: sirf public website (`bingoopos.com`) — POS / back office ka Arabic alag track hai.
Pehla nishana: **Saudi Arabia**.

---

## 0. Abhi kya hai (code aur prod se parh kar, kuch likha nahi)

| Cheez | Halat |
|---|---|
| Public website | 12 Blade safhe + layout, ~3,070 lines. **Ek bhi `__()` nahi** — har jumla English me hard-coded. |
| Translation files | `resources/lang/en/` me sirf 4 files (auth, common, dashboard, sidebar). **Arabic file koi nahi.** |
| Zaban badalna | `SetLocale` middleware session se `en/ar/ur` leta hai; switch sirf login ke andar (`/locale/{x}`). Website par koi switcher nahi. |
| RTL | Layout `dir="rtl"` pehle se lagata hai, **magar CSS LTR wali hi load hoti hai** (`bootstrap.min.css`). `bootstrap.rtl.min.css` `public/assets/css/` me pehle se rakhi hai. |
| Qeemat | Har plan ki **ek hi currency** (`plans.currency_code` = PKR). `monthly_price` / `yearly_price` columns hain (yearly = ×10 pehle se: 30,000 / 80,000 / 70,000 / 150,000). |
| Subscription | `subscriptions` me na `billing_period`, na currency, na qeemat ki copy. Invoice (`subscription_invoices`) me `currency_code` hai. |
| Mulk pehchanna | Prod par nginx seedha (Cloudflare nahi), koi GeoIP extension ya database nahi. PHP `intl` hai. |
| Cookies | Sirf do: `XSRF-TOKEN` aur `bingoo-pos-session` — dono zaroori (form aur login ke liye). Koi Google Analytics / Pixel nahi. |
| **Yearly bug** | Pricing ka Monthly/Yearly button sirf CSS badalta hai. "Start Trial" link me sirf `?plan=` jata hai. Checkout (`start-trial.blade.php:66-67`) hamesha `monthly_price` aur "per month" dikhata hai. |

**Yearly ka fix pehle se bana hua hai** — branch `feat/cloud-billing-onboarding-v1`, commit `1029f2c` (CLOUD-BILLING-2):
`?billing=yearly` pricing → link → form → validation → `subscriptions.billing_period`. Ye 14 Aug ko bana,
**kabhi merge nahi hua** (8 commits aage, prod branch se 623 peeche). Ye `BillingPeriodResolver` par khara hai
jo usi branch ke `9480515` (1B) me aaya.

---

## 1. "Allow cookies" — kya hai, aur hamein kya milega

**Cookie** = ek chhoti si yaad jo website visitor ke browser me rakhti hai. Do qism:

| Qism | Misaal | Ijazat chahiye? |
|---|---|---|
| **Zaroori** | login session, form ka security token, "is ne Arabic chuni thi", "is ne cookie ka jawab de diya" | **Nahi** — in ke baghair site chalti hi nahi |
| **Analytics** | Google Analytics: kitne log aaye, kis mulk se, kis safhe par chhod gaye | **Haan** |
| **Marketing** | Meta / Google / Snapchat / TikTok Pixel: jo site dekh kar chala gaya use baad me ad dikhana | **Haan** |

**Banner khud kuch nahi deta.** Wo sirf **ijazat ka darwaza** hai. Faida us se aata hai jo ijazat ke baad lagta hai:
- **Analytics:** Saudi se kitne log aaye, kitno ne pricing dekhi, kitno ne trial shuru kiya → kahan paisa lagana hai.
- **Retargeting ads:** jo Saudi visitor pricing dekh kar chala gaya, use Snapchat/Instagram par dobara dikhana
  (Saudi me Snapchat bohat bara hai).
- **Qanoon:** Saudi ka **PDPL** (Personal Data Protection Law) ghair-zaroori personal data ke liye ijazat maangta hai;
  UAE ka bhi apna qanoon hai; Europe se koi aaye to GDPR. Banner = in ke saamne saaf rehna, aur bharosa.

**Aaj ki halat me** hamare paas sirf zaroori cookies hain, is liye banner abhi qanoonan lazmi nahi.
**Jis din Analytics ya Pixel lagega, us se pehle banner lazmi.** Is liye dono saath banenge (Phase 3).

Banner kaisa: neeche ek patti, visitor ki zaban me — **"Accept all" / "Necessary only" / "Settings"**.
Analytics/Pixel ka script **ijazat milne ke baad hi load ho** (Google Consent Mode v2). Jawab cookie `bingoo_consent`
(12 mahine) me; footer me "Cookie settings" se badla ja sake. Privacy page me cookies ka hissa.

---

## 2. Zaban ka nizaam ("language manager")

### 2.1 URL: `/ar/...` — sirf session nahi
- English wahi rahe: `bingoopos.com/pricing` (koi purana link na toote).
- Arabic: `bingoopos.com/ar/pricing`, `/ar/start-trial`, waghaira — **wahi controller, wahi view**.
- **Kyun URL:** Google har zaban ko alag tab hi index karta hai jab us ka apna URL ho. Session par chalne wali Arabic
  Google ko kabhi nazar nahi aati → Saudi me "نظام نقاط البيع" search karne wala hamein nahi paata.
- Har safhe par `<link rel="alternate" hreflang="ar">`, `hreflang="en"`, `x-default`; canonical apni zaban ka.
- Navbar me switcher: **English | العربية** → cookie `bingoo_lang` (1 saal).

### 2.2 Files aur config
- `resources/lang/en.json` + `resources/lang/ar.json` (key = English jumla) — website ke liye sab se saada aur Laravel ka apna tareeqa.
- `config/saas.php` me `enabled_locales` pehle se hai → har zaban ka naam, apna naam, `dir`, font yahin.
- Arabic par: `bootstrap.rtl.min.css` + Arabic font (Google Fonts: **IBM Plex Sans Arabic** ya **Tajawal**); icons/teer
  jo "aage" ishara karte hain wo palatne.
- Naya mulk/zaban = config me ek line + ek JSON file. Code nahi.

### 2.3 Translation kaun karega — "90% theek, muft"
**Mashwara: Claude khud tarjuma kare aur file me likhe** — bilkul wahi jo Arabic video me hua tha
(`tools/demo-video/voice.json` ka Arabic matn Claude ne likha tha; Edge TTS ne sirf bola tha).

| Tareeqa | Muft? | Mayaar | Masla |
|---|---|---|---|
| **Claude, ek dafa, file me** ✅ | haan | marketing ke liye acha; lehja, glossary, POS ki zaban samajhta hai | ek native nazar chahiye |
| DeepL API Free | 500k huroof/mahina | acha | signup par card; glossary mehdood |
| Google Cloud Translation | 500k huroof/mahina | theek | billing account lazmi |
| LibreTranslate (khud chalao) | haan | kamzor Arabic | VPS par bhaari |
| Har visitor ke liye live tarjuma | — | — | **kabhi nahi:** dheema, mehnga, "POS"/"KOT" jaise lafz bigaarta hai |

Tarjuma **ek dafa** hota hai aur git me rehta hai — na API key, na mahina ka bill, na har request par kharcha.
Saath me `docs/i18n/glossary-ar.md`: Bingoo = بينجو, POS = نقاط البيع, branch, terminal, trial, VAT… taake har safha ek hi lafz bole.
**Ek native Saudi reader ek dafa parhe** (khas taur par pricing, checkout, aur legal safhe). Legal safhon par likha ho:
"Arabic tarjuma suhoolat ke liye hai; ikhtilaf me English mo'tabar hai".

### 2.4 Koi jumla bina tarjume ke na jaye (guard)
- `php artisan lang:audit` — public views me har `__('...')` dhoondh kar batata hai kaun sa jumla kis zaban me nahi hai.
- Test: **koi bhi enabled zaban me koi key gum ho to test laal.** Yani naya English jumla Arabic ke baghair deploy nahi ho sakta.
- Baad me (zaroorat ho to): Super-admin me "Languages" screen jahan bina developer ke lafz badle jayein. **Abhi nahi.**

---

## 3. Mulk pehchanna (geo detection)

### 3.1 Kaise
- Visitor ke IP se **mulk**, server par rakhi ek database file se: **DB-IP "IP to Country Lite"** (muft, signup nahi,
  CC BY 4.0 — footer me ek line credit) ya **MaxMind GeoLite2 Country** (muft, account + license key).
  Parhne ke liye composer package `geoip2/geoip2` (dono file parhta hai).
- File chand MB, `storage/app/geoip/`; mahine me ek dafa cron se nayi (as `www-data`).
- Lookup microseconds me, **kisi bahar ki service ko call nahi** → visitor ka data kahin nahi jata (PDPL ke liye bhi acha).
- Prod par nginx seedha hai, is liye `$request->ip()` asli IP hai. Kabhi Cloudflare lagaya to `CF-IPCountry` header le lena.

### 3.2 Shehar (city) — mashwara: nahi
Shehar se na zaban badalti hai na qeemat. City database bari hai aur shehar ka andaza aksar ghalat hota hai.
Kabhi "qareebi sales rep" jaisi cheez chahiye ho to tab.

### 3.3 Qaide (sirf pehli dafa, jab visitor ne khud kuch nahi chuna)
| Sawal | Faisla kaun karta hai |
|---|---|
| Zaban | 1) visitor ka chuna hua (`bingoo_lang`) 2) URL (`/ar`) 3) mulk Middle East me → Arabic 4) English |
| Currency | 1) visitor ka chuna hua (`bingoo_currency`) 2) mulk ki currency 3) USD |

- Zaban aur currency **alag** hain: Riyadh me English parhne wala bhi SAR dekhe; Pakistan me Arabic chunne wala PKR dekhe.
- Redirect sirf **pehli dafa, GET, `/` jaise landing safhon par**, cookie lag jati hai, phir kabhi nahi.
- **Googlebot aur doosre bots kabhi redirect nahi** (wo America se aate hain — warna Arabic safha kabhi index na ho).
- Switcher hamesha nazar aaye — ghalat andaze se koi phansay nahi.

---

## 4. Har mulk ki apni qeemat

### 4.1 Qeemat **rakhi jaye**, convert na ki jaye
- Naya master table **`plan_prices`**: `plan_id`, `currency_code`, `monthly_price`, `yearly_price`, `is_active`,
  unique(`plan_id`, `currency_code`). Migration maujooda PKR qeematein isi me copy kare; `plans.monthly_price` filhaal fallback.
- **Live exchange rate se nahi:** PKR 8,000 seedha convert ho kar SAR 106.67 jaisa ajeeb number banta hai, rate roz badalta
  hai, aur jo qeemat dikhayi wahi invoice par aani chahiye. Owner har currency ki qeemat khud rakhe (SAR 149, 299…).
- Central admin ki Plan screen par "Prices by currency" ka grid.

### 4.2 Mulk → currency
PK → PKR · SA → SAR · AE → AED · QA → QAR · KW → KWD · BH → BHD · OM → OMR · baqi duniya → USD.
**Shuru me sirf PKR, SAR, AED, USD** — baqi config me ek line. Jis currency ki qeemat na ho → USD; USD bhi na ho → us plan
par "Contact sales".

### 4.3 Dikhana
- `intl` ka `NumberFormatter`. Arabic safhe par **Western digits** (`ar-SA-u-nu-latn` → "149 ر.س"), kyunke Saudi web
  par yahi aam hai aur Arabic-Indic digits qeemat ko parhne me mushkil banate hain.
- Pricing safhe par currency ka dropdown.
- **VAT:** Saudi me 15% VAT. "VAT ke saath" ya "VAT alag" — owner/accountant ka faisla (neeche).

### 4.4 Signup par
- Form `currency` + `billing` bhejta hai, magar **raqam kabhi form se nahi** — backend `plan_prices` se dobara nikalta hai
  (cloud branch ka bhi yahi qaida).
- `subscriptions` me `billing_period`, `currency_code`, aur **us waqt ki qeemat ki copy** — taake owner baad me qeemat
  badle to purane customer ka bill na badle. Invoice usi currency me.

### 4.5 Asal rukawat: SAR me paisa kaise aayega
Trial muft hai, is liye Saudi customer signup aaj bhi kar sakta hai. Magar maujooda tareeqe
(EasyPaisa / JazzCash / NayaPay — cloud branch) **sirf PKR** ke hain. Saudi customer ke paas pay karne ka koi rasta nahi.
Ye code ka nahi, **business ka faisla** hai (koi payment gateway jo SAR le aur aap tak pohnchaye, ya bank transfer).
Phase 1–3 is ke baghair chal sakte hain; trial khatam hone se pehle iska hal chahiye.

---

## 5. Yearly ka bug (checkout par "per month")

**Wajah:** upar Section 0 — toggle sirf CSS, link me period nahi, checkout sirf `monthly_price`.

**Hal:** cloud branch ka `1029f2c` + `BillingPeriodResolver` (`9480515` se) prod branch par lana:
- Pricing ka toggle har "Start Trial" link me `&billing=yearly` daale (JS ke baghair bhi `?billing=yearly` chale).
- Checkout par chuni hui muddat: **"PKR 80,000 per year — 2 months free"** + "PKR 6,667/month equivalent"; form me
  Monthly/Yearly radio (badalne par qeemat badle).
- `subscriptions.billing_period` (additive migration, default monthly).
- Pehle us branch ka test parhunga: agar wo 1B ki invoice par tika hai to sirf do commit nahi, poori branch rebase karni hogi
  (us me payment methods aur emails bhi aate hain jin ke liye asal account details chahiye) — wo faisla aap ko dikha kar.

---

## 6. Phases (har ek alag deploy, alag tests)

| Phase | Kya | Kyun is tarteeb me |
|---|---|---|
| **P0** | Yearly bug (Section 5) | Aaj ghalat qeemat dikh rahi hai; chhota, pehle se bana hua |
| **P1** | Zaban ki buniyad: `/ar` routes, switcher, RTL CSS + font, hreflang, `en.json`/`ar.json`, 12 safhe + layout `__()` me, `lang:audit` + guard test, Claude ka Arabic + glossary | Saudi visitor ko pehle Arabic chahiye |
| **P2** | Mulk + currency: GeoIP file + cron, `plan_prices` + admin grid, currency dropdown, pricing/checkout, subscription me copy | P1 ke baad Arabic safhe par SAR |
| **P3** | Cookie banner (en/ar) + GA4 Consent Mode v2 (+ jo pixel aap chunein), footer "Cookie settings", privacy page | Analytics/ads se pehle lazmi |

---

## 7. Saboot jo har phase de ga

- **Asal safhe render**, asal controller se, `en` aur `ar` dono: `dir="rtl"`, RTL CSS laga, koi English jumla bacha nahi
  (sirf brand names ki ijazat wali list).
- **Missing-key guard** — jaan-boojh kar ek jumla `ar.json` se nikal kar sabit karna ke test laal hota hai.
- **Geo:** country resolver ko interface ke peeche rakh kar test me fake mulk: SA → Arabic + SAR; chuni hui cookie IP se
  jeete; Googlebot redirect na ho; POST/`start-trial` par kabhi redirect nahi.
- **Currency:** SAR visitor ko SAR; form me chheri hui raqam nazarandaz; subscription me SAR + yearly + qeemat ki copy.
- **Cookies:** "Accept" se pehle HTML me Analytics ka script **nahi**; baad me hai.
- Headless Chrome me `/ar/pricing` aur `/ar/start-trial` ki tasveer — RTL ankh se dekhna.
- Har Blade change compile + lint; poora suite.

---

## 8. Aap ke faisle (kaam shuru karne se pehle)

1. **SAR (aur AED, USD) me har plan ki mahana qeemat** kya ho? (Yearly = ×10, jaisa PKR me.)
2. **Kaun se mulk Arabic par khulein** — sirf Saudi, ya sab GCC? (UAE/Qatar me aksar log English pasand karte hain;
   mera mashwara: Saudi hamesha Arabic; UAE/Qatar me browser English ho to English.)
3. **VAT:** qeemat "VAT ke saath" dikhayein ya "+ 15% VAT"?
4. **Saudi customer pay kaise karega** (Section 4.5)?
5. **Arabic kaun parhega** — koi Saudi contact jo ek dafa nazar daal de?
6. **Analytics:** sirf GA4, ya Meta / Snapchat / TikTok pixel bhi?
7. **Cloud-billing branch:** sirf yearly wala hissa laayein, ya poori branch (payment methods + auto invoice + emails)?

## Kya NAHI badlega
- POS / back office ki zaban (alag, bara track — `resources/lang` me sirf 4 English files hain).
- Purane English URLs.
- Login ke andar ka `/locale/{x}` switch.
- Koi tenant ka data, koi deploy — jab tak aap na kahein.
