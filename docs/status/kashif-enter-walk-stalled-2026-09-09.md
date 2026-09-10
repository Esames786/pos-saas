# Enter ka safar Instructions par ruk gaya tha — PUNCH-WALK-VISIBLE-1

**Date:** 2026-09-09 · **Branch:** `feat/catering-stacked-material-row-20260909`
**Kis ne banaya:** qadam 2 (`248fa4c`, live) · **Kis ne pakda:** owner ka sawal

---

## 1. Owner ka sawal

> *"jese abhi tab indexing, input focus on enter, edit row pe focus input jese
> chalra hai — make sure design change ke baad bhi ye saray focus aur tab
> indexing proper chalen."*

Sawal 14-column redesign ke baare me tha. **Redesign ne kuch nahi toda** — Enter
ka poora safar `#punch-bar` aur `#punch-mats` ka hai, jo table se ooper alag
baithte hain; lines table ke columns badalne se un ka koi taalluq nahi.

**Magar dekhte hue ek asli kharabi mil gayi — aur wo qadam 2 se abhi LIVE hai.**

## 2. Kya toota tha

`punchSeq()` wo fehrist banata hai jis par Enter chalta hai:

```
punch-qty → punch-customer-rate → punch-instr → punch-own
          → har material ki pm-rate → pm-own → pm-cust
```

**Qadam 2 ne OWN/PARTY ka switch chhupa diya** (`#punch-seg-wrap` par `d-none`)
**magar `#punch-own` fehrist me chhora rahne diya.**

Aur `focus()` chhupi hui cheez par **kuch nahi karta**. Yaani:

1. Operator `punch-instr` par khara hai, Enter dabata hai
2. Code `#punch-own` ko focus karta hai — kuch nahi hota
3. `document.activeElement` abhi bhi `punch-instr` hai
4. Agla Enter wahi hisab dobara karta hai → wahi chhupi hui cheez
5. **Safar wahin qaid** — material ki qataarein Enter se kabhi nahi milengi

Yehi haal ek `disabled` Party box ka hai.

## 3. Kya theek kiya

Fehrist par bharosa khatam:

```js
return seq.filter(el => el && ! el.disabled && el.offsetParent !== null);
```

Yaani **safar wahan jata hai jo operator ko dikh raha hai aur jis me wo likh
sakta hai** — na ke wahan jo is function ko yaad hai ke usne daala tha.

Ye usool purana nahi hota: qadam 5 jab button poori tarah hata dega, tab bhi
yehi chalega.

## 4. Ek aur cheez jo isi jaanch me nikli

Un-saved row ka unit is tarah parha ja raha tha:

```js
unitCode: row.find('td').eq(3).text().trim()
```

Yaani **cell gin kar**. Ye us waqt tak durust tha jab table 9 columns ki thi.
Ittefaq se 14 columns me bhi cell #3 hi Unit hai — magar **ye ittefaq hai, usool
nahi**. Cell gin kar parhne wala code column hilne par **shor nahi machata**, wo
khamoshi se galat cell le aata hai.

Ab cell ka apna naam hai (`punch-unit-cell`) aur usi naam se parha jata hai.

## 5. Guard

`test_the_enter_walk_only_visits_fields_the_operator_can_use`

- filter mojood ho, aur purana `seq.filter(Boolean)` **na** ho
- safar ki tarteeb: qty → customer rate → note → pm-rate → pm-own → pm-cust
- punch bar me daakhil hone ke **teenon** raaste (item chunna, saved row edit,
  un-saved row edit) Qty par utren, number select shuda

`test_the_unsaved_row_names_the_cell_it_reads` — koi cell gin kar na parhe.

**Tor kar dekha:** filter wapas `seq.filter(Boolean)` kiya → guard laal.

## 6. Aur jo abhi bhi ek jaal hai (chhua nahi)

`punchSetMode()` apne aakhir me `$('#punch-mats .pm-cust').prop('disabled', …)`
karta hai. Abhi ye kaatta nahi kyunki har jagah `punchSetMode()` **pehle** aur
`punchRenderMats()` **baad me** chalta hai, is liye render dobara enabled box
bana deta hai. **Ye tarteeb par tika hua aman hai, usool par nahi.**

Qadam 5 me `punchSetMode()` aur `#punch-seg` ka markup dono hat rahe hain —
tab ye jaal khud khatam ho jayega. Tab tak safar ka naya filter is se bacha
raha hai.

Ek aur purani baat, isi ilaqe me, jo **theek nahi ki gayi** kyunki wo alag faisla
hai: jis line par pehle se party ka hissa likha ho aur us item ka party flag baad
me band kar diya gaya ho, us line ko edit karte hi `punchSetMode('OWN')` wo hissa
**sifar** kar deta hai. Ye qadam 2 se pehle ka amal hai, aur bahut kam pesh aata
hai — magar likha jana chahiye tha.
