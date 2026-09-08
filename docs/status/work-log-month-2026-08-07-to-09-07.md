# Ek Mahine ka Kaam — 7 August se 7 September 2026

**Daur:** 7 Aug – 7 Sep 2026 (31 din)
**Prod HEAD is waqt:** `21f734f` — server Hostinger `187.77.140.39`, branch `feat/14d-2-plan-upgrade-requests`
**Likha gaya:** 7 Sep 2026

> Pichle log: `work-log-2026-08-11-to-08-24.md`, `work-log-2026-08-25-to-09-01.md`,
> `work-log-month-2026-08-05-to-09-05.md`. **Ye file un sab ko ek jagah samet-ti hai
> aur 5 Sep shaam se 7 Sep tak ka wo hissa bhi joro deti hai jo kisi log me nahi tha.**

---

## 1. Ek nazar me

| | |
|---|---|
| Commits | **462** |
| Files chhui gayin | 729 (app ka asli code: 608) |
| Satrein | +219,883 / −1,947 (app code: +97,000 / −1,884) |
| Naye test files | **183** |
| Nayi migrations | **74** — sab additive |
| Naye docs | **97** |
| Live tenants | **11** (4 asli kaarobar + demo + 6 plan-demo) |
| Worktrees | 15 (7 zinda, 8 khatam) |

Sab se ghana din **11 August (43 commits)** — Khatri ka go-live. Doosra
**3 September (29)**. 31 me se 31 din kaam hua; ek din bhi khali nahi.

Mahine ka asli hasil ek jumle me: **do restaurant live hue, teesra (do branch wala)
banaya gaya, chautha go-live ke liye tayyar hua — aur is arse me kaarobar ek din band
nahi hua.**

---

## 2. Bab 1 — 7–10 August: go-live se pehle ka hafta

Khatri Biryani ka onboarding, print agent, terminal routing, aur wo saari cheezein jo
pehle din tootti hain. Tafseel → `work-summary-2026-08-07-to-12.md`.

Isi arse me server **DigitalOcean se Hostinger** par gaya (11 Aug). Purana
`144.126.216.59` ab POS nahi chalata (profinder/just2service wahin hain).

---

## 3. Bab 2 — 11–24 August: live kaarobar, aur ek caterer ka poora business

**11 Aug — go-live ka din, 43 commits.** Khatri live. Usi din ka apna log:
`khatri-live-day-2026-08-11.md`.

**12 Aug — 403 ka toofan.** Spatie ka permission cache shared `database` store par tha:
ek static-key row ek garam FPM worker ke pehle DB connection se bandh gaya → galat guard
ka Gate → **saare tenant 403**. Ilaj: store per-request `array` (`e17d6b5`).
⚠️ Isi liye **bare `permission:cache-reset` kabhi nahi** — hamesha
`system:clear-tenant-permission-cache`.

**13–19 Aug — catering ki bunyaad.** Kashif Kitchen ke liye poora catering module:
estimates, cost blocks, events, production release, kitchen sheet, Urdu print.

**18–21 Aug — printing overhaul aur report ka sach.**
- `703d789` — settings-driven layout, terminal-keyed KOT routing
  (`PrintRoutingService::applyTerminalPrecedence`), Report Center Send-to-Network
- Khatri ke report/finance fixes: thermal-bold, category filter + distinct order count,
  sales-return partial, **supplier opening → GL 3300 EQUITY (P&L nahi)**,
  REPORT-BUSINESS-DATE-1

**22–24 Aug — punch screen aur client ka apna catalogue.**
- POS-DRAFT-1 (`0b5df5a`) — draft held sale, KOT rok kar
- Kashif Kitchen ka catalogue client ke apne DB se **dobara banaya** (`616f793`)
- **Kashif Food tenant live** (`b3617c8`, 24 Aug) — 199 products, 41 combos, 4 terminals

---

## 4. Bab 3 — 25 August – 1 September

