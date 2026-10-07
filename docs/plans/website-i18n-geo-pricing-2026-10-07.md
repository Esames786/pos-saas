# WEBSITE-I18N-GEO-1 — bingoopos.com: Arabic, har mulk ki qeemat (per branch), custom plan, cookies, yearly bug

Date: 2026-10-07 (v2 — owner ke faislon ke baad) · Scope: public website + signup + subscription limits.
POS / back office ka Arabic **alag track** hai (Section 11).
Pehla nishana: **Saudi Arabia**, saath UAE, Qatar, USA. **Pakistan jaisa chal raha hai waisa hi rahega.**

---

## 0. Owner ke faisle (7 Oct)

| # | Faisla |
|---|---|
| 1 | **Pakistan bilkul jaisa hai** — chaar bundle plans, PKR, monthly + yearly. Sirf yearly ka bug theek. |
| 2 | **Saudi, UAE, Qatar, USA = per-branch qeemat**, market research ke hisaab se, website par dikhe. |
| 3 | **Custom plan banane ka option** — user branches (aur terminals) barhaye, total saath saath barhe. |
| 4 | Monthly aur yearly dono, PKR jaisa (yearly = ×10, "2 months free"). |
| 5 | **Payment gateway baad me.** Abhi sab free trial lete hain; checkout par paisa nahi liya jata. |
| 6 | Zaban wala poora kaam karna hai — is MD me poora design. |

---

## 1. Abhi kya hai (code aur prod se parh kar, kuch likha nahi)

| Cheez | Halat |
|---|---|
| Public website | 12 Blade safhe + layout, ~3,070 lines, **ek bhi `__()` nahi**. Andaza ~570 jumlon ki lines (home 128, pricing 78, features 69, layout 54, demos 46, start-trial 40, legal ~100). |
| Translation files | `resources/lang/en/` me sirf 4 files. **Arabic koi nahi.** Validation messages bhi sirf Laravel ke English. |
| Zaban badalna | `SetLocale` session se `en/ar/ur`; switch sirf login ke andar (`/locale/{x}`). Website par switcher nahi. |
| RTL | Layout `dir="rtl"` lagata hai magar **LTR `bootstrap.min.css`** load karta hai (`bootstrap.rtl.min.css` maujood). **29 jagah** custom CSS me `left/right` (home 11, layout 5, …). Subdomain wala `input-group` ("your-subdomain .bingoopos.com") RTL me ulta ho jayega. |
| Qeemat | `plans` me ek currency (PKR), `monthly_price` / `yearly_price` (×10) maujood. |
| Limits | `plan_features`: `branch_limit`, `terminal_limit`, `user_limit`, `product_limit`. **Sab ek jagah lagti hain:** `TenantSubscriptionAccessService::featureLimit()` → `checkLimit()` (Branch/User/Terminal controllers ke 7 callers). |
| Subscription | `subscriptions` me na `billing_period`, na currency, na branches, na qeemat ki copy. `subscription_change_requests` (type `addon` maujood, magar quantity nahi). Invoice me `currency_code` + `invoice_type='addon'` maujood. |
| **Signup ka mulk** | `SelfSignupService` tenant ki currency PKR default; **pehli branch ka timezone `Asia/Karachi` hardcoded** (`TenantProvisioner.php:202`). Saudi customer ka business date / shift / report din ghalat chalega. |
| Mulk pehchanna | Prod par nginx seedha — **Cloudflare nahi, GeoIP nahi**. PHP `intl` hai. |
| Cookies | Sirf `XSRF-TOKEN` + `bingoo-pos-session` (zaroori). Koi analytics/pixel nahi. |
| Sitemap | `robots.txt` hai, `sitemap.xml` nahi. |
| **Yearly bug** | Toggle sirf CSS; "Start Trial" link sirf `?plan=`; checkout (`start-trial.blade.php:66-67`) hamesha monthly. Fix pehle se `feat/cloud-billing-onboarding-v1` `1029f2c` (+ `BillingPeriodResolver` from `9480515`) — kabhi merge nahi. |

---

## 2. Business model

