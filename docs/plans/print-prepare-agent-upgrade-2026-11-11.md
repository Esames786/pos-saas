# PRINT-PREPARE-1 + AGENT-UPGRADE-1 — research (11 Nov 2026)

> Status: **research / plan only.** Koi code nahi likha gaya. Har faisla neeche "Owner se sawaal" me hai.

## 1. Maqsad — owner ke alfaaz

- "Client keh raha hai bohot dair — aakhri quotation me 22 second lage."
- "User sirf ye screen khole, abhi print na bheje, to agent saara kaam kar le — HTML se PDF bana kar rakh le.
  User ko pehle 'Preparing your print' dikhao. Jaise hi user Send to network kare, foran print ho."
- "Ye saare screen pe hoga na — proforma, kitchen, quotation, invoice — sab pe English, Urdu, Both."
- "Data kisi PC pe jama na ho — purani PDF / HTML auto delete hon."
- "Agent naya banega? Pichli dafa thermal wala auto-install / background kharab ho gaya tha — wo na ho."
- "Install par purana manually uninstall karwate the, phir naya. Ye sab server ke setup se ho; script de do;
  admin / super admin permission ka masla na aaye; process poora aasaan ho."

## 2. Aaj ka haal — saboot ke saath

### 2.1 22 second kahan jaate hain (prod, read-only, quotation #86, printer #4 P2055dn)

| Hissa | Waqt | Kis ka |
|---|---|---|
| Job bani → agent ne uthai (`claimed_at`) | +2 s | agent har **3 s** poll karta hai |
| Agent: HTML → Chrome → PDF → SumatraPDF → Windows (`printed_at`) | +9 s | agent |
| Windows spooler / WSD → printer jaagna → kaghaz | ~13 s | printer (server ko nazar nahi aata) |

