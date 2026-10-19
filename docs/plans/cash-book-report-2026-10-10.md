# CASH-BOOK-REPORT-1 — Cash Book report: kitna aaya, kitna gaya, kis cash/bank me, tareekh-war

Date: 2026-10-10 · Maang: Kashif Kitchen (Bilal) ke zariye client — "cashbook report view, taake sara cashbook feed kar sakein".

## 1. Kya ye report pehle se hai? — NAHI (poori shakl me)

| Jagah | Kya deta hai | Kyun kaafi nahi |
|---|---|---|
| Finance → Cash & Bank Accounts (`/finance/cash-bank-accounts`) | Accounts ki list + abhi ka balance | Koi entry nahi, koi tareekh nahi — sirf list |
| Reports → Sales Report Center → section "Cash & Bank Movement" (`cash_bank`) | Muddat ka **jod**: cash in/out, bank in/out, qism-war | Account-war (kaun sa bank/drawer) nahi; entries nahi; running balance nahi. Aur ye **Reports module** ka safha hai — Kashif Kitchen ke plan me `reports` hai hi nahi (403) |
| Finance → General Ledger, account chun kar | Ek GL account ki tareekh-war Dr/Cr + running balance + totals + CSV | Accounting ki zaban (Dr/Cr); ek waqt me ek account; do drawer ek GL account par hon to (Kashif Kitchen: KK-CASH + Main Cash = 1110) alag nahi hote |

Nateeja: **"Cash Book" report banani hogi.**

## 2. Report kya dikhayegi

**Filters (upar ek qatar):** Date from / to (default: is mahine ki 1 se aaj) · Account(s) — multi-select, har cash drawer / bank / wallet alag (default: sab) · Branch · Qism (Sab / Aaya / Gaya) · Mode: **Har entry** | **Din-war khulasa**.

**Khulasa (cards):** Opening balance · Kul aaya (In) · Kul gaya (Out) · Closing balance — chune hue sab accounts ka, aur neeche har account ki ek satar (Opening / In / Out / Closing).

**Har entry (mode 1)** — har account ka alag hissa, Opening se shuru:

| Tareekh | Voucher / Ref | Tafseel (party / event / kharche ki qism) | Qism | Aaya | Gaya | Balance |
|---|---|---|---|---|---|---|

Ref par click → asal document (expense voucher, supplier payment, catering receipt/event, customer payment, sale, manual journal). Har hisse ke neeche: kul Aaya, kul Gaya, Closing.

**Din-war khulasa (mode 2)** — restaurants ke liye zaroori (Khatri ~338, Kashif Food ~388 entries roz): har din ki ek satar — Opening · Aaya · Gaya · Closing; din par click → us din ki entries.

**Export:** CSV (jo filter laga ho wahi) + Print/PDF (Trial Balance jaisa, "Page X of Y").

## 3. Data — kahan se aur kaise

- Source: `cash_bank_account_transactions` (har cash drawer/bank ki apni harkat: `transaction_date`, `direction in|out`, `amount`, `transaction_type`, `reference_type/id`, `notes`). Indexes `cash_bank_account_id`, `transaction_date` maujood.
- **Opening = account ka `opening_balance` + date_from se pehle ki saari entries ka jod.** Jama `current_balance` par bharosa NAHI (neeche 4.1).
- Qismon ke naam: `SalesReportEngine::MOVEMENT_LABELS` (ek hi jagah — sales receipts, expense payment, supplier payment, customer payment, refunds, void reversals, manual journal…).
- Party/tafseel: reference se — supplier ka naam, catering event no + customer, expense category, manual journal ka description.
- Restaurants me sale payments bahut hain: "Har entry" mode paginate (din-war tod kar); "Din-war khulasa" GROUP BY date ek query.

## 4. Kashif Kitchen ka data — prod par naapa (10 Oct, read-only)

Report har account ke liye ek patti dikhayegi: **entries se bana balance · jama balance · GL** — farq ho to laal.

1. **Main Cash Drawer (CASH-MAIN):** jama balance 3,340,270.60 magar entries ka jod 2,837,770.60 → **502,500 zyada.** Pehli bachi entry (#2, 9 Sep) se pehle hi balance 502,500 tha; table me 152 rows magar id 157 tak — 5 entries mitayi gayin, jama balance nahi. Go-live (17 Sep) se pehle ki test safai lagti hai. GL 1110 is 502,500 ko nahi maanta. → "Cash & Bank Accounts" list abhi Main Cash ko 502,500 zyada dikhati hai. **Durust karna = owner ki ijazat** (jama balance ko entries ke barabar).
2. **Undeposited Funds (1500):** GL 361,430 / cash-bank table −3,175. Card wale 3 catering advances (75,000) aur 1 catering settlement (289,605) GL me 1500 par gaye magar cash-bank table me entry nahi bani. → Report me Undeposited ki harkat adhoori hogi jab tak ye raasta theek na ho (alag kaam: catering settlement/card advance ka cash-bank entry likhna).
3. **KK-CASH vs GL 1110 — 8,240:** 3 manual journals cash-bank table me KK-CASH par, GL me kisi aur account par. → chhan-been alag.
4. **Petty Cash −496,765:** kharche/supplier payments petty cash se darj, magar petty cash me paisa aane ki entry (main cash/bank se) kabhi nahi. Report yahi dikhayegi — Bilal ko "Manual Journal: Dr 1120 Petty Cash / Cr 1110 (ya 1210)" se funding darj karni hai.
5. **KK-CASH aur Main Cash Drawer dono GL 1110 par** — Cash Book in ko alag dikhayegi (yahi GL se farq hai); agar ye ek hi drawer hain to milana owner ka faisla.

## 5. Access — sirf ye ek report

- Route `GET /reports/cash-book` → name `tenant.reports.cash-book.index` (+ CSV/print `format=csv|pdf` usi par).
- Module: `PermissionSyncService::MODULE_KEY_OVERRIDES['tenant.reports.cash-book.index'] = 'tenant.finance'` — Payables jaisa (PAYABLES-FINANCE-GATE-1): jis plan me `finance` ho us me khule; Kashif Kitchen ke paas finance hai, reports nahi.
- Menu: Reports menu me "Cash Book" (jab reports module ho) + Finance menu me "Cash Book" (jab reports na ho) — `$hasModule` + `@can`.
- Ijazat: deploy.sh Owner ko khud deta hai. **Bilal ka role "Owner (No Amounts) + Reports"** — sirf ye ek permission, `givePermissionTo` (additive), phir `system:clear-tenant-permission-cache`. Baqi roles ko kuch nahi.

## 6. Kya NAHI badlega
Koi posting, koi balance, koi migration nahi — sirf parhne wali report. Section 4 ke data durusti alag kaam hain, har ek owner ki ijazat se.

## 7. Tests
- Opening = opening_balance + pehle ki entries (jama balance kharab ho tab bhi sahi); In/Out/Closing; account filter; qism filter; din-war khulasa = entries ka jod; CSV = screen.
- Reference links sahi document par; party ke naam.
- Plan me sirf `finance` (reports nahi) → khulti hai; `finance` nahi → 403; Bilal jaisa custom role sirf is permission se khol leta hai, baqi reports nahi.
- 3,000+ entries par din-war mode ek query; "Har entry" paginate.
- Asal controller render + page ke scripts `node --check`; sabotage se sabit ke test pakadte hain.