### 2.1 Do bazaar, do tareeqe
| | Pakistan | Saudi · UAE · Qatar · USA (+ baqi duniya USD) |
|---|---|---|
| Model | **Bundle** — plan me branches shamil (jaisa aaj) | **Per branch** — har branch ka paisa |
| Kyun | Chal raha hai, customer isi ke aadi hain | Foodics, Rewaa, Square, Toast, Lightspeed sab per location bechte hain — customer isi hisaab se milata hai |
| Currency | PKR | SAR · AED · QAR · USD |
| Custom | Enterprise = Contact Sales (jaisa aaj) | **"Build your plan"** — branches + terminals khud chuno |

### 2.2 Hamari pehchan (website par yahi likhna)
- **Hardware ki qaid nahi** — koi bhi Windows PC / Android tablet + network printer. Foodics jaison me hardware package alag.
- **Card payment par hamara koi hissa nahi** — Square/Toast processing se kamate hain.
- **Saari qeematein website par** — Marn aur Foodics ke bohat se plans "contact sales".
- **30 din free trial, card ke baghair** (Rewaa 14 din).
- **Arabic + English** ek hi system me.

### 2.3 Qeemat ka qaida
Har qeemat = **us tier ka market average (per branch), 0–10% neeche, x9 par gol.**
Naye company ke liye average se thoda neeche — aur abhi ZATCA nahi (Section 2.8).

### 2.4 Per-branch qeemat (mahana, VAT alag)

| Plan | Har branch me shamil | SAR | AED | QAR | USD |
|---|---|---|---|---|---|
| **Retail Starter** | 1 terminal · 3 users | **189** | **199** | **199** | **69** |
| **Inventory Store** (Retail Pro) | 2 terminals · 5 users | **399** | **399** | **399** | **139** |
| **Restaurant Starter** | 2 terminals · 8 users | **219** | **219** | **199** | **59** |
| **Restaurant Pro** | 3 terminals · 10 users | **549** | **469** | **469** | **169** |

- **Yearly** = mahana × 10 per branch ("2 months free").
- **Products** ki hadd poore account ki (500 / 5,000 / 1,000 / 10,000) — branch se nahi barhti.
- Market average (per branch) jis se ye nikle: Retail Starter SAR 195 · AED 224 · USD 72 — Inventory SAR 447 · AED ~400 · USD 149 —
  Restaurant Starter SAR 230 · AED 224 · QAR ~198 · USD 59 — Restaurant Pro SAR ~570 · AED ~477 · USD ~175.
  Qatar ka retail data kamzor hai, is liye QAR = AED ke barabar (Gulf me qareeb qareeb ek jaisi qeemat).

### 2.5 Add-ons aur chhoot
| | SAR / AED / QAR | USD | Basis |
|---|---|---|---|
| **Extra terminal** (kisi bhi branch par) | 59 / mahina | 19 / mahina | Lightspeed USD 59/register; hum software-only hain, is liye kam |
| **3–5 branches** | 10% off | 10% off | chains ko kheenchne ke liye |
| **6–10 branches** | 15% off | 15% off | |
| **11+ branches** | Enterprise — Contact Sales | | |

### 2.6 Misaal (custom builder ka hisaab)
Restaurant Pro · Saudi · 4 branches · 2 extra terminals · yearly:
`(549 × 4) + (59 × 2) = 2,314` → 10% off (3–5 branches) = **SAR 2,082.60 / mahina** → yearly ×10 = **SAR 20,826 / saal**.
Is me: 4 branches · 14 terminals (4×3 + 2) · 40 users · 10,000 products.

### 2.7 Pakistan (bilkul wahi)
Retail Starter 3,000 · Inventory Store 8,000 · Restaurant Starter 7,000 · Restaurant Pro 15,000 PKR / mahina; yearly ×10;
Enterprise / Finance ERP = Contact Sales. Sirf yearly ka bug theek hoga (Section 7).

### 2.8 Khatre jo qeemat par asar rakhte hain
- **ZATCA (Saudi e-invoicing Phase 2):** Saudi ke sab competitors ke paas hai, hamare paas nahi (28 Sep ko chhoda). VAT-registered
  Saudi business ke liye ye zaroori hai. Jab tak nahi — qeemat average se neeche, aur website par **"ZATCA: coming soon"** saaf likhna,
  "compliant" kabhi nahi.
- **UAE e-invoicing** bhi aa raha hai — baad me.
- **VAT:** Saudi 15%, UAE 5% — website par "+ VAT" likha ho. Qatar me VAT nahi. USA me sales tax state ka.
- **Payment gateway baad me:** trial khatam hone par abhi manual (sales se rabta). Gateway aane tak trial customer se paisa lene ka
  tareeqa owner tay kare.