- **`c49e883` (27 Aug)** — chaar cheezein ek saath: Quick Report Send, Report Schedule,
  Tenant Auto Backup, Printer Health
- **TABLE-RESERVATION** (`3d241ce`) — khali dine-in table reserve/details/cancel
- **`8e35a9d` (30 Aug)** — POS combo sub-categories; Kashif backup cron live
- **30 Aug terminal scoping** — cashier apne counter par utarta hai, doosron ka parhta hai
  magar bechta sirf apne par; reprint operator ke counter par; order apna `terminal_id`
  **rakhta** hai (pehli koshish jo usay hilati thi, `dff1313` par wapas li gayi)
- **`cba7e09` (31 Aug)** — cancel table free karta hai, reminder cancel karne wale counter
  ka, chhupa product khuli bill par ab bhi payable
- **`d746abe` HOTFIX** — `voided@endif`: directive lafz se chipak gaya to compile hi nahi
  hota, Close Branch **sab ke liye 500**. Ab har Blade change compile karke generated PHP
  lint hota hai
- **`67cde05` (1 Sep)** — return par manager PIN; Close Table ka faisla transaction ke andar
- **`03f0d99`** REPORT-DEAL-IDENTITY-1 — deal ka apna product nahi hota, is liye kai cheezein
  ek row me mil jaati thin. **Paisa 0.00 hila**, sirf naam alag hue

Tafseel → `work-log-2026-08-25-to-09-01.md`.

---

## 5. Bab 4 — 2–5 September: thermal report, aur Kashif Kitchen ka go-live

- **`fa35d80`** — thermal Z report ki **shakl**; section total ka naam `KUL` → **`GRAND TOTAL`**
  (Urdu lafz angrezi report par tha, owner ko poochna para)
  ⚠️ Purana guard sirf ROLL parhta tha, isi liye Blade ki kharabi pakad nahi paya — ab
  Blade ka apna guard hai
- **`1573e14`** HIDE-AMOUNTS-2 — shift ka apna safha aur shifts ki list bhi `*****`
  (pehle chaar operator accounts wahi figures ek click door parh sakte the)
- **`56c9838` (5 Sep)** — Kashif Kitchen go-live prep. Prod par reset chala
  (backup `20260905_093655`), **phone-fusion source par theek** (extractor punctuation
  hata kar do numbers ko ek 22-digit shanakht bana deta tha), 237 suppliers import
  ⚠️ Booking numbers 0001 se shuru — pehli asli booking ke **baad** reset kabhi nahi
  ⚠️ 14 suppliers ka Σ6.49M cr / Σ4.49M dr **rokka hua** hai (owner ki tasdeeq + GL posting)
  ⚠️ 61 customers zahiri duplicate — merge **nahi** kiye, owner ka faisla
- **CHARGE-BREAKUP-1** (`d89f32a`) — bridge par har kharcha batata hai kis ka paisa hai

---

## 6. Bab 5 — 5 September shaam se 7 September: **wo hissa jo kisi log me nahi tha**

### 5 Sep shaam — shift ka hisab

| commit | kaam |
|---|---|
| `27b677e` | ZERO-DRAWER-1 + SHIFT-RECONCILE-1 — khali drawer band ho jata hai, list ginti dikhati hai |
| `29044da` | SHIFT-RECONCILE-2 — aath naye column **hata** kar har row ke neeche ek **khulne wala panel**; branch ka total ek bar, har row par nahi |
| `e2d14bc` | SHIFT-DATE-FILTER-1 — shifts ki list par din ka filter + exact Today/Yesterday |

### 6 Sep — parchiyon ka waqt, aur do-branch wala tenant

**Parchi ka waqt sach bola** (teen commit, ek hi kahani):
- `fad960b` **KOT-TIME-TRUTH-1** — KOT par **order ka** waqt chhape, chhapne ka nahi.
  Reminder reprint aaj ki tareekh chhap raha tha. Naya `app/Support/KotTicketTime.php`
  payload ke `kot_batch_id` se `kot_batches.created_at` parhta hai
