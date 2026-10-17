# PARTNER-SHARE-1 — Partner share report (ek dish, do kitchen)

Date: 2026-10-14 · Status: **bana aur test hua (17 Oct), deploy baqi** · Tenant: kashiffood (partner: K.Grill)

## Jo bana (17 Oct ka haal)

| Hissa | Kahan |
|---|---|
| Partners (jitne chahein) + har partner ki rate list | **Reports → Partner Share Rates** (`/reports/partner-share`), `PartnerShareController` |
| Hisaab | `SalesReportEngine::partnerShare()` |
| Report | Report Center section `partner_share`: screen, Print Selected (Thermal / A4), Send to network (ESC/POS), CSV, Email Now, Schedule |
| Tables | `share_partners`, `share_rates` (migration `2026_10_14_000002`) |
| Section permission | `tenant.reports.center.sections.partner-share`, sirf Owner role (migration `2026_10_14_000003`) |
| CSV import | `share-rates:import {tenant} --file=… --partner="…" [--yes]`: dry run, phir poori list ya kuch nahi |
| Saath ka fix | `TenantProvisioner`: naye tenant ke Owner ko Report Center ke saare sections (pehle 0 milte the) |
| Reset | `share_*` tables `tenant:reset-transactions` me rakhi jati hain (setting hain, bikri nahi) |

Section tab tak nahi dikhta jab tak koi rate na ho. Rate list sirf report ke liye hai: POS bill, stock,
journals aur cash kuch nahi chhoota. K.Grill ko adaegi owner khud karta hai.

Tests: `PartnerShareMySqlTest` (11). 9 sabotage, har ek sahi test laal karta hai. Poora suite (2059)
me ek hi laal tha (reset ki table list), wo theek kiya. Asli screens aur teeno print local Kashif Food par
controller ke raaste render kar ke dekhe.

## Client ki teen sheets: raqam ka rukh

Teeno sheets me naam ka matlab ek jaisa nahi. Har sheet ka matlab deal ke andar ke saman se pakka kiya:

| Sheet | Naam ka matlab | Saboot |
|---|---|---|
| 1. Dishes (14 Oct) | **Dene wala** kitchen | Client ne WhatsApp par kaha: "platter ki amount K.Grill humain dega", "Khass K.Food K.Grill ko dega" |
| 2. Deal 1–20 (17 Oct) | **Lene wala** kitchen (us ki cheez ki qeemat) | Har Chicken Chatni Roll (K.Grill ka) ~320: 1 roll 320, 2 = 650, 4 = 1,300, 6 = 1,950; Deal 13 me Malai Boti 1,200; poori BBQ wali Deal 15/19 me "K.Food 200/450" = paratha + drinks |
| 3. Midnight (17 Oct) | **Dene wala** kitchen | BBQ + Drink deals par "K.Grill 100" = drink ke 100 (drink K.Food ki); BBQ Sandwich Midnight par K.Food → K.Grill 400 |

System me rukh hamesha `payer` se likha jata hai: `partner` = K.Grill hum ko deta hai, `us` = hum K.Grill ko dete hain.
Midnight Khass par sheet me do raqamein hain (K.Food 1,500, K.Grill 200); system me net `us 1,300`.
Deal 4, 5, 12 poori K.Food ki hain, in ki koi row nahi.

Rate list (43 rows, 1 Sep se lagu) CSV me hai; tasdeeq wala doc: "K.Grill rate list: dishes aur deals".
Pichhle 30 din (14 Sep – 13 Oct) par net **K.Food → K.Grill 3,52,940**.

## Deploy aur rates daalna

1. Branch ko origin ke saath milao, push, `bash deploy.sh` (2 tenant migrations; POS / stock / GL nahi chhoote).
2. Prod: `share-rates:import kashiffood --file=… --partner="K.Grill"` **dry run**. Har naam mile, list owner ko dikhao.
3. Owner ki haan par `--yes`. Phir Report Center → Partner Share, 14 Sep – 13 Oct, net ~3,52,940 se milao.
4. Raat ki email + WhatsApp PDF: Kashif Food ka schedule #1 ("Daily Owner A4 Sales Report", 02:30) — sections me `partner_share` jodo (baqi 9 waise hi). Schedule ki screen par edit nahi, is liye ek guarded data update (sirf agar pehle se na ho). Email aur WhatsApp dono `SalesReportDocumentService::pdf()` se bante hain, is liye dono me aa jayega. WhatsApp ke message ka TEXT (Meta template) nahi badalta — sirf PDF.
5. Koi aur role (Accounts) report dekhe to section permission `givePermissionTo` se do, phir `system:clear-tenant-permission-cache`.

