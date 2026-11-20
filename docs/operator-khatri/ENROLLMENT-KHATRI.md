# Enrolling the existing Khatri accounts on the Branch Server

Who: the **Khatri Biryani Owner** account and the **Noman Owner/Manager** account (and later each cashier). No new accounts are
created. The Cloud roles and permissions of these users arrive on the Branch Server with the bootstrap / configuration refresh;
enrolment only gives the user an **Edge PIN** so they can log in at `https://bingoo-edge.local:8443/edge/local/login`.

Preconditions (all from the install day): the Branch Server is installed and paired (`STANDBY READY`), the Cloud deployment that
carries the Edge endpoints is live, `offline_edge` is enabled on the Khatri plan, and the Owner is logged in to
`https://khatribiryani.bingoopos.com`.

## A. Find the user_id on the Cloud (owner's browser)

1. `https://khatribiryani.bingoopos.com/users` → click the user → the address bar shows `/users/<id>`; that number is the `user_id`.
2. The user must be **active** and must have an **employee code** (Users → edit → Employee code). Without an employee code the
   Cloud refuses to issue the assertion and the user cannot log in to the Branch Server.
3. Branch: Khatri has one branch, `branch_id = 1` (confirm on Settings → Branches).
4. For the person who will press **Switch to Offline / Return to Online**: the user needs the tenant permission
   `tenant.offline-edge.security`. The Owner role has it after the deployment; for a manager, grant it in the Cloud **Permission
   Center** (Settings → Roles → Manager → Offline Edge → security). Cashiers do not need it.

## B. Get the Cloud-signed enrollment assertion (owner)

There is no button for this on the Offline Edge page. The assertion is issued by the Cloud endpoint
`POST /settings/offline-edge/enrollment-assertions` with `user_id` and `branch_id` (permission `tenant.offline-edge.enrollment.issue`,
held by the Owner). Two ways, both on the owner's word:

- the lead engineer triggers it for you and hands you the JSON file; or
- you run this in the browser console (F12 → Console) while logged in to the Khatri Cloud as Owner — replace the two numbers:

```js
fetch('/settings/offline-edge/enrollment-assertions', {method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}, body: JSON.stringify({user_id: 2, branch_id: 1})}).then(r => r.json()).then(j => { console.log(JSON.stringify(j)); });
```

Copy the printed JSON into a file on the Branch Server PC: `C:\BingooEdge\secrets\<user>.json` (for example `owner.json`, `noman.json`).
**The assertion is valid for 15 minutes** — do step C right away. It contains no password; it only names the user, branch and device.

## C. Enrol on the Branch Server (owner, PowerShell on the Branch Server PC, not elevated)

```powershell
& "C:\Program Files\Bingoo Edge\php\php.exe" "C:\Program Files\Bingoo Edge\artisan" edge:local:enroll C:\BingooEdge\secrets\owner.json --type=pin
```

The command prompts **secretly** for the Edge PIN (the person types it; nothing is echoed, nothing is logged). Then delete the JSON file.
Use `--type=password` instead of `--type=pin` if the person prefers a password.

PIN rules (enforced by the Branch Server): at least **6 digits**, not all the same digit, not `123456`/`000000`. Password: at least
8 characters, not `password`/`admin`. The Edge PIN is separate from the Cloud password and from the manager approval PIN.

Repeat A–C for each person: Khatri Biryani Owner, Noman Owner/Manager, every cashier who will sell in Offline mode.

## D. Verify (the enrolled person, any LAN device)

1. `https://bingoo-edge.local:8443/edge/local/login` → employee code + Edge PIN → lands on the POS (cashiers) or the status page.
2. `https://bingoo-edge.local:8443/edge/local/status` (after login) shows JSON with `authenticated: true`, `runtime_mode: branch_server`,
   the user's `employee_code`, `roles`, and a `permissions_count` greater than 0. For the owner/manager the roles list must contain
   the role that carries `tenant.offline-edge.security`.
3. Owner/manager: `https://bingoo-edge.local:8443/edge/local/pos/health` shows the two buttons **Switch to Offline** and
   **Return to Online**. If the page shows the status but not the buttons, the permission from step A.4 is missing — fix it on the
   Cloud and wait for the next configuration refresh (or log out and in after it).

## E. Removing access

Cloud: deactivate the user or revoke the role/permission → the next configuration refresh removes it from the Branch Server.
Branch Server (owner): `edge:local:enroll` again with a new PIN replaces the credential. Device revocation lives on the Cloud
Offline Edge page (Revoke device) and disables every local login.