---

## 3. Website par kaise dikhega

### 3.1 Pricing safha — mulk ke hisaab se
- **Pakistan visitor:** aaj wala safha, bilkul wahi (bundle cards PKR me).
- **Gulf / USA visitor:** chaar cards — **"SAR 549 / branch / month"**, neeche "includes 3 terminals & 10 users per branch",
  yearly par "SAR 5,490 / branch / year — 2 months free", "+ VAT" (SA/AE).
- Upar currency dropdown: **PKR · SAR · AED · QAR · USD** — PKR chunne par bundle cards, baqi par per-branch.
- Sab se neeche Enterprise: "11+ branches, custom modules — Contact Sales".

### 3.2 "Build your plan" (custom)
Pricing safhe par alag hissa, aur har card par **"Customize"** button jo isi me us plan ke saath khulta hai:
1. **Business:** Retail / Restaurant
2. **Tier:** Starter / Pro
3. **Branches:** − [ 4 ] + (1–10; 11 par "Contact Sales")
4. **Extra terminals:** − [ 2 ] +
5. **Billing:** Monthly / Yearly
6. **Live hisaab:** branches × qeemat + terminals × qeemat − chhoot = **total / month** (yearly par / year) + kya kya shamil
7. **"Start 30-day free trial"** → `/start-trial?plan=restaurant_pro&market=sa&branches=4&terminals=2&billing=yearly`

Checkout par yahi khulasa dobara, wahan bhi badla ja sake. **Raqam kabhi URL ya form se nahi li jati** — server `plan_prices` se
dobara nikalta hai.

### 3.3 Trial ke dauran
Jitni branches/terminals chune, trial me utni hi khuli — taake multi-branch customer asal me azma sake.

---

## 4. Data aur code ka design

### 4.1 Naye / badle tables (sab additive)
| Table | Kya |
|---|---|
| **`plan_prices`** (master, naya) | `plan_id`, `currency_code`, `pricing_model` enum(`bundle`,`per_branch`), `monthly_price`, `yearly_price` (null = ×10), `extra_terminal_monthly`, `is_active`; unique(`plan_id`,`currency_code`). Migration PKR ki maujooda qeematein `bundle` rows me copy kare. |
| **`plan_features`** (nayi keys) | `terminals_per_branch`, `users_per_branch` — sirf per-branch model ke liye. |
| **`subscriptions`** (naye columns) | `billing_period` (cloud branch wala), `currency_code`, `pricing_model`, `branches_purchased`, `extra_terminals`, `price_snapshot` json (unit qeematein, chhoot %, total). Purane subscriptions par sab null = **bundle, aaj jaisa**. |
| **`tenants`** (naya column) | `locale` — owner ki zaban (emails usi me). |
| **`config/saas.php` → `markets`** | `pk` → PKR/bundle · `sa` → SAR/per_branch/Asia/Riyadh/ar · `ae` → AED/Asia/Dubai/ar · `qa` → QAR/Asia/Qatar/ar · `us` → USD/browser timezone/en · `default` → USD/en. Chhoot ki seerhiyan bhi yahin. |

### 4.2 Limits — ek hi jagah
`featureLimit()` me:
- `pricing_model = per_branch` → `branch_limit = branches_purchased`, `terminal_limit = branches × terminals_per_branch + extra_terminals`,
  `user_limit = branches × users_per_branch`, `product_limit` = plan ki.
- Warna (bundle / purane) → `plan_features` jaisa aaj.
Branch/User/Terminal ke saaton callers khud sahi ho jate hain. Khatri, Kashif, Tawakal par **koi farq nahi** (unke custom plans, null model).

### 4.3 Ek hisaab, ek jagah
`PlanPricingService` (cloud branch ke `BillingPeriodResolver` ko barha kar): `quote(plan, market, branches, terminals, billing)` →
unit qeematein, chhoot, total, shamil limits. Pricing safha, builder ka JS (server se aayi qeematein), checkout, signup, aur baad me invoice —
**sab isi se**. Builder ka JS sirf dikhata hai; faisla server ka.

### 4.4 Signup par mulk ki cheezein
`SelfSignupService` → tenant: currency (SAR…), `locale`; `TenantProvisioner` → pehli branch ka **timezone** market se
(USA me browser ka `Intl` timezone, server par PHP list se jaanch). `Asia/Karachi` hardcode khatam — PK ka default wahi rahe.

