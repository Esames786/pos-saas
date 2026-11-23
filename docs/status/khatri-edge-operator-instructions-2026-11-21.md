# Khatri — Edge operator instructions and training checklist (prepared 21 Nov 2026, D-E)

For: one PRIMARY operator and one BACKUP operator at Khatri, named by the owner (no names or privileges are assigned here).
Manual takeover and handback only. `AUTO_FAILOVER_ENABLED=no` stays. The Cloud is the financial truth; Edge is the branch's
warm standby that takes over ONLY when the internet is down and ONLY when an operator says so.

## The three states the operator must recognise

| what the cashier screen shows | state | what it means |
|---|---|---|
| normal Online POS at `https://khatribiryani.bingoopos.com` | ONLINE | sell as always; Edge is silently mirroring (STANDBY_READY) |
| Online POS does not load / internet down; Edge health page says `STANDBY_READY`, authority `standby` | WAN LOST, Edge waiting | nobody can sell until an operator runs the takeover (or the internet returns) |
| Edge POS at `https://bingoo-edge.local:8443` shows `LOCAL_ACTIVE` | LOCAL MODE | sell on Edge; receipts and KOT print directly on the LAN printers; every sale syncs to the Cloud when the internet returns |

Health page (any device on the LAN, no login): `https://bingoo-edge.local:8443/edge/local/health`.

## A. Takeover (internet down, the shop must keep selling)

1. Confirm the internet is really down (phone on the shop Wi-Fi cannot open any website; the Online POS does not load).
2. Confirm Edge is ready: health page shows `STANDBY_READY` and problems `none`. If it shows a problem, call the owner; do NOT force anything.
3. On the Branch Server PC, open PowerShell and run (one line):
   ```powershell
   & "C:\Program Files\Bingoo Edge\php\php.exe" "C:\Program Files\Bingoo Edge\artisan" edge:local:authority-takeover --confirm --by="<your name>"
   ```
   (there is no button for this anywhere; the command refuses, with the reason, if any gate fails: it never half-takes.)
4. Status page (`https://bingoo-edge.local:8443/edge/local/pos/health`, after login) shows `LOCAL ACTIVE`. Cashiers open `https://bingoo-edge.local:8443/edge/local/login`, log in with their employee code + Edge PIN/password, open a shift, sell; the POS badge reads `LOCAL MODE ACTIVE`.
5. Tell the owner (phone/SMS): "Khatri in Local Mode since HH:MM, by <name>".

Never: pull a cable or restart the router to "make it offline"; run the takeover while the Online POS still works (split brain is prevented by the lease, but the refusal wastes time); run it twice.

## B. Handback (internet is back)

1. Confirm the internet is back (the Online POS loads on the phone).
2. Finish and CLOSE the open Edge shift (cashier closes the shift on the Edge POS).
3. Wait until the health page shows the outbox at `0 pending / 0 failed` (sales synced). If `failed` is not 0, call the owner; do NOT hand back.
4. Run on the Branch Server PC (one line; add `--assess` first to only see the blockers):
   ```powershell
   & "C:\Program Files\Bingoo Edge\php\php.exe" "C:\Program Files\Bingoo Edge\artisan" edge:local:authority-handback --by="<your name>"
   ```
   (a blocked handback lists its blockers and discards nothing.)
5. Status page shows `STANDBY READY`. Cashiers go back to the Online POS and open a NEW shift there.
6. Tell the owner: "Khatri back online since HH:MM, by <name>".

## C. What the operator never does

- Never enters Local Mode for a "test" without the owner on the phone.
- Never deletes, resets or re-installs anything on the Branch Server PC; never runs any command not on this sheet.
- Never prints a test page from the Cloud printer page while Edge is in Local Mode (the Online Agent and Edge both talk to the same printers).
- Never gives the Edge PIN/password to someone else; never writes passwords on paper near the counter.
- Never switches off the Branch Server PC during service; if Windows wants to reboot, do it after closing.

## D. Daily 1-minute check (opening time)

1. Health page opens from a cashier device → `STANDBY_READY`, problems `none`, "last heartbeat" less than 1 minute old.
2. Printers: Edge health lists each printer `reachable`.
3. Clock on the PC is correct (within a minute of the phone).
If any item fails: do not touch anything, send a photo of the health page to the owner.

## E. Training checklist (the owner ticks each item for each operator)

| # | skill | primary | backup |
|---|---|---|---|
| 1 | Can open the health page from a phone and read state / problems / outbox | ☐ | ☐ |
| 2 | Can tell ONLINE / WAN LOST / LOCAL MODE apart from the screen | ☐ | ☐ |
| 3 | Knows the two conditions before takeover (internet really down, STANDBY_READY) | ☐ | ☐ |
| 4 | Ran a takeover on the LAB (or the supervised off-hours exercise) with the owner present | ☐ | ☐ |
| 5 | Knows the handback order: close shift → outbox 0/0 → handback → new shift online | ☐ | ☐ |
| 6 | Ran a handback with the owner present | ☐ | ☐ |
| 7 | Knows what to do when a gate refuses (read the reason, call the owner, change nothing) | ☐ | ☐ |
| 8 | Knows the "never" list (§C) | ☐ | ☐ |
| 9 | Has the owner's phone number and the Edge PIN in a sealed place | ☐ | ☐ |

Operators receive their Edge credentials only through the owner's enrolment (Cloud-signed assertion for an EXISTING tenant user →
`edge:local:enroll <assertion.json> --type=pin` on the Branch Server, credential typed secretly), never from this sheet.
Health page without login (JSON, any LAN device): `https://bingoo-edge.local:8443/edge/local/health`. Status page with login:
`https://bingoo-edge.local:8443/edge/local/pos/health` (headline pill, problems, connection, takeover gates, handback blockers,
printers, outbox). The takeover / handback commands exist only as the two PowerShell lines above, on the Branch Server PC.
