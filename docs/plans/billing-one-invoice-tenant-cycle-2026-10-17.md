# Aik invoice, har tenant ke apne cycle par

**Tareekh:** 2026-10-17 · **Haalat:** manzoori ka muntazir — **abhi tak koi code nahi badla**

---

## 1. Malik ne kya kaha (mere alfaaz me, taake ghalti pakRi ja sake)

> "1 se 31 ka concept nahi hai. **15 se 15** ka hai. Kisi tenant ka **1 to 1** hai, kisi ka **20 to
> 20**. Jis waqt invoice banti hai us waqt tak ka WhatsApp ka amount **lock** ho jaye aur usi aik
> invoice me **breakup** ke saath aaye — SaaS fees aur WhatsApp fees. Us ke baad running bill
> **sifar se** shuru. Alag WhatsApp invoice **mat** banao, magar running bill dikhate raho. Aur ye
> **har us tenant par** jahan WhatsApp chal raha hai."

Yani:

| | abhi | chahiye |
|---|---|---|
| Muddat | calendar mahina (1–31) | **tenant ka apna din se agle din tak** |
| Invoice | DO — plan ki alag, WhatsApp ki alag (`addon`) | **AIK**, jis ke andar lines |
| Dashboard | aik jama kiya hua khana | **DO** — "Billing" (dena hai) + alag **running** WhatsApp |
| Running counter | calendar mahina khatam hone par | **invoice ke din** sifar |

Misal (khatri, din **15**):

```
15 Nov ko aik invoice:
  Plan fee                                     25,000.00
  WhatsApp messages   (15 Oct – 14 Nov)           886.50
  Website orders 1%   (15 Oct – 14 Nov)             0.00
  ──────────────────────────────────────────────────────
  Total                                        25,886.50
```

Us lamhe wo usage **lock** — invoice se bandh gayi, dobara nahi hilegi. 15 Nov se dashboard ka
running khana **sifar** se ginna shuru.

### ⚠️ Aik farz jo malik tasdeeq karein
Invoice **15 ko subah 6 baje** banti hai, to us me usage **14 tak** ki jayegi — 15 ka din abhi poora
hua hi nahi. Yani 15 wale din ki usage **agle** invoice me jayegi. Agar malik "15 samet" chahte hain
to invoice raat ko banani hogi, subah nahi.

---

## 2. Aaj ka dhaancha (prod se parha, 17 Oct)

```
billing:generate-monthly-invoices   → `subscription` invoice, sirf plan fee, tenant ke invoice_day par
billing:whatsapp-invoice            → `addon` invoice, CALENDAR mahine par, + messages ko invoice_id
billing:website-commission          → USI addon invoice me website ka 1% (alag lines)
UsageInvoiceService                 → addon invoice dhoondta/kholta hai aur lines likhta hai
billing:usage-lines-backfill        → purani invoices ko din-wise lines deta hai
```

**Calendar mahina `UsageInvoiceService` me KAI jagah pakka hua hai:**

```php
findInvoice($tenantId, $monthStart)        // period_start = mahine ka pehla din
openInvoice(...)   → period_end = $monthStart->endOfMonth()
monthIsClosed($tenantId, $monthStart)
websiteChargesFor($tenant, $from, $to)     // commands mahine ke hisaab se loop karti hain
```

Is liye "mahina" se "tenant ka cycle" karna **aik jagah ki tabdeeli nahi** — service, dono feeder
commands, mahana invoice wali command, dashboard, aur backfill sab aik hi paas me.

### 🎁 Waqt ki aik aasani
Malik ne aaj hi **kashiffood ka website ordering band** kar diya (plan_modules pm#194 → `is_enabled=0`;
`/order` ab 404). Ginti pehle ki thi: **sirf 2 orders, dono cancelled**, 15 Oct ke test, aur
**commission ki aik bhi line nahi, koi paisa nahi**.

Matlab website ka 1% is waqt **zinda paisa nahi** hai. Ye poora dhaancha badalne ka sab se mehfooz
lamha hai — baad me website wapis on hogi to wo seedha naye cycle par aayegi.

---

## 3. Jo banega

### 3.1 Cycle ka hisaab — AIK jagah
Aik chhota helper: tenant ke `invoice_day` se cycle nikale.

```
cycleFor($invoiceDay, $onDate) → [from, to]
   15 Nov par  → from = 15 Oct, to = 14 Nov
   1  Nov par  → from = 1  Oct, to = 31 Oct
   20 Nov par  → from = 20 Oct, to = 19 Nov
```

31 wala din chhote mahinon me aakhri din par chala jaye (February), warna us tenant ka cycle us mahine
banta hi nahi. **Ye hisaab sirf yahan rahega** — abhi jaisa chaar jagah nahi, warna wo kisi din aapas
me alag ho jayenge aur kisi ko pata nahi chalega.