### 4.5 Baad me (P5): app ke andar branches barhana
Billing → **"Change branches"** → stepper → `subscription_change_requests` (type `addon` + naye `quantity`, `addon_key`) →
admin approve → `branches_purchased` barhe. Kam karna: pehle branch band karo, phir agle period se. Invoice gateway ke saath.

---

## 5. Zaban ka poora nizaam ("language manager")

### 5.1 URL
- English wahi: `bingoopos.com/pricing` (koi purana link na toote). Arabic: `bingoopos.com/ar/pricing`, `/ar/start-trial`… wahi controller.
- **Kyun URL:** Google har zaban tab hi alag index karta hai jab us ka apna URL ho.
- Har safhe par `hreflang` (en, ar, x-default), apni zaban ka canonical, `og:locale` (`ar_SA`), `<title>`/meta tarjume me.
- **`sitemap.xml`** naya — dono zabanon ke URL.
- Navbar me switcher **English | العربية** → cookie `bingoo_lang` (1 saal).

### 5.2 Kya kya tarjuma hoga
| Hissa | Tareeqa |
|---|---|
| Marketing safhe (home, features, pricing, demos, contact, start-trial, trial-success, coming-soon, layout/nav/footer) | `__('English jumla')` → `resources/lang/ar.json` |
| Legal safhe (terms, privacy, refund, support) | lambe paragraph — **har zaban ki apni Blade partial** (`public/legal/ar/terms.blade.php`), sau keys nahi |
| Plan ka naam / description (DB, admin badalta hai) | `plans.translations` json `{ar:{name, description}}` + central admin form me Arabic khaane; khaali ho to English |
| `config('saas.demos.cards')`, badges, chips | `__()` se |
| Form validation | `resources/lang/ar/validation.php` + `StartTrialRequest::messages()` / attribute names `__()` se |
| JS ke jumle (5 script blocks) | Blade se `@json(__('…'))` |
| Welcome email (`TrialWorkspaceCreatedMail`) | `Mail::to()->locale($tenant->locale)`; blade tarjume me |
| Cloud branch ke 7 billing emails | jab wo branch aaye, tab — `tenant.locale` pehle se maujood hoga |

### 5.3 RTL
- Arabic par `bootstrap.rtl.min.css` (`me-*`/`ms-*` khud palat jate hain).
- 29 custom `left/right` → logical properties (`margin-inline-start`, `inset-inline-start`, `text-align: start`).
- Aage/peeche wale icons `[dir=rtl]` me `scaleX(-1)`.
- **Hamesha LTR:** subdomain input-group, login URL preview, email, phone, qeemat ke number (`dir="ltr"` / `<bdi>`).
- Font: Google Fonts **IBM Plex Sans Arabic** (sirf `ar` par load).
- App ke screenshots English hain — filhaal wahi; baad me Arabic demo tenant se naye.

### 5.4 Number aur currency
`intl` `NumberFormatter`, Arabic par **Western digits** (`ar-SA-u-nu-latn`) — "549 ر.س / فرع / شهرياً". Currency ka naam/nishan
config se (SAR ر.س, AED د.إ, QAR ر.ق).

### 5.5 Tarjuma kaun karega
**Claude ek dafa file me likhega** — wahi tareeqa jo Arabic video me hua (`tools/demo-video/voice.json` ka Arabic Claude ne likha tha).
Na API key, na mahana bill, na har request par kharcha. Live machine tarjuma **kabhi nahi** (dheema, "POS/KOT" bigaarta hai).
`docs/i18n/glossary-ar.md`: Bingoo = بينجو, POS = نقاط البيع, branch = فرع, terminal = جهاز نقاط البيع, trial = تجربة مجانية, VAT = ضريبة القيمة المضافة…
**Ek native Saudi reader ek dafa** pricing, checkout, aur legal parhe. Legal par: "Arabic tarjuma suhoolat ke liye; ikhtilaf me English mo'tabar".

### 5.6 Manager ke auzaar (bina DB screen ke)
| Command | Kaam |
|---|---|
| `php artisan lang:audit` | public views ke har `__()` ko dhoond kar batao kaun sa jumla kis zaban me gum hai, aur kaun si key ab istemal nahi hoti |
| `php artisan lang:export ar` | CSV (key · English · Arabic · notes) — reviewer ko bhejne ke liye |
| `php artisan lang:import ar file.csv` | reviewer ki theek ki hui CSV wapas `ar.json` me (sirf maujood keys, report ke saath) |
| **Guard test** | kisi enabled zaban me koi key gum → test laal. Naya English jumla Arabic ke baghair deploy nahi ho sakta |

