# Qadam 4 ho gaya — table ab 14 columns ki hai

**Date:** 2026-09-09 · **Branch:** `feat/catering-stacked-material-row-20260909`
**Base:** `9ca63c1` (qadam 1–3 live, prod `6d4570e`)
**Plan:** `docs/plans/kashif-lines-table-14-columns-2026-09-09.md`

Owner ka jumla yehi tha: *"the only thing we are changing is the way we add a row
to table."* Ye qadam wahi hai — aur **sirf** wahi.

---

## 1. Ab screen par kya hai

```
1 Item · 2 Urdu Name · 3 Qty · 4 Unit · 5 System Rate · 6 Customer Rate
· 7 Material · 8 Rate · 9 Required Qty · 10 Own · 11 Party
· 12 Amount · 13 Instructions · 14 (actions)
```

Material ke paanch khaane **Customer Rate ke baad** hain — wahi jagah jo mockup me
thi, aur wahi jahan Enter ka safar (`punchSeq`) pehle se jata hai.

Ek dish ke jitne material hain utni qataarein: **pehla material line ki apni row
me**, baqi har ek apni row me neeche. Item, qty, rate, amount, note aur buttons
`rowspan` se poore stack par phaile rehte hain, is liye ek dish **ek hi block**
dikhta hai — chahe uske do material hon ya paanch.

Jis dish ka koi material nahi (jaise haath se likhi hui line), uski row ek hi
rehti hai aur material ke khaane me `—`.

## 2. Yehi cheez saved aur naye — dono par

Ye ahem hai. Table me do qism ki qataarein baithti hain:

| kis ne banayi | kahan se |
|---|---|
| **saved line** (server) | `line-material-cells.blade.php` |
| **abhi punch ki hui line** (browser) | `punchStackedRowHtml()` ka `matCells` |

Dono ab **ek jaise alfaz** bolti hain: naam ke neeche unit, aur party ki ijazat
sirf **Party ke khaane** se — jहां ijazat nahi wahan `—`. Pehle punch wali row
naam ke neeche `PARTY ALLOWED` / `OWN ONLY` ka badge lagati thi aur saved row
kuch nahi — ek hi table ke do hisse do zabanein bol rahe the.

## 3. Server ke liye kuch nahi badla

Poore rebuild ki hifazat ek hi daawe par hai: **ye badalta hai ke line kaise
DAALI jati hai, aur bas.** Is liye:

- jitne `lines[i][...]` khane pehle post hote the, utne hi ab bhi
- `lines[i][materials][j][label|kg|rate|cust]` — teenon builder ab bhi ek jaise
- na koi naya route, na migration, na permission
- print/kitchen sheet par asar **sifar** — wo apne partials se chhapte hain
- paisa **sifar** — koi hisab chhua hi nahi

## 4. Jo cheezein ek line ko ek `<tr>` samajhti thin

Ye asal kaam tha, aur plan me isi ka darr likha tha. Ab ek line **kai qataarein**
hai, is liye har wo jagah theek ki gayi jahan "row" ka matlab "line" tha:

| jagah | pehle | ab |
|---|---|---|
| `lineGroup()` | `.next('.cost-details-row, .punch-detail')` — naam le kar | `nextUntil('tr[data-row]')` — agli line tak jo bhi hai, wo isi ka hai |
| Remove (saved) | apni row + cost-details | poora group |
| Remove (punch) | apni row + detail | poora group |
| `recalc()` | `har tr siwaye ek class ke` | `#lines-body > tr[data-row]` — sirf wo qataarein jin par paisa hai |
| saved row ka breakdown | `row.after(...)` | `lineGroup(row).last().after(...)` — warna breakdown line aur uske apne material ke **beech** ghus jata |
| edit par row dobara banana | `row.replaceWith(...)` | pehle group ka baqi hissa hata kar, phir replace |

Aakhri wali ne ek **purani chhupi hui kharabi** bhi theek kar di: `replaceWith`
sirf **ek** `<tr>` badalta hai, is liye purani `punch-detail` row wahin reh jati
thi aur ek hi `data-detail` do dafa mojood ho jata tha. Ab group ke saath jati hai.

`lineGroup` ka naam lena chhorna soch kar kiya gaya. **Naam ginwane ki wajah se hi
ek kharabi shipped hui thi** (`LINE-ORDER-1` me guard ne wahi ek selector likha
jo code me tha, aur bug ke saath razamand ho gaya). Ab code kehta hai: *agli line
row tak jo kuch hai, wo isi line ka hai.*

## 5. Guard — aur wo waqai kaatta hai

Naya test: **`test_the_lines_table_is_square_with_the_materials_stacked_in_it`**

Ye alfaz nahi dhoondta. Ye rendered table ko **cell dar cell** chalta hai aur har
row ka hisab lagata hai: uske apne khaane + oopar se `rowspan` par utar kar aane
wale khaane. **Square table hi is jama ko bardasht karti hai.** Saath me headings
ki tarteeb bhi ginn kar dekhi jati hai — sirf 14 hona kaafi nahi, tarteeb bhi wohi
honi chahiye jo owner ne mangi.

Fixture me jaan-boojh kar **do material** wala dish banaya gaya — warna stack
kabhi bana hi nahi hota aur `rowspan` ka hisab ghalat hone ka mauqa hi na aata.

**Teen dafa tor kar dekha, teenon dafa laal:**

| kya toda | guard ne kya kaha |
|---|---|
| header se ek `<th>` uda diya | `row 0 is 13 columns wide, not 14` |
| ek saved cell ka `rowspan` uda diya | `row 2 is 13 columns wide, not 14` (yaani doosri material row) |
| builder wapas purana kar diya | `and nothing calls the old builder any more` |

Do purane guard bhi badle gaye, kyunki wo jaan boojh kar purani soorat pin karte
the: `lineGroup` wala selector, aur `punchRowHtml` ka call site.

## 6. Kya nahi kiya

- **`punchRowHtml` abhi maujood hai** — koi ise bulata nahi (guard sabit karta
  hai), magar hataya qadam 5 me jayega. Uska `colspan` phir bhi 14 kar diya taake
  agar kabhi chal bhi jaye to table na tootey.
- **Cost Details ka collapse qayam hai.** Wo material se ziyada batata hai — rate
  ka source, override ki wajah — aur wo grid me nahi aa sakta.
- **Drag & drop abhi nahi.** Uska MD `kashif-row-drag-and-drop-2026-09-09.md` me
  hai aur usi ki tajweez thi ke **ye redesign pehle**, warna wahi kaam do bar.

## 7. Deploy se pehle

- Blade compile karke uska generated PHP lint kiya gaya (`d746abe` ka sabaq)
- `view:clear` har test se pehle — warna purana compiled HTML jhoota green deta hai
- Cashier/operator ko **Ctrl+F5** — table ka dhaancha badla hai
