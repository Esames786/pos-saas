# Kashif Kitchen go-live — research + plan

**Date:** 2026-09-05 · **Status:** RESEARCH COMPLETE — nothing executed, nothing deployed
**Tenant:** `kashifkitchen` (kashifkitchen.bingoopos.com) — catering
**Untouched by everything in this document:** `khatribiryani`, `kashiffood`, `tawakalkashif`,
and the six public demos. Every finding below comes from READ-ONLY queries against prod
plus the legacy workbook; the only thing actually run was the existing reset guard on the
disposable test database (5/5 green).

**Owner ka sawaal, char hisson mein:**
1. Live karte waqt test data jaye, master data rahe.
2. "Jo customer humne khud dale thay wo remove hon, sirf genuine rahen."
3. `public/old_software.xlsx` se vendors/suppliers nikal kar dump ho sakte hain?
4. System Reset ka button theek chal raha hai — tasdeeq karo.

---

## 0. Aaj kashifkitchen kahan khara hai (prod, ginti ke saath)

### Master data — ye sab bacha rehna chahiye

| | ginti | |
|---|---|---|
| categories | 19 | client ki apni legacy categories |
| products | 915 | 909 dishes + 6 raw materials |
| catering_product_profiles | 909 | har dish ka catering profile |
| catering_product_cost_blocks | 1,119 | client ke apne rates se bane cost blocks |
| product_translations (ur) | 939 | Urdu naam |
| catering_instructions | 55 | kitchen instruction vocabulary |
| catering_service_time_presets | 9 | Sehri/Iftar/Walima jaisi sittings |
| catering_material_rates | 6 | Material Rate Book |
| catering_printer_mappings | 8 | station routing |
| accounts | 51 | chart of accounts |
| customers | 4,848 | **sab legacy import** — neeche §2 |
| suppliers | **1** | sirf `SUP-001 Default Supplier` — khaali |
| users / roles | 1 / 1 | sirf Owner |

### Transaction / test data — ye jaana hai

| | rows |
|---|---|
| catering_events | 7 |
| catering_estimates / lines / cost blocks | 7 / 17 / 25 |
| catering_event_revisions | 14 |
| catering_production_releases (+ lines) | 1 (+8) |
| catering_cost_snapshots | 2 |
| catering_email_logs | 44 |
| catering_event_reminders | 42 |
| stock_ledgers / stock_balances | 8 / 8 |
| print_jobs | 2 |

**Saatoon events humaray UAT ke hain** — `mohsin`, `mohsin sajjad`, `MR,TABISH`,
`MR. BABLOO`, `TABISH`, `MR MOHSIN`, `MR. TABISH` (23–28 Aug ko banaye).

**Paisa bilkul saaf hai — ye is poori kahani ki sab se ahem baat hai:**

```
journal_entries         0        journal_lines            0
catering_advances     Σ 0        catering_final_invoices Σ 0
catering_refunds      Σ 0
```

Yaani **koi GL entry nahi bani, koi paisa record nahi hua.** Is ka matlab: test data
hatana finance ke lehaz se be-khatar hai — koi ledger un-wind nahi karna parega. Agar
ek bhi advance ya final invoice hota to yeh kaam bilkul aur shakal ka hota.

Aur ye 8 stock rows bhi humari apni hain — `reference_type = 'catering_uat_seed'`,
19 Aug ko dali gayi opening stock, sirf UAT chalane ke liye.

---

## 1. Test data hatana — kya `tenant:reset-transactions` kaafi hai?

### Command kya karta hai

`app/Console/Commands/TenantResetTransactionsCommand.php` — 89 transaction tables
truncate karta hai, 24 master tables ki ginti pehle aur baad mein match karta hai, aur
mismatch par FAILURE deta hai. Catering documents (bookings, estimates, advances,
refunds, final invoices, production releases, material issues, revisions, email logs,
reminders) **sab wipe list mein hain** — `KASHIF-CATERING-UAT-RESET-1` ne ye pehle hi
add kar diya tha. Catering CONFIG (profiles, cost blocks, Material Rate Book, commercial
rates, instructions, service-time presets, printer mappings, settings) **KEEP list mein**
hai, bilkul waise jaise recipes.