Nayi zaban (masalan Urdu) = `enabled_locales` me ek line + `ur.json` + `lang:audit` + font (Noto Nastaliq Urdu). Code nahi.
Super-admin me "Languages" screen — **sirf jab** bina developer ke lafz badalne ki zaroorat ho. Abhi nahi.

---

## 6. Mulk pehchanna (geo)

- IP se **mulk**, server par rakhi file se: **DB-IP "IP to Country Lite"** (muft, signup nahi, CC BY 4.0 — footer me credit) ya
  **MaxMind GeoLite2 Country** (muft, account + key). Composer `geoip2/geoip2`. File `storage/app/geoip/`, mahine me ek dafa cron (`www-data`).
  Kisi bahar ki service ko call nahi — visitor ka data kahin nahi jata.
- **Shehar nahi** — na zaban badalti hai na qeemat; database bari aur andaza kamzor.
- Faisla (sirf jab visitor ne khud kuch na chuna ho):
  - **Market / currency:** `bingoo_market` cookie → mulk → `default` (USD).
  - **Zaban:** `bingoo_lang` cookie → URL → mulk SA/AE/QA → Arabic → English. (USA = English.)
- Redirect sirf **pehli dafa, GET, landing safhon** par; **bots kabhi nahi** (Googlebot America se aata hai); POST / start-trial par kabhi nahi.
- Resolver interface ke peeche — tests me fake mulk.

---

## 7. Yearly ka bug

Cloud branch ka `1029f2c` + `BillingPeriodResolver` (`9480515`) prod branch par laana, phir `PlanPricingService` me barhana:
- Har "Start Trial" / "Customize" link me `billing=yearly` (JS ke baghair bhi chale).
- Checkout par chuni hui muddat: **"PKR 80,000 per year — 2 months free"** (+ "PKR 6,667/month equivalent"); Monthly/Yearly radio.
- `subscriptions.billing_period` (additive, default monthly).
- Pehle us branch ka test parhunga: agar wo 1B ki invoice par tika hai to wo hissa alag karke laaunga — payment methods / emails
  (gateway baad me) **nahi** laaunga.

---

## 8. Cookie banner + analytics

- **Cookie** = browser me rakhi chhoti yaad. **Zaroori** (session, form token, zaban/market ki pasand, cookie ka jawab) — ijazat nahi chahiye.
  **Analytics** (GA4) aur **Marketing** (Snapchat / Meta / TikTok pixel) — ijazat chahiye.
- Banner khud kuch nahi deta; ijazat milne ke baad: kis mulk se kitne aaye, kitno ne pricing dekhi, kitno ne trial shuru kiya;
  aur chhod kar jane walon ko dobara ad. Saudi PDPL, UAE ka qanoon, Europe ka GDPR — sab ke saamne saaf.
- Neeche patti visitor ki zaban me: **Accept all / Necessary only / Settings**. Scripts **sirf ijazat ke baad** (Google Consent Mode v2).
  Cookie `bingoo_consent` (12 mahine), footer me "Cookie settings", privacy safhe me cookies ka hissa (en + ar).

---

## 9. Phases (har ek alag deploy, alag tests)

| Phase | Kya | Note |
|---|---|---|
| **P0** | Yearly bug (Section 7) | Pakistan ke liye bhi; sab se chhota |
| **P1** | Zaban ki buniyad: `/ar` routes + middleware, switcher, RTL CSS + font, 29 CSS jagah, hreflang + sitemap, `en.json`/`ar.json`, 12 safhe + layout `__()` me, legal partials, validation `ar`, glossary, `lang:audit/export/import` + guard | Arabic matn Claude likhega |
| **P2** | Markets + per-branch: `plan_prices`, `plan_features` keys, `config markets`, `PlanPricingService`, pricing safha (PK bundle / baqi per-branch), currency dropdown, central admin me qeematon ka grid | |
| **P3** | Custom builder + checkout: "Build your plan", stepper, chhoot, checkout khulasa, signup → subscription (branches, terminals, currency, snapshot), `featureLimit()` per-branch, tenant currency + timezone + `locale`, welcome email Arabic | Limits ka asal kaam yahan |
| **P4** | Geo: GeoIP file + cron, resolver, pehli dafa ka redirect, bots ka bachao | P1–P3 ke baghair bekar |
| **P5** | Cookie banner + GA4 (+ jo pixel aap chunein) | Ads se pehle lazmi |
| **Baad me** | App ke andar "Change branches"; payment gateway; ZATCA | Alag faisle |