- `417436e` **SALE-DATE-TRUTH-1** — order ka waqt payment par dobara na likha jaye
- `6e3e67c` **GL-BUSINESS-DATE-1** — khaata usi din par baithe jis din par report baithti hai
- `193b5c4` **HOTFIX** — Layout ka live preview 500 kar raha tha. Meri hi KOT-TIME-TRUTH-1
  ki regression: wo blade **teen** jagah se render hoti hai, maine do sambhali thin; aur usi
  satar par doosra keera — preview ek stdClass sample sale bhejta hai, is liye `?object`

**Dashboard/Shifts ka "aaj"**:
- `5080e4a` **OPERATING-DATE-1** — dashboard ka aaj = khuli shift ka business date
  (naya `TenantClock::operatingBusinessDate`: khuli shifts ka MAX business_date, warna
  branch-tz ka aaj). Mid-service 0.00 dikhna khatam
- `5972f96` **OPERATING-DATE-2** — shifts list ka Today bhi wohi

**Tenant #4 live**: `87cd76f` — **CATEGORY-BRANCH-SCOPE-1 + Tawakal / The Kashif Foods
onboarding**. Pehla tenant **do branch, do domain** par:
`thekashiffoods.bingoopos.com` (br 1) aur `tawakkalbiryani.bingoopos.com` (br 2).
Category ab `branch_id` rakhti hai, aur NULL = sab branch — isi liye baqi teen tenant
achhoote rahe.

`56bdf13` **ADDRESS-ATTACH-1** — address save karte hi wo order par bhi chadh jaye
(preview me address gayab tha, final bill par aa jata tha).

**Cert (infra, commit nahi):** wildcard `*.bingoopos.com` **chhor diya** — wo
`authenticator = manual` dns-01 tha, yani `certbot renew` kabhi unattended chal hi nahi
sakta tha. Ab **HTTP-01 webroot**, 14 naam, **4 Dec 2026** tak, timer khud chalta hai.
Naya subdomain khud shamil ho jata hai: `/usr/local/bin/bingoo-cert-sync` (cron har 10 min)
master DB se zinda domains parh kar expand karti hai, farq na ho to kuch nahi karti.

### 7 Sep — Quick Report, aur font

| commit | kaam |
|---|---|
| `fdad50a` `9887b1c` `8ced875` | **QUICK-REPORT-OPEN-BILLS-1** — Quick Report ke **har** section me held + draft bhi (HTML aur ESC/POS dono raaste). Report Center **achhoot** |
| `6c22fc7` | **QUICK-REPORT-BRANCH-SCOPE-1** — report apni branch tak, aur modal me branch ka picker |
| `21f734f` | **FONT-FS-COLLISION-1** — `fs-1..fs-6` ko bootstrap ke maani wapas |

**Quick Report me khule bills.** Owner ko live, chalte kaarobar ka hisab chahiye tha.
`SalesReportEngine::POPULATION` ki **qeemat** kabhi nahi cherhi — wo chaar aur jagah parhi
jaati hai. Ek naya filter `include_open`, aur faisla `salesBase()` par — jo aik hi choke
point hai (`linesBase()` usay `joinSub` karta hai), is liye ek tabdeeli Categories, Items,
Items-by-Category, Deals, Waiters, Order Types aur Combos — sab tak pahunch gayi.
`returnsBase()`, `cashBank()`, `cancellations()` apni tables par chalte hain, is liye
qudrati taur par paid-only rahe.

Khatri ke live data par tasdeeq: Orders 71→72, Categories jama 70,270→71,350,
Items qty 197→201, **Cash & Bank be-harkat**, Report Center 11 section me hu-ba-hu wohi.

