# Ek mahine ka kaam — 19 August se 19 September 2026

**Tamam sessions, tamam parallel worktrees, tamam branches.**
Prod HEAD is waqt: **`acf33a8`** (`feat/14d-2-plan-upgrade-requests`)
Likha gaya: 2026-09-19

Ye file pichle mahine ke log (`work-log-month-2026-08-07-to-09-07.md`) ke BAAD ki tasveer hai,
aur us ke saath overlap karti hai — wo 7 Aug se 7 Sep tha, ye 19 Aug se 19 Sep.

---

## 0. Kaun si file kis cheez ki maalik hai, aur kis par bharosa pehle

Is mahine ke teen snapshot hain. **Teenon apni jagah rehte hain — ek doosre ki naql nahi.**

| file | kya hai | kahan |
|---|---|---|
| **ye file** | **tafseeli bayaniya** — kya hua, kyun hua, kis commit par | `docs/status/work-log-month-2026-08-19-to-09-19.md` (canonical repo) |
| `project_month_2026_08_19_to_09_19.md` | canonical session ki **project memory** — live halat aur khule kaam | `…\d--laragon2-www-pos-saas\memory\` |
| `all-sessions-month-2026-08-19-to-09-19.md` | **milkiyat / index naqsha** — kaun sa worktree kis session ka, Edge ki tranche ladder | `…\d--laragon2-www-pos-saas-edge\memory\` |

### Source priority — ikhtilaf ki soorat me tarteeb

1. **Taza `git` / zinda prod tasdeeq** — sab se upar
2. **`docs/status/work-log-month-2026-08-19-to-09-19.md`** (yehi file)
3. Track-makhsoos status docs (Edge tranche docs, catering status)
4. Cross-session memory index (`all-sessions-…`)
5. Purani memory entries

> **Purani memory kisi naye, tasdeeq-shuda work log ko kabhi rad nahi kar sakti.**
> Agar koi purana note is file se takrae, to ye file jeetti hai — jab tak taza `git` ya prod
> census us ko bhi na jhutla de.

---

## 1. Aankre (canonical branch)

| | |
|---|---|
| commits (merge ke baghair) | **308** |
| files chhue gaye | 486 |
| lines | **+80,488 / −1,959** |
| naye test files | **91** (+ 29 mojooda badle) |
| migrations | **40** (sab additive) |
| naye docs | **104** |
| `app/` | 45 naye, 98 badle |
| `resources/views/` | 21 naye, 46 badle |

⚠️ **Ek aankre ki wazahat:** `git diff` ka khaam number **+184,418** aata hai. Us me se ~104,000
lines sirf `docs/data/*.csv` hain — Kashif Kitchen ki legacy migration ke dumps
(`legacy-order-lines.csv` akela 68,550 lines). Wo data hai, kaam nahi. Upar wala **+80,488**
un ke baghair hai, aur wohi asal number hai.

### Har din ka zor

Sab se bhaari din: **22 Aug (23)**, **9 Sep (24)**, **23 Aug (21)**, **31 Aug (18)**,
**30 Aug (17)**, **1 Sep (16)**, **24 Aug (16)**, **3 Sep (15)**.
Sab se halke: 25 Aug (1), 11 Sep (2), 16/17 Sep (2 har ek). 12 Sep par koi commit nahi.

---

## 2. Kaam kis kis cheez par hua

Commit tags ki ginti se (canonical):

| silsila | commits | kya |
|---|---|---|
| **CATERING-*** | 37 | Kashif Kitchen ka poora catering module — overpayment, refund, status rollback, customer credit, PDF, address, revision-as-financial-act |
| **KASHIF-ORDER-PUNCH-*** | 15 | punch screen ki poori shakl — search relevance, tab order, no-free-text, inline editor, row drag |
| **Reports / thermal** | ~20 | ITEMS-BY-CATEGORY-1, THERMAL-ITEM-LAYOUT 1+2, REPORT-DEAL-IDENTITY-1, REPORT-DEAL-COMPONENTS-1, DEAL-CATEGORY-1, REPORT-CATEGORY-ORDER-1, LEGACY-REPORTS-POPULATION-1 |
| **POS / printing** | ~25 | terminal scoping, KOT-SENT-POOL, COMBO-KOT-DEAL-NAME-1, KOT-REPRINT-BLANK-1, PRINTER-HEALTH-1, PRINT-AUTOCLOSE-STUCK-1 |
| **Shift / business date** | ~10 | OPERATING-DATE 1+2, SALE-DATE-TRUTH-1, KOT-TIME-TRUTH-1, GL-BUSINESS-DATE-1, SHIFT-RECONCILE 1+2, SHIFT-CANCELLATIONS-1, ZERO-DRAWER-1 |
| **Dashboard** | ~8 | DASHBOARD-DETAILS-1, DASHBOARD-OPEN-BILLS-1, DASHBOARD-7DAY-POPULATION-1, SALES-ANALYTICS-1, DASHBOARD-8DAY-1 |
| **Restaurant table** | ~8 | TABLE-RESERVATION 1/2/2b, TABLE-CLOSE-EMPTY-1, HELD-SALE-DEAD-SESSION-1, BILL-PREVIEW-* |
| **Supplier finance** | 4 | SUPPLIER-FINANCE-DIRECT-1, SUPPLIER-PAYMENT-HTTP-GUARDS-1, PURCHASE-RETURN-GL-REGRESSION, UI-CONSISTENCY-1 |
| **docs / research** | **53** | har bare kaam se pehle MD, aur baad me tasdeeq |

53 me se kai commits **apni hi ghalti ki tasheeh** hain — `docs: correct a defect I reported that
does not exist`, `docs: MD ki tasheeh — P2 ka khatra jo maine likha tha wo hai hi nahi`,
`docs: step 4 is a table redesign, not one line — correcting my own plan`,
`docs: fix the timezone claim`. Ye jaan-boojh kar record me hain.

### Teen HOTFIX (sab usi din live)

- `d746abe` — **Close Branch sab ke liye 500**: `voided@endif` — Blade directive lafz se chipak
  gaya to compile hi nahi hota. Isi ke baad se har Blade change compile kar ke generated PHP lint
  hota hai.
- `1385876` — POS payload ne aisi relation maangi jo hai hi nahi (`SalesOrderLine::salesOrder`)
- `193b5c4` — KOT-TIME-TRUTH-1 ne Layout ka live preview 500 kar diya tha

Aur ek **Revert + Reapply** jodi: `RECALL-REPRINT-TERMINAL-2` (`ed977c2` → `dff1313` revert →
`f0923e4` reapply) — pehli koshish order ka terminal badal deti thi, jo ghalat tha.

---

## 3. Aakhri hafta (13–19 Sep) — is session ka kaam

| commit | kya |
|---|---|
| `ea89c66`+`05bcbaa` | **HELD-SALE-DEAD-SESSION-1** — band table par bill hold ho jata tha aur phir kabhi pay nahi hota. `HeldSaleController:419` par `whereIn('status', …)` ki kami; `RestaurantTable::openSession()` ka `latestOfMany()` status filter inner subquery par nahi lagata tha. Live bill #5408 (Rs 2,465) bachaya gaya. |
| `09c4998`→`9d5e8af` | **STEAK-SIDE-MODIFIER-1** — aur us ki **durusti**: maine pehle side ko upcharge samjha tha, client ka matlab tha side **shamil** hai. Prod par `price_delta = 0`. Tasdeeq: 0 sale lines ne modifier use kiya tha, yani **kisi grahak se ghalat paisa nahi liya gaya**. |
| `8f6e467`→`a46d03a` | **SALES-ANALYTICS-1** — Owner-only graphs wala safha, order-type filter, chart par din ka naam, matn English |
| `7cd5924`+`ed4d2b2` | **BILL-PREVIEW-WRONG-PRINT-1** + **UNHIDE** — modal jo dikhata tha wo nahi, background wali order chhapti thi. 31 Aug ko button chhupa diya gaya tha (`75dc5cf`) — wo ilaj nahi tha. Ab theek, aur button wapas. |
| `58e6ff5` | **TABLE-WORKSPACE-WIDTH-1** — View Tables modal chaurha, sirf ≥992px par |
| `636ab9d` | **TABLE-BILL-PREVIEW-PARITY-1** — table ka bill ab wohi `receipt.blade.php` document hai jo cart preview aur asli parchi hai. 11 Aug ka PARITY sirf cart par laga tha, table chhoot gaya tha. |
| `e4ee1f6` | **ORDER-TYPE-PERCENT-1** — Order type donut par %, Payment par nahi |
| `2bba878`+`f5d4881` | **DASHBOARD-8DAY-1** — history 7 se 8 din; ginti ab ek jagah (`$windowDays`) |

### Isi arse ke prod DATA changes (code nahi)

- **Tawakkal Biryani**: Beef Pulao Single 250→280, Half KG 350→380, 1 KG 700→750
- **Dono branches**: Cherry Crunch 250g 280→300, Half Pack 550→600, Full Pack 1100→1200
- **The Kashif Foods**: Beef Pulao Single 250→280, Half KG 350→380; naya product **Extra Garlic
  KF-074 @ 60** (Singaporean Rice cat1 me)
- **The Kashif Foods category restructure**: `cat4 Chicken Pulao` → **`Pulao`**, uske neeche naye
  `cat23 Chicken` (4 items) aur `cat24 Beef` (2 items); 6 items (Sada/Chana/Extras) parent par.
  **Koi printer mapping nahi daali** — is tenant par `category_printer_mappings` **0 rows** hain,
  har KOT pehle se branch default par girta hai, aur branch 1 par printer ek hi hai. Report ka root
  total sabit kiya: 156,270 + 5,050 + 20,940 = **182,260**, yani pehle ke barabar.

Har data change par `tb_diff = 0.00` tasdeeq hua.

---

## 4. Worktree aur branch ka naqsha — **kya kahan hai, aur kya khatre me hai**

**15 worktrees** (`git worktree list`, taza ginti 19 Sep). Har ek me `git status` chalaya aur
`git ls-remote --heads origin` se tasdeeq ki — **merged hona untracked files ya unpushed branches
ka koi saboot nahi.**

> **Tasheeh (19 Sep):** is file ne pehle **14** likha tha. Wo ghalat tha — meri ginti
> `pos-saas-catering/.codex-worktrees/` ke teen worktrees chhod gayi thi. Cross-session index ka
> **15** durust hai. Branch-only ref worktree nahi ginti jati.

### ✅ Mehfooz — canonical me shamil, origin par maujood

| worktree | branch | halat |
|---|---|---|
| `pos-saas` | `feat/14d-2-plan-upgrade-requests` | **PROD** `acf33a8`, saaf, 0 unpushed |
| `pos-saas-hideamounts` | `fix/bill-preview-unhide-v1` | saaf, merged, pushed |
| `pos-saas-catering` | `feat/catering-stacked-material-row-20260909` | saaf, canonical se bahar **0** |
| `pos-saas-tawakal` | `feat/tawakal_kashif` | saaf, bahar 0 |
| `pos-saas-catering-parity` | `feat/catering-operator-completion-v1` | bahar 4, origin par |
| `pos-saas-catering-catalogue` | `data/kashif-catalogue-prep-v1` | bahar 1, origin par |
| `pos-saas-catering-codex-cert` | `audit/catering-rate-impact-cert-v1` | bahar 17, origin par |
| 3× `.codex-worktrees/*` | catering fixes (27/28/29 Aug) | bahar **0** — poori tarah merged |

### 🟡 Origin par hai, magar canonical me NAHI — soch samajh kar

| branch | bahar | aakhri | kya |
|---|---|---|---|
| **`feat/edge-config-refresh-v1`** | **93** | **14 Sep** | 🚨 neeche dekhein |
| `feat/catering-product-ux-v1` | 25 | 8 Sep | Aug ka commercial-rate/quotation-lifecycle kaam + 09-07 wali rescue (cert tests yahan mehfooz hain) |
| `feat/cloud-billing-onboarding-v1` | 8 | 15 Aug | CLOUD-BILLING 1A-HARDEN/1B/2/3A + E2E. **Code taiyar hai — payment account ka intezaar hai, code ka nahi.** |

### 🔴 SIRF IS MACHINE PAR — origin par nahi

| branch | bahar | aakhri |
|---|---|---|
| `audit/catering-product-completeness-v1` | 18 | 21 Aug |
| `audit/catering-rate-impact-cert-v2` | 17 | 20 Aug |
| `audit/catering-e2e-qa-v1` | 13 | 20 Aug |

**In ka asal natija mehfooz hai** — dono audit dastavez canonical ke `docs/audits/` me maujood hain
(`catering-independent-architecture-audit-2026-08-20.md`,
`catering-product-completeness-2026-08-21.md`, 09-07 ki rescue `10ae49d` se). Jo cheez sirf yahan
hai wo un branches ke **48 commits ka safar** hai — audit ke darmiyan ke code experiments. Agar ye
machine chali jaye to natija nahi, **raasta** kho jayega.

### Detached worktree — check kiya, kuch khatre me nahi

`pos-saas-catering-codex-cert-v2` (detached `ec09b6a`) me 1 tracked + 2 untracked files hain:
`CateringRecertificationRedTeamMySqlTest.php`, `CateringFinalPermissionHttpMySqlTest.php`,
`Support/catering_rate_race_worker.php`.

Ye wohi teen files hain jo 09-07 ko bachai gai thin. **Teenon `origin/feat/catering-product-ux-v1`
par mehfooz nuskhe se BYTE-BARABAR hain** (`git hash-object` se milaya). Yani yahan untracked
dikhna surat hai, khatra nahi.

---

## 5. 🚨 Edge track — "FROZEN + DORMANT" ab SAHI NAHI

Memory me ye track "FROZEN+DORMANT, 51 unmerged commits, aakhri commit 1 Sep" likha hai. **Wo
purana ho chuka.** Ab:

- **93 commits** canonical se bahar — August me 51, **September me 42**
- Aakhri commit **14 Sep**
- Canonical ko baqaidgi se andar merge kiya ja raha hai (5 merge commits 8–13 Sep ke darmiyan)

September me jo hua:

| | |
|---|---|
| **P (8 Sep)** | branch-authority-lease ki tasneef — tenant reset par lease qaayam rehta hai |
| **Q (10 Sep)** | connection state machine, supervised authority worker, warm-standby freshness, controlled handback |
| **F1 (10 Sep)** | sales returns + cash refunds **exactly once** — returnable-sale warm cache, immutable return event |
| **F2 (11 Sep)** | supplier finance parity — offline direct supplier payment + supplier-aware General Journal; Cloud AP/GL/cash-bank exactly once |
| **F3 (11 Sep)** | purchase return parity — offline, source goods receipt ke khilaf; Cloud stock OUT / subledger / AP-GL exactly once |
| **P4 (12 Sep)** | Windows appliance packaging, print authority lock, installer prep |
| **P5 (13 Sep)** | release-shaped appliance package, certification kit, key-custody tooling, **teen release-shape defects theek** |
| **P5B (14 Sep)** | release-signing custody keystore, Cloud backup recovery authority, replacement-machine restore |

Un ke apne docs me gate numbers bhi hain (full MySQL 1616/1611 at P4, 1603/1598 at F3,
1582/1576 at F2) aur ek **eemandaar** faisla: `READY_FOR_WAN_UNPLUG_PILOT = no`.

**Durust jumla (tay shuda):** Edge ek **zinda development track** hai jo **production-disabled** hai
aur **physical/admin-lab certification ka muntazir** hai. "dormant", "abandoned" ya "finished" —
teenon ghalat hain. Prod `cloud` hi rehta hai (`APP_ROLE` aur `EDGE_FEATURE_ENABLED` ghair-maujood).

| | |
|---|---|
| `EDGE_HEAD` | **`ecf4694`** |
| P5B | mukammal |
| `READY_FOR_WAN_UNPLUG_PILOT` | **no** (ADMIN_LAB_BLOCKED) |
| P6 | **shuru nahi hua** |
| kabhi production par deploy hua? | **nahi** |

---

## 6. Live tenants

| tenant | host | halat |
|---|---|---|
| Khatri Biryani | khatribiryani | live |
| Kashif Food | kashiffood | live |
| Kashif Kitchen | kashifkitchen | live (catering) |
| Tawakal + The Kashif Foods | thekashiffoods **+** tawakkalbiryani | live, **do-branch** |

19 Sep ko chaaron par `tb_diff = 0.00` aur print backlog 0 (Kashif Food par 3 jobs zinda trading
ke, backlog nahi).

---

## 7. Khule kaam

**Chhote / faisle ka intezaar**
- **"Add Round" button** — table attached hone par ye kuch karta hi nahi (sirf search box par
  cursor + ek toast). Prod data: 7,343 sessions me se sirf **4** par ek se zyada order — aur wo 4
  bhi normal raaste se bane, is button se nahi. Chhupane ka mashwara diya, jawab ka intezaar.
- **Cherry Crunch wali khuli bill** `HS-20260917074017-141` — purane 280 par lagi hai
- **Pulao ke 6 bache items** (Sada/Chana/Extras) — koi apni sub-category chahiye?
- **Card Bill Preview** ab live hai; **combined thermal table bill** (naya `document_type =
  'table_bill'`) alag sprint hai
- Growth % par nishan lagana jab pichle daur me kam trading din hon (Khatri par +4,912% dikha tha)

**Malik ke faisle ka intezaar**
- **`kashiffood` KOT routing** — Singaporean Rice, Chicken Biryani, Raita, Beverages, Dessert,
  Singaporean Sauce ka koi kitchen printer nahi; un ke KOT counter par jate hain (ek din 466 me se
  302 parchiyan). 32 duplicate category→printer mappings bhi.
- **Foodpanda rates** The Kashif Foods (cat18/19) — base price barhne se faasla kam ho gaya
- **Kashif Kitchen ke supplier openings** — do alag cheezein hain, unhein mila kar mat parhna:
  - **237** = system me maujood supplier records (sirf master data: naam, kuch phone; NTN/tax/terms
    khali). Ye zinda hain.
  - **0** = un me se kitne ke paas system me koi opening balance hai. **19 Sep par read-only
    tasdeeq:** `suppliers.opening_balance` aur `.current_balance` **saare 237 par sifar**, aur
    `opening_balance_lines` / `opening_balance_batches` / `supplier_ledgers` **teenon khali**.
  - **Σ6.49M cr / Σ4.49M dr** = ye aankre **sirf source workbook me hain**, system me aaye hi
    nahi — jaan-boojh kar rokke gaye. **Post NAHI karna jab tak maalik na kahe.**

  ⚠️ Mere apne purane do notes workbook ki **rows** ki ginti par ikhtilaf karte hain (7 banaam 14).
  Wo source-document ka sawaal hai, ghair-tasdeeq shuda, aur **system par is ka koi asar nahi** —
  system me in me se kuch bhi maujood nahi.
- **Kashif Kitchen** reports/analytics 403 — us ke `kashif-catering` plan me `reports` module nahi
  (commercial faisla)

**Qarz (technical debt)**
- `stash@{0}` REPORT-SHIFT-BREAKUP-1 P1 — **30 Aug se bina commit ke pada hai**
- Supplier payment screen ka warning banner ab bhi ledger-only ka wada karta hai
- `tawakal_restaurant` plan me `purchasing` module nahi
- Khatri par `cashier@demo.com`
- **MEMORY.md apni hadd se bara hai** (26.8KB vs 24.4KB) — index ki lines chhoti karni hain
- `39.53.215.233` par purana print agent

---

## 8. Do sabaq jo is mahine mehnge pare

**(a) Test DB ka takraao.** 18 Sep ko analytics ka suite bekaar me RED hua — deadlock, "users
already exists", FK errors. Sabab mera code nahi tha: **catering worktree ka process usi
`pos_test_tenant` par chal raha tha** aur dono ek doosre ki tables gira rahe the. Hal: apne run ko
alag DB dena (`DB_DATABASE` + `EDGE_TEST_TENANT_DB` + `EDGE_TEST_LOCAL_DB` — **teenon**), doosre ka
process **kabhi na maarna**.

**(b) Ek cheez badlo to uske SAARE rishtedaar usi pass me badlo.** DASHBOARD-8DAY-1 me unwaan
"Last 7 Days" se "Last 8 Days" hua — aur `DashboardDetailsScopeMySqlTest` ke 3 assertions toot
gaye kyunki wahan wo lafz hard-coded tha. Ab needle `'Days — Net Sales'` hai, ginti se azaad —
kyunki us test ka maqsad **permission gate** hai, window ka size nahi.