## Abhi tasdeeq baqi (client)

Deal 10 (400 ya 1,050); Midnight Khass net 1,300; Midnight BBQ + Drink 100 ka rukh; Al-Faham ka rukh;
Balochi / Chullu / Turkiya platter me; fries wale sandwich 400; sheet par na aane wali deals (Bar-B-Que Platter,
Malai Tikka Midnight, Garlic Shashlik Midnight); akele BBQ items; lagu tareekh 1 Sep.
Nayi deal jis me K.Grill ki cheez ho, us ka rate daale baghair report use nahi ginti.

## Masla

Kashif Food ki 22 dishes do kitchen mil kar banate hain. Har dish bikne par ek kitchen doosre ko
**pakki raqam** deta hai. Client ki 14 Oct ki tasdeeq: rate list ka column **dene wala** kitchen
batata hai. Masalan Classic Platter 1 par K.Grill → K.Food 1,000, aur Singaporean Rice Khass par
K.Food → K.Grill 1,500. Owner ko ek report chahiye jo kisi bhi muddat ke liye bataye ke kaun kis ko
kitna dega, aur net kitna bana. Rate list: client tasdeeq doc (22 rows).

Ye **sirf report** hai: na POS badlega, na stock, na GL. Paisa owner khud deta hai.

## Teen raaste — aur faisla

| | Cost block (catering) | Department + handover | **Naya: Partner Share** |
|---|---|---|---|
| Kis cheez se juda | `catering_product_profiles` (`/catering/profiles/{id}/blocks`) | `departments` + category map | Product ya deal par seedha |
| Kashif Food par chalega? | Nahi: plan me catering module hi nahi | Haan, magar 0 departments, kabhi set nahi hua | Haan, reports module pehle se |
| Matlab | Quotation ki **laagat** (qty × rate, house/commercial rate book, estimate snapshots) | Product ka **100%** ek department ka; handover GL me jata hai | Har dish par **pakki raqam, dono taraf** |
| Ek dish do me baantna | "Kis kitchen ka" ka field nahi | Nahi ho sakta | Yahi is ka kaam |
| Khatra | Live catering pricing (Kashif Kitchen) se jorna | GL entries, department reports ka alag hisaab | Sirf parhna; POS / stock / GL nahi chhoota |

**Faisla: naya chhota "Partner Share" nizaam.** Cost block se sirf ek idea liya jayega: aage chal
kar har raqam ka **breakup** (rice kitna, BBQ kitna) rakhne ki jagah (Phase 2).

## Data

### `share_partners`
`id`, `name` (masalan "K.Grill"; naam DB me, code me nahi), `is_active`, timestamps.

### `share_rates`
| Column | Matlab |
|---|---|
| `share_partner_id` | Kis partner ke saath |
| `product_id` / `combo_id` | Ek hi bhara hoga: product ya deal |
| `payer` | `partner` (partner hum ko deta hai) ya `us` (hum partner ko dete hain) |
| `amount` decimal(12,2) | Fi dish pakki raqam |
| `effective_from` date | Is din se lagu (business date) |
| `notes` | Jaise "client 14 Oct" |
| `created_by` | Kis ne dala |

- Ek item + partner + `effective_from` = ek hi row (unique).
- Rate badle to **nayi row**, naye `effective_from` ke saath. Purani muddat ki report purane rate se
  hi banegi. Purani row kabhi edit nahi hoti.
- Kisi item ka hissa khatam karna = nayi row `amount = 0`.

## Hisaab ke usool

1. **Bikri wahi jo Report Center ginta hai:** `SalesReportEngine::POPULATION` (paid,
   partially_returned, returned), business date, branch / terminal / order type filter.
