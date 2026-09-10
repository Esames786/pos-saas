# Jo parchi ek ghante se atki rahe, wo khud band ho jaye

**Tareekh:** 2026-09-11
**Branch:** `feat/print-job-autoclose-v1` (worktree `pos-saas-hideamounts`), base `af6755d`
**Kis ne maanga:** owner — "make sure autoclose failed job if they stuck for too long for
more than 1 hour or more"

---

## 1. Kis waqia ne ye maangwaya

The Kashif Foods branch par parchi 12–17 second der se chhap rahi thi. Tehqeeq par nikla ke
**teen** Report Center ki parchiyan (`#1697` 09-Sep, `#2902`/`#2903` 10-Sep) galti se
**doosri branch ki printer** (Tawakkal, `192.168.100.240`) par bheji gayi thin, jabke un ka
branch Kashif Foods tha. Wo printer agli gali ke doosre network par hai — wahan se pahunch
hi nahi sakti.

Nateeja ek **na-khatam hone wala chakkar**:

```
agent poll  →  teen report jobs milte hain  →  192.168.100.240 par connect
   →  pahunch nahi  →  ~16 second atka  →  30 second ka defer  →  phir wohi
```

Har ~45 second par 16 second ka wuqfa. Subah 7 baje se raat tak, **har ghante 79–82 bar**.
Aur usi 16 second mein jo receipt ya KOT banti, wo intezar karti. Ek namoona:

```
bana 18:49:11  →  claim 18:49:26 (15 sec)  →  chhapa 18:49:26 (usi second)
```

Chhapna **0.9 second** tha. Printer, server, queue — teenon theek. Sirf ek purani parchi
poore counter ko rok rahi thi.

Wo teen parchiyan haath se Dismiss kar di gayin. **Magar asal kami ye hai ke aisi parchi
hamesha ke liye atki reh sakti hai aur khud se kabhi band nahi hoti.**

## 2. Kyun ye pehle se nahi tha — aur wo faisla ghalat nahi tha

`deferForRetry()` ka apna docblock kehta hai:

> a printer being OFF is not the ticket's fault, so defer **NEVER bumps attempts and never
> gives up**

Ye jaan-boojh kar hai, aur **theek** hai: kitchen ki parchi is liye gum nahi honi chahiye ke
printer ek minute band tha. Isi liye `attempts` nahi barhta aur job zinda rehti hai.

Magar "kabhi haar na maano" ka matlab "hamesha" nahi hona chahiye. Ek ghante baad:

- KOT bemani ho chuki hai — khana ya ja chuka hai ya order cancel ho gaya
- receipt bemani ho chuki hai — grahak chala gaya
- aur wo zinda parchi **baqi sab ko rok rahi hoti hai**

Yani ye us faisle ka **ulta** nahi, uski **hadd** hai.

## 3. Kya banaya

Ek command: `printing:autoclose-stuck`

```
--tenant=<code>    sirf ek tenant
--minutes=60       hadd (default 60)
--dry-run          kuch band na karo, sirf batao kya band hota
```

Scheduler mein `everyFifteenMinutes()` par, usi **Cloud-only** block ke andar jahan baqi
scheduled commands hain (`EdgeRuntime::isCloudSafe()`) — branch appliance ko Cloud ke
scheduled kaam kabhi khud nahi chalane chahiye.

### Kis parchi ko band karta hai

Chaar shartein, sab lazmi:

1. `print_status` **`queued` ya `failed`** — yehi do `cancelObsolete()` qabool karta hai
2. `printed_at IS NULL` — jo chhap chuki, us par haath nahi
3. `created_at` **hadd se purana** (default 60 minute)
4. **abhi chhap na rahi ho** — `claimed_at` pichle **2 minute** ke andar ho to CHHORO

Chauthi shart sab se ahem hai. Agent ka claim lease theek 2 minute hai
(`PrintAgentApiController:163` — `claimed_at < now()->subMinutes(2)`). Agar us window ke
andar wali parchi band kar di jaye to hum us parchi ko cancel kar rahe honge jo **usi
lamhe printer se nikal rahi hai** — aur `cancelObsolete()` `printed` ko rok deta hai magar
"chhap rahi hai" ko nahi pehchan sakta. Isi liye ye faasla khud rakhna parta hai.

### Kaam usi ek authority se

Job `PrintJobService::cancelObsolete()` se band hoti hai — wohi jo screen ka **Dismiss**
button chalata hai. **Apna update nahi likha**, kyunke:

- `markPrinted()` **kabhi nahi** — wo jismani chhapai ki kamyabi ka maani rakhta hai
  (counters, `last_*_printed_at`, KOT bookkeeping). Us se ginti jhoot bol deti.
- `cancelObsolete()` `printed_at` ko NULL rakhta hai (kuch chhapa nahi), `claimed_at` saaf
  karta hai, aur `error_message` mein sabab likhta hai — yani Printing screen par wajah
  nazar aati hai aur banda `Retry` bhi kar sakta hai (`requeueFailed` cancelled→queued
  qabool karta hai).

### Sabab likha jata hai, chup-chaap nahi

Har band ki gayi parchi par likha jata hai ke wo kitni der atki rahi aur kis printer par
thi. Ye is liye ke **khamoshi se parchi gayab hona sab se bura nateeja hai** — screen par
`cancelled` aur uska sabab dikhna chahiye.

## 4. Jo NAHI kiya

- **Defer ka contract nahi badla.** `deferForRetry()` waisa hi hai (30 second, `attempts`
  nahi barhta). Printer 5 minute band ho to parchi ab bhi intezar karti hai aur chhap jaati
  hai. Ye command sirf **ek ghante** ki hadd lagati hai.
- **`printed` job ko chhooa bhi nahi.**
- Koi nayi table, koi migration — kuch nahi. Sirf ek command + ek scheduler satar.
- Koi naya route → koi nayi permission (`deploy.sh` ka Owner-grant wala jaal nahi lagta).

## 5. Wo cheez jo agla parhne wala na tore

**2 minute ka faasla `PrintAgentApiController` ke lease se bandha hua hai.** Agar kabhi wo
lease badle (2 minute se kuch aur), to yahan bhi badalna parega — warna ye command us
parchi ko cancel kar sakti hai jo usi waqt chhap rahi ho. Command mein wo baat comment
mein likhi hui hai, aur guard us par chalta hai.