Kashif ke aaj ke data par ye **teenon cheezein poori tarah cover karta hai** —
catering documents, 8 UAT stock rows, aur 2 print jobs.

### Do cheezein jo reset NAHI karta (aur nahi karni chahiye)

1. **Customers ko haath nahi lagata** — wo master data hain. §2 dekhein.
2. **Numbering restart ho jaati hai.** `CateringNumberService` saal ka counter mojooda
   rows se nikalta hai, is liye truncate ke baad pehli live booking `EV-2026…-0001`
   banegi — **go-live ke liye bilkul yahi chahiye.** Lekin isi wajah se: **live jaane ke
   baad reset dobara mat chalana**, warna naye documents un numbers ko dobara istemal
   karenge jo client pehle chhaap chuka hoga.

---

## 2. Customers — jo aap ne poocha, aur jo data kehta hai

> "customer jo humne khud dale thay live karne k bad wo remove hojaen ga, sirf kashif
> kitchen k apne genuine customers rahen gay"

**Data is se ittefaq nahi karta, aur ye achhi khabar hai:**

```
total customers          4,848
legacy (code = C-<phone>) 4,848      ← 100%
humaray banaye hue            0      ← ek bhi nahi
created_at buckets            1      ← sab 2026-08-23 22:04:58, ek hi import
```

**Humne apna ek bhi customer nahi dala.** Saray 4,848 client ke apne purane software ki
order book se aaye hain. Wo 5 customers bhi jinke saath humare test bookings judi hain
(`MR. BABLOO`, `MR MOHSIN`, `MR,TABISH`, `MR. TABISH`, `TABISH`) — wo bhi legacy rows
hain, humari banai hui nahi. Test **booking** hatane se customer nahi hatta, aur hatna
bhi nahi chahiye.

To **delete karne ko kuch hai hi nahi.** Jo karne wala kaam hai wo alag hai — safai:

| masla | ginti | kya hai |
|---|---|---|
| phone kharab | **165** | purane software mein DO number ek hi khaane mein thay; import ne poori string ko phone samajh liya. Misal: `030558600040341455877` = `03055860004` + `0341455877` |
| naam ek jaisa, phone alag | 527 groups | "MR ALI" jaise aam naam — ye alag alag asli log hain, **delete nahi karne** |
| phone ghayab | 0 | — |
| naam khaali | 0 | — |
| phone duplicate | 0 | phone hi identity hai (`code = C-<phone>`), is liye duplicate mumkin hi nahi |
| address maujood | 4,086 / 4,848 | 84% |

**Meri tajweez:** customers delete na karein. Un 165 kharab phone numbers ko theek
karein — pehla 11-digit mobile phone field mein, doosra address ya notes mein. Ye asli
customers hain jinka doosra number ghalti se number ke saath chipak gaya; unhein mitana
client ki apni customer book ka nuqsan hoga. Agar phir bhi client kehta hai ke "purani
list nahi chahiye, sirf naye customer khud add honge" — to wo **client ka faisla** hai,
technical majboori nahi; wo bhi ho sakta hai (`customers` + `customer_addresses` khaali
karna) magar tab 4,848 asli numbers zaya honge.

---

## 3. Suppliers — `public/old_software.xlsx` mein kya mila

Workbook mein **369 sheets** hain. Vendor/supplier ki shakal wali sheets ye nikleen:

| sheet | rows | asal mein kya hai |
|---|---|---|
| **`V_Supplier`** | **252** | **asli supplier master** — GL account `201002` ke neeche |
| `V_SUPPLIERITEM` | 8,434 | naam dhoka deta hai — is mein supplier hai hi nahi; ye ITEM ka **rate history** hai (ITEMID, ITEMDESC, DELIVERYDATE, RATE) |
| `tbl_Party` | 30 | supplier nahi — `CASH`, `KASHIF FOOD`, `MR NAYAB` jaisi booking parties |
| `tbl_Contactor` | 1 | contractor, supplier nahi |
| `v_PoParty` | 0 | khaali |
| `tbl_glaccount` | 719 | poora chart of accounts (suppliers isi ka hissa hain) |

### `V_Supplier` ka sach — 252 rows, magar kitna kaam ka?

```
accountno  accountdesc  accountref  opdramount  opcramount  staxper  staxno
address  phone  city  discount  accountlavel  TermDays  ntnno
```

Har column ki asal bharai:

| column | kitne rows mein hai | system mein kahan jayega |
|---|---|---|
| accountdesc (naam) | **252 / 252** | `suppliers.name` |
| accountno | 252 / 252 | `suppliers.code` |
| phone | **28 / 252** | `suppliers.phone` |
| address | **2 / 252** | `suppliers.address` — aur un 2 mein se ek `1500000` hai (purane software mein number ghalat khaane mein chala gaya) |
| city | 2 / 252 | — |
| opening balance (credit) | **7** suppliers, Σ **6,491,957** | `suppliers.opening_balance` + GL |
| opening balance (debit) | 7 suppliers, Σ 4,485,746 | wahi |
| ntnno / staxno / staxper / discount / TermDays | **0 / 252** | kuch nahi — bilkul khaali |

Naam bhi bilkul saaf nahi: 252 mein se **238 alag** hain, kyunki `DELETE` naam ki 14
rows aur `EMTY` ki 2 rows purane software ka kachra hain. Yaani **asli suppliers ≈ 236**.

Kai naamon mein hisaab chipka hua hai — `RAFIQ CHICKEN (30) 15`, `MUZAMMIL MUTTON (23) 3`,
`FAISAL BEEF (11) 3`. Ye rate/credit-days ki yaad-dashtein hain jo naam ke saath likh di
gayi thin.

### Jawab: **haan, ho sakta hai — magar ye jaanne ke baad ke kya milega**

Jo cheez mil sakti hai wo hai **~236 supplier ke naam + 28 phone number + ek GL code.**
Address, NTN, sales-tax number, payment terms, discount — in mein se kuch bhi purane
software mein hai hi nahi, is liye import ke baad wo khaane khaali hi rahenge.

Ye phir bhi qeemti hai: aaj Kashif ke paas **ek** supplier hai (`Default Supplier`), aur
purchase karte waqt har vendor haath se banana parega. 236 naam pehle se maujood hone se
purchasing pehle din se chal sakti hai.

**Opening balances alag maamla hain.** 7 suppliers par Σ 64.9 lakh ka credit aur Σ 44.8
lakh ka debit — ye **paisa** hai, master data nahi. Ye tabhi dalna chahiye jab:
- client tasdeeq kar de ke aaj ki tareekh ko ye rakamein waqai wajib-ul-ada hain, **aur**
- ye GL mein jaayein (Dr 3300 Equity / Cr 2100 Accounts Payable — wahi raasta jo Khatri
  ke supplier openings ke liye `52b5c85` mein bana tha), warna trial balance jhoot bolega.

Meri tajweez: **pehle sirf naam + phone import karein (balance 0), openings baad mein
client ki tasdeeq ke saath alag qadam mein.**

### Bonus jo isi workbook mein pada hai

`V_SUPPLIERITEM` ki 8,434 rows dated purchase rates hain (`CHICKEN (REGULAR)`
2025-02-06 par 775, `BEEF (WITH BONE)` 2026-05-05 par 1450). Aaj Material Rate Book mein
sirf **6** materials ka ek-ek rate hai. Chahein to yahan se rate ki poori tareekh bhari
ja sakti hai — magar ye go-live ki shart nahi, alag kaam hai.