**Branch scope.** Owner ne khud Tawakkal Counter se login kar ke pakra: uski report par
**Singaporean Rice** (doosri branch ki category) aa rahi thi. Kharabi "galat code" nahi thi
— **ek durust faisla jo doosri branch aane par galat ho gaya**: controller ke docblock me
saaf likha tha ke report "jaan-boojh kar unscoped" hai, aur jab wo bani (27 Aug) har live
tenant ek branch ka tha. Ab branch `UserDataScope::branchIds()` se; khali assignment = koi
rukawat nahi, is liye teeno single-branch tenant aur Owner ka jawab wohi. Live tasdeeq:
counter_tb 34 orders + counter_kf 51 = owner ka 85, aur Net 12,300 + 43,130 = 55,430 —
**data kata, toota nahi**.

**Font.** Owner ne Close Shift ka safha bheja: raqam parhi hi nahi ja rahi thi, jabke usi
row ka "Opened At" theek tha. Yehi farq isharah tha. Theme ki `style.css` bootstrap ke
**baad** load hoti hai aur unhi chha classes ko dobara ta'reef karti hai, apne scale par
jahan `fs-N` = **N pixel**:

```
.fs-5 { font-size: 5px !important; }      <- theme (jeet raha tha)
.fs-5 { font-size: 1.25rem !important; }  <- bootstrap
```

Ye **99 jagah** thi. Is liye ilaj ek jagah — `a11y-custom.css` (sab se aakhir me load hoti
hai) me bootstrap ki asli qeematein bahal, us ke `min-width:1200px` steps ke saath. Theme
ka apna scale (`fs-7`–`fs-40`) chhooa nahi.

### 7 Sep — data ka kaam (koi deploy nahi)

**The Kashif Foods (tawakalkashif br 1)** par, har bar backup le kar aur ek transaction me:

- **Foodpanda** ke do sub-category: `Foodpanda Singaporean Rice` (cat 18),
  `Foodpanda Chicken Biryani` (cat 19)
- KF-051 Singaporean Rice Small (Foodpanda) **650**, KF-052 Large **1,180**,
  KF-053 Chicken Biryani Small **350**, KF-054 1 KG **750**
  (Extra Sauce 130 / Extra Piece 120 **nahi** banaye — rate pehle se wohi tha)
- **Making** category (cat 20) + KF-055…059 = Making **100 / 200 / 400 / 700 / 800**

Har naya product mojooda item ki **naql** hai (`service` / `finished_good`, stock off, tax
nahi) — settings khud se nahi likhi taake kuch bhatak na jaye. Tasdeeq asli POS safhe se:
`CATEGORY_HAS_CONTENT` me 18/19/20 Kashif Foods par mojood, **Tawakkal par nahi**.

Isi mahine ke baqi data kaam: Half KG Chicken Pulao 260→250, Biryani me Small/Large
Container (30/50) phir apni Containers category me, Extra Boti (Tawakal 80/80, Kashif 50),
Chicken Biryani 1 Pao 100 aur 350 Gram 140, category sorting Khatri ke usool par,
riders Salman/Shakir ki duplicate safai, printer IP `192.168.100.240`.

---

## 7. Parallel sessions — 15 worktree, kaunsa kis liye

⚠️ **`D:/laragon2/www/pos-saas` = PROD ka canonical** — ye sanjha hai, is par do session
ek waqt me kaam **na** karein.

### Zinda — inhein rakhna hai

| worktree | branch | aakhri | haalat |
|---|---|---|---|
| `pos-saas` | `feat/14d-2-plan-upgrade-requests` | 07-Sep | **PROD** `21f734f` |
| `pos-saas-hideamounts` | `feat/items-by-category` | 07-Sep | poora merge ho chuka (0 aage) |
| `pos-saas-tawakal` | `feat/tawakal_kashif` | 06-Sep | poora merge ho chuka (0 aage) |
| `pos-saas-cloud` | `feat/cloud-billing-onboarding-v1` | 15-Aug | **8 commit aage, DEPLOY NAHI** — cloud billing/onboarding. Baqi: payment account + deploy |
| `pos-saas-edge` | `feat/edge-config-refresh-v1` | 01-Sep | **53 commit aage, DEPLOY NAHI** — offline/Edge chain, FROZEN + DORMANT |
| `pos-saas-catering` | `feat/catering-product-ux-v1` | 21-Aug | 23 commit aage — catering dev **freeze** |
| `pos-saas-catering-parity` | `feat/catering-operator-completion-v1` | 22-Aug | 4 commit aage |

