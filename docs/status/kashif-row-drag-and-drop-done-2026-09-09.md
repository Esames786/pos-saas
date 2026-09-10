# Rows ab pakad kar hilti hain — ROW-DRAG-1

**Date:** 2026-09-09 · **Branch:** `feat/catering-stacked-material-row-20260909`
**Plan:** `docs/plans/kashif-row-drag-and-drop-2026-09-09.md`
**Base:** 14-column redesign ke BAAD, jaisa plan me tay hua tha

---

## 1. Kya bana

Har line ke actions me ek chhota handle (⣿) aa gaya hai. Usay pakad kar line ko
kahin bhi le jaya ja sakta hai. **Arrows waise ke waise hain.**

Girne se pehle **nishan** dikhta hai — us line ke ooper ya neeche ek neeli lakeer,
taake pata ho ke row jayegi kahan.

## 2. Arrows kyun nahi hatay

Ye sab se ahem faisla hai, aur ye ehtiyat nahi — **HTML5 drag touch screen par
chalti hi nahi**, aur keyboard se bhi nahi. Agar counter par tablet hai to wahan
**sirf arrows** kaam karti hain.

Is liye drag ek **izafa** hai, **badal nahi**.

## 3. Drag koi naya hisab nahi karta

Ye is kaam ki asal hifazat hai.

Dono raaste — arrow aur drag — bilkul **ek hi do cheezein** istemal karte hain:

```
lineGroup(row)      poori line uthata hai: uske material rows aur breakdown samet
renumberLines()     hilne ke baad lines[i] ke index dobara likhta hai
```

Drag apni tarteeb nahi banata. Wajah sirf safai nahi: **quotation isi tarteeb par
chhapti hai** — `saveDraftLines` posted tarteeb se `sort_order` likhta hai aur har
document `orderBy('sort_order')` par chhapta hai. Do jagah ginne wala hisab wo
kaghaz hai jo apni hi screen se ikhtilaf kar sakta hai.

## 4. Chaar cheezein jo ghalat ho sakti thin

| khatra | jawab |
|---|---|
| Material rows ya breakdown peechay reh jaye | `lineGroup` — wahi jo arrows istemal karte hain |
| Index dobara na likhe jayen | `renumberLines()` drop par |
| Punch chal raha ho aur row hil jaye | Drag **mana** jab `punch` zinda ho — warna `punch.editIdx` purani jagah ki taraf ishara karta reh jata |
| Row apne hi andar gir jaye | `drop` par pehle dekha jata hai ke uthai hui aur target ek na hon |

Ek aur baat jo bhoolna aasan thi: **`dragover` par `preventDefault()` na ho to
browser `drop` chalata hi nahi.** Ye guard me pin hai.

## 5. Guard — aur us ki hadd, saaf saaf

`test_a_row_can_be_dragged_and_it_moves_exactly_as_the_arrows_do`

**Jo ye sabit nahi kar sakta:** drag ka asal amal browser me hota hai; koi
server-side test kuch drag nahi kar sakta. **Ye jhoot nahi bola jayega.**

**Jo ye sabit karta hai** — aur jo asal me ahem hai:

- handle **dono** builders par (saved row aur punch row)
- `dragover` par `preventDefault` (warna drop kabhi nahi chalta)
- drop wale hisse me `lineGroup(moving)` **aur** `renumberLines()`
- drop wale hisse me `sort_order` ka naam tak **nahi** — browser apni tarteeb na
  bana le
- punch chalte waqt drag ka inkar
- arrows abhi bhi mojood

**Deploy se pehle ek shakhs aur ek mouse chahiye.** Ye likh diya gaya hai taake
koi ise "test ho chuka hai" na samjhe.
