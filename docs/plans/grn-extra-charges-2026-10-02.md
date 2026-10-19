# GRN-EXTRA-CHARGES-1 — cartage ko product ki jagah "Extra Charges" banana

**Tareekh:** 2026-10-02
**Halat:** PLAN — **koi code nahi likha, prod par kuch nahi badla**
**Maalik ki baat:** *"add a separate fees with description of cartage since it is a fees not product"* → naam **Extra Charges**

---

## 1. Masla asal me kya hai

Cartage koi cheez nahi, **kharcha** hai. Magar GRN par sirf product ki line daali ja sakti hai, is liye client usay **Spoon ke bhes** me daal raha tha:

| GRN | qty | rate | rakam |
|---|---|---|---|
| grn9 | 1 | 400 | 400 |
| grn10 | 1 | 400 | 400 |
| grn11 | 1 | 400 | 400 |
| grn12 | 1 | 400 | 400 |

**4 lines, 1,600 rupay.** Asli spoon 1.70 ka hai. Natija: spoon ka on-hand **4 zyada** hai aur 1,600 ki laagat ghalat item par paR gayi.

Kal (1 Oct) jo guard laga, us ne cartage ko GRN par aane se rok diya — theek rok diya, magar client ke paas ab koi jagah hi nahi bachi, is liye GRN ruk gayi.

---

## 2. Jo dhaancha dekh kar maloom hua (ye design badal deta hai)

```
goods_receipts       : id grn_no … receipt_date status notes …     ← paisa ka EK khaana nahi
goods_receipt_lines  : … quantity_received unit_cost discount_amount tax_amount
purchase_bills       : … subtotal discount_total tax_total grand_total   ← paisa yahan rehta hai
```

Aur:
- **GRN koi GL journal nahi banati** — `JournalPostingService` me GRN ka zikr hi nahi. `postGrn()` sirf `InventoryService::postIn()` chalati hai.
- **Khatri ne aaj tak 0 purchase bill banaya** — sirf GRN.

> Dono milaa kar iska matlab ye hai: **agar Extra Charges sirf "likh" diya jaye aur laagat me na jaye, to wo 1,600 kahin nahi jayega.** Koi journal nahi, koi bill nahi — mehez aik yaaddasht. Isi liye neeche ka faisla ahem hai.

---

## 3. Teen raaste

| | kya hota hai | paisa kahan jata hai |
|---|---|---|
| **(a) Sirf darj karo** — GRN par rakam + tafseel, laagat par asar nahi | sab se saada, paisa bilkul nahi hilta | **kahin nahi** (jab tak bill na bane — jo bante hi nahi) |
| **(b) Landed cost** — rakam ko lines par un ki qeemat ke tanasub se baant do | khareedi hui cheezon ki asli laagat barh jati hai | **inventory me**, phir bikne par COGS me |
| **(c) GL kharcha** — GRN par journal banao (Dr Freight-In / Cr supplier ya cash) | durust-tareen, magar GRN ko paise wala document bana dena hai | **kharche me** |

### Meri sifarish: **(b) landed cost**

Wajah ye hai ke **client aaj bhi yehi kar raha hai, bas ghalat item par.** 400 ka cartage spoon ki laagat me chaRh raha hai. (b) wohi 400 un cheezon par baant dega jo waqai aayi thin (containers, spoon) — yani **sahi bucket, sahi items**, aur koi jaali product nahi.

(a) chhoTa aur mehfooz hai, magar us se wo 1,600 **kitabon se ghayab** ho jayega — abhi kam az kam (ghalat jagah) darj to hai. (c) sab se durust hai magar GRN ko poora financial document banana paRega, aur Khatri ke pass abhi GL me purchase ka koi silsila hai hi nahi — wo alag, baRa kaam hai.

⚠️ **(b) paisa chhoota hai** — `unit_cost` badalta hai, is liye stock ki qeemat aur aage chal kar COGS badlegi. Ye jaan boojh kar hai, magar maalik ko maloom hona chahiye.

---

## 4. Banane me kya kya

**Migration (additive):**
```
goods_receipts + extra_charges        DECIMAL(14,4) NOT NULL DEFAULT 0
goods_receipts + extra_charges_note   VARCHAR(255) NULL
```
Purane sab receipts par 0 → un ka bartaao bilkul nahi badalta.

**Create/Edit screen:** rakam ka khaana + tafseel ka khaana (maslan "Cartage", "Labour", "Unloading"). Khaali chhoRo to kuch nahi hota.

**Post (`PurchasingService::postGrn`):** rakam ko lines par **un ki apni qeemat ke tanasub se** baanto, aur har line ka `unit_cost` usi hisaab se barhao.

> Rounding: aakhri line par baqi paisa daal kar jama poora milaya jayega, warna 400 me se chand paise gum ho jate hain.

**Show/print:** GRN par alag satar — *"Extra Charges: 400.00 — Cartage"* — taake jo laagat barhi wo nazar bhi aaye.

**⚠️ Linkage (memory ka usool — aik hi pass me):** jahan bhi GRN se bill banta hai, wahan `extra_charges` saath jana chahiye, warna bill GRN se kam dikhega.

---

## 5. Purane 4 lines ka kya

Ye **posted GRN aur chaRha hua stock** hai, is liye mitaya nahi jata — durust keya jata hai.

**Tajweez:** har us GRN (9, 10, 11, 12) par
1. 400 wali spoon line hatao
2. usi GRN ka `extra_charges = 400`, `note = 'Cartage'` kar do
3. us line ka stock asar ulta do — spoon **−1** aur laagat **−400** per GRN

Nateeja: spoon ka on-hand **6,504 → 6,500** (yani asli ginti), aur 1,600 apni sahi jagah par.

⚠️ Ye **stock aur laagat dono ko chhoota hai**, is liye maalik ki saaf ijazat ke baghair nahi hoga. Pehle **dry-run** dikhaunga: kya hatega, stock pehle/baad, aur rakam.

---

## 6. Guards (likhne hain)

1. Extra charges 0 / khaali → GRN ka bartaao **bilkul** pehle jaisa (purani har receipt isi raaste par hai).
2. Charge lines par tanasub se banta hai, aur **jama poora** milta hai (rounding se paisa gum na ho).
3. Aik hi line ho to poora charge usi par.
4. Tafseel (note) mehfooz rehti hai aur GRN par chhapti hai.
5. Charge **stock ki ginti nahi badalta** — sirf laagat. (Aik jaali product ki line ginti badal deti thi; yehi to theek kar rahe hain.)
6. Negative ya hurf-wali rakam rad ho.
7. Migration ke baad purani GRN ki qeemat **jyun ki tyun** (re-post se qeemat na hile).

---

## 7. Risk

| khatra | haqeeqat |
|---|---|
| stock ki ginti | **nahi badalti** — charge sirf laagat par lagta hai |
| stock ki qeemat / COGS | **badalti hai** — yehi maqsad hai (§3), aur maalik ko bataya gaya |
| purani receipts | migration default 0 → koi asar nahi |
| dusre tenants | koi charge daalega hi nahi to kuch nahi badlega |
| palatna | code revert; column additive hai, data 0 |

---

## 8. Tarteeb

1. Ye MD maalik dekh lein — khaas kar **§3 ka faisla (b)** aur **§5 ki durustagi**.
2. Migration + code + 7 guards, phir sabotage.
3. Purane 4 lines ka **dry-run** dikhana, phir ijazat par durustagi.
4. Deploy sirf ijazat par.