### Khatam — hataye ja sakte hain

`pos-saas-catering-codex` (`audit/catering-e2e-qa-v1`, 13 aage) ·
`pos-saas-catering-codex-product` (18 aage) ·
`pos-saas-catering-codex-cert` (17 aage) ·
`pos-saas-catering-codex-cert-v2` (detached) ·
`pos-saas-catering-catalogue` (`data/kashif-catalogue-prep-v1`, 1 aage) ·
aur `pos-saas-catering/.codex-worktrees/` ke teen fix branches
(client-feedback 27-Aug, quoted-rate 28-Aug, history-urdu 29-Aug) — **teeno merge ho chuke**.

### Wo branches jo merge ho gayin (kaam prod par hai)

`feat/hide-amounts` · `feat/dashboard-7day` · `feat/deal-heads` · `feat/kot-sent-pool` ·
`feat/legacy-reports-population` · `feat/rider-returns` · `feat/catering-events-v1` ·
`release/catering-go-live-1` aur `-2`

### Stash — do, abhi tak khule

- `stash@{0}` — **REPORT-SHIFT-BREAKUP-1 P1**: shift filter sach bolta hai (G1 cancellations,
  G2 cashBank drawer, G3 scope label) + plan doc + 7/7 test. **Bana hua, green, magar 30 Aug
  se commit nahi.** Us plan doc me P1–P4 hain; P4 (multi-shift refund cash) ka keera likha hua hai
- `stash@{1}` — `codex-manual-discount-wip`

---

## 8. Project ke hisab se haalat

| project | haalat |
|---|---|
| **POS / counter** | Zinda, sab se ghana kaam. Terminal scoping, draft, reservation, table close, manager PIN, delivery meta |
| **Printing / KOT** | Zinda. Layout settings-driven, terminal routing, print-state semantics, printer health. **Agent 2.5 abhi client ne install nahi kiya** |
| **Reports** | Zinda. Deal identity, business date, charge bridge, thermal shakl, Quick Report (open bills + branch scope) |
| **Shift / cash** | Zinda. Zero drawer, reconcile panel, date filter, operating business date, hide-amounts |
| **Catering** | **Dev FREEZE**. Module mukammal, Kashif Kitchen go-live ke liye tayyar |
| **Offline / Edge** | **FROZEN + DORMANT**, 53 commit undeployed. Aage: EDGE-CONFIG-REFRESH-1 → COMPATIBILITY-CONTRACT-1 → OFFLINE-SYNC-ENGINE-1 |
| **Cloud billing** | **Bana hua, deploy nahi**. Baqi: payment account |
| **Manufacturing** | Plan-gated, posting per-tenant OFF (sirf demo + financedemo on) |
| **Public website** | Bingoo / bingoopos.com, 6 plan + legacy `standard` |

---

## 9. Tenant ke hisab se

| tenant | domain | haalat |
|---|---|---|
| `khatribiryani` | khatribiryani.bingoopos.com | **LIVE** 11 Aug. Nightly report 00:30, backup 14:30/19:30/02:30 |
| `kashiffood` | kashiffood.bingoopos.com | **LIVE** 24 Aug. 199 products, 41 combos, 4 terminals, nightly 02:30 |
| `kashifkitchen` | kashifkitchen.bingoopos.com | **LIVE**, catering. Go-live prep 5 Sep |
| `tawakalkashif` | thekashiffoods + tawakkalbiryani | **LIVE** 6 Sep. Pehla **do branch / do domain** |
| `demo` + 6 plan demo | *.bingoopos.com | Chal rahe |

