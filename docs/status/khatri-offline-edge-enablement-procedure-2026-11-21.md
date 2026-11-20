# Khatri — offline_edge enablement and pairing procedure (D-B, prepared 21 Nov 2026, NOT executed)

Nothing here has been run. Every step below is a production mutation and runs only on the owner's separate GO, after the
production Cloud carries the Edge endpoints (D-A deployment). Order matters: the module must be ON before the pairing screen exists.

## Three independent gates (code: `OfflineEdgeEntitlementService`)

| gate | what | where it is set | Khatri today |
|---|---|---|---|
| 1 commercial | module `offline_edge` in the tenant's effective plan/module set, module active + enabled, subscription active | master DB `plan_modules` (plan `khatri_restaurant` ↔ module `offline_edge`) via the central admin **Modules** screen (`/modules`, `/modules/{module}/edit`), or a tenant-level module override if one exists on the plan screen | OFF (onboarding command excluded it on purpose) |
| 2 rollout | `EDGE_FEATURE_ENABLED=true` (`config('app.edge_feature_enabled')`) | production `.env` of the Cloud + `config:cache` rebuild (part of the deployment, owner-approved) | absent → false |
| 3 installer | a configured installer artifact for the download button | not needed for Khatri: the kit carries the package by USB | n/a |

Pairing itself is additionally gated per branch and per device (`BRANCH-DEVICE-PAIRING-1`): branch limit 1 on Khatri, so exactly one
Branch Server.

## Procedure (owner, in the browser, after the deployment)

1. **Backup first**: the deployment's verified master + tenant backup must be less than one hour old.
2. **Gate 2**: `EDGE_FEATURE_ENABLED=true` in the production `.env` → `php artisan config:cache` (deploy step 7 pattern). Other tenants are
   unaffected: without gate 1 their Offline Edge menu stays hidden.
3. **Gate 1**: central admin → Modules → `offline_edge` → attach to plan `khatri_restaurant` (only that plan; demo/other plans unchanged).
   Verification query (read-only, master DB): the `plan_modules` row for (`khatri_restaurant`, `offline_edge`) exists; no other plan gained it.
4. Khatri tenant → Settings → **Offline Edge** (`/settings/offline-edge`): the page opens (entitlement check passed) and lists branch 1.
5. **Pairing code**: "Generate pairing code" for branch 1 (`POST /settings/offline-edge/branches/{branch}/pairing-code`, throttled 10/min,
   code valid for the installer's `-PairingCodeWaitSeconds` window of 15 min by default). The owner writes the code into
   `C:\BingooEdge\secrets\pairing.code` on the Branch Server PC. Never pasted into chat, never on a command line.
6. **Enrollment assertions** (`POST /settings/offline-edge/enrollment-assertions`, throttled 20/min): one per Edge user — primary
   operator, backup operator, each cashier, the approver with `may_approve_pos`. Each assertion file goes to the PC as
   `-EnrollmentAssertionFile` (installer) or later `edge:local:enroll`. The user's Edge PIN/password is typed by that person at enrolment.
7. Install (worksheet §6). The device appears under Settings → Offline Edge → devices with its id and last heartbeat.
8. **Verification on the Cloud** (read-only): device row present, `edge_branch_authority_leases` row for branch 1 with holder `cloud` and a
   recent heartbeat, config revision 1 acknowledged, `edge_backup_recovery` material escrowed (`php artisan edge:recovery-key status
   --tenant=khatribiryani --branch=1` prints ids only).
9. **Rollback** (any time, no data loss): Settings → Offline Edge → device → Revoke (`POST …/devices/{edgeDevice}/revoke`); detach the
   module from the plan; `EDGE_FEATURE_ENABLED=false` + `config:cache`. The appliance then fails closed (ENTITLEMENT_VALID false).

## What this does NOT do

It does not change Khatri's POS, printing, agent, terminals, menu or GL. The branch keeps selling on the Cloud; Edge mirrors. No sale is
ever created by this procedure. Local Mode needs the operator's manual takeover (D-E) and the LAB acceptance (D-D) first.
