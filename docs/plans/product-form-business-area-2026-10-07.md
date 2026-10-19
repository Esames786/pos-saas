# PRODUCT-FORM-AREA-1 — Product form: pehle "kis kaam ka product", phir us ke cards

Date: 2026-10-07 · Screen: Catalog → Products → Add / Edit (`resources/views/tenant/products/form.blade.php`)

## Kya toota hua hai (saboot ke saath)

1. **Form ka poora JavaScript 29 Sep se band hai.** `bdd41b83` (PRODUCT-FORM-ROLE-1) ne card-click par
   `window.confirm('…?<ENTER><ENTER>Its role…')` likha. Single-quote string ke andar asal line-break
   = `SyntaxError: Invalid or unexpected token` → line 630–865 ka script parse hi nahi hota.
   Headless Chrome me asal controller-rendered edit page (Cola Next 300 ml):
   as-is = SyntaxError, summary khali, BOM boxes + Product Role + Inventory & Kitchen Profile **dikhte**,
   "Packing Material" dabane se kuch nahi; sirf `\n\n` likhne par = koi error nahi, sab kuch chhupta,
   card badalta hai. Add aur Edit dono.
2. **"Manufacturing available" sirf permission dekhta hai** (L11 `$u->can(...)`). `deploy.sh` Owner ko har
   permission deta hai, is liye chaaron live tenants par ye `true` hai — halaan ke kisi ke plan me
   `manufacturing` nahi (prod 10-07: khatri/kashiffood/tawakal = restaurant plans, kashifkitchen = catering).
3. **Asar:** 6 Oct ko Khatri par 3 crate products bane — #68 `pakola 500ml (crt)` ka role **Finished Good**
   (manufacturing role). Zero GRN, zero stock rows — abhi paisa/stock nahi chhua. (Data fix = owner ka faisla,
   is kaam me shamil NAHI.)

## Kya banega

### A. Hotfix
`confirm()` ka text `\n\n` escape ke saath. Kuch aur nahi.

### B. "Business area" box — cards se pehle
"Which part of the business is this product for?" — radio buttons, sirf wo jo **plan + role** dono dein:

| Area | Kab dikhe | Cards |
|---|---|---|
| Restaurant / POS | plan me `pos` ya `restaurant`; manufacturing wale safhe par `tenant.products.index` bhi | POS Sale Item, Recipe/Kitchen Item, Ingredient/Raw Material, Packing Material, Service Item, Advanced |
| Catering | plan me `catering` + `tenant.catering.materials.index` | Catering Dish, Dish with Recipe, Ingredient, Packaging Material, Service / Charge, Advanced |
| Manufacturing | plan me `manufacturing` + (`manufacturing.bom.index` ya `manufacturing.products.index`) | Manufacturing Raw Material, Manufacturing Finished Good, Advanced |

- Jis safhe par user khara hai us ka apna area hamesha hota hai (route middleware pehle hi plan check kar chuka):
  `/manufacturing/products` → Manufacturing, `/catering/materials` → sirf Catering, aur wahan sirf
  Ingredient + Packaging (aaj jaisa — us safhe ka form `sale_item` role aur POS Visible render hi nahi karta).
- Plan pata na ho (tenant bound nahi — sirf tests) → plan ki shart chhod do, permission hi faisla kare (aaj jaisa).
- **Restaurant aur Catering me manufacturing ka kuch nahi:** na mfg cards, na BOM checkboxes (Advanced me bhi
  nahi), na Product Role me "Finished Good"/"Semi-Finished" — sivaye us product ke jis par wo pehle se laga ho
  (warna dropdown jhoot bolega aur save role badal dega).
- Catering ke cards wahi modes hain jo restaurant ke (same defaults, same server) — sirf naam aur wazahat
  catering ki zabaan me. Koi data ka farq nahi.

### C. Edit par area khud select ho kar aaye
- Role `finished_good`/`semi_finished`, ya BOM Output / Manufactured FG laga ho → Manufacturing (agar milta ho).
- Warna jis safhe se khola: materials → Catering, manufacturing → Manufacturing, catalog → pehla milne wala
  (Restaurant, phir Catering).
- Raw material dono jagah ek hi data hai (server har raw/packaging par `can_be_bom_component` khud lagata hai),
  is liye Manufacturing me wo "Manufacturing Raw Material" card hai aur baqi areas me "Ingredient".
- Manufacturing role wala product jis ke tenant ke paas manufacturing hi nahi (Khatri #68) → Restaurant +
  Advanced, taake Role dropdown dikhe aur owner use Sale Item kar sake.

### D. Area badalne ka bartaao
- **Add:** naye area me wahi type ho to wahi rahe; warna us area ka pehla card, us ke defaults ke saath.
- **Edit:** area badalne se **product par kuch nahi badalta**. Wahi type naye area me ho to sirf naam badle;
  na ho to koi card active nahi aur likha aaye "pick a type — nothing changes until you do". Card dabane
  par wahi purana confirm.
- Add screen par load hote hi card ke defaults lag jayein (jab tak validation ke baad wapas na aaye).
  Aaj manufacturing ke Add par card "Raw Material" dikhta tha magar chhupa role `sale_item` + POS Visible
  rehta tha.

### E. Save ke baad kahan jaye
`/manufacturing/products` se save kiya gaya product agar us list me aata hi nahi (masalan Restaurant area
chun kar POS item) → product ka apna safha `/products/{id}`, list nahi. Faisla wohi filter karta hai jo list
karti hai (`applyContextFilter`) — doosra hisaab nahi.

## Kya NAHI badlega
- Server ka save (`validated()`) — ek line bhi nahi. Koi migration, koi permission, koi route nahi.
- Chhupe checkbox ab bhi CSS se chhupte hain aur post hote hain (L736 ka usool) — koi flag chup-chaap clear nahi.
- Catering materials safhe par manufacturing ka koi lafz nahi (`test_manufacturing_vocabulary_never_reaches_a_catering_screen`).

## Tests
- **Naya guard:** asal create + edit page render karo, har inline `<script>` `node --check` se guzaro.
  Jaan-boojh kar line-break wapas daal kar sabit karo ke test laal hota hai.
- Area: plan/permission ke hisaab se kaun se areas; edit par sahi area + card; restaurant me mfg card/role
  option nahi; #68 jaisa product Restaurant + Advanced me; catering materials par sirf Catering.
- Redirect: manufacturing safhe se POS item → `/products/{id}`; mfg raw → list.
- Purane tests (ProductFormRoleDetect, ProductArchetypeContract, CateringViewRender) jaise ke taise green.
- Headless Chrome se asal page par: area badlo, cards badlein, Restaurant me BOM kabhi na dikhe.

## Deploy
Sirf owner ke kehne par. View + ek controller method; migration nahi.
