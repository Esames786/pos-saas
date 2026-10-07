# TRIAL-SIGNUP-QUEUE-1 — Free trial: form foran wapas, workspace queue me, email foran

Date: 2026-10-07 · Screen: bingoopos.com/start-trial → "Start Free Trial"

## Aaj kya hota hai (prod par naapa, 07 Oct 17:31 UTC, tenant #614 `mohsin`)

Ek hi HTTP request ke andar: master rows → `CREATE DATABASE` → 117+ migrations → saikdon permissions,
roles, CoA, payment methods → owner → **phir** SMTP par "workspace ready" email → redirect.
Nateeja: browser **70 second** ghoomta raha (17:31:45 → POST 302 17:33:00). Koi error nahi tha — bas lamba.

Khatre jo isi bartaao me chhupe hain:
- User beech me tab band kare / network toote → `ignore_user_abort` provision to poora karta hai, magar user
  ko kabhi pata nahi chalta ke bana ya nahi (success page tak pahunchta hi nahi).
- Email provisioning ke **baad** jaati hai — pehli email 70+ second baad.
- Email ka link `https://<naam>.bingoopos.com` hota hai, magar naya subdomain SSL cert me
  `bingoo-cert-sync` (root cron, **har 10 minute**) se judta hai → pehle 10 minute tak link "Not secure"
  dikhata hai. (#614: 17:33 par bana, cert me nahi tha.)

## Kya banega

### 1. Form foran wapas (~1 second)
`trialStore` sirf master rows banata hai (tenant `pending` + domain + trial subscription — wohi transaction
jo aaj hai), aur do kaam queue karta hai **is tarteeb me**:
1. **"Aap ka workspace ban raha hai" email** — pehle, taake ek hi worker par provisioning ke peeche na atke.
   Few seconds me inbox me.
2. **`ProvisionTrialWorkspaceJob`** — database, migrations, owner.

Password: controller **wahin hash** karta hai (bcrypt); queue payload (jo `jobs` table me DB me rehta hai)
me **sirf hash** jaata hai, plain password kabhi nahi. Provisioner ko naya optional flag `passwordIsHashed`
— baqi 6 callers (onboard commands, admin panel, demos) jaise ke taise.

### 2. Success page = live status
Session me `trial_tenant_id`. Page har 3 second `GET /trial/status` (sirf apne session ka tenant, throttle)
puchta hai:
- **preparing** — "Workspace ban raha hai… aam taur par 1–2 minute. Ye page band kar dein to bhi email aa jayegi."
- **securing** — DB tayyar, HTTPS abhi judna baqi — "Address ko secure kar rahe hain".
- **ready** — login button + URL.
- **failed** — saaf paighaam + dobara koshish ka link + support email.

### 3. Worker me job
- Prod par **ek** worker (`bingoo-queue.service`, `--queue=default`, koi `--timeout` nahi = 60s default).
  Provisioning 70s → job ka apna `$timeout = 900`, `$failOnTimeout = true`, `$tries = 1`
  (provisioning ke baad cleanup tenant hi mita deti hai — dobara chalane ko kuch nahi bachta).
- Worker ek hi process me chalta rehta hai: `deactivate()` default connection master par lata hai magar
  `database.connections.tenant` naye tenant ke DB par chhod deta hai. Job shuru me ye config yaad rakhta hai
  aur `finally` me wapas rakhta hai.
- Nakami (exception ya timeout): `failed()` → aadha bana DB drop + master rows delete (wohi
  `cleanupFailedSignup` jo aaj hai) → customer ko "nahi ban saka, dobara koshish karein" email → `report()`.
- Kamyabi: `SendTrialReadyMailJob` queue.

### 4. "Ready" email tab jab link waqai secure ho
`SendTrialReadyMailJob`: subdomain par TLS handshake (`verify_peer` + SNI). Cert me nahi → `release(30)`.
Zyada se zyada 15 minute intezar, phir email bhej deta hai (cert ki wajah se email kabhi gum na ho).
`http://` (local) par jaanch nahi. Email wohi purani `TrialWorkspaceCreatedMail`.

### 5. (Infra — alag manzoori) cert-sync har minute
`/usr/local/bin/bingoo-cert-sync` jab farq na ho to sirf ek tinker + openssl chalata hai (sasta). Root crontab
`*/10` → `* * * * *` + `flock -n` (overlap na ho). Is se "securing" 10 minute se ~1–2 minute. Code deploy ka
hissa NAHI — root crontab ki ek line; aap kahein to.

## Kya NAHI badlega
- Validation, throttle (`5/min`), coming-soon mode, plan ka chunao, trial din, tenant code ka usool.
- Provisioning ka andar ka kaam (migrations, seed) — ek line nahi.
- Admin panel / onboard commands / demo provisioning — wohi synchronous raasta.
- Queue ka global DB connection. (Alag masla, yahan nahi: kashifkitchen ke catering emails isliye queue nahi
  hote ke tenant request me default connection tenant hota hai aur `jobs` table tenant DB me dhoondi jaati hai.
  Wo theek karna live customers ko emails shuru kar dega — owner ka faisla.)

## Tests (signup ka aaj ek bhi test nahi)
- Store: tenant `pending`, DB **nahi** bana, ack email queued, job queued **ack ke baad**, payload me plain
  password nahi, hash verify hota hai.
- Asal provisioning job (asli DB, asli migrations, test naam): tenant active, owner login hash sahi,
  `tenant` connection config wapas, ready-mail job queued.
- Nakami: rows + DB gone, failure email.
- Ready mail: HTTPS na ho → release(30); ho → email; 15 min baad → email bhar bhi.
- Status: preparing / securing / ready / failed; doosre session ka tenant nahi dikhta.
- Jaan-boojh kar tod kar sabit karna ke har test pakadta hai.

## Deploy
Sirf aap ke kehne par. Migration nahi. `deploy.sh` worker ko `queue:restart` deta hai (naya code). Cron (5)
alag.
