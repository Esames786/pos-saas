# Event 1 (EV-20260908-0001) — system rate par laaya gaya

**Date:** 2026-09-09 · **Tenant:** `kashifkitchen` · **Booking:** Farhat Hussain / c-o the orbit academy, 450 PAX, 15 Oct 2026
**Backup:** `storage/app/private/backups/20260908_212940` (tenant 2 MB) — ye tabdeeli ke **baad** liya gaya

⚠️ **Imaandari ki baat:** owner ne backup tab kaha jab tabdeeli laga chuki thi. Is liye
neeche wo **poori purani haalat** likhi hai jo tabdeeli se pehle padhi gayi thi — wapas
lena isi se mumkin hai, aur asal me sirf **ek line** badli hai.

---

## 1. Kya masla tha

Client ne quotation ka total theek karne ke liye **rate haath se badle** thay, kyunki
formula galat tha: gosht ka charge **dish** ki miqdaar par lag raha tha, gosht ki apni
miqdaar par nahi.

Formula ab durust hai (`per_material_unit`, 234 blocks), magar **is booking ki lines apna
purana snapshot** liye baithi thin — snapshot us waqt bana tha jab basis `per_dish_unit`
thi. Is liye screen par system abhi bhi purana jawab de raha tha.

## 2. Kya kiya

1. Is estimate ke **6 material snapshots** `per_dish_unit` → `per_material_unit`
2. Har line apne blocks se **dobara hisab** (`recalculateForQuantityLocked`)
3. Jin lines par **haath se likha rate** tha, wo hataya — ab har line apne **system rate** par hai

## 3. Nateeja — sirf ek line hili

```
Biryani Masala Beef   3,375.00 -> 3,375.00   (override gaya, magar system ab KHUD 3,375 nikalta hai)
                                              Beef 42 x 1,450 = 60,900 + Making 33,600 = 94,500 / 28
Chicken Chanp Grill   1,155.00 -> 1,035.00   *** yehi wo line hai jo badli ***
                                              amount 103,950 -> 93,150
```

Biryani par override isliye be-zarurat hua ke system ab wahi 3,375 nikalta hai jo client
ne haath se likha tha — yaani unka hisab durust tha, system ka formula galat tha.

Chanp Grill par material ki miqdaar dish ke barabar thi (90 = 90), is liye basis badalne
se kuch nahi badla; wahan 1,155 **sirf** total theek karne ke liye tha, aur owner ke
kehne par system ke 1,035 par wapas aa gaya.

**Grand total: 645,890 → 635,090** (farq 10,800 = 120 × 90)

Baqi 11 lines me se **ek ka bhi amount nahi hila**. Journals 0, stock 0 — ye ab bhi sirf
quotation hai.

## 4. Wapas lena

Sirf ek line:

```sql
UPDATE catering_estimate_lines
   SET rate = 1155.00,
       amount = 103950.00,
       rate_override_reason = 'Customer agreed rate entered in order punch'
 WHERE catering_estimate_id = 1 AND item_name = 'Chicken Chanp Grill';
```

Aur agar snapshot ki basis bhi wapas chahiye:

```sql
UPDATE catering_estimate_line_cost_blocks b
  JOIN catering_estimate_lines l ON l.id = b.catering_estimate_line_id
   SET b.rate_basis = 'per_dish_unit'
 WHERE l.catering_estimate_id = 1 AND b.block_type = 'material';
```

Uske baad totals dobara ginne parenge.

---

## 5. Jo abhi bhi owner ka faisla hai

**Teen ek jaisi `Chicken Karahi Shanwari` rows** — teenon 40 KG @ 1,535 = **184,200**.

Ye us edit wale bug ki paidawar hain jo ab theek ho chuka hai (product badalne par nayi
row banti thi aur purani bhi reh jati thi). Do hataane par **−122,800**, aur total
**635,090 → 512,290** ho jayega.

Maine inhe **chhua nahi**: line hataana rate badalne se alag cheez hai, aur ye batana
mera kaam nahi ke client ne ek shinwari karahi mangwai thi ya teen.

**Do cheezein jinka system rate abhi 0 hai:** Wonton aur Roti. Ye us waqt punch hui thin
jab dish ki koi qeemat thi hi nahi, is liye inka koi breakdown nahi bana. Charged rate
theek hai (32 aur 300, amount 43,200 aur 3,600), magar System Rate ka khaana khali dikhega
jab tak inhe dobara punch na kiya jaye.
