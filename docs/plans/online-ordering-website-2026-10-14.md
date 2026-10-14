# ONLINE-ORDERING-1 — Tenant ki apni ordering website (pehle Kashif Food)

Date: 2026-10-14 (teen round ke faisle) · Status: **Phase 1 bana (branch `feat/online-ordering-20261014`,
push/deploy NAHI)** · Research: `docs/research/kashiffood-website/`

## 0. Phase 1 — jo bana (15 Oct)

- Master `platform_settings` + `BillingRates` (WhatsApp rate/laagat, website commission %); WhatsApp ke teeno
  parhne wale isi se; platform admin → **Billing Rates** screen. Pehli bharai `.env` ki maujooda qeemat se.
- Master `geo_areas`: Karachi ke 74 areas (75 me "FB Area Block 7" do dafa tha).
- Module `online_ordering` (kisi plan par khud nahi; `ROLLOUT_GATED_MODULE_KEYS`). MasterSeeder ka purana
  quick_sale/standard loop aur teeno onboard commands ab gated modules ko na on karte na off.
- Tenant: `web_settings`, `web_order_routes`, `delivery_zones`, `products/combos.website_price`.
- Screens: **Online Ordering → Website Settings** (on/off, branch tareeqa, ETA, waqt, order type → terminal +
  backup, auto-cancel, WhatsApp toggles, contact) aur **Delivery Areas** (master list, tick, branch, fee,
  min order, free above, ETA). Website bina delivery terminal ke on nahi hoti.
- Product + deal form par **Website Price** (sirf module wale plan par; khaana na ho to save purani qeemat
  nahi mitata; POS qeemat kabhi nahi chhuti).
- `online-ordering:import-areas {tenant} {file} --branch= [--yes]` — purani website ki area JSON se fees
  (dry run default; duplicate par pehla rakhta hai aur batata hai).
- (Phase 1 me `show_on_website` nahi tha — Phase 2a ke faisle ke baad aaya, neeche.)

## 0b. Phase 2a — website menu MOJOODA catalog se (owner, 15 Oct)

Owner ke sawal/faisle: "alag website product ya category screen to nahi ban rahe? catalog ki screen pehle se hai";
"live ke baad modifier web ki tarah add karoge to POS par reflection aayegi?"; "recall par modifier chalega?";
**"bas mera POS kharab na ho is poore website ke process me"**.

- **Koi nayi screen nahi.** Category, product aur deal ki mojooda screens par "Website" hissa (sirf module wale
  plan par): show on website, card naam + size label (aik card naam = aik card ke sizes), website category
  (khaali = POS category; POS ki tarteeb nahi badalti), tag, Popular Items, website order, **website photo aur
  website description alag** (POS tile apni tasveer rakhta hai — product ki POS photo POS tile par dikhti hai,
  website ki tasveerein waha daalne se counter badal jata), website price (Phase 1). Category: show on website,
  website naam, tarteeb, waqt (Midnight 00:00–04:00).
- **Add-ons = POS ke Modifier Groups**, har group par "Show on: POS only / Website only / POS + Website"
  (`modifier_groups.channel`, default `pos` → har mojooda group waisa hi). "Website only" group POS ko bheja hi
  nahi jata (`POSController` filter) — cashier ko koi naya popup nahi. Deal par group sirf website ke liye
  (`combo_modifier_group`).
- **Recall:** POS cart modifiers order line ke SAVED data se banata hai (product ke groups se nahi), server bhi
  line ke modifiers waise hi save karta hai, KOT/bill par chhapte hain → website-only modifier recall/hold/KOT
  par chalega. Kami: line ka "modifier edit" (pencil) sirf POS groups dikhata hai → Phase 3 me recall kiye
  website order par website groups bhi dikhayenge.
- Kashif Food POS me aaj sirf 1 modifier group: "Steak Side" (French Fries / Vegetable Rice, muft, 6 steaks),
  30 din me 0 dafa use. Deals par POS modifier ka nizam nahi tha.
- `online-ordering:import-menu {tenant} {menu} --map= --crawl= --images= [--yes]`: sirf `website_*`,
  show_on_website, "Website only" groups aur pivots likhta hai; POS naam/qeemat/tasveer/category kabhi nahi.
