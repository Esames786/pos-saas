# BINGOO Offline — Owner / Manager guide (Khatri Biryani)
# BINGOO Offline — Malik / Manager ki guide (Khatri Biryani)

Three desktop shortcuts / Desktop par teen shortcuts:

| shortcut | opens | use when |
|---|---|---|
| **BINGOO Online** | `https://khatribiryani.bingoopos.com/pos` | normal days — internet working / aam din, internet chal raha hai |
| **BINGOO Offline** | `https://bingoo-edge.local:8443/edge/local/pos` | ONLY after the owner/manager pressed **Switch to Offline** / SIRF jab malik ne **Switch to Offline** dabaya ho |
| **Branch Server Status** | `https://bingoo-edge.local:8443/edge/local/pos/health` | owner/manager control page / malik-manager ka control page |

The Branch Server never switches by itself. Automatic failover is OFF. A person presses the button.
Branch Server khud kabhi switch nahi hota. Automatic failover BAND hai. Button insaan dabata hai.

---

## Step 1 — Log in to the Branch Server / Branch Server par login karein

Open **Branch Server Status**. Enter your **employee code** and your **Edge PIN** (the PIN you chose at enrolment — NOT your
Cloud password).
**Branch Server Status** kholein. Apna **employee code** aur **Edge PIN** likhein (jo PIN enrolment par rakha tha — Cloud ka
password NAHI).

If the page says permission denied: your account is enrolled but does not hold the `tenant.offline-edge.security` permission.
The Owner role has it; a manager gets it from the Cloud Permission Center. Call the owner.
Agar "permission denied" aaye: aap ka account enrolled hai lekin `tenant.offline-edge.security` permission nahi hai. Malik ko call karein.

## Step 2 — Read the "Selling authority" box / "Selling authority" ka box parhein

At the top of the status page / status page ke sab se upar:

| line | what it means / matlab |
|---|---|
| **Internet / Cloud** `online` or `lost` | Cloud reachable or not / Cloud mil raha hai ya nahi |
| **Who sells** `Cloud` or `This Branch Server` | who is allowed to take sales right now / abhi sale kaun le sakta hai |
| pill **STANDBY READY** | Branch Server is ready and waiting; counters sell on BINGOO Online / tayyar hai, counters Online par bech rahe hain |
| pill **LOCAL ACTIVE** | Offline mode is on; counters sell on BINGOO Offline / Offline chal raha hai |
| pill **TRANSITION** | a switch is in progress — wait / switch ho raha hai — intezar |
| pill **DEGRADED** | something is wrong — read "problems", call the owner / kuch kharab hai — malik ko call |
| **Takeover gates** table | every row must say `pass` before Offline can start / har row `pass` ho to hi Offline shuru ho sakta hai |
| **Freshness** config / stock | the Branch Server's copy of menu, prices, stock is current / menu, rate, stock ki copy taza hai |
| **Sync** pending / in flight / permanently refused | sales still waiting to reach the Cloud / jo sales abhi Cloud tak nahi pohnchi |
| **Printers** | each printer reachable or not / har printer mil raha hai ya nahi |

## Step 3 — Press "Switch to Offline" / "Switch to Offline" dabayein

Only when BOTH are true / Sirf jab dono sach hon:
1. The internet is really down (BINGOO Online does not open on the phone either).
   Internet sach mein band hai (phone par bhi BINGOO Online nahi khulta).
2. The pill says **STANDBY READY** and every takeover gate says `pass`.
   Pill **STANDBY READY** hai aur har takeover gate `pass` hai.

Press **Switch to Offline** → the browser asks "Are you sure?" → **OK**.
**Switch to Offline** dabayein → browser poochhega "Are you sure?" → **OK**.

Result banner on the same page / isi page par banner:
- green: Offline mode started — pill becomes **LOCAL ACTIVE**. Go to Step 4.
  sabz: Offline shuru — pill **LOCAL ACTIVE**. Step 4 par jayein.
- red with a blocker text (for example `AUTHORITY_TAKEOVER_SAFE blocked` or `STANDBY_FRESH_ENOUGH blocked`): the Branch Server
  REFUSED. It is not broken; it is protecting the business. Do not press again and again. Wait 1 minute if the internet just dropped
  (the Cloud lease must expire first), re-read the gates, otherwise call the owner.
  laal, blocker ke saath: Branch Server ne INKAAR kiya. Kharab nahi hai, business ko bacha raha hai. Baar baar na dabayein. Agar
  internet abhi abhi gaya hai to 1 minute rukein (Cloud lease pehle khatam hoti hai), gates dobara parhein, warna malik ko call karein.