---

## 10. Is mahine ke sabaq — jo dobara na hon

1. **Guard ko asli raasta chalna chahiye.** Ek hi din do outage: guard ne query dobara likhi
   thi, controller ko chalaya hi nahi. Ab: deploy se pehle safha **render**, aur guard ko
   **RED hote dekho**
2. **Blade compile karke lint karo.** `voided@endif` ne Close Branch sab ke liye 500 kar diya
3. **`git status` parho, files ginn kar na likho.** Ek enumerate ki hui list se
   `EscPosPayloadService.php` chhoot gaya — HTML me nayi satrein aayin, printer ke bytes me
   nahi. Prod ke bytes milaane par pakda gaya
4. **Regex se code na kaato.** Ek failed `preg_replace` NULL de kar `print.blade.php` khali kar
   gaya; doosri bar ek regex `salesBase()` uda gaya (PHP lint pass ho gaya — gum method syntax
   error nahi hota). Ab: sirf exact-string replacement, aur MISS par **kuch na likho**
5. **Apni likhi tambeeh ka pass rakho.** Plan me likhi warning tabdeeli me zinda rehni chahiye
6. **Ek process me kai tenant switch karke `can()` na poochho** — Spatie ka per-request array
   cache pehle tenant par jam jata hai aur **jhoot** bolta hai
7. **Naya route = ghair-Owner roles check karo.** `deploy.sh` sirf Owner ko deta hai;
   grant **additive** (`givePermissionTo`, kabhi `syncPermissions` nahi)
8. **Naya faisla purani soorat-e-haal ka qaidi hota hai.** "Ye jaan-boojh kar unscoped hai
   kyunke tenant ek branch ka hai" — doosri branch aane par wohi jumla keera ban gaya

---

## 11. Khule kaam

**Code:**
- `stash@{0}` REPORT-SHIFT-BREAKUP-1 P1 — green, magar 30 Aug se commit nahi
- Cloud billing — payment account + deploy
- Edge chain — 53 commit undeployed, RELEASE GATE khula
- Print agent 2.5 — client ne install nahi kiya
- Foodpanda + Making ke data kaam ka MD abhi nahi bana

**Owner ke faisle ka intezar:**
- Kashif Kitchen: 14 suppliers ka Σ6.49M cr / Σ4.49M dr (GL posting Dr 3300 / Cr 2100)
- Kashif Kitchen: 61 zahiri duplicate customers — merge karein ya nahi
- Manager PIN ek hi hai kai users ka (Kashif 6 users, Tawakal dono counter) → audit bemani
- **Khatri par shift-close ki masking OFF** — wahan har role expected/counted/difference
  parh sakta hai. Tawakal aur Kashif par ON
- Purana print-agent process IP `39.53.215.233` par (1,531 heartbeat 401)
- Tawakkal ki apni Containers category; `Chicken Pulao` → `Chicken Pulao (Half KG)`

**Owner ne mana kiya:** refund-method case fix, P4 sale_date backfill.

---

## 12. Kahan se tafseel milegi

| daur | file |
|---|---|
| 7–12 Aug | `docs/status/work-summary-2026-08-07-to-12.md` |
| 11 Aug (go-live) | `docs/status/khatri-live-day-2026-08-11.md` |
| 11–24 Aug | `docs/status/work-log-2026-08-11-to-08-24.md` |
| 25 Aug – 1 Sep | `docs/status/work-log-2026-08-25-to-09-01.md` |
| 5 Aug – 5 Sep | `docs/status/work-log-month-2026-08-05-to-09-05.md` |
| Catering | `catering-*.md` (5 files isi folder me) |
| 6–7 Sep ke plan/research | `docs/plans/` — `print-timestamps-truth-2026-09-06.md`, `dashboard-operating-business-date-2026-09-06.md`, `pos-delivery-address-attach-2026-09-06.md`, `quick-report-*-2026-09-07.md` (chaar) |