- Aam din: claim→printed 3–7 s. Khaali printer ke baad pehla parcha bohot dheema (#75 **+27 s** 19 min khaali rehne ke
  baad; #74 +13 s) → printer ki neend. Owner: "printer ko jaga kar rakh doon ga" — wo hissa owner ka.
- Agent ka "keep awake" sirf `ip:9100` par khaali TCP dastak hai — network jagta hai, fuser nahi.

### 2.2 Agent har parche par kya karta hai (`tools/print-agent/print-agent.js`)

1. `%TEMP%\bingoo-<job>.html` likhta hai.
2. **Har dafa naya, chhupa Chrome** kholta hai (`--headless`, naya khaali profile `bingoo-chrome-<job>`), `--print-to-pdf`.
3. SumatraPDF PDF ko Windows printer ko deta hai (`-print-settings paper=A5|A4,portrait,noscale`).
4. `finally` me HTML, PDF aur Chrome profile **foran delete** — kamyabi ho ya nakami. Kuch jama nahi hota.
   Kami: bijli chali jaye to beech ki files reh sakti hain; un ko saaf karne wala koi sweep nahi.

**PDF kyun:** printer HTML nahi samajhta; Urdu Nastaliq sirf Chrome sahi banata hai; chhupa Chrome seedha printer ko
nahi bhej sakta, sirf PDF save karta hai. Server ka PDF renderer Urdu huroof jor nahi sakta (quotation ka PDF button isi
liye English-only hai).

### 2.3 Installer 2.6.6 (`installer/windows/BingooPrintAgent.iss`)

- `PrivilegesRequired=admin` → **har install par UAC / admin password**.
- Custom page **har dafa pairing code maangta hai** (khaali ho to aage nahi barhta) → update bhi asal me **nayi pairing**
  = naya agent record, purane ka token bekaar. Yehi wajah hai ke "pehle purana uninstall, phir naya" karwana parta tha.
- Chalte hue purane agent ko **band nahi karta**; exe "in use" ho sakta hai.
- Auto-start: `install-autostart-user.ps1` → task `BingooPrintAgent`, console par baithe user ke naam par,
  `RunLevel Limited`, `wscript run-hidden.vbs` (koi window nahi), single-instance `agent.lock`; `Start-ScheduledTask`
  aur `Get-Process` se tasdeeq. Purana `install-service.ps1` (SYSTEM + Highest) wohi task naam istemal karta tha.
- Uninstall: `uninstall-service.ps1` task hatata hai.
- Download: `PrintAgentController` shelf `dist/releases/BingooPrintAgent-Setup-<ver>.exe` se, version hash se sabit.

### 2.4 Server ko version nahi pata

Agent `agent_version` **sirf pairing par** bhejta hai (`PrintAgentApiController::pair`), heartbeat me nahi, aur
`print_agents` me us ka khaana nahi. Isi liye "kis PC par kaun sa agent" server se nazar nahi aata.

### 2.5 Pichli dafa "thermal ka background kharab" — jo saboot mila

- 2.6.2 (9 Oct) me install ka tareeqa **SYSTEM task (boot par, login se pehle, poori tarah background)** se badal kar
  **user ke logon task** kiya gaya — kyunke SYSTEM/elevated Chrome PDF nahi banata. Thermal ko is ki zaroorat nahi thi.
  Asar: agent ab **user ke login ke baad** hi chalta hai; aur 2.6.6 (16 Oct) se pehle ek **kaali console window** khulti
  thi jise staff band kar deta tha — saath agent bhi mar jata tha.
- Kashif Food: 31 Oct se ek **doosra agent har ~3 s par 401** le raha hai (open items) — purana token / purani pairing ka
  bacha hua agent, jo "naya install = nayi pairing" ka natija lagta hai.
- ⚠️ Owner se tasdeeq chahiye ke "kharab" se bilkul kya hua tha (kaun sa tenant, kya nazar aaya) — taake test **usi**
  soorat ka bane.

## 3. Hissa A — PRINT-PREPARE-1: screen khulte hi tayyari

### 3.1 Kahan kahan

Har document preview jahan "Send to network" hai — **kitchen sheet, quotation, proforma, final invoice**, single aur
bulk, booking ke andar ya bahar — aur **teeno zubaanen (en / ur / both)**. Zubaan fingerprint ka hissa hai: safhe par
zubaan badli to us zubaan ki nayi tayyari; ghalat zubaan ki PDF kabhi nahi chhapti.

### 3.2 Silsila

1. Safha khulta hai → background me `prepare` (wohi `kind` / `ids` / `lang` jo Send bhejta hai; invoice ke liye us ka
   apna raasta). Server document ka HTML wohi service bana kar deti hai jo Send banati hai (CATERING-SEND-LANGUAGE-1).
2. Server ek **"sirf tayyar karo"** job banata hai (`print_jobs`, alag status/qisam), **fingerprint** = sha256(HTML jis me
   se "chhapne ka waqt" wali satar nikali gayi + kaghaz ka size). Wohi safha dobara khula → wohi fingerprint → koi nayi job
   nahi (idempotent).
3. Agent (naya version) ye job **sirf khaali waqt me** uthata hai — asli parche hamesha pehle. PDF bana kar
   `C:\ProgramData\BingooPrintAgent\ready\<fingerprint>.pdf` me rakhta hai, server ko "ready" batata hai. **Chhapta nahi.**
4. Safha: "⏳ Print tayyar ho raha hai…" → "✓ Tayyar". **Send kabhi band nahi** — tayyar na ho to purana raasta.
5. Send: server parcha dobara banata hai, fingerprint job me jata hai. Agent ko `ready\` me wohi PDF mile → Chrome chhor
   kar seedha SumatraPDF. Na mile → purana raasta. Booking beech me badli → fingerprint alag → **purani PDF kabhi nahi**.

### 3.3 Safai (owner ki shart)

- Chhapte hi PDF delete.
- 10 minute me istemal na ho → delete (owner: "30 min bohot"). Sweep: agent start par + har 5 minute.
- Had: 50 files (purani pehle). Ek PDF ~100–300 KB.
- `%TEMP%` me bachi `bingoo-*` files (bijli jane wali) bhi 1 din baad saaf.
- Files ka hisaab: graahak ka naam/phone hota hai — sirf usi PC par, kuch minute.

### 3.4 Hifazat

- **Master switch**, sirf Kashif Kitchen ON (owner rule: live tenants OFF).
- Tayyari ki job sirf us agent ko jo `caps` me `prepare` kahe — purana agent kabhi nahi dekhta, waisa hi chalta hai.
- Thermal (KOT/receipt) ka raasta **chhua nahi jata**.
- Chhota farq: parche par "chhapne ka waqt" tayyari ka hoga (zyada se zyada 10 min purana).

### 3.5 Kitna farq (andaza, naapa nahi)

Agent wala 9 s → ~2–3 s. Printer jaaga ho to bhi page nikalne me apne kuch second. Total andaza **22 s → ~8–12 s**.
Saath poll 3 s → 1 s.

## 4. Hissa B — AGENT-UPGRADE-1: bina uninstall, bina nayi pairing, bina admin jhanjhat

### 4.1 Naya version 2.7.0 — upgrade "in place"

- **Pairing bachi rahe:** installer `config.json` mojood ho (token wala) to pairing page **chhor de** → wohi agent
  record, wohi token. Na naya record, na 401 wala bhoot. Re-pair sirf tab jab config na ho (ya jaan-boojh kar "Re-pair").
- **Purana khud band:** copy se pehle `Stop-ScheduledTask BingooPrintAgent` + `BingooPrintAgent.exe` process band;
  copy ke baad task dobara register (console user, Limited, hidden) aur start + `Get-Process` se tasdeeq — 2.6.6 wala
  hi silsila.
- Purana SYSTEM/Highest task (agar kahin reh gaya) usi naam se hai → naya register usay **badal** deta hai.
- **Ek-line script** (AnyDesk ke liye): `BingooPrintAgent-Setup-2.7.0.exe /VERYSILENT /SUPPRESSMSGBOXES /NORESTART`
  ek `.cmd` me, server se hi download. Koi sawaal nahi, koi pairing nahi.
- Heartbeat me `agent_version` → Printing › Print Agents par har PC ka version nazar aaye (naya khaana
  `print_agents.agent_version` — **schema pehle deploy, phir code**).

### 4.2 Admin / UAC — do raaste (owner chune)

| | B1: Machine-wide (aaj jaisa) | B2: Per-user (naya) |
|---|---|---|
| Kahan | `C:\Program Files\BingooPrintAgent` | `%LOCALAPPDATA%\Programs\BingooPrintAgent` |
| UAC / admin | **har install par ek dafa** | **kabhi nahi** |
| Auto-start | task, console user, Limited | wohi task apne user ke liye (admin nahi chahiye) |
| Agla update | PC par ja kar (ya AnyDesk) | **server se button** mumkin (4.3) |
| Kami | admin password chahiye | sirf usi Windows user ke liye; purana machine-wide install ek dafa admin se hatana hoga |

Mashwara: **B2**, ek dafa ki migration ke saath (purana hatane ki script — wohi ek dafa admin).

### 4.3 Baad ka qadam (Phase 2): server se update

Agent pehle se server ke commands leta hai (`ping`, `reboot`). Naya command `update`: agent server se setup download kare,
hash milaye, silent per-user install chalaye, khud restart. Sirf B2 par mumkin (admin nahi). Pehle KK par, phir baqi.

## 5. Thermal ki hifazat (owner ki sab se bari shart)

- Thermal code path me koi tabdeeli nahi; naya kaam sirf document jobs par, `caps` ke peeche.
- Mojooda pehre qayam: `autostart-guard-test.js` (15), `elevation-guard-test.js`, `exe-thermal-test.js`,
  `lane-lock-test.js`, `version-match-test.js`.
- Naye test: (a) 2.6.6 ke upar 2.7.0 — wohi task, wohi user, Limited, hidden, ek copy, config bachi; (b) silent install
  par koi pairing page nahi; (c) prepare job thermal ke beech kabhi nahi aati (asli job pehle); (d) PC restart +
  logoff/logon ke baad KOT chhapti hai (asli Windows PC, `FakePrinter.exe`).
- **Rollout:** shelf par 2.6.6 rehta hai. 2.7.0 pehle sirf KK ke PC par; "default download" 2.6.6 hi jab tak KK par
  sabit na ho. Wapsi: 2.6.6 ka setup upar chala do (config bachi rehti hai).

## 6. Impact (system map)

- **Tables:** `print_jobs` (nayi qisam/status "prepare/ready", fingerprint `payload` me — migration ki zaroorat shayad
  nahi); `print_agents` (+ `agent_version`, migration); `catering_settings` / master switch (tenant feature).
- **Routes / screens:** `tenant.catering.documents.bulk-print` (prepare mode) aur `…final-invoice-print`; agent API
  (`pending` / heartbeat / naya "ready" report); Printing › Print Agents (version khaana). Preview safhe: kitchen sheet
  (release + preview), quotation, proforma, final invoice, bulk kitchen sheets, bulk quotations.
- **Tests:** naye MySQL (prepare idempotent, fingerprint, priority, switch OFF = koi prepare nahi, purana agent ko kabhi
  nahi) + agent JS tests (upar) + asli PC par end-to-end.

## 7. Marhale

1. Owner: printer ki neend (Sleep Delay 60–120 min) + stopwatch test — **bina code**.
2. AGENT-UPGRADE-1 (B1 ya B2) + heartbeat version — KK PC par 2.7.0 + poll 1 s.
3. PRINT-PREPARE-1 server + agent — Master switch, KK ON.
4. (Baad me) server se `update` command.

## 8. Owner se sawaal — aur jawab (11 Nov)

1. B1 (har install par ek dafa admin) ya **B2** (admin kabhi nahi, ek dafa migration)? → **Abhi khula.** Owner ne aasaan
   alfaaz me samajh maangi; mashwara B2.
2. Pichli dafa thermal me kya kharab hua? → **Owner:** "thermal install ke baad tum script se haath se agent on karwa rahe
   the, phir agent exe ka poora setup theek kiya" — yani install ke baad agent **khud chalu nahi hota tha** (2.6.6 me theek).
   → Lazmi test: install / upgrade ke baad **bina kisi script** agent khud chale; restart + logoff/logon ke baad bhi;
   2.6.6 aur us se purane version ke upar upgrade; installer khud "agent chal raha hai" bataye ya wajah likhe.
3. Tayyar PDF kitni der? → **Owner: 30 min bohot** (proposal 15 tha). Naya proposal **10 min** (chhapte hi foran delete;
   10 min me istemal na ho to delete; 10 min ke baad Send purane raaste se). Owner chahe to 5.
4. Pehle sirf Kashif Kitchen, phir baqi? → **Owner: haan.**