There is no "force" button. Nobody can skip a blocked gate from this page.
Koi "force" button nahi hai. Is page se koi blocked gate skip nahi ho sakta.

## Step 4 — Cashiers use BINGOO Offline / Cashier BINGOO Offline istemal karein

Tell every counter: close BINGOO Online, open **BINGOO Offline**, log in with employee code + Edge PIN, open the shift, sell.
The badge at the bottom of the POS reads **LOCAL MODE ACTIVE**. Receipts and KOTs print on the usual LAN printers.
Har counter ko batayein: BINGOO Online band, **BINGOO Offline** kholo, employee code + Edge PIN se login, shift kholo, becho.
POS ke neeche badge **LOCAL MODE ACTIVE** hoga. Receipt aur KOT aam LAN printers par niklenge.

Nothing moves cashiers automatically — they open the other shortcut by hand.
Cashier khud doosra shortcut kholta hai — koi automatic redirection nahi hai.

## Step 5 — Internet returns / Internet wapas aa gaya

Nothing changes by itself. Counters KEEP selling on BINGOO Offline until Step 7 is done.
Khud kuch nahi badalta. Jab tak Step 7 na ho, counters BINGOO Offline par hi bechte rahein.

## Step 6 — Check sync / Sync check karein

On **Branch Server Status**: **Sync** must show `pending 0`, `in flight 0`, `permanently refused 0`, and the cashiers must have
CLOSED their Offline shift. Internet / Cloud must say `online`.
**Branch Server Status** par **Sync** mein `pending 0`, `in flight 0`, `permanently refused 0` hona chahiye, aur cashiers ne apni
Offline shift BAND kar di ho. Internet / Cloud `online` ho.

If `permanently refused` is not 0: do NOT return to online. Send a photo of the page to the owner.
Agar `permanently refused` 0 nahi: Online wapas NA jayein. Page ki photo malik ko bhejein.

## Step 7 — Press "Return to Online" / "Return to Online" dabayein

Press **Return to Online** → "Are you sure?" → **OK**.
- green: pill back to **STANDBY READY**, "Who sells" = `Cloud`. Tell every counter: close BINGOO Offline, open **BINGOO Online**,
  open a NEW shift there.
  sabz: pill **STANDBY READY**, "Who sells" = `Cloud`. Har counter: BINGOO Offline band, **BINGOO Online** kholo, wahan NAYI shift kholo.
- red with blockers (for example `pending 3` or `Handback not acknowledged by the Cloud`): nothing was discarded; fix the named
  blocker (usually: wait for sync, or the internet is not really back), then press again. Still red → call the owner.
  laal, blockers ke saath: kuch zaya nahi hua; jo blocker likha hai usay theek karein (aksar: sync ka intezar, ya internet abhi
  sach mein wapas nahi), phir dobara dabayein. Phir bhi laal → malik ko call.

---

## Never / Kabhi nahi

- Never press Switch to Offline while BINGOO Online still works. / Jab BINGOO Online chal raha ho, Switch to Offline kabhi na dabayein.
- Never pull a cable or restart the router "to test". / Test ke liye kabhi cable ya router band na karein.
- Never return to online with sync not clean. / Sync saaf na ho to Online wapas na jayein.
- Never switch off the Branch Server PC during service. / Service ke dauran Branch Server PC kabhi band na karein.
- Never share your Edge PIN; never write it near the counter. / Edge PIN kisi ko na dein, counter ke paas na likhein.
- Never run any command on the Branch Server PC — the buttons are the only controls for staff. The PowerShell commands are the
  owner's recovery tools. / Branch Server PC par koi command na chalayein — staff ke liye sirf ye do button hain.

## Call the owner when / Malik ko call karein jab

- the pill is **DEGRADED** or **TRANSITION** for more than 2 minutes;
- a button answers red twice in a row;
- `permanently refused` is not 0;
- a printer shows not reachable during service;
- you are not sure. / shak ho to call karein.

## Daily 1-minute check (opening) / Roz subah 1 minute

Open **Branch Server Status** from any counter: pill **STANDBY READY**, "No problems reported", Internet / Cloud `online`,
all printers reachable. If not, photo to the owner, touch nothing.
