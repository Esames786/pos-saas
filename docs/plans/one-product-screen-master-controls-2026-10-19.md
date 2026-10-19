# Ek Product Screen + Bingoo Master Controls — Plan (v2)

Date: 2026-10-19 · Status: **raat ko bana (owner: "saara kaam karke rakhna, bhale live kar dena")** — tafseel neeche "Raat ko kya bana".
Prod: `a9499a10` · System map: https://claude.ai/artifact/3aVXbRq9PAEoWn1ivwMnKs (schema + code se khud bana)

## Raat ko kya bana (19 Oct)

| Cheez | Haal | Kahan |
|---|---|---|
| Primary Business (tenant ki setting) + switches + har tabdeeli ka record | ✅ bana | master `tenants.primary_business`, `tenants.features`, `tenant_setting_changes`; `App\Support\Business`, `TenantFeatures` |
| Karobar badalne ki hifazat (asar dikhao, plan me module na ho to roko, tenant code likh kar tasdeeq, data nahi badalta) | ✅ bana | `TenantBusinessService`, `TenantBusinessController` |
| Nayi product screen (Add = Edit, tasveer wale cards, haan/nahi, simple English + Urdu, SKU khud, barcode bana kar label print, dawai ka salt / strength / nuskha) | ✅ bana | `tenant/products/form-v2.blade.php`, `App\Support\ProductCards` |
| Live tenants par asar | Nahi — switch Khatri / Kashif Food / Tawakal / Kashif Kitchen par **OFF**; demos par ON | Master → tenant → switches |
| Upar ka button karobar ke mutabiq (POS / Counter / Events / Production) | ✅ bana | `partials/header.blade.php` |
| Counter search salt se | ✅ | POS |
| Master universe, tenant ka ghar, module ke bade dabbe | 🔧 alag worktree me ban raha (`pos-saas-masterui`) | — |
| Box / Strip / Goli (packs) | ❌ nahi — GRN, counter aur return teeno chahiye (1-2 hafte); screen par jhoota nahi dikhaya | — |
| Master admin ka password har deploy par "password" ho jata tha | ✅ seeder theek (ab sirf naye install par banta hai); **owner password khud badle** | `MasterSeeder` |

## 0. Ek nazar me

1. **Bingoo Master** me har tenant ka **Primary Business** (Restaurant · Catering · Medical Store · Mart · Industry) — tasveer se chuna, signup par bhi poocha. Saath me **Features** ke switch (tenant / branch level, saaf likha kahan asar).
2. **Ek hi product screen** (Add = Edit): pehle "Aap kya add kar rahe hain?" (sirf apne karobar ki tasveerein), phir ek page par hisse — har hisse ka sawal "Haan / Nahi"; jawab haan to us ka poora hissa khule. SKU, barcode, stock, khareed, tax, recipe, catering — sab wahin.
3. **12 screens kam**: Catering Materials aur Manufacturing Products ke alag forms, Catering Profiles, Cost Blocks ki alag screen, Recipe aur BOM ke alag forms product screen me (§5).
4. **Permissions aasaan**: module = screens ka set, ek click; har button ke saath "is se ye screens / ye permissions"; "Sync permissions" ka manual button khatam (§3.3).
5. **Primary badalna**: pehle asar dikhaye (kitne products, kaun se cards/menus jayenge), tasdeeq ke baad; data kabhi nahi badalta (§3.1).

---

## 1. Ghaltiyan (pharmacy banate hue)

