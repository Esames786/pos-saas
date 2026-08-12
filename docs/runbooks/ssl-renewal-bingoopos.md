# SSL Renewal — bingoopos.com

**⏰ HARD DEADLINE: renew by 2026-09-01.** The certificate expires **2026-09-17 11:23 UTC** and
`certbot renew` CANNOT do it unattended — the renewal profile is `authenticator = manual` with
dns-01 and no auth hook, so the nightly certbot timer silently skips it. If nobody acts, every
tenant site (including the live Khatri client) goes hard-down on browser trust on Sep 17.

## Grounded current state (verified 2026-08-12)

| Fact | Value |
|---|---|
| Certificate | `bingoopos.com` + `*.bingoopos.com` (RSA), Let's Encrypt |
| Expiry | 2026-09-17 11:23:20 UTC |
| Renewal profile | `/etc/letsencrypt/renewal/bingoopos.com.conf` — `authenticator = manual`, `pref_challs = dns-01` |
| DNS | Namecheap BasicDNS (`dns1/dns2.registrar-servers.com`) |
| Server | Hostinger `187.77.140.39`, nginx, cert at `/etc/letsencrypt/live/bingoopos.com/` |

## Option A — automate (preferred, do once, forget forever)

Uses acme.sh's Namecheap DNS hook. **Blocked on owner input:** a Namecheap API key with the
server's IP whitelisted (Namecheap → Profile → Tools → API Access; whitelist `187.77.140.39`).
Never commit or paste the key into the repo or chat — it goes only into root's environment on the
server.

```bash
# On the server, as root, AFTER exporting NAMECHEAP_USERNAME / NAMECHEAP_API_KEY /
# NAMECHEAP_SOURCEIP in the shell (not in any file under the repo):
curl https://get.acme.sh | sh -s email=admin@bingoopos.com
~/.acme.sh/acme.sh --issue --dns dns_namecheap -d bingoopos.com -d '*.bingoopos.com' \
  --server letsencrypt
~/.acme.sh/acme.sh --install-cert -d bingoopos.com \
  --fullchain-file /etc/letsencrypt/live/bingoopos.com/fullchain.pem \
  --key-file       /etc/letsencrypt/live/bingoopos.com/privkey.pem \
  --reloadcmd      "systemctl reload nginx"
```

acme.sh installs its own cron; verify with `crontab -l | grep acme`. After the first successful
automated issue, disable the stale certbot profile so two systems never fight over the same
files: `mv /etc/letsencrypt/renewal/bingoopos.com.conf{,.disabled}`.

## Option B — manual renewal (fallback; ~15 minutes; requires Namecheap dashboard login)

1. On the server: `certbot certonly --manual --preferred-challenges dns -d bingoopos.com -d '*.bingoopos.com'`
2. Certbot prints one or two `_acme-challenge.bingoopos.com` TXT values. In Namecheap →
   Domain List → bingoopos.com → Advanced DNS, add each as a TXT record, host `_acme-challenge`,
   TTL 1 min. **Wait until** `dig +short TXT _acme-challenge.bingoopos.com @dns1.registrar-servers.com`
   returns the value(s) — usually 2–5 minutes — before pressing Enter in certbot.
3. Certbot writes the new cert into the same `live/` paths; nginx needs only `systemctl reload nginx`.
4. Delete the TXT records afterwards.

## Post-renewal verification (either option)

```bash
certbot certificates | grep -A2 "bingoopos.com"        # expiry ~90 days out
echo | openssl s_client -connect khatribiryani.bingoopos.com:443 -servername khatribiryani.bingoopos.com 2>/dev/null | openssl x509 -noout -dates
curl -sI https://bingoopos.com | head -1               # 200/301, no trust error
```

## Why this runbook exists

Found during KHATRI-STABILITY-CLOSURE-1 (2026-08-12): the manual dns-01 profile means the
standard certbot timer has never been able to renew this cert. The August deadline exists so a
failed first attempt still leaves two weeks of slack.
