# Rows ko pakad kar hilana (drag & drop) — arrows ke saath

**Date:** 2026-09-09 · **Status:** PLAN — koi code nahi laga
**Base:** `LINE-ORDER-1` (▲▼ live hain, prod `6d4570e`)

Owner: *"ye arrows jo add kiye hain, is ke saath saath rows drag and drop to any order ho jaye to maza aa jaye."*

Yaani **arrows rahenge**, drag unke saath aayega. Ye durust hai: ek qatar do jagah se hil sakti hai, aur dono ka apna waqt hai — lambi list me pakad kar khींchna tez hai, aur ek qadam ke liye teer.

---

## 1. Bunyaad pehle se maujood hai

`LINE-ORDER-1` me teen cheezein already ban chuki hain, aur drag inhi par baithega:

| pehle se hai | kaam |
|---|---|
| `lineGroup(row)` | line ke saath uski breakdown row bhi uthata hai (dono qism — `.cost-details-row` aur `.punch-detail`) |
| `renumberLines()` | hilne ke baad `lines[i]` ke index dobara likhta hai |
| `sort_order = $index` | server posted tarteeb se sort_order banata hai; documents `orderBy('sort_order')` par chhapte hain |

Is liye drag ko sirf **rows ki tarteeb badalni** hai — baqi sab pehle se jagah par hai. Yehi wajah hai ke ye kaam chhota hai.

---

## 2. Kaise — aur library kyun nahi

Ye table `rowspan` istemal karti hai (14-column redesign ke baad aur ziyada), aur ek line ke saath uski **breakdown row** bhi hilti hai. Aam drag libraries (SortableJS waghera) ek `<tr>` ko dusre se badalti hain — wo **jodi** (line + breakdown) ko nahi samajhtin, aur rowspan wale dhaanche me ulti-seedhi jagah chhod jaati hain.

**Tajweez: HTML5 native drag** — koi library nahi, chaar handler:

```
dragstart  → kaunsi line uthai gayi (uska data-row yaad)
dragover   → kis line ke oopar hai (preventDefault, warna drop hota hi nahi)
drop       → lineGroup(uthai hui) ko lineGroup(target) ke aage/peeche daalo
dragend    → nishani hatao, renumberLines()
```

Yaani wahi do function jo `▲▼` istemal karte hain — `lineGroup` aur `renumberLines`. **Drag sirf ek doosra tareeqa hai wahi kaam karne ka**, alag raasta nahi. Isi liye ye mehfooz hai: agar tarteeb ka hisab ek jagah hai to wo do jagah se galat nahi ho sakta.

**Pakadne ki jagah:** poori row nahi — ek chhota handle (`⠿`) actions ke paas. Poori row draggable karne se qty ya rate ke khaane me text select karna mushkil ho jata hai.

---

## 3. Touch — aur yahan imaandari

HTML5 drag **mobile/tablet par nahi chalta**. Agar counter par tablet istemal hota hai to wahan drag kaam nahi karega.

Isi liye **arrows hatana nahi hain**: wo har jagah chalte hain — keyboard, mouse, touch. Drag ek izafa hai, badal nahi.

Agar aage chal kar tablet par bhi drag chahiye to `pointerdown/pointermove` wala apna raasta likhna parega — wo alag aur bara kaam hai, aur ab tak ki zarurat is ki nahi.

---

## 4. Kya toot sakta hai

| khatra | jawab |
|---|---|
| Breakdown row peechay reh jaye | `lineGroup` istemal hoga — wahi jo arrows karte hain. (Ye kharabi ek bar ho chuki hai: pehla `lineGroup` sirf saved rows ki breakdown jaanta tha) |
| Index dobara na likhe jayen | `renumberLines()` `dragend` par |
| Punch bar khula ho aur row hil jaye | Drag tab band jab `punch` chal raha ho — warna `punch.editIdx` purani jagah ki taraf ishara karta reh jayega |
| Row apne hi andar gir jaye | `drop` par pehle dekha jaye ke uthai hui aur target ek hi na hon |

**Guard:** drag ka asal amal browser me hota hai, is liye server-side test wahi pakad sakta hai jo markup me hai — handle maujood ho, aur drag `lineGroup`/`renumberLines` hi bulaye, apna alag hisab na likhe. Ye imaandari se likha jayega, jaise `RECALC-ASKS-TO-SAVE-1` par likha tha ke wo guard kamzor hai.

---

## 5. Kab

**14-column table redesign ke BAAD.** Wo redesign rows ka dhaancha hi badal raha hai (rowspan, material rows). Drag pehle likhna aur phir dhaancha badalna do bar kaam karna hai.