---

## 10. Saboot jo har phase de ga

- Asal safhe, asal controller se, `en` aur `ar` dono render: `dir="rtl"`, RTL CSS, koi English jumla bacha nahi (brand names ki ijazat wali list).
- **Missing-key guard** — jaan-boojh kar `ar.json` se ek jumla nikal kar sabit karna ke laal hota hai.
- **Qeemat:** har market × plan × branches × terminals × billing ka hisaab ek table-driven test me (Section 2.6 wali misaal bhi);
  URL/form me chheri raqam nazarandaz; PK visitor ko aaj wala safha byte-for-byte jaisa (snapshot).
- **Limits:** per-branch subscription par branch/terminal/user ki hadd; purane bundle subscription par **koi farq nahi** (Khatri jaisa fixture).
- **Signup:** SA → SAR + Asia/Riyadh + `ar`; PK → PKR + Asia/Karachi (aaj jaisa).
- **Geo:** fake mulk SA → Arabic + SAR; cookie IP se jeete; Googlebot redirect na ho; POST par kabhi redirect nahi.
- **Cookies:** "Accept" se pehle HTML me analytics script **nahi**; baad me hai.
- Headless Chrome me `/ar/pricing`, builder, `/ar/start-trial` — ankh se RTL aur hisaab.
- Har Blade change compile + lint; poora MySQL suite.

---

## 11. Is MD se bahar (alag track)
- **POS / back office ka Arabic** — `resources/lang` me sirf 4 English files, sau se zyada screens. Saudi customer trial ke baad app English
  me dekhega. Ye agla bara kaam hai; is ka apna MD.
- **ZATCA Phase 2**, **payment gateway**, **POS ka default tax** (Saudi 15% VAT) tenant settings me.

---

## 12. Abhi baqi faisle (chhote)
1. Section 2.4 ki qeematein aur "har branch me shamil" theek hain?
2. Chhoot ki seerhiyan (10% / 15%) aur extra terminal (59 / USD 19) theek hain?
3. Baqi duniya (in 5 mulkon ke ilawa) = USD per-branch?
4. Arabic kin mulkon me khud khule — SA, AE, QA teeno? (UAE/Qatar me bohat log English pasand karte hain.)
5. Arabic kaun parhega (native reviewer)?
6. Analytics: sirf GA4, ya Snapchat / Meta / TikTok bhi?

---

## 13. Market research (web, 2026-10-07)

Sab mahana, saalana billing ke hisaab se, VAT ke baghair jahan likha tha.

| Competitor | Mulk | Qeemat / mahina | Note |
|---|---|---|---|
| Foodics Starter / Basic / Advanced | Saudi | SAR 392 / 742 / 1,133 (monthly: 423 / 801 / 1,224) | foodics.com/pricing (page par currency nahi likhi) |
| Foodics Starter / Basic / Advanced | UAE | AED 199 / 375 / 556 | foodics.com/foodics-pricing-uae |
| Foodics | Qatar | QAR ~250 / terminal + setup QAR 2,500–3,000 | sadad.qa ka guide |
| Rewaa Basic / Advanced | Saudi | SAR 3,449 / 5,939 saal (≈ 287 / 495) | retail; 1 branch; sirf saalana; VAT alag |
| Marn | Saudi | SAR ~149 se | qeemat published nahi |
| Snad Basic / Pro | Saudi | SAR 149 / 399 | |
| Slant POS / Hulm | Qatar | QAR 145 / 70 | |
| Ari POS / TajerGo | UAE | AED 249 / 499 per branch | |
| Square Plus / Premium | USA | USD 49 / 149 per location | |
| Toast Point of Sale | USA | USD 69 per location | KDS/online ke saath asal 200–400 |
| Lightspeed Retail Basic / Core / Plus | USA | USD 89 / 149 / 289; extra register USD 59 | |
| Shopify POS Pro | USA | USD 79 (saalana) / 89 | |
| Loyverse | — | muft + USD 25 / store | |
