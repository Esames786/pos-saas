# EMPTY-DEAL-PILL-1 — a deal sub-category with nothing in it still gets a tab

**Status:** DIAGNOSIS + PLAN — abhi tak NAHI bana (data se workaround kiya gaya)
**Date:** 2026-08-31
**Author:** Claude
**For:** Kashif Food (LIVE). Har us tenant par jiske deals sub-categories me hain.
**Live on:** `4bdda60` — masla maujood hai.

---

## 1. Jo hua

Deals tab ke neeche ek dusri qatar hai — `All · Exclusive Deals · Family Deal · Meal Deal ·
Pocket Friendly · Midnight · Platters`. 30 Aug ki raat maine Al-Faham ke dono combos band kiye
(owner ka faisla — Al-Faham deal nahi, normal item hai). **Uska pill phir bhi wahan raha**, aur click
karne par:

```
Products
┌──────────────────────┐
│ No products found.   │
└──────────────────────┘
```

Owner ne screenshot par usay cross kiya: *"deal me abhi bhi Al-Faham kyun aa raha hai empty product
ke saath"*.

## 2. Kyun

Do alag raaste hain, aur sirf **ek** khali categories ko rokta hai.

**Parent pills (upar wali qatar)** — server par filter hote hain. `POSController` [:325-345]:

```php
$contentCategoryIds = $productsPayload (grid-visible) ->pluck('category_id')
    ->merge($combosPayload->pluck('category_id'));      // combosPayload sirf status=active

$pillCategoryIds = $categories->filter(fn ($parent) =>
    collect([$parent->id])->merge($parent->children->pluck('id'))
        ->intersect($contentCategoryIds)->isNotEmpty()
)->pluck('id');
```

Ye theek kaam karta hai — khali parent ka pill nahi banta.

**Child strip (neeche wali qatar)** — client par banti hai aur `pillCategoryIds` ko **dekhti hi nahi**.
`resources/views/tenant/pos/index.blade.php` [:6258]:

```js
parent.children.forEach(function (child) {
    const childBtn = document.createElement('button');
    childBtn.textContent = child.name;
    strip.appendChild(childBtn);        // <- har child, chahe khali ho
});
```

`$categories` me har active category aati hai, chahe us me koi combo/product ho ya na ho. So a
sub-category that has been emptied keeps its pill.

## 3. Filhal kya kiya

`Al-Faham` deals sub-category (#32) ko **`is_active = 0`** kar diya — `$categories` `is_active` par
filter karta hai, is liye pill gayab ho gaya. Uske do retired combos wahin mehfooz hain (chhere nahi).

**Ye workaround hai, hal nahi.** Jab bhi koi deal sub-category khali hogi — ya kisi tenant ne category
banayi magar combo abhi nahi bhara — wahi khali pill dobara aayega.

## 4. Fix ki shakl

Server payload me content-bearing category ids bhejein aur strip unhi ko dikhaye.

`POSController` me ek aur key:

```php
'contentCategoryIds' => $contentCategoryIds->values()->all(),   // pehle se compute ho chuki hai
```

Blade me:

```js
const hasContent = @json($contentCategoryIds);

parent.children
    .filter(function (ch) { return hasContent.includes(Number(ch.id)); })
    .forEach(function (child) { ... });
```

Aur agar filter ke baad koi child na bache to poori strip chhupa dein (`wrap.style.display = 'none'`)
— warna akela "All" button reh jayega jo kuch karta hi nahi.

⚠️ **Dhyan:** `$contentCategoryIds` grid-visible products + ACTIVE combos se banti hai. Yani ek
sub-category jis me sirf **hidden** products hain (combo fillers) uska pill bhi nahi aayega — jo theek
hai, wahi `HIDE-EMPTY-TABS` ka usool hai.

## 5. Test plan

Ye client-side hai, is liye guard PHP side par:
- `PosCategoryPillsMySqlTest`:
  - deals parent ke do children — ek me active combo, doosra khali → `contentCategoryIds` me sirf
    pehla ho
  - combo `inactive` ho jaye → uski category `contentCategoryIds` se nikal jaye
  - jis child me sirf hidden products hon wo bhi na aaye
- Manual: POS khol kar dekhein ke khali pill nahi hai aur strip theek chhupti hai.

Regression: POS suites (aakhri run **381/381**).

## 6. Deploy notes

Koi migration nahi, koi naya route nahi. Ek payload key + strip ka filter. Har tenant ko faida.
Fix jane ke baad `Al-Faham` (#32) ko dobara `is_active = 1` kiya ja sakta hai — pill khud ba khud
chhupa rahega jab tak us me koi combo na ho.
