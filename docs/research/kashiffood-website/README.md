# Kashif Foods website: purani website ka mutala aur nayi website ki bunyaad

Date: 2026-10-14 · Status: **research, abhi kuch bana nahi** · Tenant: `kashiffood`

Ye file purani website (https://kashiffood.com, indolj platform par) ko poora parh kar likhi gayi hai,
taake nayi website **wohi tarteeb aur wohi bartaao** rakhe, magar menu, qeemat, orders aur branch hamare
POS se chalein.

| File | Kya hai |
| --- | --- |
| `README.md` (ye) | Layout, flow, settings, hamare system se farq, owner ki shartein, khule sawal |
| `menu.md` | Purani website ka poora menu: category → item → sizes/qeemat → add-ons, website ki tarteeb me |
| `compare.md` | **Website menu vs hamara POS menu**: har item × size ki POS se milaan, qeemat ka farq, POS-only items |
| `images.md` | Owner ki download ki hui 186 tasveerein: kaun si kis product/category ki, size, aur kin products ki tasveer download me nahi |
| `data/menu-api.json` | Owner ki di hui purani website ki menu API (poori; 25 categories + "Popular Items") |
| `data/compare.json` | `compare.md` ka machine-readable roop (har line ka POS product/deal id) |
| `data/categories.json` | Har category: naam, sequence, layout, items (crawl se) |
| `data/products.json` | Har product page: sizes, qeemat, add-on groups, tasveer ka link (crawl se) |
| `data/images.json` | `images.md` ka machine-readable roop |
| `screens/` | Owner ki di hui screenshots + poore menu page ke 12 tukde (`00-full-menu-01…12.jpg`) |

**Data kaise liya:** website ke `sitemap.xml` se 26 category aur 156 product links; har page ek ek karke (beech me waqfa)
parha aur page me maujood JSON se data nikala. Sirf public pages; koi login, token ya signed API nahi chhui.
Do category links (`/category/New Arrival `, `/category/Midnight-Deals`) khali page dete hain; un ke items
products ki apni category se jore gaye (Platters 8, Midnight Deals 21).

## 1. Owner ki shartein (14 Oct 2026)

1. **Items ki placement wahi rahe** jo purani website par hai: categories ki tarteeb, har category ke andar items ki tarteeb, card ka andaaz.
2. **Website ek plan module ho.** Jis tenant ke plan me ye module ho, us ki website chale. Pehle Kashif Food ko diya jayega.
3. **Client khud chune ke website kis branch se judi ho.** Branch-wise bhi aur area-wise bhi:
   - client har branch ke delivery areas khud map kare (kaun sa area kis branch se deliver hoga);
   - customer area chune → us area ki branch → **usi branch ke bikne wale items** website par dikhein.
4. **Website ka waqt client khud set kare** (kab orders lene hain, kab band).
5. **Website ek terminal se judi ho.** Naya order aaye to **usi terminal par notification + ghanti** baje.
6. **Order khud-ba-khud POS me DRAFT ban kar aaye.** Cashier apni POS screen par rehte hue use **recall** kare aur
   **Held** me daal de (wahin se KOT/kitchen ka mojooda raasta). "Easy as you like" — koi naya alag screen nahi.
7. **Delivery channel "Website" khud lage**, jaise Foodpanda wale orders par channel lagta hai (`delivery_channels`).

## 2. Purani website: bunyadi baatein

| Cheez | Haal |
| --- | --- |
| Platform | indolj (Next.js app), `kashiffood.com`; tasveerein `assets.indolj.io` par; footer "Powered by indolj" |
| Branch | Ek: **Kashif Foods Block 2 Gulshan-e-Iqbal** (Qamar Square, near Meezan Bank). Delivery, pickup, dine-in teeno on |
| Order types (website par) | **Delivery** aur **Pick-Up** (pehli popup me) |
| Waqt | Har din 12:00 PM – 04:00 AM; pre-order 12:45 PM se. Band ho to: "Sorry, we are closed right now. We will open on Thu 12:00 pm" |
| Delivery ka andaaza | 45 minute (pickup 45, dine-in 45). Cart me likha aata hai: "delivered approximately in 45 minutes on <date> at <time>" |
| Delivery fee | Cart me **Rs 300** dikhi (area Scheme 33). Har area ki alag fee hai ya ek hi, ye public data me nahi |
| Minimum order | Khaali (koi nahi) |
| Payment | Sirf **Cash on Delivery** (pickup par "Cash On Pickup") |
| Tax | Items "non_taxable"; checkout par tax nahi |
| Contact | Phone, email, WhatsApp button (neeche daayen), Facebook, Instagram |
| Menu | 26 categories, 156 products (Deals 20, Midnight Deals 21, Barbecue 12, Chinese 13 …); tafseel `menu.md` me |
| Tasveerein | Owner ki 186 files: 152 kisi product/category ki, 4 banner slides (5120×1600), logo, icons. Sirf 4 dishes ki apni tasveer nahi: Chicken Corn Soup, Hot N Sour Soup, Ustad Special Roll, Chicken Balochi Boti (website par bhi nahi). Deals/Meals/Midnight par logo wali tasveer |

**Delivery areas** ki poori list indolj ki signed API se aati hai, khuli nahi. Screenshots me ye naam nazar aate hain:
Bahadurabad, Clifton Block 1–5, FB Area Block 2, 4, 5, 6, 7, 8, Scheme 33 … (list lambi hai, scroll hoti hai).
Nayi website me ye list **client khud banayega** (shart 3), is liye poori purani list zaroori nahi.

## 3. Page ka dhaancha (upar se neeche)

Screens: `screens/00-full-menu-01.jpg` … `-12.jpg`.

1. **Header** (safed): baayen "DELIVERY · Scheme 33 ›" (chuna hua area, dabane par badle) + phone `0321…`; beech me gol laal logo; daayen cart ka icon.
2. **Banner slider:** badi tasveer, beech me heading ("GRILLED TO PERFECTION.") aur ek button-label ("SIZZLING BBQ PLATTER"), daayen-baayen teer, neeche dots.
3. **Category bar** (laal, chipka rehta hai): har category ek pill; chuni hui safed. Daayen teer se aage scroll.
4. **Search:** "Search for Chicken Burger…" + laal teer button.
5. **Popular Items** ("Most ordered right now"): 4 bade cards — tasveer, laal "+" button, naam, "From Rs. 600".
6. **Har category ka hissa:**
   - cream rang ka gol-kona banner, beech me category ka naam **script font me laal** (jaise "Starters", "Deals"), kinaron par khajoor ke patton ka design;
   - neeche **3 column cards**: upar naam (bold), neeche chhota description (2 line, "…" se kata), neeche-baayen qeemat ki chip ("Rs. 990" ya "From Rs. 600"), daayen tasveer, tasveer ke kone par laal gol "+";
   - kuch cards par peela tag: **Popular** / **Hot Selling**;
   - tasveer na ho to laal "KASHIF FOODS" logo wali tasveer (deals par aksar).
7. **Footer:** SEO paragraph ("Experience the Rich Flavors of Desi Cuisine at Kashif Foods in Karachi"), logo, phone, email, address, Follow Us (Facebook, Instagram), Privacy Policy, FAQs, "© 2026 Powered by indolj". Neeche-baayen search button, neeche-daayen WhatsApp aur "upar jao" button.

## 4. Customer ka safar

| # | Screen | Kya hota hai | Screenshot |
| --- | --- | --- | --- |
| 1 | Pehli popup | "Select Your Order Type": **Delivery / Pick-Up**. Delivery par "Use Current Location" ya dropdown se area (search ho sakta hai). "Select" | `01-…`, `12-…`, `13-…` |
| 2 | Menu | Upar wala dhaancha; category bar se seedha category par | `00-full-menu-*` |
| 3 | Product popup | Baayen badi tasveer + naam; daayen qeemat, **CHOOSE AN OPTION** (sizes, radio), **Add Ons** (Optional/Required chip, checkbox ya radio), **Special Instructions** (500 akshar), neeche qty (−/+) aur "Rs. 250 \| Add to Cart →". Share button | `02-…`, `06-…` – `11-…` |
| 4 | Cart (daayen drawer) | Har item: tasveer, naam, size chip, "From Rs. 600", qty. "+ Add more items". **Popular with your order** (Raita 100, Fresh Salad 200 — ek click se add). Total, **Delivery Fee 300**, Grand Total. Andaaza: "delivered approximately in 45 minutes on October 14th, 2026 at 10:19 PM". **Checkout →** | `03-…`, `04-…` |
| 5 | Checkout (`/guestcheckout`) | "This is a **Delivery Order**". Title (Mr./…), Full Name\*, Mobile\*, Alternate Mobile, **Delivery Address\*** (area chip saath), Nearest Landmark, Email, Delivery Instructions, Payment: **Cash on Delivery**. Daayen order ka khulasa aur **Place Order** | `05-…` |

### Product popup ki qismein (screens se)

| Qism | Misaal | Kya chunna hota hai |
| --- | --- | --- |
| Sirf qeemat | Mushroom Melt (1,050) | Kuch nahi; description dikhti hai |
| Size | Chicken Biryani: Plain 250 / small 400 / Large 750 / Platter 2,000 / Family Pack 2,270 | Ek size (radio) |
| Size + add-on | Chicken Biryani + "Extra Chicken Piece +200" (optional) | Size + checkbox |
| Size + zaroori pasand | Mushroom Steak: Chicken 2,050 / Beef 2,650 + "Select Your Preference" **Required**: White / Brown | Size + ek zaroori radio |
| Persons | Chicken Chilli Dry With Fried Rice: 1 Person 1,350 / 2 Person 2,450 | Ek |
| Roll ka flavour | Beef Boti Rolls: Plain 370 / Mayo 400 / Cheese 410 | Ek |
| Deal | Pocket Friendly Deal 02 (950): description me hisse ("1 Chicken Zinger Burger, 1 Crispy Fried Chicken (Drum Stick)") + "Add Fries +150" | Optional add-on |

## 5. Hamare system ke saath milaan (prod, 14 Oct, sirf parh kar)

| Cheez | Purani website | Hamara POS (`kashiffood`) | Matlab |
| --- | --- | --- | --- |
| Branch | 1 | 1 ("Kashif Food") | Theek |
| Categories | 26, flat | 46, kuch nested (Deals → Midnight, Platters…; Rolls → 7 sub) | Website ki tarteeb alag se rakhni hogi |
| Sizes | Ek item, andar sizes | **Har size alag product** (jaise "Chicken Biryani (Platter)"); `product_variants` me 0 sizes | Website par kai POS products ko **ek card + sizes** me jorna hoga |
| Add-ons | Kai (Extra Chicken Piece, Add Fries, White/Brown…) | 1 modifier group, 2 options | Add-ons POS me banane honge (modifier groups pehle se bane hain) |
| Tasveerein | Har item ki | **0 / 212** products par tasveer | Tasveerein laani hongi (purani website se ya client se) |
| Deals | 20 + Pocket Friendly + Family + Midnight | 73 active combos (75 kul) | Combo = deal; card par hisse dikhane hain |
| Delivery fee | 300 (Scheme 33) | Branch default **0** | Area-wise fee ka faisla |
| Qeematein | Website ki apni | POS ki | Website **POS ki qeemat** dikhaye (ek hi sach). Milaan neeche |

### Menu ka milaan (tafseel `compare.md`)

Website ki 235 lines (har item ka har size, Midnight Deals samet) POS se milayi gayin:

| Nateeja | Lines |
| --- | --- |
| POS me mili, qeemat bhi wahi | **231** |
| Qeemat alag | 3: Al-Faham Half 1,600 vs POS 1,650; Full 3,100 vs 3,200; Sting Can 180 vs 160 |
| POS me nahi | 1: Midnight Deal 17 (Garlic Rice With Shashlik, 1,550) — POS me sirf aam deal 1,650 |
| POS me hain, website par nahi | 42 (zyada tar "(With Fries)" products, jo website par "Add Fries" add-on hain) |

**Popular Items** (menu API se, homepage ke 4 bade cards): Singaporean Rice, Chicken Biryani, Chicken Zinger Burger, Chicken Tikka,
Pizza Fries, Beef Kebab Rolls, Beef Boti Rolls, Chicken Chow Mein, Deal 8 Serve 2, Deal 11 Serve 2.

## 6. Hamare paas pehle se kya hai, kya banana hoga

**Pehle se hai:**
- Branch-wise categories aur deals (`categories.branch_id`, `combos.branch_id`).
- Branch-wise qeemat aur "is branch me milta hai ya nahi" (`product_branch_prices.selling_price`, `is_available`).
- Add-ons ka dhaancha (`modifier_groups`: min/max/required; `modifiers`: price_delta).
- Delivery order, delivery charge, customer addresses, riders, delivery channels (`delivery_channels`, `delivery_riders`, `customer_addresses`, `branches.default_delivery_charge`).
- Plan modules (`plan_modules`): naya module jor kar sirf usi plan ko dena.
- POS par order ka aana, KOT/print, shift — sab mojood.

**Banana hoga (naqsha baad me, abhi sirf fehrist):**
1. **Naya plan module** "Online Ordering / Website".
2. **Delivery areas:** area ka naam, kis branch se, delivery fee, minimum order, chalu/band.
3. **Website kis branch se judi:** ek ya kai branches; area → branch ka nizaam (shart 3).
4. **Website menu ka dhaancha:** categories ki website wali tarteeb, kai POS products ko ek card ke sizes me jorna, tasveer, description, Popular/Hot Selling tag, "Popular Items", cart ke "Popular with your order".
5. **Public website** (tenant ke domain/subdomain par): upar wala dhaancha aur safar.
6. **Website ka order POS me (shart 5–7):** naya order source "website"; order **draft** ban kar chuni hui branch ke
   **jure terminal** par aaye; wahan **notification + ghanti**; cashier POS par hi recall karke **Held** kare (mojooda KOT raasta);
   delivery channel **"Website"** khud lage; customer + address + area saath.
7. **Settings (client ki screen):** waqt (abhi 12 PM – 4 AM), band hone ka paigham, delivery ka andaaza (45 min), kaun sa terminal,
   banner slides, WhatsApp, social links, SEO texts.

## 7. Khule sawal (owner se)

1. **Delivery fee** har area ki alag hai ya sab par 300?
2. **Qeemat:** website par POS ki qeemat chale? Farq sirf 3 jagah hai: Al-Faham Half/Full (website 1,600/3,100, POS 1,650/3,200) aur Sting Can (180 vs 160). Midnight Deal 17 POS me banani hai ya hatani hai?
3. **Tasveerein:** owner ki di hui 152 tasveerein hi lagayein? 4 dishes (do soups, Ustad Special Roll, Chicken Balochi Boti) ki tasveer chahiye.
4. **Domain:** `kashiffood.com` hamare server par aayega ya pehle subdomain (jaise `order.kashiffood.com`) par test?
5. **Payment:** sirf Cash on Delivery, ya online payment bhi?
6. **Dine-in / pickup:** website par pickup bhi rakhna hai? Dine-in?
7. **Website ka order** kis counter/terminal par aaye aur kaun "accept" kare?

## 8. Dobara banana ho to

`tools/` me wohi scripts hain jin se ye files bani (PHP 8.3, sirf parhte hain):
`crawl.php` (sitemap → categories/products) · `retry.php` (chhoote pages) · `fix_cats.php` (Platters, Midnight Deals) ·
`menu_md.php` → `menu.md` · `images_md.php` → `images.md` · `compare_md.php` + `compare_lib.php` → `compare.md`
(POS ki fehrist prod se `json_object` query se nikali gayi, sirf parh kar).