2. **Kaun si line:** product ki `standard` line (`combo_id` null) aur deal ki `combo_header` line.
   Deal ke andar ka `component` kabhi rate nahi lagata. Is liye Deal 10 me rakhe BBQ Sandwich par
   Deal 10 ka apna rate lagta hai, sandwich ka nahi, aur dohra hisaab nahi hota.
3. **Rate kaun sa:** order ki business date par jo row lagu thi.
4. **Wapsi:** wapas hui qty ghat jati hai (`sales_return_lines`, return ki business date se, jaise engine).
5. **Muft / 100% discount line** (`line_total = 0`): default **nahi gini jati**. Owner ka sawal neeche.
6. **Bill par discount:** pakki raqam par asar nahi (client ne fixed rate chuna).
7. Har partner ke liye: "Partner deta hai" ka jor, "Hum dete hain" ka jor, aur **Net**.

## Screens

1. **Reports → Partner Share Rates** (setup): saari rows ek grid me, rate list doc jaisi. Product ya
   deal search, payer toggle, raqam, "lagu kab se". "Purane rates" tab me history.
2. **Report Center → naya checkbox "Partner Share"**: upar 3 tiles (Partner deta hai / Hum dete hain /
   Net), neeche do tables (item, rate, bike, wapas, net qty, raqam).
3. **Print, Excel, Email, Schedule:** naya section `SECTIONS` me shamil hoga. Is liye ye har jagah
   chalega. Departments tab ki tarah sirf screen par nahi rahega.

## Permissions

- Routes: `tenant.share-rates.index|store|update` (setup), section permission
  `tenant.reports.center.sections.partner-share` (synthetic, tenant migration se, jaise
  `2026_09_02_000001_add_deals_report_section_permission.php`).
- **Paisa wala section hai:** migration sirf Owner ko deti hai, baqi roles ko nahi. Owner bataye to
  `givePermissionTo` se additively.
- Module: `reports` (kashiffood ke plan me hai).

## Rate list ka import

`share-rates:import kashiffood --file=… --dry-run` → 22 rows dikhaye. Owner ki haan ke baad `--yes`.
Data DB me jata hai, code me koi rate ya naam nahi.

## Kya NAHI chhooega

POS checkout, `stock_*`, journals / GL, departments, catering cost blocks. Report sirf `SELECT` karti hai.

## Tests

- Deal header gina jata hai, component nahi (Deal 10).
- `effective_from`: rate badalne se purani muddat ka total nahi badalta.
- Wapsi qty ghatati hai; muft line (0) nahi gini jati.
- Net = hum dete hain − partner deta hai, misaal ke numbers par.
- Section permission ke baghair na tab dikhe, na print / export me aaye.
- Asal page controller ke raaste render ho (sirf HTML string nahi).

## Owner se sawal (banane se pehle)

1. Muft (100% discount) dish par bhi raqam deni hai ya nahi? Default: nahi.
2. Al-Faham Full / Half: K.Grill → K.Food maana hai, sahi?
3. Fries, Midnight aur Deal 10 par asal dish wali raqam, sahi?
4. Report kis kis ko dikhe: sirf Owner, ya Accounts bhi?
5. Rate list kis tareekh se lagu ho (`effective_from`)? Pichhle mahine ka hisaab chahiye to 1 Sep jaisi tareekh.

## Baad me (abhi nahi)

- **Phase 2: breakup.** Har rate ke hisse (label + raqam, jor = rate), jaise "Rice 600 + Salad 400".
  Report me dikhenge. Ye cost block wala idea hai, magar catering se juda nahi.
- `%` ka option (dish ki asal bikri ka %).
- Settlement: report se "Payment record karo" (Cash Book entry). Tab GL chhoota jayega, alag plan.

## 30 din ki misaal (14 Sep – 13 Oct, prod se sirf parh kar)

| | Raqam |
|---|---|
| K.Grill → K.Food (platters, crispy rolls, Al-Faham) | 2,44,950 |
| K.Food → K.Grill (Khass, sandwiches, shashlik) | 4,56,000 |
| **Net: K.Food → K.Grill** | **2,11,050** |