### 3.2 Aik invoice, lines ke saath
`billing:generate-monthly-invoices` ab poora kaam karega:

1. `subscription` invoice banaye (`addon` ab naye kaam ke liye nahi)
2. line: **plan fee** (`line_type = plan`)
3. line(s): us cycle ki **WhatsApp usage** — din-wise, `line_type = whatsapp_messages`
4. line(s): us cycle ka **website 1%** — `line_type = website_commission`
5. un usage rows par `invoice_id` likhe → **lock**
6. `total = SUM(lines)`

`billing:whatsapp-invoice` ab **alag invoice nahi** banayegi. Us ka kaam reh jayega: rozana usage ko
chalti hui ginti me rakhna (yani kuch bhi invoice se na bandhna jab tak cycle ka din na aaye).

### 3.3 Dashboard — do khanay wapis
- **Billing** — jo dena hai (issued/overdue). Click par wohi modal jo abhi bana hai, lines ke saath.
- **WhatsApp running** — alag khana: is cycle me ab tak kitna bana, aur **kis tareekh ko** bill banega.
  `invoice_id IS NULL` wali rows se, yani cycle ke din apne aap sifar.

### 3.4 Har tenant par
Kisi tenant ka naam code me nahi aayega. Shart yehi: **jis ke paas `invoice_day` hai aur jis ki
usage rows hain** — us ka invoice khud banega. Aaj khatri (15) aur kashiffood (25); kal koi WhatsApp
on kare to wo khud shaamil.

---

## 4. Kya toot sakta hai (aur us ka ilaj)

1. **Purani `addon` invoices.** khatri aur kashiffood par October ki draft addon invoices maujood hain.
   Naya dhaancha unhe nahi samjhega. **Faisla darkar** — section 6, sawal 2.
2. **Website ka 1%** doosre session ka live kaam tha. Aaj band hai, to abhi mehfooz — magar jab wapis
   on ho, us ka raasta naye cycle se guzarna chahiye. Us session ko bata dena hoga.
3. **Do baar bill.** Agar cycle ka hisaab aur `invoice_id` ka lock aik hi transaction me na hon to
   aik hi usage do invoices me ja sakti hai. Guard zaroori: aik row kabhi do invoice par na lage.
4. **Cycle ka kinara.** 14 aur 15 ki seema par koi din na chhoote aur na do dafa gine — guard me dono
   kinare pin karne hain.
5. **`current_period_ends_at` ko haath NAHI lagana.** 9 Oct ko wohi hilne se charon live tenants band
   ho gaye thay. Invoice banana subscription ka period nahi hilata (ye shart ab code me maujood hai).

---

## 5. Tarteeb

1. `cycleFor()` helper + us ke apne guards (31 wala din, February, kinare).
2. `UsageInvoiceService` ko cycle par laana (mahina hata kar).
3. `billing:generate-monthly-invoices` me plan + usage lines, aik transaction me, lock ke saath.
4. `billing:whatsapp-invoice` se addon invoice banana hatana.
5. Dashboard: do khanay.
6. Purani addon invoices ka faisla lagoo karna.

Har qadam dry-run pehle, phir `--yes`.

---

## 6. Malik ke jawab ka intezar

1. **15 ki usage kis invoice me?** Mera farz: 15 ko banne wale invoice me **14 tak** ki. Agar "15
   samet" chahiye to invoice raat ko banegi, subah nahi.
2. **October ki jo draft addon invoices pehle se hain** (khatri `INV-20261009-0281` PKR 403.85,
   kashiffood `INV-20261009-2063` PKR 522.05) — un ka kya karein?
   - (a) **void** kar ke wo usage agle cycle ke invoice me daal dein, ya
   - (b) jaisi hain waisi rehne dein aur naya dhaancha unhe chhoRe.
   Mera mashwara **(a)** — warna aik hi mahine ke do tareeqe chalte rahenge.
3. **Pehla cycle kahan se?** Usage ka register **2 October** se hai. Khatri ka pehla naya invoice
   15 Nov ko banega — us me **15 Oct se** ki usage jayegi. To **2–14 October** wali usage kisi cycle
   me nahi aati. Use (a) October ki draft invoice me rehne dein, ya (b) pehle naye invoice me
   shaamil kar lein?

---

## 7. Jo is plan ko likhte waqt KIYA gaya

- kashiffood ka website ordering **band** (`plan_modules` pm#194 → `is_enabled = 0`). Pehle ginti ki:
  2 orders, dono cancelled, commission ki koi line nahi. `/order` ab 404 — asal raaste se tasdeeq.
- Baqi sab par koi tabdeeli nahi: POS, dashboard, charon tenants chal rahe hain.
