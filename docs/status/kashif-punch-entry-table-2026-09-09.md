# Punch area ab ek block — PUNCH-ENTRY-TABLE-1

**Date:** 2026-09-09 · **Branch:** `feat/catering-stacked-material-row-20260909`
**Base:** `7c16401` (REVERT-MAIN-TABLE-1) · **Scope:** SIRF top punch area

---

## 0. Pehle ek ghalti, saaf lafzon me

Owner ne ye design **entry area** ke liye maanga tha. Maine ise **main quotation
list** par bhi laga diya — grouped header, paanch material columns, saved rows par
`rowspan`, Additional Note ka column, Urdu/Unit ko hidden. **Ye maanga nahi gaya
tha aur wapas le liya gaya** (`7c16401`).

Us revert me main list ki file poori ki poori `9ca63c1` par le jaayi gayi —
haath se nahi choti — taake us redesign ka koi hissa ittefaq se bach na jaye. Phir
wo **14 fixes ek ek kar ke naam le kar** wapas lagaye gaye jo kisi aur kaam ke
the: drag, `renumberLines`, Required Qty, PDF button waghera.

**Aur ek guard likha gaya taake ye dobara na ho:**
`test_the_main_quotation_table_keeps_its_own_columns` — quotation list ke naam
aur tarteeb pin hain. Entry area me kuch bhi karo, list chupke se hil nahi
sakti. (Tor kar dekha: list se ek `<th>` hatate hi laal.)

---

## 1. Ab entry area kya hai

Ek table, sirf us line ke liye jo abhi likhi ja rahi hai:

```
Item │ Qty │ System Rate │ Customer Rate │     Material Breakdown      │ Kitchen │ Note │ Line   │ Action
     │     │             │               │ Material│Rate│Required│Own│Party│ Instr.  │      │ Amount │
```

Product ke khaane `rowspan` se poore stack par phaile; **material neeche ki taraf
barhte hain**. Do material wali dish me doosra material seedha pehle ke neeche,
unhi paanch columns me — kabhi **paanch naye columns bagal me nahi**, kyunki
material us line ka **beta** hai, apni alag line nahi.

## 2. Ek faisla jo dikhta nahi magar sab se ahem hai

**Product ke khaane sirf EK BAAR render hote hain aur dobara kabhi nahi.**

Item ek select2 hai. Har dafa block dobara banane ka matlab hai **wohi control
ukhaad dena jis me operator type kar raha hai** — search, focus, sab. Is liye
sirf material ke khaane redraw hote hain, aur product ke khaano ka `rowspan`
badal diya jata hai.

## 3. OWN/PARTY ka global switch — poora gaya

Function `punchSetMode()`, dono buttons, `O`/`P` keys aur teenon call sites —
sab. Supply split ab **sirf har material ke apne Own/Party** se.

Ye chhupana nahi, **hataana** hai — aur farq asli hai: chhupane par `#punch-own`
Enter ki fehrist me reh gaya tha, aur chhupi cheez par `focus()` kuch nahi karta,
is liye caret Instructions par **qaid** ho gaya tha aur material rows keyboard se
pahunch se bahar. Jis control ka sawal khatam ho jaye, use safhe par chhorna
mehez malba nahi — wo kaatta bhi hai.

Jis material par party ki ijazat nahi, uska Party ka khana **disabled** hai, aur
Enter ka safar disabled/chhupi cheez khud chhod deta hai — ek hi usool, har
jagah.

⚠️ **Hadd, saaf saaf:** `party_allowed` aaj bhi **product** par hai
(`catering_product_profiles.allow_party_supply`), material par nahi. Is liye ek
dish ke sab material ek hi jawab lete hain — mockup wala "Chicken PARTY ALLOWED +
Rice OWN ONLY" aaj **mumkin nahi**. UI ki shakl per-material bana di gayi hai
(`m.partyAllowed !== false`), is liye jis din `catering_product_cost_blocks` par
column banega, **sirf ek satar** badlegi. Wo migration owner ka faisla hai.

## 4. Enter ka safar — owner ki tarteeb

```
Qty → Customer Rate → (har material) Rate → Own → Party
     → Kitchen Instructions → Additional Note
```

Note ab **aakhir me** hai. Pehle wo materials se **pehle** aata tha — yaani
operator us dish ka note likhta tha jiski miqdaar abhi daali hi nahi thi.

## 5. Validation — halki, aur imaandar

Har material ka `Own + Party` recipe ke jawab se mile to **halka sabz**, farq ho
to **halka amber**. Amber **ghalti nahi** — 28 KG dish par 42 KG beef jaan
boojh kar hota hai, aur wohi is screen ka asal kaam hai. Neeche ek satar:

```
Material rows: 2   Supply validation: OK — har material ka split recipe se mel khata hai
```

## 6. Kya bilkul nahi badla

- **Main quotation list** — ek harf nahi (guard isay pin karta hai)
- **POST ka shakl** — `lines[i][...]` aur `lines[i][materials][j][label|kg|rate|cust]`
- **Hisab** — `punchLineCalc`, `punchAgreedRate`, `punchRateIntent`, `punchCommit`
  sab jyun ke tyun; ids **badle nahi, jagah badli**
- **Drag / arrows / renumber / Required Qty** — sab qayam
- **Paisa** — kuch nahi chhua

Ek naya field: `profileMap.mats[].sku` — material ka code (`RM-CH-01`), sirf naam
ke neeche dikhane ke liye. Read-only view payload, costing use parhta bhi nahi.

## 7. Guard — aur us ki hadd

`test_the_entry_area_is_one_block_with_the_materials_stacked` — header do satron
me, `Material Breakdown` waqai `colspan=5`, aath product headings waqai
`rowspan=2`, entry row **server-rendered**, aur `punchRenderMats` **sirf** material
cells hataata hai (item picker ko haath nahi lagata).

`test_the_main_quotation_table_keeps_its_own_columns` — list ke columns pin.

⚠️ **Jo koi server-side test sabit nahi kar sakta:** Tab/Enter ka asal safar,
select2 ka khulna, aur Save Row ke baad ka amal. **Ye ek shakhs aur ek keyboard
maangta hai** — bilkul waise jaise drag ne maanga tha, aur wahan na dekhne ki
qeemat do din ka bug thi.