### Kaam ka size

Nayi cheez: ek CSV extract (`scripts/extract-legacy-xlsx.php` mein ~40 lines) + ek
guarded import command (`kashifkitchen` allowlist, idempotent `code` par, GL/stock
fingerprint) + guard test. Koi migration nahi, koi naya route nahi, koi permission kaam
nahi — `suppliers` table aur uski screen pehle se maujood hai.

---

## 4. System Reset ka button — chal raha hai, do chhoti kamiyaan hain

### Raasta

```
/system-reset (GET)   → SystemResetController@index  → resources/views/tenant/system-reset/index.blade.php
/system-reset (POST)  → SystemResetController@execute
                          ├─ confirm_code == tenant_code
                          ├─ Hash::check(password, owner ka password)
                          ├─ backup_ack ticked
                          └─ Artisan::call('tenant:reset-transactions', --yes --confirm=kashifkitchen)
                                ├─ Branch Server par refuse
                                ├─ --yes + --confirm match zaroori
                                ├─ 89 tables truncate
                                └─ 24 master sentinels ki ginti before == after, warna FAILURE
```

**Tasdeeq shuda:**
- Blade ka form theen fields bhejta hai — `confirm_code`, `password`, `backup_ack` —
  jo bilkul wahi hain jo controller maangta hai. Button par `confirm()` ki alag warning bhi hai.
- Prod par permission maujood hai aur **sirf Owner** ke paas hai:
  `tenant.system-reset.index` → `[Owner]`, `tenant.system-reset.execute` → `[Owner]`.
- Sidebar link `@can('tenant.system-reset.index')` ke peeche hai, yaani Owner ko dikhega.
- Reset guard test disposable test DB par **5/5 green**.

### Kami 1 — do tables par reset ki koi raai nahi

Command ka apna usool hai: "har table ya jaan-boojh kar wipe ho ya jaan-boojh kar keep".
Aaj ke schema (165 tables) mein **2 tables kisi list mein nahi**:

```
customer_translations   rows=0
supplier_translations   rows=0
```

Abhi nuqsan nahi hai: wipe list explicit hai, is liye ye do **bach jaati hain** — jo
in ke liye durust bhi hai (master data ka tarjuma). Magar command har reset par warning
chhaapta hai, aur usool ye hai ke faisla likha hua ho. Fix = `KNOWN_KEPT` mein do naam
jodna.

### Kami 2 — guard sirf catering tables dekhta hai

`CateringTenantResetMySqlTest::test_no_catering_table_is_left_unclassified` sirf un
tables ko parakhta hai jo `catering_` se shuru hoti hain:

```php
$catering = array_values(array_filter($all, fn ($t) => str_starts_with($t, 'catering_')));
```

Isi wajah se upar wali do tables kisi guard mein nahi pakdi gayin. **Yehi wajah hai ke
`customer_translations` mahinon se be-rai pari rahi aur kisi test ne shor nahi machaya.**
Fix = guard ko poore schema par chalana (jaise command khud chalata hai), sirf catering
prefix par nahi.

### Ek dhyan dene ki baat (bug nahi)

`Artisan::call` HTTP request ke andar chalta hai. Kashif ki tables chhoti hain to ye
lamhon ka kaam hai (nginx par 300s ki gunjaish hai), magar ek bhari tenant par yeh
timeout kar sakta hai. Aaj koi khatra nahi.

---

## 5. Go-live ka tajweez karda tarteeb

Har qadam se pehle uska sabot, har qadam ke baad uski tasdeeq.