- **Read-only "Website Menu" preview** (edit nahi): menu jaisa customer dekhega + "POS par hai, website par nahi".
- **POS safety rule:** har column ikhtiyari, default = aaj ka rawayya; POS par sirf aik server-side filter;
  har commit se pehle POS ke saare tests (POS, held/recall, drafts, deals, modifiers, KOT/printing, sales,
  shifts, tables, delivery, riders, reset — 664). Edge (live nahi) abhi nahi chhua: **Edge live karne se pehle
  bootstrap me `modifier_groups.channel` bhejna zaroori**, warna Edge POS par website-only group dikhega.
- Tests: `OnlineOrderingSetupMySqlTest` (14), `BillingRatesScreenMySqlTest` (4), `WhatsAppUsageLedgerMySqlTest`
  (13, rate wala hissa naya). Sabotage: 5 guards toR kar dekhe, har aik ne apna test laal kiya.
- **Prod par activation ke qadam (jab malik kahe):** deploy → `kashif_restaurant` plan par module on →
  `online-ordering:import-areas kashiffood <webarea.json> --branch=MAIN` (pehle dry run) → Website Settings me
  Delivery terminal → Sting/Al-Faham website price. (**Midnight Deal 17 prod par bana diya 15 Oct: combo #76 `KF-MID-GARLICSHASH`**, #75 ki cheezein, Midnight, 1,550.)

## 0c. Phase 2c/3 — website order POS par: code se nikli haqeeqatein (15 Oct, read-only trace)

- **Likhne ka raasta:** draft/held sirf `HeldSaleController::store()` me likha jata hai (inline, HTTP + logged-in
  user par mabni; koi service nahi). Status hamesha `held`, draft = `is_draft=true`. KOT **browser** bhejta hai
  (Hold ke baad `/printing/jobs/kot/{sale}`), server nahi — server par bana draft KOT nahi bhejta; cashier ke Hold
  par poori KOT jati hai (`kot_sent_quantity=0`).
- **⚠ Mera pehla bayan ghalat tha:** "server Held par rider nullable leta hai" — sach ye hai ke
  `validateDeliveryAttribution` **own channel ke delivery order par rider LAZMI** karta hai (Hold par bhi).
  Faisla: POS ka code nahi badlega — cashier ka Hold/Pay waise hi rider maangega (malik ka usool). Sirf
  website ka apna service (draft banana, aur WhatsApp link se auto-Held) bina rider ke likhta hai; rider phir
  website modal se mojooda "rider badlo" endpoint (`tenant.sales-orders.rider.update`) se.
- **Website order ka service:** `WebsiteOrderService` — POS draft jaisi HUBAHU rows (header + lines, deal =
  `combo_header` poori qeemat + `component` 0 par, modifiers JSON `{modifier_group_id, modifier_group_name,
  modifier_id, name, price_delta}`, `unit_price` me modifier shamil), routed terminal + us ki KHULI shift,
  business_date shift ka, sale_no `HS-…`, `order_source='website'` (enum migration), channel "Website",
  customer `CustomerDirectory::findOrCreateByPhone`, address snapshot. Guard test: **aik hi cart POS ke
  HeldSaleController se aur website service se — line rows barabar** (ids/waqt chhoR kar).
- **Recall par jo mit jata hai (POS ke 3 write paths):** re-Hold `notes`/`customer_email` null kar deta hai; Pay
  `order_source` ko `pos` likh deta hai aur terminal/shift paying counter par le jata hai. Is liye: website ki
  saari tafseel (pin, landmark, customer note, website token) **alag column `web_meta` (JSON)** me jise POS
  kabhi nahi likhta; aur **Pay par `order_source='website'` bacha rahe** (SalesOrderController me aik shart +
  test) — warna commission aur reports se website order gayab.
- **Delivery fee:** branch par `delivery_charge_locked` ho to POS re-Hold/Pay par fee branch default se badal
  deta hai → website order ki area fee mit jayegi. **Kashif Food (prod, 15 Oct): lock OFF**, default 0 — fee
  har order par likhi jati hai (30 din: 3,008 delivery orders, 1,261 par fee). Is liye abhi exception ki zaroorat
  nahi; lock wale tenant ke liye baad me.
- **"Website" channel pehle se hai** Kashif Food par (id 3, `own`, 0%) — shayad aaj website (indolj) ke order
  cashier haath se isi par daalte hain. Naya channel NAHI banana; naam "Website" wala dhoondo, na ho to banao.
  Purane haath wale orders `order_source='pos'` hain → 1% commission sirf nayi website (`order_source='website'`) par.
- Kashif Food: 9 active riders; terminal T1 ka naam hi "Delivery" hai.
- **Idempotency:** held path par koi nahi, aur `client_uuid` Pay par overwrite hota hai → website ka apna
  `web_order_key` (unique).
- **Held list:** `terminal_id` ke baghair order terminal-bound cashier ko dikhta hi nahi → routed terminal par
  stamp. Held/draft order shift band hone ko rokta hai (mojooda usool) → bina dekhe koi website order nahi rehta.
- **Auto-cancel:** `KotCancellationService::cancelHeldOrder` ko requesting user chahiye → system/owner user.

## 0d. Asli domain (malik, 15 Oct): kashiffood.com = sirf ordering website, POS bingoopos par

- `tenant_domains` me **domain ki qism**: `app` (POS/admin, `*.bingoopos.com`) aur `website` (client ka apna
  domain, jaise `kashiffood.com` + `www`). Central ke "Domains" box me aaj sirf `____.bingoopos.com` banta hai —
  wahan "Website domain" (poora domain) ka option + DNS ki hidayat (A record → server IP).
- Tenant routes `Route::domain('{subdomain}.bingoopos.com')` par hain, is liye `kashiffood.com` un se match nahi
  karta. Website domain ke liye **alag route group** jo host se tenant pehchane (sirf `website` qism ke domain)
  aur **sirf** website ke public raaste khole (`/`, menu, checkout, order status) — login/POS/admin kabhi
  nahi. `www` → bina-www par redirect.
- Test ke liye website `kashiffood.bingoopos.com/order` par bhi (POS ke saath, login ke baghair public).
- SSL: naya domain cert sync me (aaj HTTP-01 subdomains ke liye `/usr/local/bin/bingoo-cert-sync`) + web server
  par `server_name kashiffood.com www.kashiffood.com`.
- WhatsApp confirm link `https://bingoopos.com/o/{token}` (Meta template ka pakka base) → tenant ke website
  domain ke confirm page par redirect.

## 1. Ek line me

Plan module "Online Ordering" wale tenant ki apni website: purani kashiffood.com jaisa menu aur safar (magar halki, tez
aur animated), menu aur stock **POS se**, qeemat **website ki apni**; order POS me **Draft** ban kar order type ke jure
terminal par **ghanti** bajata hai; customer WhatsApp link se confirm kare to order **Held** (= confirmed) ho kar **KOT**
nikal jati hai; delivery channel **"Website"**. Bingoo: website ki **mahana kul sale ka 1%**, WhatsApp ke kharche se alag.

## 2. Poora safar (end to end)

1. Customer website kholta hai. **Pehli dafa:** Delivery / Pick-Up → area (aur chahe to "Use Current Location").
   **Dobara aaya:** usi device par pichhla area aur address khud chuna hua, area popup nahi; "Deliver to … · change".
2. Area se **branch** tay → usi branch ke bikne wale items, **website price** par. Har jagah **ETA** (area ka).
3. Product popup (size, add-ons, note) → cart (area ki delivery fee, ETA) → checkout (naam, WhatsApp number, address,
   landmark, location pin, Cash on Delivery) → **Place Order**.
4. Server: POS **Draft** (`order_source = website`, order type Delivery / Takeaway, channel "Website", customer + address
   + area + pin), **order type ke terminal** par ghanti + POS ka "Website orders" modal.
5. WhatsApp (agar client ne on kiya): "Order mila, Rs …, **Confirm Order**" link.
6. Customer link dabaye → order **Held** → KOT mojooda Hold raaste se → (on ho to) WhatsApp "Confirmed, ETA …".
7. Link na dabaye → Draft hi rehta hai. Order taker phone kar ke modal se **Hold** karta hai (rider usi waqt chunta hai),
   ya cancel. Client ne **auto-cancel** on kiya ho to N minute baad khud Cancelled (+ WhatsApp agar on).
8. Rider: modal me **Hold** karte waqt, ya recall kar ke save karte waqt chunna lazmi (mojooda POS usool). Link se
   auto-Held order par rider baad me isi modal se.
9. Payment POS par wahi jo aaj (Cash on Delivery). Rider ke baad wala safar ("on the way" waghera) **baad me**.

## 3. Faisle (owner, 14 Oct)

**Pehla round**
- Items ki tarteeb purani website jaisi; website = plan module; pehle Kashif Food.
- Area-wise (area → branch) aur branch-wise (pickup par branch); client waqt set kare; terminal par ghanti.
- Order **Draft** me, cashier POS se Held kare; delivery channel "Website" khud lage.
- Customer ko WhatsApp; confirm link dabane par khud Held + KOT.
- Product/deal par **"Website price"**; delivery areas (75, Karachi) system ke master data me.
- Bingoo ka **1% commission**; dashboard par WhatsApp card ke saath card. ~99% orders Delivery honge.

**Doosra round**
- Rates **`.env` me nahi, master (platform admin) settings me**: WhatsApp fi message charge **aur** commission %.
- Commission = **website ki mahana kul sale ka 1%, delivery waghera sab mila kar** (grand total). Har mahine **payable
  invoice**. **WhatsApp ka kharcha alag, 1% alag.**
- **Teesra round:** **ek invoice, kai child rows** — record bhi rahe, group bhi; WhatsApp par bhi yehi (§5b).
- **Website ke rate alag** rakhne hain (website price). POS ki qeemat nahi chhedni.
- Jo deal website par hai magar POS me nahi (**Midnight Deal 17**) wo POS me banani hai.
- **Order status sirf system wale**: Draft, Held, Paid, Cancelled, Partially Returned, Returned. Koi naya status nahi.
  **Held = confirmed.** Order akhir tak POS screen se hi manage hota hai; POS ka website modal sirf orders **receive** karta hai.
- **Auto-cancel client ki setting**: minute ke saath; off ho to kabhi auto-cancel nahi (aksar order taker khud phone kar
  ke Held karta hai).
- **WhatsApp ikhtiyari service**: client khud chune kis status par message jaye; off kar sakta hai. Templates hum banayenge.
- **Rider usi modal me**: Hold karte waqt ya recall kar ke save karte waqt (rider chunna waise bhi lazmi hai).
- **Rider ke baad ka process abhi nahi**, baad me.
- **Website**: pattern purana, magar UI/UX professional — scroll par animated tasveerein, lazy loading, achhi animations,
  **halki (lightweight)**.
- **Dobara aane wala customer**: address khud chuna ho (cookies ya behtar tareeqa — mashwara §4.12). **ETA** bhi dikhana.

## 4. Mashwara (suggestions)

1. **WhatsApp kab (sab client ke toggle):** Draft par "order mila + Confirm link" (default on), Held par "confirmed"
   (default on), Cancelled par "cancelled" (default on). "Received" off ho to confirm link bhi nahi jayega; phir har order
   order taker phone se Held karega. Har message ka kharcha alag (master rate × messages, mojooda WhatsApp khaata).
2. **Confirm link = phone ki tasdeeq:** sirf wohi confirm kar sakta hai jis ke WhatsApp par message gaya. OTP ka kharcha nahi.
3. **Link ek dafa, 30 min:** dobara dabane par dobara KOT nahi (KOT idempotency pehle se). Link expire hone par order Draft
   hi rehta hai; page kehta hai "restaurant aap ko call karega". Order Cancelled ho chuka ho to link kaam nahi karta.
4. **Website khud band:** waqt se bahar **ya** us order type ke terminal (aur backup) par shift band → "Abhi band hai,
   … baje khulega". Bina dekhe koi order nahi padta.
5. **Order kis terminal par — "order type → terminal"** (har branch: Delivery → T1, Pick-Up → "Delivery wala hi",
   optional backup). Sirf order type: terminals ka aaj koi order type nahi, ghanti har terminal par. Sirf terminal: kal
   Pick-Up/Delivery alag counter par nahi ho sakte. Draft ko dono chahiye hi: drafts/Held/shift terminal ke hisaab se,
   KOT printer `category_printer_mappings` (terminal_id + order_type) se, aur user scope
   (`UserDataScope::deniesSale`, "another terminal or order type") bhi isi jore par chalta hai.
6. **Rider:** "Website" channel `type = own` (warna rider assign hi nahi hota — `SalesOrderController` external channel
   par rider rokta hai). POS form me own channel par rider `required` hai, server Held par rider `nullable` leta hai →
   link se auto-Held bina rider ke theek; modal par "Rider baqi" nishan, cashier wahin chune.
7. **Status ka customer wala naam (naya status nahi, sirf label):** Draft → "Order received, waiting for confirmation",
   Held → "Confirmed, being prepared", Paid → "Completed", Cancelled → "Cancelled". Returns customer ko nahi dikhte.
   Order number wohi jo system deta hai — naya number nahi.
8. **Auto-cancel:** setting `Off` (default) / `N minute`. Sirf **Draft** website orders par; Held ko kabhi nahi chhuta.
   Cancel = mojooda Cancelled status; WhatsApp "cancelled" agar on.
9. **Website price:** product aur deal form par ek khaana; khaali = POS ki qeemat. Kashif Food ke liye pehle se bharna:
   Grill Chicken Al-Fahom Half **1,600** (POS 1,650), Full **3,100** (POS 3,200), Sting Can **180** (POS 160).
10. **Sizes ek card me:** "Website menu" par kai POS products ek card ke sizes (Chicken Biryani = Plain/Small/Large/
    Platter/Family Pack). Add-on = POS product line (Extra Chicken Pcs) ya "With Fries" wale product se badal.
11. **Location:** area dropdown (fee + branch isi se) + "Use Current Location" pin (lat/lng) + address + landmark;
    POS/rider ko Google Maps link.
12. **Dobara aane wala customer — mashwara: device par yaad, cookie banner nahi.** Browser ki apni storage
    (localStorage) me: order type, area, naam, WhatsApp number, address, landmark, pin, pichhla order. Agli dafa area popup
    nahi, header me "Deliver to Gulshan Block 2 · change", checkout bhara hua, "Pichhla order dobara" ka button.
    - Ye zaroori (functional) storage hai, tracking nahi — is ke liye "Allow cookies" ka rok dene wala banner nahi chahiye;
      neeche ek patli line: "Aap ka address isi device par yaad rakha jata hai · Bhool jao".
    - **Phone number likh kar purana address server se bharna NAHI** — koi bhi kisi ka number likh kar us ka ghar ka
      pata dekh leta. POS ki taraf customer phone se mojooda `customers` me milta hai (restaurant ka apna record).
    - Agar baad me Meta Pixel / Google Ads tracking lagani ho, tab us ke liye alag consent.
13. **ETA:** area ka ETA (`delivery_zones.eta_minutes`, warna settings ka default 45) — area list, header chip, cart,
    checkout, "order mila" page, WhatsApp "confirmed". Mashwara: POS modal par **"Busy" button (+15 / +30 min)** rush ke
    waqt, jo website par foran ETA badha de. Pick-Up par "ready in … min".
14. **Website ka design aur raftaar:** §6b.
15. **Custom domain:** pehle `kashiffood.bingoopos.com/order` par test, phir `kashiffood.com` ka DNS hamare server par
    (`tenant_domains` + cert sync). **Payment:** pehle sirf Cash on Delivery / on Pickup.

## 5. Data (naye tables / columns)

**Master (sab tenants ke liye):**
- `platform_settings` (key → value, kis ne kab badla): `whatsapp_rate_pkr` (aaj prod `.env` me **9.85**),
  `whatsapp_cost_pkr` (aaj 5.95), `website_commission_percent` (**1**). Platform admin ki "Billing rates" screen.
  WhatsApp code aaj teen jagah `config('services.whatsapp.rate_pkr')` parhta hai — `WhatsAppChannel` (row likhte
  waqt), `WhatsAppBackfillUsageCommand`, `Central\WhatsAppUsageController` — teeno setting se parhenge; setting khaali ho
  to `.env` fallback (deploy aur pehli bharai ke beech kuch na toote). Fayda: rate badalne ke liye deploy /
  `config:cache` nahi.
- `geo_areas`: country, state, city, area name, sort. Pehli bharai `webarea.json` (Karachi, 75; "FB Area Block 7" do dafa).
- `subscription_invoice_lines` (**naya — invoice ki child rows; aaj system me invoice lines hain hi nahi**): invoice_id,
  line_type (`whatsapp_messages` / `website_commission` / `plan` / `manual`), usage_date (din), description, quantity
  (messages ya orders), base_amount (commission ke liye us din ki sale), rate, rate_unit (`per_message` / `percent`),
  amount (4 decimal; invoice ka total 2 par), timestamps. Unique (invoice_id, line_type, usage_date) — dobara banana
  idempotent. Invoice total = **SUM(lines)**, hamesha.
- `website_order_charges` (record, sirf drill-down ke liye): tenant_id, order uuid, order number, business_date,
  grand_total, refunded, rate_percent, amount, invoice_id. Paid website order ki snapshot — tenant ka data baad me badle
  tab bhi bill khud apni wazahat rakhta hai. WhatsApp ka record pehle se `whatsapp_messages` (invoice_id ke saath).
- `plan_modules` me naya module `online_ordering`.

**Tenant:**
- `web_settings`: on/off, branch mode (single / area-wise), hours (din-wise), closed message, default ETA, banners,
  **auto_cancel_minutes** (null = off), **WhatsApp toggles** (received+link / confirmed / cancelled), SEO, social links.
- `web_order_routes`: branch_id, order_type (delivery / takeaway), terminal_id, backup_terminal_id (nullable).
- `delivery_zones`: geo_area_id, branch_id, fee, min_order, free_delivery_above, eta_minutes, is_active. Kashif Food: 75.
- `web_menu_items` → `web_menu_item_options` (card → size → POS product_id / combo_id); Popular Items ki list.
- `products.website_price`, `combos.website_price` (nullable), `products.show_on_website`, `combos.show_on_website`.
- `sales_orders`: `order_source` me `website` (aaj **enum `['pos','manual']`** — migration chahiye), `web_confirm_token`
  (hash) + `web_confirm_expires_at`, `geo_area_id`, `delivery_lat`, `delivery_lng`. **Koi `web_status` nahi** — status
  wohi system wala. Pick-Up = mojooda `order_type = 'takeaway'`.
- `delivery_channels`: ek row "Website", **`type = own`** (rider ke liye), commission_percent 1 (sirf tenant ki "By
  Channel" report ke liye; GL nahi).

## 5b. Bingoo ka 1% commission (WhatsApp se alag)

- **Rate:** master `platform_settings.website_commission_percent` = 1. Platform admin screen se badle; chalte (khule) mahine
  par naya rate lagega, band mahine nahi hilte (rate row par likha).
- **Raqam (tay, owner 14 Oct):** **sirf website orders** (poore restaurant ki sale nahi) ki mahana kul sale
  (`grand_total`: items + delivery fee + tax waghera sab) × 1%. **Net sale** (owner: "jo best option ho"): Paid aur
  Partially Returned orders, returns minus. Draft / Cancelled par kuch nahi. Return agle mahine ho aur pichhli invoice band
  ho chuki ho, to us mahine ki line me minus (band invoice nahi hilti).
- **Ek invoice, kai child rows (owner, 14 Oct, teesra round)** — "record bhi rahe, group bhi". Teen tah:
  1. **Invoice** (parent): har tenant ki har mahine **ek usage invoice** (`invoice_type = addon`, jo aaj WhatsApp ki hai).
     Mahine bhar `draft` (barhti hai), mahina khatam par `issued` / payable.
  2. **Child rows** (`subscription_invoice_lines`, group): har service ki **har din ki ek line**. WhatsApp: "14 Oct ·
     34 messages × 9.85". Website: "14 Oct · 10 orders · sale 14,050 · 1%". Invoice par service-wise subtotal.
  3. **Record** (drill-down): line kholo to us din ke messages (`whatsapp_messages`) ya orders (`website_order_charges`).
- **WhatsApp ka kharcha aur 1% alag rehte hain** — alag line_type, alag subtotal — magar **ek hi invoice** me. Mashwara
  isliye: warna mahine me teen invoice (plan + WhatsApp + website) aur teen screenshot; ab do (plan + usage), jo owner ne
  09-10 ko maana tha (plan aur usage alag). Alag invoice chahiye ho to sirf invoice dhoondne ki shart badalti hai.
- **Raqam joRi nahi, dobara nikali jaye:** har raat WhatsApp builder sirf apni lines `whatsapp_messages` se dobara
  banaye, commission builder sirf apni lines website orders se; phir invoice total = SUM(lines). Retry par dugna nahi,
  void/return par khud sahi. Is se wo masla bhi khatam: aaj `billing:whatsapp-invoice` total ko sirf WhatsApp rows se
  likhta hai — ek invoice me commission mit jata; lines ke saath har builder sirf apni lines chhuta hai.
- **1% mahana kul par:** din ki lines 4 decimal par, invoice ka total 2 par round — to din ki lines ka jor = mahine ki
  sale × 1% (paisa-paisa).
- **Live WhatsApp invoices par asar (build ke waqt):** lines table bana kar maujooda WhatsApp invoices (Oct drafts:
  khatri `INV-20261009-0281`, kashiffood `INV-20261009-2063`) ki din-wise lines un ke `whatsapp_messages` se bharni;
  **saboot: har invoice ka SUM(lines) = purana total**, warna rukna. Phir `billing:whatsapp-invoice` total ko SUM(lines)
  se likhe. Plan (subscription) invoice ko bhi ek "plan" line — har invoice ki shakl ek jaisi.
- **Dashboard:** "Ban raha hai" card me usage invoice ka total, neeche do hisse: WhatsApp aur Website 1%.
  `tenant.billing.index` ke peeche. Tenant ki `/billing` par invoice ki lines aur drill-down. Platform admin ka
  `/whatsapp-usage` page "Usage" ban jaye (WhatsApp + website).
- Misaal (Oct): WhatsApp 1,036 messages × 9.85 = **10,204.60**; website sale 4,21,350 × 1% = **4,213.50**; invoice
  **14,418.10**.

## 6. Screens

**Customer (public):** home (purani tarteeb), area popup, product popup, cart, checkout, "order mila — WhatsApp dekhein",
confirm page, order status page (system status ka customer wala label).

**Tenant admin:** Settings → Website (waqt, order type → terminal, auto-cancel, WhatsApp toggles, ETA); Delivery Areas;
Website Menu; product/deal form par "Website" hissa (show, website price).

**POS:** ghanti + toast; **"Website orders" modal** (sirf receive): naye Draft orders (customer, area, pin, items, total,
kitne minute se), **Hold** (rider chuno — lazmi), **Recall** (normal POS cart; save par rider lazmi), **Cancel**; link se
Held hue orders "Rider baqi" ke saath. Held Orders list me mojooda tareeqa.

**Platform admin:** Billing rates (WhatsApp fi message, WhatsApp laagat, website commission %); `/whatsapp-usage` →
"Usage" (WhatsApp + website 1%, din-wise, laagat/margin).

**Tenant billing (`/billing`):** usage invoice ki din-wise lines (WhatsApp / Website 1% alag subtotal) + line kholne par
record (messages / orders).

## 6b. Website ka design aur raftaar

- **Tarteeb purani:** header, banner slider, laal category bar, script wale category banners, cards, footer.
- **Halki:** server-rendered Blade + thoda saada JavaScript (React/Next nahi). Budget: pehli load par CSS+JS ~150 KB se kam,
  4G par ~2 second me menu nazar aaye. Fonts 2 se zyada nahi.
- **Tasveerein:** upload par khud chhoti/badi WebP (400 / 800 px) + `srcset`; `loading="lazy"`; pehle dhundli chhoti
  jhalak (blur placeholder) phir asal — jagah pehle se tay taake page na uchhle.
- **Animations (sirf jahan maana ho):** scroll par cards ka halka fade-up (IntersectionObserver), banner slide, category
  bar scroll ke saath chalti (scroll-spy), "Add to Cart" par cart icon ka bounce + ginti, popup ka smooth khulna, loading
  par skeleton. `prefers-reduced-motion` par sab band.
- **Mobile pehle:** zyada tar customer phone se; bara "Add" button, neeche chipka hua "View cart · Rs …" bar.
- **SEO:** har category/product ka saaf URL, title, Google ke liye menu data; nishana Lighthouse 90+.

## 7. Kinare (edge cases)

- Qeemat beech me badli: draft me website price ka snapshot.
- Item khatam (branch par `is_available` off): "Sold out"; checkout par dobara check.
- Do dafa "Place Order": client UUID se ek hi order (POS ka idempotency).
- Confirm link dabaya magar terminal par shift band: auto-Held nahi; "Hum jald confirm karenge" + cashier ko khabar.
- Auto-cancel aur cashier ka Hold ek saath: Hold jeete — auto-cancel sirf tab jab order abhi bhi Draft ho (row lock).
- Area list se bahar: "Is area me delivery nahi" + Pick-Up ka option.

## 8. Menu ki tayyari (Kashif Food)

- **Midnight Deal 17 POS me banani hai (abhi bani nahi):** naam "Garlic Rice With Shashlik Bar-B-Que (Midnight)",
  category **Midnight**, qeemat **1,550**, andar ki cheezein **wohi jo POS deal #75** "Garlic Rice With Shashlik Bar-B-Que"
  (Deals > Chinese Deals, 1,650) me hain. Website par baqi Midnight deals ki tarah "Midnight Deal 17" card isi se juRega.
- Website price: Al-Fahom 1,600 / 3,100, Sting Can 180 (§4.9).
- 4 tasveerein baqi: Chicken Corn Soup, Hot N Sour Soup, Ustad Special Roll, Chicken Balochi Boti.
- "FB Area Block 7" ki fee: 200 ya 250.

## 9. Phases

| Phase | Kya | Nateeja |
| --- | --- | --- |
| 1 | Platform "Billing rates" (WhatsApp rate `.env` se yahan), plan module, master areas, delivery zones, website settings (waqt, order type → terminal, auto-cancel, WhatsApp toggles), website price; Kashif Food: Deal 17 + website prices | Setup mukammal |
| 2 | Website (purani tarteeb, animations, lazy loading, halki), area → branch, dobara aane wala customer, ETA, cart, checkout | Customer order de sake |
| 3 | Draft POS me, ghanti, "Website orders" modal (Hold + rider, Recall, Cancel), auto-cancel, "Website" channel | Cashier order le sake |
| 4 | WhatsApp (status-wise ikhtiyari) + confirm link → Held + KOT; invoice lines (WhatsApp ki maujooda invoices samet, SUM = purana total ka saboot) + website commission lines + dashboard | Poora safar, Bingoo ka hissa |
| 5 | kashiffood.com domain + SSL, tasveerein, SEO, go-live | Purani website band |
| Baad me | Rider ke baad ka safar (on the way), online payment, pre-order | — |

## 10. WhatsApp templates (Meta par pehle approve)

Category **UTILITY**, language **English (en)**. Number ek (Bingoo ka), is liye karobar ka naam variable hai. Button ka
base URL hamara apna domain, aakhir me token: `https://bingoopos.com/o/{{1}}`. Order number = system ka order number.

1. **`web_order_received`** (Draft) — Body: `Hi {{1}}, thank you for ordering from {{2}}. Your order {{3}} for Rs {{4}}
   has been received. Please tap Confirm Order below so our kitchen can start preparing it. If you did not place this
   order, please ignore this message.` · Button (URL, dynamic): **Confirm Order** → `https://bingoopos.com/o/{{1}}`
   · Samples: Ahmed · Kashif Foods · HS-1023 · 1,550 · button: `a8F3kQ`
2. **`web_order_confirmed`** (Held) — Body: `Hi {{1}}, your order {{2}} from {{3}} is confirmed and our kitchen has
   started. Estimated delivery time is {{4}} minutes. For any help, call us at {{5}}. Thank you.` · Samples: Ahmed ·
   HS-1023 · Kashif Foods · 45 · 0300 0000000
3. **`web_order_cancelled`** (Cancelled) — Body: `Hi {{1}}, we are sorry, your order {{2}} from {{3}} has been
   cancelled. For any help, call us at {{4}}. Thank you.` · Samples: Ahmed · HS-1023 · Kashif Foods · 0300 0000000
4. *(Baad me, rider process ke saath)* **`web_order_on_the_way`** — Body: `Good news {{1}}! Your order {{2}} from {{3}} is
   on its way and should reach you shortly. Please keep Rs {{4}} ready for cash on delivery.` Abhi submit karna zaroori
   nahi; chahein to saath approve karwa lein, istemal baad me.

Meta ke qaaide jo upar maane gaye: body variable par khatam nahi hoti; do variable saath saath nahi; har variable ki sample;
promotion nahi (sirf order ki khabar) taake UTILITY me approve ho.
