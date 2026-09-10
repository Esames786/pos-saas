# Lines table → 14 columns: qadam 4 ka asal naqsha

**Date:** 2026-09-09 · **Base:** `6e6bb37` (qadam 1–3, deploy ho rahe hain)
**Status:** PLAN — koi code nahi laga

MD `kashif-stacked-material-row-2026-09-08.md` me maine qadam 4 ko **"ek satar"** likha tha. **Wo galat tha**, aur ye document usi ki tasheeh hai. Qadam 4 is screen ki table ka dobara design hai — wahi screen jis par abhi live quotation ban rahi hai.

---

## 1. Kyun ek satar nahi

Table ke abhi **9 columns** hain. Naya builder **material ke 5 columns** beech me daalta hai. Agar sirf builder switch ho, to punch ki hui rows 14 cells ki hongi aur header + saved rows 9 ki — table toot jayegi.

```
abhi (9):
  1 Item · 2 Urdu · 3 Qty · 4 Unit · 5 System Rate · 6 Customer Rate
  · 7 Amount · 8 Instructions · 9 (actions)

chahiye (14):
  1 Item · 2 Urdu · 3 Qty · 4 Unit · 5 System Rate · 6 Customer Rate
  · 7 Material · 8 Rate · 9 Required · 10 Own · 11 Party
  · 12 Amount · 13 Instructions · 14 (actions)
```

Material ke paanch columns **Customer Rate ke baad** aate hain — wahi jagah jo mockup me hai, aur wahi jo Enter ke safar (`punchSeq`) se milti hai: qty → customer rate → material rows → instructions.

---

## 2. Sab se ahem: 4a aur 4b **alag nahi ho sakte**

Ye is kaam ki asal qaid hai.

Jis lamhe header 14 columns ka hua, **purana builder (9 cells) toot gaya**. Aur jis lamhe builder 14 cells ka hua, **purana header toot gaya**. Donon ek hi commit me jane parenge — warna beech ka koi bhi lamha screen kharab dikhayega.

Is liye qadam 4 ka matlab hai: **header + saved rows + colspans + builder switch, ek saath.** Ye wo cheez hai jo MD me nahi likhi thi.

---

## 3. Kya kya badalna hai

| jagah | abhi | baad me |
|---|---|---|
| `<thead>` (line ~458) | 9 `<th>` | Customer Rate ke baad 5 naye `<th>` |
| saved row (line ~483) | ek `<tr>`, 9 `<td>` | ghair-material cells par `rowspan`, 5 material cells daakhil, har agle material ki apni `<tr>` |
| saved cost-details (line ~598) | `colspan="9"` | `colspan="14"` |
| `punchRowHtml` detail row (~2457) | `colspan="9"` | `colspan="14"` |
| `punchDetailHtml` wali row (~2720) | `colspan="9"` | `colspan="14"` |
| `punchStackedRowHtml` | 12 cells | 14 cells, isi tarteeb par |
| `punchRowHtml` | zinda | is switch ke baad be-kaar → qadam 5 me hataana |

Saved row ke liye khabar achhi hai: **`$lineBlocks = $line->costBlocks` pehle se us scope me maujood hai**, is liye `filter->isMaterial()` se material rows nikal kar stack karna seedha hai. Koi naya query, koi controller change nahi.

---

## 4. Do cheezein jo shayad be-kaar ho jayengi

1. **`punch-detail` wali chhupi hui row aur uska chevron.** Jab materials qatar me hi dikh rahe hain, alag "cost details" kholne ka faida kam reh jata hai. Filhal **rakhenge** — hatana alag faisla hai, aur ek waqt me ek cheez.
2. **Saved row ka "Cost Details" collapse** — ye rehna chahiye: wo materials se ziyada dikhata hai (rate ka source, override ki wajah).

---

## 5. Khatra aur uska jawab

| khatra | jawab |
|---|---|
| Beech ke kisi lamhe me table toot jaye | 4a/4b ek hi commit; koi adhoora deploy nahi |
| `rowspan` ka hisab galat ho (0 material wala dish) | `span = max(1, materials)` — qadam 1 me pehle se hai |
| Saved row ke hidden inputs jagah badalne se gum ho jayen | Guard: rendered HTML me har `lines[i][...]` field ginna, tabdeeli se pehle aur baad me barabar |
| Print/kitchen sheet par asar | **Koi nahi** — ye sirf screen ki table hai; documents apne partials se chhapte hain |
| Paisa | **Koi nahi** — koi hisab nahi chhu raha; `computeAmount` aur block authorities be-harkat |

Aur wahi do aadatain: har Blade change **compile** karke, aur `view:cache` ke baad **`view:clear`** — warna test purana HTML parh kar jhoota green de deta hai.

---

## 6. Tajweez

Ye kaam **apni branch par, apne waqt me** hona chahiye — qadam 1–3 ke deploy ke baad, jab counter par un par ek din guzar chuka ho.

Wajah sirf ehtiyat nahi: qadam 2 ne **checkbox hataya** hai aur qadam 3 ne **columns badle** hain. Dono live behaviour hain aur abhi tak kisi operator ne nahi dekhe. Agar table ka redesign bhi saath chala gaya aur counter par kuch ajeeb laga, to ye batana mushkil ho jayega ke kis tabdeeli ne kiya.