| # | qadam | tasdeeq |
|---|---|---|
| 0 | **Backup** — `tenant_kashifkitchen.sql` (auto-backup cron chahe chal raha ho, ek manual bhi lein) | file ka size + dump ki aakhri line |
| 1 | Do reset kamiyaan theek karein (§4), suite green, deploy | `customer_translations` + `supplier_translations` classified; guard poore schema par |
| 2 | **Reset chalayein** — Owner khud UI se, ya `tenant:reset-transactions kashifkitchen --yes --confirm=kashifkitchen` | events/estimates/releases/revisions/stock/print_jobs = 0; products 915, customers 4,848, profiles 909, blocks 1,119, translations 939 **jyun ke tyun** |
| 3 | 165 kharab phone theek karein (§2) — sirf phone field, koi row delete nahi | malformed count 165 → 0; total 4,848 be-harkat |
| 4 | *(agar owner haan kahe)* suppliers import — 236 naam + 28 phone, balance 0 | suppliers 1 → ~237; journal_entries 0 hi rahe |
| 5 | Operator role + users banayein — abhi sirf Owner hai | naya role, additive `givePermissionTo`, phir `system:clear-tenant-permission-cache` |
| 6 | Khatri + Kashif Food ka integrity proof (ye kaam un se juda hai, phir bhi sabot) | dono ka order count + Σ grand_total pehle jaisa |

**Qadam 5 dhyan talab hai:** aaj tenant par sirf 1 user aur 1 role (`Owner`) hai. Live
jaane ka matlab hai ke asli staff booking karega — us ke liye alag role chahiye, aur
naye route ka permission `deploy.sh` sirf Owner ko deta hai (memory ka standing rule).

---

## 6. Owner se poochne wali baatein

1. **Customers:** data kehta hai humne ek bhi nahi dala — saray 4,848 aap ke apne purane
   software se hain. Kya inhein rakhna hai (meri tajweez: haan, phone theek kar ke), ya
   waqai poori list khaali karni hai?
2. **Suppliers:** ~236 naam + 28 phone import karein? (baqi khaane purane software mein
   hain hi nahi)
3. **Supplier opening balances:** 7 suppliers, Σ 64.9 lakh wajib-ul-ada + Σ 44.8 lakh
   advance — kya ye aaj bhi sach hai? Agar haan to GL ke saath alag qadam mein.
4. **Reset kab?** Reset ke baad booking numbers `0001` se shuru hongay — is liye ye
   client ke pehle asli booking se **theek pehle** chalna chahiye, uske baad kabhi nahi.
5. **Purchase rate history** (8,434 dated rates) — abhi chahiye ya baad mein?

---

## 7. Khatra aur us ka jawab

| khatra | jawab |
|---|---|
| Reset se master data ud jaye | Command khud 24 master tables ki ginti before/after milata hai aur farq par FAILURE deta hai. Uske upar backup. |
| Kisi doosre tenant par asar | Har cheez `tenant_code` se chunti hai; reset command `--confirm=<code>` maangta hai. Khatri/Kashif Food/Tawakal ka koi code is kaam mein chhua tak nahi jaata. |
| Reset dobara chal jaye aur numbers takra jayein | Go-live ke baad Owner ko batana; chahein to reset ko is tenant par band kiya ja sakta hai (alag faisla). |
| Supplier openings se trial balance bigde | Openings pehle import mein shamil hi nahi. Alag qadam, GL ke saath, client ki tasdeeq ke baad. |
| 165 phone fix se customer gum ho jaye | Sirf `phone` column likha jayega, row kabhi delete nahi hogi; before/after mein total 4,848 sabit karna. |

---

## 8. Aaj is document ke liye kya chala (record)

- **Chala:** prod par sirf READ-ONLY queries (ginti, sample rows); workbook ka read-only
  probe; `CateringTenantResetMySqlTest` disposable test DB par (5/5 green).
- **Nahi chala:** koi reset, koi import, koi migration, koi deploy. Local kashifkitchen par
  reset chalane ki koshish auto-mode classifier ne roki — sahi roka; live-fire proof
  Owner ki ijazat ka muntazir hai.
- **Prod HEAD:** `4ca972e` — is research se koi code nahi badla.