| # | Ghalti | Sahi kya tha |
|---|---|---|
| 1 | Product screen pehle theek karne ka hukm tha; maine canvas me design kiya, code nahi, aur demo pehle bana diya. | Pehle product screen, phir demo. |
| 2 | "Abhi wala software" me saaf nahi likha ke product/GRN/POS purane dikhenge. | Har option ke saath "client ko ye screens is shakal me dikhengi". |
| 3 | "Ready" kaha sirf backend tests par; screens client ki nazar se nahi kholin. | Har menu kholo, screenshot, checklist — phir ready. |
| 4 | Karobar ka faisla jugaad se: alag plan `pharmacy_demo`; kitchen popup modules dekh kar; "modules se andaza lagao". | Primary Business — Master/signup ki setting. |
| 5 | Salt naam me daala; patta = qty 10 (waqti hal, saaf nahi kaha). | Generic/Salt khana; Box/Strip/Goli packs. |
| 6 | Pharmacy tenant me bhi restaurant floor, 6 tables, waiter ban gaye. | Base data karobar ke mutabiq. |
| 7 | 3 create-product screens pehle se thin; maine ek aur karobar jodne ka socha, inhein ek karne ka nahi. | Ek product screen. |
| 8 | Har kaam se pehle "kya kya hilega" ka naqsha nahi; memory padh padh kar andaza. | System map (bird's-eye) — har plan me impact list (§2). |
| 9 | Owner ke samjhane ke beech plan aur sawal shuru kar diye. | Poori baat suno, dohrao, phir ek tajweez. |

---

## 2. System map — ab har kaam isi se

- `php tools/system-map/build.php --tenant-db=… --master-db=…` → `docs/system-map/system-map.json` + `index.html`.
  Database (foreign keys + `_id` rishte) aur code (route → controller → models / services / `DB::table`) dono se khud banta hai.
- Aaj: **222 tables · 422 foreign keys · 198 naam se rishte · 722 routes**. Misaal: `products` — 40 tables FK se, 13 naam se, 303 routes chhoote hain.
- **Qaida:** har plan ke shuru me "Asar" ki list map se — kaun si tables, kaun si screens, kaun se tests. Map har badlav ke baad dobara banega.

---

## 3. Bingoo Master par kya badlega

### 3.1 Tenant → Karobar (Primary Business)

| Cheez | Kaise |
|---|---|
| Kahan | Master → Tenants → Edit → **Karobar** tab; aur website signup ka pehla sawal |
| Chunao | 5 tasveer wale cards: Restaurant · Catering · Medical Store · Mart / Dukaan · Industry |
| "Ye bhi karta hai" | Sirf woh karobar jin ke modules plan me hon (restaurant jo catering bhi karta ho) |
| Asar ka preview | Isi screen par: upar ka button (POS / Events / Counter / Production), sidebar, product ke cards, "kabhi nahi dikhega" |
| Record | Kisne, kab, pehle kya tha |
| Maujooda tenants | Likhi list se, owner ki manzoori ke baad: khatribiryani, kashiffood, tawakalkashif = Restaurant · kashifkitchen = Catering · pharmacydemo = Medical Store |

**Primary badalna — hifazat:**
1. "Karobar badlo" dabane par pehle **asar ki khidki**: is tenant ke products kis kis type ke kitne hain (misaal: "212 menu items, 38 kitchen ingredients, 0 medicines"), kaun se cards aur menu chale jayenge, kaun si screens nahi dikhengi.
2. Jo products naye karobar ke kisi card me nahi aate, woh product list me "Purane karobar ke products (N)" me alag dikhenge — mitenge nahi, kholne par Advanced me.
3. Tasdeeq: tenant code type karo → "Badlo". **Data ko haath nahi lagta**; sirf screens badalti hain; wapas badla ja sakta hai.
4. **Roka jayega** agar naye karobar ke zaroori modules plan me na hon (misaal: Catering banana hai magar plan me catering module nahi) — "pehle plan badlein".
5. Live tenant (jahan sale ho chuki) par laal tambeeh: "Is tenant par roz kaam hota hai; staff ki screens badlengi."

### 3.2 Tenant → Features (switch, saaf level ke saath)

| Feature | Level | Kahan asar | Naya tenant |
|---|---|---|---|
| Box / Strip / Goli (packs) | Tenant | Product → Khareed · GRN · Counter · Return | Off |
| Batch aur expiry | Tenant | Product → Stock · GRN · Expiry report | Off (Medical Store par On ki tajweez) |
| Expired maal bechne se roko | Tenant | Counter | Off |
| Salt / generic se dhoondo | Tenant | Product → Medicine · Counter search | Off |
| Kitchen order ticket (KOT) | Branch | Counter → Pay · kitchen printer | Restaurant par On |
| Catering cost blocks | Tenant | Product → Catering · Quotation | Catering par On |
| Website se order | Tenant | Website handler | Off |
| Minus stock par bechna · Return par manager PIN | Branch | Counter · Return | Off (aaj jaisa) |

Ye switch aaj ke jugaad hatate hain: `pharmacy_demo` plan ka matlab, kitchen popup ka modules se andaza (`KITCHEN_TICKETS`) — dono in settings par aa jayenge.

### 3.3 Modules aur permissions — aasaan

| Aaj | Banega |
|---|---|
| Module edit me "Route module keys" ka **text khana** (route ke naam haath se) | Route → module **khud** (routes se); screen par sirf parhne ke liye list: "is module me ye 12 screens" |
| Plan Builder me modules ki checkbox list | **Module cards**: ek click on/off; har card par "N screens · M permissions", khol kar poori list (seedhi zubaan me: "GRN banana", "GRN void karna") |
| Tenant ke liye module alag nahi | Tenant → **Modules** tab: plan se aaye modules + "is tenant ke liye extra / band" (record ke saath) |
| "Sync permissions" ka manual button | Deploy par khud; Master me sirf **"Check"** — farq dikhaye (kis role ko kya naya mila / kya gaya), kuch na chhede |
| Tenant ke andar role editor: route naamon ki lambi list | **Screen-wise**: har screen ki ek line, khane: Dekhna · Banana · Badalna · Mitana; tayyar sanche: Owner · Manager · Cashier · Store keeper; har button par "is se ye 8 permissions" |

### 3.4 Signup
Pehla sawal "Aap ka karobar?" (wahi 5 tasveerein) → plans ki sifarish usi karobar ki; tenant ban-te hi primary business set, base data usi ka (restaurant ko tables, pharmacy ko nahi).

---

## 4. Ek product screen — kya karegi

### 4.1 Pehla qadam: "Aap kya add kar rahe hain?" (sirf apne karobar ki tasveerein)

| Karobar | Cards |
|---|---|
| Restaurant | Menu item (recipe andar poochi jayegi) · Ready-made (cold drink, crate) · Kitchen ingredient · Packaging |
| Catering | Menu dish (per head / kg / deg) · Ingredient · Disposables & packaging · Service / hire |
| Medical Store | Medicine · Store item (shampoo, sabun, toothbrush) |
| Mart | Packed product (barcode) · Loose by weight |
| Industry | Raw material · Finished product · Semi-finished · Packing material |

### 4.2 Phir ek page, hisse — har hisse ka sawal Haan / Nahi

| Hissa | Sawal | Haan par kya khule | Kis ke liye |
|---|---|---|---|
| Basic | — | Tasveer, naam, category, kis hisaab se bikta (piece / plate / kg / litre), sizes (half / full) | Sab |
| Bechna | Customers ko bechte hain? | Qeemat, counter par tasveer/rang, kitchen printer, tax | Card khud batata hai; packaging/ingredient par band (likha hua kyun) |
| Khareed | Supplier se khareedte hain? | Khareed ki qeemat, supplier, "1 carton = __ piece"; Medicine: "1 Box = __ Strip, 1 Strip = __ Goli" | Sab |
| Stock | Stock ginna hai? | Opening stock, kam hone par alert; Medicine: batch + expiry (on, likha hua) | Service par band |
| Banane ka tareeqa | Doosri cheezon se banta hai? | Recipe (restaurant/catering) / BOM (industry) ki lines yahin | Restaurant · Catering · Industry |
| Medicine details | — | Salt / generic, strength, form, company, nuskha (Rx) chahiye? | Medical Store → Medicine |
| Catering | — | Party ka saman la sakti hai (party supply), complimentary, minimum qty, per head / fixed, costing (recipe / blocks), quote unit, production station, Urdu naam, cost blocks | Sirf Catering |
| Code & barcode | Barcode chahiye? | SKU (khud bane / Generate / apna), barcode scan ya Generate (sahi EAN-13), label print | Sab |
| Add-ons | Iske saath add-ons hain? | Modifier groups jodna (aaj product ki alag show page par) | Restaurant |
| Website | — | Website ki qeemat / tasveer (sirf jin ke paas website module) | Website wale |

- Daayein taraf **"Kya hoga" ka dabba**, jawab ke saath badalta: "POS par nahi bikega · supplier se aayega · stock gina jayega".
- **Ek sawal ek dafa.** Card jo tay kare woh checkbox se dobara nahi.
- **Add = Edit**: edit par wahi screen, purane data se bhari; jo stock wala product bik chuka, us ka "stock ginna" band nahi ho sakta (likha hua kyun).

### 4.3 Server ke qaide jo bilkul wahi rahenge (jaanch se)
- Raw / packaging → bechne aur POS se bahar, BOM me lag sakta.
- Stock band + `stock_item` → `none`; recipe wahi.
- SKU, barcode, slug unique.
- **Aaj sirf browser me** hain, ab server par bhi: service → stock/khareed band; finished good → khareed band; tax rate > 0 → taxable.
- **Har chhupa khana wohi qeemat bheje** jo saved hai (aaj edit par chhoota khana khali/false ho jata hai).

Purane bug jo saath theek honge: edit par reorder level save nahi hota · barcode sirf banate waqt · SKU badlo to default variant ka SKU nahi badalta · Catering Materials list se finished good edit karo to raw material ban jata hai.

---

## 5. Screens ka hisaab (38 product-related screens)

| Screen | Faisla | Kyun |
|---|---|---|
| `/products` list | **Rahegi** — ek hi list, upar type ke filter | Sab products ek jagah |
| `/products/create`, `/products/{id}/edit` | **Nayi product screen** | — |
| `/products/{id}` (show) | Rahegi (khulasa); barcode panel aur add-ons jodna product screen me | Do jagah ek kaam nahi |
| `/catering/materials` (+create/edit) | **Hategi** → products list (filter: kitchen purchase) / product screen (redirect) | Alag form tha |
| `/manufacturing/products` (+create/edit) | **Hategi** → products list (filter) / product screen (redirect) | Alag form tha |
| `/catering/profiles` | **Hategi** → product screen ka Catering hissa | Settings product ki hain |
| `/catering/profiles/{id}/blocks` (cost blocks) | **Hategi** → product screen ka Catering → Costing | Wahi |
| `/recipes` (+create/edit/show) | Lines product screen ke "Banane ka tareeqa" me; list rahegi (overview) | Recipe dish ka hissa hai |
| `/manufacturing/bom` (+create/edit/show) | Wahi — Industry ke "Banane ka tareeqa" me; list rahegi | BOM product ka hissa |
| `/catering/material-rates`, `/catering/commercial-rates`, `/catering/rate-impact` | **Rahengi** (rate books, product banana nahi); product screen se link | Rates ki list |
| `/modifier-groups` | Rahegi (add-on groups bahut products me) — jodna product screen se | — |
| `/combos` | Rahegi (deal apni cheez) | — |
| `/categories`, `/units`, `/unit-conversions` | Rahengi (settings) | — |
| `/products-bulk-import` | Rahegi + naye khane (salt, strip/box size, barcode) | Pehle din ki list |
| Manufacturing production orders / requisitions / FG receipts | Rahengi (kaam ki screens, product banana nahi) — sirf Industry ko | — |

Purane URL redirect karenge — koi bookmark ya link nahi tootega. Kul **12 screens kam**.

---

## 6. Kya hil sakta hai aur kaise bachayenge

| Khatra (jaanch se) | Bachao |
|---|---|
| Nayi screen koi khana na bheje → edit par woh false / khali (POS se gayab, qeemat 0, tax band) | Har khana hamesha bheja jaye (chhupa ho to saved qeemat); test: purane product ko kholo-save karo → koi khana na badle (Khatri/Kashif ke asli products ki copy par) |
| `product_kind` galat → cost blocks, rate books, store issue se material gayab | Card → kind ka naqsha test me pakka; Catering ke tests |
| `is_stock_tracked` band → stock wale modifier ka checkout toot jaye | Server roke: jo product kisi stock-wale modifier ya recipe me laga ho us ka stock band na ho (wajah ke saath) |
| `inventory_consumption_method` chhoota → recipe dish ka maal na kate, COGS galat account | Card / "banane ka tareeqa" se khud; test |
| `unit_id` badla, conversion nahi → checkout throw | Unit badalne par check + tambeeh |
| Teen render tests (`_area`, `_setup_mode`, `var MODES`) | Naye screen ke mutabiq dobara; server wale contract tests (ProductArchetypeContract) waise hi green |
| Catering profiles product screen me aayen | Same table (`catering_product_profiles`), same service; sirf jagah badle |
| Live tenants | Primary business likhi list se; screen badle, data nahi; har deploy se pehle/baad permissions + plans + screens ka milan; parallel sessions ka worktree map aur `git fetch` pehle |

---

## 7. Kaam ki tarteeb (har phase: design → owner ki haan → wahi banao → design vs bani screen ki tasveerein → haan → deploy)

| Phase | Kya | Andaza |
|---|---|---|
| 1 | Master: Karobar tab (primary + asar preview + badalne ki hifazat) + Features switches + maujooda tenants ki likhi list | 2 din |
| 2 | Ek product screen (cards, hisse, SKU/barcode/label, Medicine details, Add = Edit) + Catering Materials / Manufacturing Products / Catering Profiles / Cost Blocks ka milap + redirects | 4-5 din |
| 3 | Modules aur permissions ki nayi Master screen + tenant role editor screen-wise | 3 din |
| 4 | Pharmacy: packs (Box/Strip/Goli), GRN saaf + entry row, counter POS | 1-2 hafte |
| 5 | Recipe / BOM lines product screen ke andar · signup ka sawal | 3-4 din |

## 8. Owner ke faisle (baqi)

1. Industry ka main screen (button "Production" kahan le jaye)? ⏳
2. Mart alag karobar rahe? ⏳ (tajweez: haan)
3. Maujooda tenants ki list (§3.1) theek? ⏳
4. Phase 1 kab? ⏳ — owner design dekh kar
