# CATERING-RESTORE-IN-PLACE-1 — Restore dabane par wohi purani quotation wapas aaye (31 Oct 2026)

Status: **BUILT on `feat/catering-restore-in-place-20261031`** (malik 31 Oct: "teekh hai … dekh k samaj k test kar k
kaam karna, purani cheezon ko mind me rakh, start karo"). Not deployed.

**Faisle jo code me gaye (31 Oct):**
- Restore = wohi version, apni purani haalat me (accepted/sent/draft — timestamps se), koi nayi row nahi.
- Booking "paper ke peeche chalti hai" — sirf wohi do qaide jo pehle se hain: finalised version wapas aur booking
  inquiry/draft → `quoted` (markSent jaisa); draft version wapas aur booking quoted/confirmed → `draft` (revise jaisa).
  Baqi sab jahan hai wahin — confirmed booking par bheji hui version wapas aaye to **confirmed hi rehti hai**.
- Restored (bheji hui) version par editor khula; pehli BADLI hui Save = `revise()` + wohi Save (Q3); booking wohi
  qadam leti hai jo Create Revision par (purana qaida nahi chheda). Bina badle Save → kuch nahi (rollback).
- Restored version par "Create Revision" button nahi (wo sirf be-badli copy banata); inline Apply rate / Charge it
  instead / Cost Details buttons band (Cost Details pehle se sirf draft par khulte the).
- Purane Q3-copy wale bookings ko nahi chheda.

## 1. Client ne kya kaha

Booking `/catering/events/470` (MR. SHAKEEL, event 5 Dec): Q1 = 5,65,275 (superseded), Q2 = 4,92,650 (sent).

> "Jab bhi restore karta hoon, hamesha nayi revision ban jaati hai. Aisa nahi hona chahiye.
> Restore karoon to asal me wohi purani (Q1) wapas aaye, aur wo edit ho sake. Agar user
> kuch badle aur save kare tab bataye: aap ne restored version badla, ab nayi revision main hogi."

Shart: **payment, refund, kitchen sheet, invoice, proforma (draft invoice) — koi cheez kharab na ho.**

## 2. Aaj kya hota hai (asal code)

`CateringEstimateService::restoreVersion()` (KASHIF-EVENT-HISTORY-2, commit `250ae485`, 23 Aug):

```
Q1 superseded, Q2 sent  ──Restore Q1──▶  Q1 superseded, Q2 superseded, Q3 = Q1 ki copy (DRAFT)
```

Yani restore = **nayi Q3** (draft). Q3 ko phir Finalize karna parta hai. Isi ko client "har restore par nayi
revision" keh raha hai.

Ye shakl jaan boojh kar 23 Aug ko chuni gayi thi — plan `docs/plans/kashif-event-history-revert-2026-08-23.md`
§3: "Revert ka matlab purani row par wapas likhna NAHI … history sirf aage chalti hai". Ab malik ye usool
restore ke liye badal raha hai — ye **malik ka faisla** hai, technical majboori nahi (§4 dikhata hai ke naya
tareeqa bhi history nahi mitata).

### Purane commits jo is kaam se jude hain (pehle parhe gaye)

| Commit | Kya sikhaata hai |
|---|---|
| `250ae485` 23 Aug KASHIF-EVENT-HISTORY-1/2 | Restore = copy aage. `revise()` aur restore EK hi clone (`cloneAsNewDraft`) use karte hain. History (`catering_event_revisions`) har save par poori haalat ka snapshot rakhti hai. |
| `d32722c2` → `b8985a05` 9 Sep | "Create Revision" button chhupaya gaya, **usi din wapas** laya gaya (revert). Commit me likha: "sent quotation ko draft me la kar wahin edit karna ek alag workflow change hai, apna plan chahiye — wo plan abhi baqi hai." **Ye wohi plan hai.** |
| `1e75d723` 10 Sep CATERING-STATUS-ROLLBACK-1 | `reopenCurrentQuotation()`: booking quoted → draft karo to bheji hui quotation **usi jagah** dobara draft ho jaati hai (status badalta hai, nayi row nahi). Yani "row ka status wapas badalna" pehle se system me hai aur immutability guard ise rokta nahi (status COMMERCIAL_COLUMNS me nahi). |
| `5376989c` 15 Sep CATERING-REVISION-MONEY-1 | Revision ek **paison wala kaam** hai: graahak ka baqaya HAMESHA current quotation se parha jaata hai. Revise par booking `quoted/confirmed` → `draft`. Paisa (advance, refund, journal) kabhi nahi chhua jaata. |
| `e0742716` 1 Oct CATERING-EDIT-AFTER-RELEASE-1 | Release ke baad quotation badalna alag permission (`edit-after-release`) maangta hai — `assertRevisable()` ke zariye. |
| `7ed8086c` 10 Oct NOTHING-FREEZES + PROFORMA | Kitchen sheet aur proforma hamesha current quotation se bante hain (`sheetFor()`, `proforma()`). |

## 3. Naqsha (system map) — kya kya is se juda hai

`docs/system-map/system-map.json` (built 31 Oct, head `5bdaac80`), table `catering_estimates`:

- **FK in:** `catering_estimate_lines` (CASCADE), `catering_cost_snapshots` (CASCADE),
  `catering_final_invoices.catering_estimate_id` (SET NULL), `catering_production_releases.catering_estimate_id` (SET NULL).
  Inferred: `catering_commercial_rate_applications.catering_estimate_id`.
- **Paisa quotation se juda hi nahi:** `catering_advances`, `catering_refunds`, `journal_entries` booking (event) se jude
  hain, kisi version se nahi. Is liye version ka status badalne se koi receipt/refund/GL row nahi hilti.
- **71 routes** isay chhoote hain; catering wale: booking list/show/edit, estimates update/send/accept/revise/
  restore-version, revisions.revert, reprice, line-cost-blocks ×4, estimate-lines ×2, commercial-rates ×5,
  rate-impact ×2, making-adjustment ×3, advances ×3, customer-credits, documents (estimate, kitchen-sheet ×2,
  proforma, final-invoice, bulk ×4, print ×2), emails ×2, calendar.

### "Current quotation" kaun tay karta hai

`CateringEvent::currentEstimate()` = **sab se oonchi version jo superseded/cancelled NAHI**. Yehi ek relation
ye sab parhte hain — is liye restore ke baad ye sab apne aap Q1 par aa jayenge:

| Kaun | Kahan | Restore-in-place ke baad |
|---|---|---|
| Kitchen sheet (single + bulk + queue) | `CateringProductionReleaseService::sheetFor()` | Q1 ki lines ✅ |
| Release purani hai? banner | `CateringEvent::hasStaleRelease()` (release ka `catering_estimate_id` ≠ current) | Agar release Q1 se hui thi to **stale nahi** (sahi — parcha Q1 hi tha). Aaj ki Q3-copy me ye ghalat "stale" dikhata hai. ✅ behtar |
| Proforma / draft invoice | `CateringFinalInvoiceService::proforma()` | Q1 ✅ |
| Final invoice | `issue()` — current non-draft maangta hai | Q1 sent hai → seedha invoice ho sakti hai (Q3 finalize karne ki zaroorat khatam) ✅ |
| Baqaya / Booking Statement / Customer Balances / Calendar | `CateringFinancialPositionService` (currentEstimate grand_total) | Q1 ka total — **wohi number jo aaj Q3 copy deti** ✅ |
| Rate Impact / Making Adjustment | estimate ka status sent/accepted dekhte hain | Q1 (sent) shamil, Q2 (superseded) bahar ✅ |

### ⚠️ Teen jagah jo "current" ko **max(version_no)** se nikaalti hain — inhein badalna LAZMI

| Jagah | Aaj | Restore-in-place ke baad kya toot-ta |
|---|---|---|
| `CateringEventHistoryService::currentEstimate()` → `snapshotOf()` (line 90) | max version | History ka snapshot **Q2 (superseded)** ka banta — ghalat yaad. |
| wohi → `revertTo()` (line 412) | max version | "Is halat par wapas jao" **error** deta: "current version is superseded". |
| `restoreVersion()` khud (line 546) | max version | Kis ko supersede kare — ghalat row chun sakta hai. |

`revise()` ka `max(version_no)+1` theek hai — wo sirf agla NUMBER deta hai (Q3), current nahi.

## 4. Tajweez (recommended): Option A — "Restore = wohi Q1 wapas, nayi row nahi"

```
Q1 superseded, Q2 sent  ──Restore Q1──▶  Q1 = wapas apni purani haalat (sent), Q2 superseded
                                          (koi Q3 nahi; Q2 par ab "Restore" button)
```

1. **Q1 ka status wapas** us haalat par jo supersede hone se pehle thi — timestamps se, bina naye column ke:
   `accepted_at` hai → `accepted`; warna `sent_at` hai → `sent`; warna → `draft`.
   (Sahi kyun: `markSent` `sent_at` likhta hai, `markAccepted` `accepted_at`, aur `reopenCurrentQuotation`
   draft me laate waqt `sent_at` null kar deta hai.) `superseded_at` → null.
2. **Current (Q2) → superseded** (aaj jaisa). Q2 draft thi to bhi mehfooz — wapas restore karo to draft hi aayegi.
3. **Hamesha EK hi current** — sab ek transaction aur `CateringDocumentLock` ke andar.
4. **Q1 ke numbers, lines, cost blocks ek byte nahi badalte** — sirf status. Immutability guard (COMMERCIAL_COLUMNS)
   chalta rehta hai.
5. **Edit — malik ki pasand (31 Oct): restored Q1 seedha editable dikhe, Q3 sirf SAVE par bane.**
   - Restored (bheji hui) Q1 par wohi edit form khulega jo draft par khulta hai, upar patti: *"Ye wapas laayi gayi
     Q1 hai. Kuch badal kar Save karenge to Q3 nayi revision banegi aur wohi main hogi; Q1 waisi hi rahegi."*
   - **Save** (`CateringEstimateController::update`) par, ek transaction me: `revise(Q1)` → Q3 draft (wohi ek clone)
     → form ki lines ke `line_uuid` Q1→Q3 par badle jayen (clone sort_order se banta hai; `line_uuid` poori table me
     unique hai, is liye copy nahi ho sakta — map banana parega) → `saveDraftLines(Q3)` + punch materials + punched
     rates (wohi teen qadam jo aaj Save karta hai).
   - **Kuch nahi badla → kuch nahi bana:** Q3 ko Q1 se milao (lines, qty, rate, blocks, charges); barabar ho to
     transaction rollback, message "kuch nahi badla, Q1 hi main hai".
   - **Cost Details ke chhote inline buttons** (block update/reset/customer-supplied/rate, quoted-rate,
     use-calculated-rate, reprice) foran likhte hain, Save ka intezar nahi karte. Restored locked Q1 par ye **band**
     rahenge; pehle Save ke baad (Q3 draft) sab normal. Punch bar ki materials (kg/rate/party) aur Customer Rate
     pehle se Save ke saath jaate hain (KASHIF-ORDER-PUNCH §B3, `rate_action`), is liye asal editing poori Save se ho
     jaati hai.
   - Agar restore ki gayi version khud draft thi to wahin edit hoti hai, koi Q3 nahi.
6. **History:** `version_restored` row pehle jaisi; snapshot ab Q1 ka (§3 wali teen jagah theek karke).

### Saath me do purane chhed band karne hain (aaj bhi khule hain)

- `restoreVersion()` **service me** `assertRevisable()` nahi bulata. Invoice ke baad restore sirf screen chhupati
  hai; aur release ke baad bina `edit-after-release` permission ke restore ho jaata hai — kitchen sheet badal jaati
  hai. Naye code me service khud rokegi.
- Restore ka confirm kehta hai *"paisa nahi badlega"* — ye adha sach hai: koi receipt nahi hilti, magar **baqaya
  badalta hai** (Q1 ka total current ho jaata hai). Confirm ko wohi paisa wala dialog milega jo Create Revision ko
  mila (quoted / received / due / credit) — `position()` se parh kar.

### Paisa, refund, kitchen sheet, invoice — kya hoga (client ki shart)

| Cheez | Asar |
|---|---|
| Advance / receipts | Koi row nahi hilti (event se jude hain, version se nahi). |
| Refund | Hadd "jitna mila" par hai, quotation par nahi → koi farq nahi. Agar Q1 ka total mile hue paise se kam ho to baqi "customer credit" dikhega — aaj ki Q3-copy me bhi bilkul yehi hota hai. |
| Journal / khate | Restore koi entry nahi likhta. Test trial balance pehle/baad barabar sabit karega. |
| Kitchen sheet | Apne aap Q1 ki lines. Release Q1 se thi to "purani" ka nishaan nahi aayega. |
| Proforma (draft invoice) | Apne aap Q1. |
| Final invoice | Event ka din guzarne ke baad Q1 se seedha banegi. Invoice ban chuki ho to restore band (pehle void). |

## 5. Jo NAHI karenge — har inline button ke andar alag se Q3

Inline buttons ko locked Q1 par chalne dena matlab 7 jagah "pehle Q3 banao, phir Q1 ki line/block ko Q3 ki
line/block se milao". Ek jagah bhoole to tabdeeli Q1 (graahak wala kaghaz) par likhi jayegi ya cost-block faisla
gum hoga. Is liye Q3 banne ka darwaza **sirf ek** hai: Save.

### Revision/restore kab tak ho sakta hai (malik ki yaad ki tasdeeq, code se)

`isCommerciallyOpen()` = booking `inquiry / draft / quoted / confirmed` **ya** `released`, aur final invoice na bani
ho. Yani haan — **complete (invoice) hone tak** kisi bhi waqt. Teen istisna:
- `released` par sirf `edit-after-release` permission wala (revise me pehra hai; **restore me nahi** — §4 ka chhed).
- `production_ready` is fehrist me nahi → wahan revise band hai.
- `cancelled` / `completed` / `closed` → band. Invoice event ka din guzarne ke baad hi banti hai (10 Oct).

## 6. Malik se sawal (code se pehle)

1. ✅ (31 Oct) Restore = wohi Q1, seedha editable; Q3 sirf badal kar Save karne par. Inline Cost Details buttons
   pehle Save tak band — malik ko batana hai.
1b. **Q3 banne ke baad booking:** aaj ka qaida (CATERING-REVISION-MONEY-1) booking ko `confirmed` → `draft` le jaata
   hai aur Q3 ko Finalize + dobara Confirm karna parta hai. Yehi rahe, ya Q3 bina dobara confirm chalti rahe?
2. **Booking ka status:** booking Q2 par *confirmed* thi, Q1 restore ki. Tajweez: **confirmed hi rahe** — Q1 bhi
   graahak ko bheja hua kaghaz hai, dobara accept/confirm na karna pare (malik pehle keh chuke "customer accept
   wagera kuch na karna pare"). Ya revise ki tarah booking `draft` par jaye?
3. Jo bookings pehle restore ho kar Q3-copy bana chuki hain — **unhein waise hi chhor dein** (tajweez), koi purana
   data nahi chheda jayega.

## 7. Kaam ka khaka (jawab ke baad)

**Files:** `CateringEstimateService::restoreVersion()` (+ `assertRevisable`, status wapsi),
`CateringEventHistoryService::currentEstimate()` (non-superseded), `CateringEstimateController::restoreVersion()`
(flash message), `events/show.blade.php` (Version History badge "Restored", patti + Edit button wording, paison wala
confirm). **Koi migration nahi, koi naya route/permission nahi** (wohi `restore-version`) → kisi role ko grant ki
zaroorat nahi.

**Tests (MySQL, asli controller/service se):**
1. Restore Q1 → estimates ki ginti wohi; Q1 = sent (current), Q2 = superseded; Q1 ke lines/blocks/totals byte-barabar.
2. Accepted aur draft versions ka restore apni apni haalat me wapas.
3. Paisa: advances/refunds rows byte-barabar, journal entries ki ginti aur trial balance barabar; baqaya = Q1 total.
4. Kitchen sheet `sheetFor()` = Q1 lines; Q1 se release → stale nahi; Q2 se release → stale banner.
5. Proforma Q1 dikhaye; din guzarne ke baad invoice `catering_estimate_id` = Q1.
6. Invoice ke baad restore → service mana kare (screen ke baghair bhi). Release ke baad bina permission → mana.
7. Restore ke baad History snapshot Q1 ka; `revertTo()` kaam kare.
8. Q2 ko wapas restore (aage-peeche) aur do restore ek saath → hamesha EK current.
9. Restored Q1 par badal kar Save → Q3 me tabdeeli + baqi lines ke cost-block faisle salamat, Q1 byte-barabar aur
   superseded. Bina badle Save → koi Q3 nahi. Locked Q1 par inline button → mana (Q1 kabhi na badle).
10. Booking screen controller se render: Version History, patti, buttons.
11. Purana test `test_restoring_a_superseded_version_copies_it_forward…` (CateringEventHistoryMySqlTest) naye qaide
    par badlega — malik ka faisla, purani shakl ka guard nahi rahega.

**Gate:** paise ki hisaab-kitaab wali screen hai → poora catering MySQL suite, phir system map rebuild, phir
malik ki "deploy" par deploy (smoke `tb_diff=0`).
