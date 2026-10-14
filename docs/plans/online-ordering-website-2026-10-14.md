# ONLINE-ORDERING-1 — Tenant ki apni ordering website (pehle Kashif Food)

Date: 2026-10-14 · Status: **plan, abhi kuch bana nahi** · Research: `docs/research/kashiffood-website/`

## 1. Ek line me

Plan module "Online Ordering" wale tenant ki apni website: purani kashiffood.com jaisa menu aur safar, magar menu,
qeemat aur stock **POS se**; order POS me **draft** ban kar jure terminal par **ghanti** bajata hai; customer
WhatsApp link se **confirm** kare to order **Held** me aa kar **KOT** nikal jati hai; delivery channel **"Website"**.

## 2. Poora safar (end to end)

1. Customer website kholta hai → **Delivery / Pick-Up** chunta hai → delivery par **area** (aur chahe to "Use Current
   Location") → area se **branch** tay → usi branch ka menu, usi branch ki qeemat, sirf wahan bikne wale items.
2. Product popup (size, add-ons, note) → cart (area ki delivery fee, ETA) → checkout (naam, mobile, address, landmark,
   location pin, Cash on Delivery) → **Place Order**.
3. Server: order **POS draft** banta hai (`order_source = website`, delivery channel "Website", customer + address +
   area + pin), branch ke **jure terminal** par **notification + ghanti**.
4. Customer ko WhatsApp: "Order mila, total Rs …, **Confirm Order**" (link).
5. Customer link dabaye → hamara chhota page "Order confirmed" → order **Held** me, **KOT** usi raaste se nikle jo POS
   ka Hold use karta hai → WhatsApp "Confirmed, kitchen ne shuru kar diya, ETA 45 min".
6. Customer link na dabaye (masalan 10 min) → POS par draft "Confirm nahi kiya" badge ke saath; cashier phone kar ke khud
   recall → Hold kare, ya cancel kare (customer ko WhatsApp "cancelled").
7. Rider lagaya (mojooda delivery rider) → (optional) WhatsApp "On the way" → payment POS par wahi jo aaj hai.

## 3. Faisle jo owner ne liye (14 Oct)

- Items ki tarteeb purani website jaisi; website = plan module; pehle Kashif Food.
- Client chune: website kis branch se; **area-wise** (area → branch) aur **branch-wise** (pickup par branch).
- Website ka waqt client set kare.
- Ek terminal se judi; naye order par wahan ghanti.
- Order **draft** me aaye; cashier POS par hi recall → Held.
- Delivery channel "Website" khud lage.
- Customer ko WhatsApp; confirm link dabane par order khud Held + KOT.
- POS ke product par **"Website price"** ka input (khaali = POS ki qeemat).
- Delivery areas (owner ki di hui list, 75 areas, Karachi) **system ke master data** me.
- Bingoo har website order par **1% commission** lega, WhatsApp (Rs 9.85/message) jaisa: rate wahin jahan WhatsApp ka
  rate hai, dashboard par WhatsApp card ke saath "Website orders is mahine" card.
- Owner ka andaza: 99% website orders Delivery honge, channel "Website".

## 4. Mera mashwara (suggestions)

1. **WhatsApp kab:** draft par (confirm link ke saath) **aur** Held par (choti "confirmed" khabar). Baqi (on the way,
   cancelled) settings me on/off. Har message ka paisa tenant ke khaate (`whatsapp_messages.rate_charged`) me jata hai,
   jaise aaj reports ka.
2. **Confirm link = phone ki tasdeeq:** sirf wohi confirm kar sakta hai jis ke WhatsApp par message gaya. Jhoote orders
   ka ilaaj, OTP ka kharcha nahi.
3. **Website khud band:** waqt se bahar **ya** jure terminal par shift band ho → website "Abhi band hai, … baje khulega".
   (Mandatory shift ka usool: bina shift ke POS order nahi hota.)
4. **Link ek dafa, 30 min:** dobara dabane par dobara KOT nahi (KOT idempotency pehle se); 30 min baad link "expired",
   cashier se rabta.
5. **Auto-Held ke waqt bhi cashier ko khabar:** ghanti + "W-1023 confirmed, KOT gayi" toast.
6. **Website price:** product aur deal form par ek khaana; khaali ho to POS ki qeemat. Al-Faham/Sting ka farq isi se hal.
7. **Sizes ek card me:** "Website menu" screen par kai POS products ko ek card ke sizes bana do (Chicken Biryani =
   Sadi/Small/Large/Platter/Family Pack). Add-on = POS product line (Extra Chicken Pcs) ya "With Fries" wale product se badal.
8. **Location:** area dropdown (fee isi se) + "Use Current Location" ka pin (lat/lng) order par; POS/rider ko Google Maps
   link. Area ka khud andaza pin se baad me (areas ke coordinates chahiye).
9. **Custom domain:** pehle `kashiffood.bingoopos.com/order` par test, phir `kashiffood.com` ka DNS hamare server par
   (`tenant_domains` + cert sync).
10. **Payment:** pehle sirf Cash on Delivery / on Pickup; online baad me.
11. **Order kis terminal par: dono, ek setting me — "order type → terminal"** (har branch: Delivery → T1, Pick-Up →
    "Delivery wala hi", optional backup terminal). Sirf order type: terminals ka aaj koi order type nahi, ghanti har terminal
    par, do cashier ek draft. Sirf terminal: aaj chalega, kal Pick-Up/Delivery alag counter par nahi ho sakte. Draft ko dono
    chahiye hi: drafts/Held/shift/ghanti terminal ke hisaab se, aur KOT printer aaj `category_printer_mappings`
    (terminal_id + order_type) se chunta hai — website order Delivery + T1 = wohi printer, naya routing nahi. Main terminal
    ki shift band → backup par ghanti; wo bhi band → website us order type ke liye "abhi band".

## 5. Data (naye tables)

**Master (sab tenants ke liye):**
- `geo_areas`: country, state, city, area name, sort. Pehli bharai: owner ki `webarea.json` (Karachi, 75; "FB Area Block 7"
  do dafa aaya hai — ek rakhna). Agla tenant sirf tick kare.
- `plan_modules` me naya module `online_ordering`.

**Tenant:**
- `web_settings`: on/off, branch mode (single / area-wise), hours (din-wise), closed message, ETA,
  banners, WhatsApp toggles, SEO texts, social links.
- `web_order_routes`: branch_id, order_type (delivery / takeaway), terminal_id, backup_terminal_id (nullable).
- `delivery_zones`: geo_area_id, branch_id, fee, min_order, free_delivery_above, eta_minutes, is_active.
  Kashif Food: 75 rows owner ki fees ke saath (50–400).
- `web_menu_items`: card (naam, description, photo, tag Popular/Hot Selling, category, sort) → `web_menu_item_options`
  (size label → POS product_id ya combo_id). Popular Items ki list.
- `products.website_price`, `combos.website_price` (nullable), `products.show_on_website`.
- `sales_orders`: `order_source = 'website'` (column hai magar **enum `['pos','manual']`** — `website` jodne ki
  migration chahiye; Pick-Up = mojooda `order_type = 'takeaway'`), `web_order_no`, `web_status`
  (received/confirmed/expired/cancelled), `web_confirm_token` (hash), `geo_area_id`, `delivery_lat/lng`.
- `delivery_channels`: ek row "Website" (type own, commission 1%) khud. Ye field sirf tenant ki "By Channel" report
  ke liye hai (`SalesReportService::byChannel`, `grand_total × percent`, GL nahi) — 1% likhne se client ki report aur
  Bingoo ki bill ek hi raqam dikhayengi.

**Master (Bingoo ki bill):**
- `website_order_charges` (WhatsApp ke `whatsapp_messages` jaisa ledger): tenant_id, order uuid, web_order_no,
  base_amount, rate_percent (row par likha), amount, usage_date (business date), status (billable / void), invoice_id.

## 5b. Bingoo ka 1% commission

- **Rate:** prod `.env` me WhatsApp ke saath: `WHATSAPP_RATE_PKR=9.85` (aaj) → nayi line
  `WEBSITE_ORDER_COMMISSION_PERCENT=1`; `config/services.php` + `.env.example` me bhi. Rate har row par likha jata hai
  (WhatsApp jaisa) — rate badle to purani bills nahi hilti.
- **Kab:** order **paid** ho tab row. Draft / unconfirmed / cancelled par nahi. Paid ke baad void: bill band hone se pehle
  row void; baad me to agle mahine minus.
- **Kis par (mashwara):** `grand_total` (delivery fee samet) — channel report bhi isi par nikalti hai. Faisla owner ka;
  doosra raasta sirf items.
- **Kaun se:** sab `order_source = website` (Delivery + Pick-Up).
- **Bill:** mahine ki **alag** draft invoice (draft → mahina khatam par issued), `billing:whatsapp-invoice` jaisa command.
  WhatsApp wali invoice me nahi: wo command invoice ko `tenant_id + invoice_type 'addon' + period_start` se dhoondti hai
  aur total sirf WhatsApp rows se dobara nikalti hai — ek invoice me commission mit jayega. Commission invoice ki pehchan
  alag honi chahiye (aur WhatsApp command ki dhoond usi hisaab se tang).
- **Dashboard:** "Website orders is mahine" card, WhatsApp card ke saath, `tenant.billing.index` ke peeche.
  Platform admin: WhatsApp Usage jaisa "Website orders usage" page.
- **Ek order (Rs 1,400) par Bingoo:** commission 14.00 + WhatsApp 2 × 9.85 = 19.70 → 33.70 (on-the-way ke saath 43.55).
  Aam order par WhatsApp ka kharcha commission se zyada — owner ko batana; messages alag ya 1% me, pricing ka faisla.

## 6. Screens

**Customer (public):** home (purani jaisi), area popup, product popup, cart, checkout, "order received — WhatsApp check
karein", confirm page, order status page.

**Tenant admin:** Settings → Website; Delivery Areas (master list, tick, branch, fee); Website Menu (cards, sizes,
photos, order, Popular); product/deal form par "Website" hissa (show, price).

**POS:** ghanti + toast; Held Orders me "Website" tab/badge (Received / Confirmed / Expired); recall → Hold (mojooda);
cancel → WhatsApp.

## 7. Kinare (edge cases)

- Order ke beech qeemat badli: order wohi qeemat rakhta hai jis par customer ne dekha (draft me snapshot).
- Item khatam (branch par `is_available` off / stock): website par "Sold out", checkout par dobara check.
- Do dafa "Place Order": client UUID se ek hi order (POS ka idempotency pattern).
- Branch band (shift band) jab customer confirm kare: auto-Held nahi; "Hum jald confirm karenge" + cashier ko khabar.
- Area list se bahar: "Is area me delivery nahi" + pickup ka option.

## 8. Phases

| Phase | Kya | Nateeja |
| --- | --- | --- |
| 1 | Module, master areas, delivery zones, website settings, website price | Admin setup mukammal |
| 2 | Public menu (purani tarteeb), area → branch, cart, checkout | Customer order de sake |
| 3 | Draft on POS + ghanti + Held Orders tab + "Website" channel | Cashier order le sake |
| 4 | WhatsApp received/confirm link/confirmed + auto-Held + KOT; 1% commission ledger + mahana bill + dashboard card | Poora safar, Bingoo ka hissa |
| 5 | Custom domain kashiffood.com, tasveerein, SEO, go-live | Purani website band |

## 9. WhatsApp templates (Meta par pehle approve)

Category **UTILITY**, language **English (en)**. Number ek (Bingoo ka), is liye karobar ka naam variable hai. Button ka
base URL hamara apna domain, aakhir me token: `https://bingoopos.com/o/{{1}}`.

1. **`web_order_received`** — Body: `Hi {{1}}, thank you for ordering from {{2}}. Your order {{3}} for Rs {{4}} has been
   received. Please tap Confirm Order below so our kitchen can start preparing it. If you did not place this order,
   please ignore this message.` · Button (URL, dynamic): **Confirm Order** → `https://bingoopos.com/o/{{1}}`
   · Samples: Ahmed · Kashif Foods · W-1023 · 1,550 · button: `a8F3kQ`
2. **`web_order_confirmed`** — Body: `Hi {{1}}, your order {{2}} from {{3}} is confirmed and our kitchen has started.
   Estimated delivery time is {{4}} minutes. For any help, call us at {{5}}. Thank you.` · Samples: Ahmed · W-1023 · Kashif Foods · 45 ·
   0300 0000000
3. **`web_order_on_the_way`** — Body: `Good news {{1}}! Your order {{2}} from {{3}} is on its way and should reach you
   shortly. Please keep Rs {{4}} ready for cash on delivery.` · Samples: Ahmed · W-1023 · Kashif Foods · 1,550
4. **`web_order_cancelled`** — Body: `Hi {{1}}, we are sorry, your order {{2}} from {{3}} has been cancelled. For any help,
   call us at {{4}}. Thank you.` · Samples: Ahmed · W-1023 · Kashif Foods · 0300 0000000

Meta ke qaaide jo upar maane gaye: body variable par khatam nahi hoti; do variable saath saath nahi; har variable ki sample;
promotion nahi (sirf order ki khabar) taake UTILITY me approve ho.
