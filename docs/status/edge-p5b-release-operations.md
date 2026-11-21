# Offline Edge — P5B release operations + physical lab closure (14 Sep 2026)

Owner directive: **P5B RELEASE-OPERATIONS + PHYSICAL LAB CLOSURE** — close ONLY the remaining P5 release/physical blockers.
Not a business-feature tranche. DO NOT START P6. DO NOT TOUCH A LIVE RESTAURANT. DO NOT ACTIVATE PRODUCTION LOCAL MODE.
AUTO_FAILOVER REMAINS OFF. Nothing production was mutated; no worktree created; nothing deployed.

Accepted Edge at start: `dc83c65` (P5 code `a159700`). Canonical assessed at P5 close: `8e78b4d`.

## §1 Canonical re-ground

```
START_EDGE_HEAD=dc83c65 (= origin/feat/edge-config-refresh-v1, clean)
CURRENT_CANONICAL=fd0f61c   (moved from 8e78b4d: 4 commits, all SALES-ANALYTICS-1 follow-ups)
```

| Canonical delta 8e78b4d → fd0f61c | Edge relevance | Action |
|---|---|---|
| `Tenant/Reports/SalesAnalyticsController` (+26), `resources/views/tenant/reports/analytics.blade.php` (+102), `SalesAnalyticsMySqlTest` | Owner-only Cloud analytics page; the Reports controllers/views are physically excluded from the artifact | assessed, not merged |
| `app/Services/Reports/SalesReportService.php` (+13): optional `?string $orderType = null` filter on the sales/returns query | this file SHIPS in the artifact (Reports services are kept for the offline Quick Report) but Edge never references `SalesReportService` (the Quick Report uses `SalesReportEngine`); the change is an additive optional parameter with the previous default | assessed, not merged — no Branch POS / finance / printing / Edge contract change |

No Cloud/report/admin work was merged just to make ancestry current. Catering remains outside scope.

## §2 Release key custody — CLOSED (shape B: offline encrypted keystore on the release authority)

**Mechanism chosen:** (B) an offline/restricted build signing authority with a protected encrypted key store. No HSM, no
KMS, no CI signing runner exists for this platform — none is claimed. This is the documented pilot custody process.

| Item | Contract (implemented in `App\Services\Edge\EdgeSigningKeyStore`) |
|---|---|
| Keystore format | `bingoo-edge-signing-keystore` v1: Ed25519 secret key sealed with libsodium secretbox (XSalsa20-Poly1305) under a key derived from the release passphrase with Argon2id13 (opslimit 3, memlimit 256 MiB). File holds ONLY: public key, key id, KDF params, nonce, ciphertext, metadata. |
| Mint | `edge:update:keygen --keystore=<file> --passphrase-file=<file> --label=…` — refuses on a Branch Server, refuses a keystore inside the source tree, refuses to overwrite, never prints the secret or the passphrase; passphrase ≥ 16 chars from a FILE (never argv). |
| Sign | `edge:build-package … --signing-keystore=<file> --signing-passphrase-file=<file>` — the private key is opened into the build process memory only and zeroed after use; the package manifest records `signing_key_id`. A RELEASE build refuses a plaintext `--signing-key-file` (dev/test only). |
| Never in a package/artifact | `*keystore*.json`, `*.keystore`, `*.passphrase` are `PACKAGE_FORBIDDEN` (builder) and `edge.artifact.forbidden` (config) patterns; `EdgeSigningKeyStoreTest` and the clean-machine proof assert it. |
| Never on an appliance | the appliance holds only `EDGE_UPDATE_PUBLIC_KEY`; `edge:update:keygen` is not in the appliance CLI allowlist and refuses on `APP_ROLE=branch_server`. |
| Never logged | static log-hygiene gate (`EdgeLogHygieneTest`) over every Edge log statement and every appliance script; keygen/build print key id + public key only. |
| Wrong key refuses | `EdgeSigningKeyStoreTest` (a foreign public key refuses the signature; a tampered payload refuses; a wrong passphrase refuses `SIGNING_KEYSTORE_PASSPHRASE_INVALID`; a swapped public key refuses `SIGNING_KEYSTORE_INVALID`); `EdgeUpdaterMySqlTest` (`UPDATE_SIGNATURE_INVALID`, `UPDATE_WRONG_TARGET`). |

### Custody register — pilot release signing key v1 (PUBLIC facts only)

```
key_id (fingerprint) : a2eaf8a3d3e66a48
public key (Ed25519) : 8BBZRe6+XEhZFVXJPrM4D+kTOi7iv/AV1RztyMZZ9EQ=
appliances           : EDGE_UPDATE_PUBLIC_KEY=8BBZRe6+XEhZFVXJPrM4D+kTOi7iv/AV1RztyMZZ9EQ=  (Install-EdgeAppliance.ps1 -UpdatePublicKey)
minted               : 2026-09-14T10:54:31Z on the release/build workstation DESKTOP-0024EPM (Dell Precision 3560, operator Mohsin Sajjad)
label                : Bingoo Edge pilot release signing key v1 (P5B, 2026-09-14)
keystore             : C:\Users\Dell\.bingoo-edge-release\keystore\edge-update-signing-v1.keystore.json   (751 bytes)
passphrase file      : C:\Users\Dell\.bingoo-edge-release\passphrase\edge-update-signing-v1.passphrase     (42 chars, random)
ACL                  : C:\Users\Dell\.bingoo-edge-release  inheritance removed; DESKTOP-0024EPM\Dell:(OI)(CI)(F) only
verification         : keystore opens with its passphrase in 1.4 s (secret 64 bytes); a probe signature verifies against the
                       public key above; a foreign key refuses it; a wrong passphrase refuses (SIGNING_KEYSTORE_PASSPHRASE_INVALID)
```

Honest limits of this custody (stated, not hidden): keystore and passphrase both live on the same workstation under the
same Windows account today — the separation is the encryption + ACL, not two custodians. Recommended before the WAN pilot
goes beyond one branch: keep the passphrase in the release manager's password manager and delete the file between
releases; hold a second, offline copy of the keystore; rotate to a v2 key with a new key id if the workstation is ever
re-imaged or shared. The appliance contract (`EDGE_UPDATE_PUBLIC_KEY`, `UPDATE_WRONG_TARGET`, signed manifest) does not
change if custody later moves to a CI secret store or an HSM.

```
REAL_SIGNING_KEY_CUSTODY=established_shape_B_offline_encrypted_keystore_release_workstation (Argon2id+secretbox; no HSM/KMS claimed)
SIGNING_PRIVATE_KEY_ON_APPLIANCE=no
```

## §3 Backup recovery key provider — CLOSED (minimal Cloud recovery authority)

A dead appliance is no longer the only holder of the key that opens its backups.

| Piece | Where | Contract |
|---|---|---|
| Escrow | master DB `edge_backup_recovery_keys` (+ `edge_backup_recovery_audits`), migration `2026_09_14_000001` | one ACTIVE 32-byte key per (tenant, branch), `key_id = brk_<ulid>`, material stored `Crypt::encryptString` under the Cloud `APP_KEY` — never plaintext; `status active|retired`; rotation retires, never deletes |
| Authority | `App\Services\Edge\EdgeBackupRecoveryAuthority` (Cloud-only; physically excluded from the artifact) | `current()` issues on first use; `materialForDevice()` releases the device's OWN branch material (current + retired) to an ACTIVE paired device only; `assertKeyInScope()` refuses another branch's key id (404 `RECOVERY_KEY_NOT_FOUND`, audited `RECOVERY_KEY_SCOPE`, no disclosure); `rotate()`; `status()`; refuses to run on a Branch Server |
| API | `POST /api/edge/backup/recovery-keys` (`EdgeBackupRecoveryApiController`, Cloud-only) | behind `edge.device.auth` (device id + bearer secret sha256; revoked devices 401) + `throttle:6,1,edge-recovery`; scope from the device ROW never from input; `Cache-Control: no-store` |
| Rotation on revocation | `EdgePairingService::revokeDevice` | revoking a device rotates the branch key (reason `device_revoked:<uuid>`); a leaked appliance secret cannot read FUTURE backups; older backups stay recoverable under the retired key by the next authorized device |
| Admin authority | `php artisan edge:recovery-key status|rotate --tenant=<code> --branch=<id> --reason=…` (Cloud-only) | ids + audit trail, never material |
| Appliance side | `EdgeRecoveryKeyClient` → `EdgeRecoveryKeyProvisioner` → `edge:local:recovery-key` (allowlisted) | writes `EDGE_BACKUP_RECOVERY_KEY` / `_ID` / `EDGE_BACKUP_RETIRED_KEYS` into `appliance.env` (the ONLY secrets file, ACL-protected); retired keys are unioned, never dropped; prints key ids only |
| Install / restore | installer step 7; `Restore-EdgeAppliance.ps1 -PullConfig` (or `-PullRecoveryKey`); `edge:local:restore --pull-recovery-key` | first boot provisions the escrowed key; a replacement machine pairs anew (owner revokes the dead device + issues a pairing code), pulls the branch material, restores; **identity follows the paired device, state follows the backup** — the restored local binding is re-pointed to the replacement's device id |
| Operator exposure | none | the cashier/operator UI and the health page never carry material; the log-hygiene gate covers the new classes |

**Threat model (documented):** the escrow's root of trust is the Cloud `APP_KEY` + master-DB access control; compromise of
BOTH exposes every branch's backup material. Mitigations: Cloud key custody and DB access controls already in force for
tenant data; every retrieval is audited with device + IP; revocation rotates. Upgrade path: replace `wrap()/unwrap()` with
a KMS/HSM call — the appliance contract does not change. A stolen device secret is bounded by revocation (401 + rotation)
and by scope (a device only ever receives its own branch's keys). Backups are still useless without the material: a stolen
`.enc` file plus a stolen appliance.env of ANOTHER branch decrypts nothing (`BACKUP_KEY_UNKNOWN`).

**Proofs:** `EdgeBackupRecoveryAuthorityMySqlTest` — issue/escrow/retrieve (no-store, audited, never plaintext in the row,
401 for unauthenticated/wrong secret/unknown device); another branch's device never receives this branch's material and a
scope request is refused + audited; revocation rotates and the revoked device gets 401; **dead-appliance replacement**:
backup sealed under the Cloud-issued key with a pending outbox event → local state wiped, env gone, device revoked → the
restore fails closed (`BACKUP_KEY_UNKNOWN`) → a replacement device pairs, pulls current + retired material, wrong branch
refused (`RESTORE_WRONG_IDENTITY`), restore succeeds, the pending outbox row (`sale_uuid, envelope, content_hash, state`) is
**byte-identical**, the local binding names the replacement device; a device of another branch cannot open the backup.
The clean-machine proof (`EdgeCleanMachineInstallMySqlTest`) does the same across REAL processes: installer with NO
recovery key file → `appliance.env` holds a `brk_…` id that is escrowed in the master DB (decrypts to the same material;
retrieval audited; installer output never carries it) → DB-B restore without material refused → `--pull-recovery-key`
over HTTP against the real `php -S` Cloud → byte-identical pending outbox.

```
REAL_RECOVERY_KEY_PROVIDER=implemented_cloud_recovery_authority (master-DB escrow under the Cloud APP_KEY; device-scoped retrieval; rotation on revoke; audited; no external vault/KMS — none exists)
DEAD_APPLIANCE_REPLACEMENT_RESTORE=proven_in_software (in-process MySQL proof + real-process clean-machine proof; NOT yet on a physical second machine — see §14)
```

## §4 Pilot release build (clean accepted commit)

Built on 14 Sep 2026 from the CLEAN accepted commit by `scratchpad/build-release.sh` (a real `git archive` export, a real
`composer install --no-dev` closure, `edge:build-package` in RELEASE mode from the clean worktree at that commit, signed
from the custody keystore of §2, then `edge:audit-package` and a byte-level source proof against the export).

```
RELEASE_COMMIT=623f887 (623f8872fb29ce83de8e7b9a75bf33f001bd29de)
RELEASE_VERSION=0.6.0-edge (artifact_version 0.6.0-edge+623f8872fb29)
RELEASE_HASH=681238fe1a1f70e6f942df5297af6f685f5c6177509774475a562db41abc023d (package_hash; artifact manifest_hash 381fc68c46dd31dac8048a7f7cedf236c459385b0ea2dfb705bfb89461f918d1)
RELEASE_PACKAGE=C:\Users\Dell\.bingoo-edge-release\releases\BingooEdge-0.6.0-edge (223 MB; app/ gateway/ php/ scripts/ templates/ update/ package-manifest.json)
RELEASE_FILES=8700 package files (8592 in app/: 1988 source files byte-identical to the git-archive export + 6604 vendor files from the closure + 1 generated edge-build-manifest.json)   VENDOR_CLOSURE=composer install --no-dev in the git-archive export of 623f887: 83 packages, dev=false, lock sha256 47152fd5b7461ba6…, no phpunit/mockery
RELEASE_VENDOR_REAL_FILES=yes   DEV_VENDOR_JUNCTION_USED=no   vendor_source=separate_no_dev_closure
BUNDLED_PHP=php-8.3.16-Win32-vs16-x64   BUNDLED_GATEWAY=nginx-1.22.0
SIGNED_MANIFEST=update/edge-update-0.6.0-edge (artifact_version 0.6.0-edge+623f8872fb29).json   signing_key_id=a2eaf8a3d3e66a48 (custody keystore v1)
SOURCE_PROOF=PASS (0 mismatches, 0 files only in the artifact; the first build caught 2 CRLF worktree copies of LF blobs — restored from the index, rebuilt)
```

| Gate | Result |
|---|---|
| `edge:audit-package` (per-file sha256, package hash, boundary audit: PACKAGE_FORBIDDEN absent, Cloud-only sentinels absent, Edge runtime sentinels present, artifact marker branch_server) | PACKAGE OK — 0.6.0-edge · files 8700; boundary_audit ok (forbidden_hits [], cloud_only_present [], edge_runtime_missing [], artifact_marker_branch_server true); signed update verifies with the custody public key, a foreign key refuses, a tampered payload refuses; no keystore / passphrase / .pem / .key / .env / tests / .git / dev vendor inside |
| Dependency-closure gate (`EdgeApplianceDependencyClosureTest`) + physical exclusion gates (`EdgeArtifactTest`, `EdgePackageBuilderTest`, `EdgeRecoveryAuthorityBoundaryTest`) — Feature Edge suite | green — 130 tests / 32,592 assertions (tests/Feature/Edge: dependency closure, artifact + package-builder exclusion gates, EdgeSigningKeyStoreTest, EdgeRecoveryAuthorityBoundaryTest, log hygiene, boot proofs) after every P5B edit |
| Include-probe RELEASE proof (`EdgeCleanMachineInstallMySqlTest` with `EDGE_PROOF_VENDOR_FROM=<closure>`: CLI + web + post-update include probes read ONLY the installed package; keystore-signed packages; Cloud-issued recovery key; dead-appliance replacement restore over HTTP; tampered + signed update; safe uninstall) | green — 1 test, release mode (EDGE_PROOF_VENDOR_FROM = the no-dev closure of 623f887): keystore-signed packages A/B (signing_key_id in the manifest, no custody files inside), installer with NO recovery key file → Cloud-issued brk_… key escrowed in the master DB + retrieval audited + never printed, cashier login/POS/health over the nginx TLS gateway, CLI/web/post-update include probes read only the installed package, DB-B restore without material refused (BACKUP_KEY_UNKNOWN) then recovered through --pull-recovery-key over HTTP, wrong branch refused, pending outbox byte-identical, tampered update refused, signed 0.2.0 update applied with pre-update backup, safe uninstall |
| Recovery authority / backup / restore / updater / pairing MySQL tests | green — EdgeBackupRecoveryAuthorityMySqlTest 4 (incl. the dead-appliance replacement), EdgeBackupRestoreMySqlTest 13, EdgeFreshDbRecoveryMySqlTest, EdgeRestoreSyncRecoveryHttpMySqlTest, EdgeReturnBackupRecoveryMySqlTest (22), EdgeUpdaterMySqlTest 10 (UPDATE_SIGNATURE_INVALID / UPDATE_WRONG_TARGET / robocopy staging), EdgeSupervisionMySqlTest 10 (task plan: LOCAL SERVICE, no secrets on command lines), EdgeApplianceHealthMySqlTest 4, EdgeNoDuplicatePrintAfterSyncMySqlTest 1 |
| Secrets in the package (`.env`, `appliance.env`, keys, certs, keystore, passphrase, dumps, tests, `.git`) | none — boundary audit |

The pilot package is the artifact a lab operator installs with `Install-EdgeAppliance.ps1` on the Administrator lab
machine (§5); the include-probe proof establishes that the runtime it starts executes only files from that package.

## §5–§16 Physical lab items — BLOCKED (no Administrator-capable Windows LAB machine)

The directive is explicit: *"If none available: STOP with ADMIN_LAB_BLOCKED=yes. Do NOT fake it on the non-admin developer
laptop."* Re-verified on 14 Sep 2026 on this box (the only machine available to this session):

| Requirement | This box | Consequence |
|---|---|---|
| Administrator-capable Windows LAB machine | `net session` → access denied (ADMIN=no); `Register-ScheduledTask` → access denied; no Hyper-V / VirtualBox / VMware (WSL only) | §5 admin install, §6 ACLs (`icacls` grants to `LOCAL SERVICE`), §7 Scheduled Task registration/principal inspection, §8 physical reboot, §9 crash-restart by the Task Scheduler, §13 signed update on the lab box, §15 real process/task inspection, §16 health after reboot/update — **cannot be executed truthfully** |
| Second cashier PC (TLS trust) | none on this LAN besides this laptop | §10 blocked |
| Real network THERMAL printer, raw TCP 9100, reserved IP | LAN re-scan 192.168.1.0/24 port 9100: none (only 192.168.1.2:80 answers; the HP MFP is WSD-only) | §11 paper proofs blocked |
| Router control (disable Internet only) | none | §12 blocked |
| Second appliance machine for §14 | none | physical replacement restore blocked (software proof done, §3) |

```
ADMIN_LAB_BLOCKED=yes
```

What IS ready for the lab operator the moment the hardware exists (all delivered in P5/P5B, nothing to build):
`scripts/edge/Invoke-EdgeCertification.ps1` (Preflight / Tasks / Crash / PreReboot / PostReboot / Lan / Security / Health /
Report → JSON evidence), `scripts/edge/Test-EdgeCashierTrust.ps1` (second-PC TLS trust with full validation), the
pilot release package of §4, the recovery runbook (`README-INSTALL.md` → "Recover on a replacement machine"), and the
printer paper checklist below.

### §11 printer contract (unchanged, to be proven on paper)

```
NETWORK_PRINTER_EDGE_DIRECT=yes (raw TCP 9100 via EdgeLocalPrintDeliveryService / EdgeNetworkPrinterTransport)
SECOND_EDGE_AGENT_FOR_NETWORK_PRINTER=no
USB_STATUS=ONLINE_REQUIRED
DUPLICATE_PRINT_FROM_CLOUD_SYNC=0 in software (EdgeNoDuplicatePrintAfterSyncMySqlTest: Cloud ingestion / replay / lost ACK / handback create NO print job) — paper proof pending
RAW_TCP_SEND_ACK_LOSS_DUPLICATE_BEHAVIOR=transport_uncertainty_possible: raw 9100 has no application ACK; a delivery whose lease expires after the bytes left the socket MAY print twice after the retry. Recognizable on paper: every KOT carries "KOT #<sequence>" (ADDITION / CANCEL / DUPLICATE prefixes) and the sale no; every receipt carries the receipt/sale no; a physical duplicate is the SAME identity twice, never a new number. Application-level duplicates from Cloud sync are zero.
```

## §17 Final P5B gate

P5B is **NOT accepted**: the software blockers (§2 custody, §3 recovery authority, §4 pilot release) are CLOSED and proven;
every physical proof (§5–§16) is BLOCKED by the absence of an Administrator-capable Windows LAB machine, a second cashier
PC, a raw-9100 thermal printer and router control — exactly the precise operational blocker the directive asks to return.
Nothing was faked on the developer laptop.

```
START_EDGE_HEAD=dc83c65   CURRENT_CANONICAL=fd0f61c (8e78b4d→fd0f61c = SALES-ANALYTICS-1 follow-ups; Cloud-only; assessed, not merged)
FINAL_EDGE_HEAD=623f887 (P5B code) / the docs commit that follows it on feat/edge-config-refresh-v1 (this document)   ORIGIN_EDGE_HEAD=pushed head of feat/edge-config-refresh-v1 after the docs commit (see git log)
RELEASE_COMMIT=623f887 (623f8872fb29ce83de8e7b9a75bf33f001bd29de)   RELEASE_VERSION=0.6.0-edge (artifact_version 0.6.0-edge+623f8872fb29)   RELEASE_HASH=681238fe1a1f70e6f942df5297af6f685f5c6177509774475a562db41abc023d (package_hash; artifact manifest_hash 381fc68c46dd31dac8048a7f7cedf236c459385b0ea2dfb705bfb89461f918d1)
RELEASE_VENDOR_REAL_FILES=yes   DEV_VENDOR_JUNCTION_USED=no   DEPENDENCY_CLOSURE=PASS   ARTIFACT_BOUNDARY=PASS
REAL_SIGNING_KEY_CUSTODY=established_shape_B_offline_encrypted_keystore_release_workstation (Argon2id+secretbox keystore + passphrase file, user-only ACL; key id a2eaf8a3d3e66a48; no HSM/KMS claimed)
SIGNING_PRIVATE_KEY_ON_APPLIANCE=no
REAL_RECOVERY_KEY_PROVIDER=implemented_cloud_recovery_authority (master-DB escrow under the Cloud APP_KEY; device-scoped retrieval; rotation on revoke; audited; no external vault)
DEAD_APPLIANCE_REPLACEMENT_RESTORE=proven_in_software (in-process + real-process proofs; physical second machine blocked)
ADMIN_LAB_BLOCKED=yes
ADMIN_INSTALL=blocked   SECRET_FILE_ACLS=blocked (ACL grants need Administrator; user-only ACL proven on the custody dir)
TASKS_REGISTERED=blocked   TASK_SERVICE_ACCOUNT=LOCAL SERVICE by plan (never SYSTEM) — not inspected on a real box   TASKS_AT_BOOT=blocked
REBOOT_AUTO_START=blocked   REBOOT_STANDBY_RECOVERY=blocked
WEB_CRASH_RESTART=blocked   GATEWAY_CRASH_RESTART=blocked   AUTHORITY_CRASH_RESTART=blocked   SYNC_CRASH_RESTART=blocked   PRINT_CRASH_RESTART=blocked
SECOND_CASHIER_PC=blocked   TLS_TRUST=blocked (kit: Test-EdgeCashierTrust.ps1)   REAL_CASHIER_EDGE_POS=blocked (software: cashier login + POS + health over HTTPS via nginx proven in the clean-machine proof)
REAL_NETWORK_PRINTER=blocked (none on this LAN)   RAW_TCP_9100=blocked
PHYSICAL_KOT=blocked   PHYSICAL_ADD_ROUND_KOT=blocked   PHYSICAL_CANCEL_KOT=blocked   PHYSICAL_RECEIPT=blocked
DUPLICATE_PRINT_FROM_CLOUD_SYNC=0 (software proof; paper proof pending)
RAW_TCP_SEND_ACK_LOSS_DUPLICATE_BEHAVIOR=transport_uncertainty_possible (lease retry after a lost send MAY print the SAME KOT #/receipt no twice; never a new identity; application duplicates zero)
LAN_WITH_WAN_DISABLED=blocked   CASHIER_TO_EDGE_LAN=blocked   EDGE_TO_PRINTER_LAN=blocked   CLOUD_UNREACHABLE=blocked
SIGNED_PHYSICAL_UPDATE=blocked (software: A→B keystore-signed update with pre-update backup, tampered refused, state preserved — proven in the clean-machine proof)
TAMPERED_UPDATE_REFUSED=yes (software)   STATE_PRESERVED_AFTER_UPDATE=yes (software)
PHYSICAL_BACKUP=blocked   REPLACEMENT_RESTORE=blocked_physically (software proof yes)
SECRETS_IN_PROCESS_LIST=no (by construction: launcher exports BINGOO_EDGE_ENV_DIR only; no secret on any task/process command line — EdgeSupervisionMySqlTest; not inspected on a real box)
SECRETS_IN_TASK_COMMANDS=no (plan)   SECRETS_IN_LOGS=no (EdgeLogHygieneTest over the new classes too)
P0_OPEN=0   P1_OPEN=0   P2_RELEASE_BLOCKERS=0 software; 1 operational (no admin lab hardware)
READY_FOR_WAN_UNPLUG_PILOT=no
AUTO_FAILOVER_ENABLED=no   LOCAL_MODE_ACTIVATED=no   PRODUCTION_MUTATED=no
```

**Precise blocker returned to the owner:** provide (1) one Administrator-capable Windows 10/11 LAB machine (physical or a
VM with a reboot), (2) one second Windows PC for the cashier browser, (3) one thermal network printer with raw TCP 9100
on a DHCP-reserved IP, (4) a router where Internet can be disabled while the LAN stays up. With those four, the P5/P5B
kit runs §5–§16 unchanged (`Invoke-EdgeCertification.ps1`, `Test-EdgeCashierTrust.ps1`, the pilot package, the recovery
runbook), and P5B can be re-gated. STOP. P6 was not started.

---

## P5B RESUME — physical certification only (19 Sep 2026)

Directive: "EDGE — RESUME FROM P5B / PHYSICAL CERTIFICATION ONLY". No business features. If the LAB equipment is absent: do not
invent software work, return the missing physical requirements and STOP.

### §1 Re-ground

```
EDGE_START_HEAD=ecf4694 (= origin/feat/edge-config-refresh-v1, clean)
CURRENT_CANONICAL=be07a5a   (moved from fd0f61c: 27 commits / 19 non-merge, 14–19 Sep. Production was verified by the canonical
                             session at acf33a8 on 19 Sep; be07a5a is one docs-only commit above it, not deployed.)
```

| Delta file that SHIPS in the Edge artifact | Edge relevance | Action |
|---|---|---|
| `resources/views/tenant/printing/documents/receipt.blade.php` (+45) | additive `@isset($tableBill)` block; only `RestaurantTableSessionController::renderTableBillReceipt` passes `tableBill`; Edge renders receipts via `PrintDocumentController::preview` → Edge output byte-identical | assessed, not merged |
| `app/Http/Controllers/Tenant/RestaurantTableSessionController.php` (+110) | not referenced by `routes/edge_runtime.php` (Cloud table workspace only) | not merged |
| `resources/views/tenant/pos/index.blade.php` (+98), `pos/partials/table-board.blade.php` | canonical cashier page; Edge serves its own `resources/views/edge/pos/index.blade.php`; the fixed bug class (shared preview modal printing the CART order) does not exist on Edge (JSON `previewBill`, per-job printing) | not merged; UX drift registered in `edge-online-pos-parity-register.md` |
| `app/Http/Controllers/Tenant/DashboardController.php`, `resources/views/tenant/dashboard.blade.php` | Cloud dashboard; never routed on a Branch Server | not merged |
| everything else (Catering mail queue / settings / estimate, analytics view, docs, tests) | physically excluded from the artifact | not merged |

`git merge-tree HEAD origin/feat/14d-2-plan-upgrade-requests` = 0 conflicts, so a reconcile stays cheap when a later tranche needs one.
Nothing in the delta changes what the appliance executes: the Edge code head stays 623f887 = the source of pilot release 0.6.0-edge
(package_hash 681238fe…), which therefore remains the package to certify.

### §2 LAB environment (re-probed 19 Sep on the only machine available)

| Requirement | Status |
|---|---|
| Administrator-capable Windows LAB machine | Only this developer laptop (DESKTOP-0024EPM, Windows 11 Pro). The account is a member of local Administrators, but the session token is UAC-filtered (`EnableLUA=1`, `ConsentPromptBehaviorAdmin=5`): elevation needs an interactive consent click no agent session can provide, and the owner rule stands — the LAB is not faked on the developer laptop. No Hyper-V / VirtualBox / VMware. |
| Second Windows cashier/client machine | None. LAN sweep 192.168.1.0/24: 3 alive hosts = router `.1`, an HTTP-only device `.2` (MAC 4c-d0-dd-c3-dc-9f; port 80 open; 9100/631/515/443/8080/23 closed), this laptop `.6`. |
| LAN router/switch, WAN disable with LAN alive | 192.168.1.1 Wi-Fi router; no admin access recorded; no separate switch. |
| Real network thermal printer, raw TCP 9100, reserved IP | None — `RAW9100_HOSTS=none`; the only printer is an HP Laser MFP over WSD. |

→ `ADMIN_LAB_BLOCKED=yes`. §3–§10 were NOT executed and nothing was simulated. Everything they need is unchanged since P5B: the
pilot package `BingooEdge-0.6.0-edge`, `Invoke-EdgeCertification.ps1`, `Test-EdgeCashierTrust.ps1`, `Register-EdgeServices.ps1`,
`Install-EdgeAppliance.ps1`, the README-INSTALL runbooks, and the custody/recovery contracts above.

### §11 Final (19 Sep 2026)

```
EDGE_START_HEAD=ecf4694   CURRENT_CANONICAL=be07a5a (assessed, not merged; production = acf33a8)
FINAL_EDGE_HEAD=this docs commit (code head unchanged 623f887)   ORIGIN_EDGE_HEAD=this docs commit (pushed)
ADMIN_INSTALL=not_run (no LAB machine)   TASKS_REGISTERED=not_run   SERVICE_ACCOUNT=NT AUTHORITY\LOCAL SERVICE (plan; unverified physically)
SECRET_FILE_ACLS=not_run
REBOOT_AUTO_START=not_run   REBOOT_STANDBY_RECOVERY=not_run
WEB_CRASH_RESTART=not_run   GATEWAY_CRASH_RESTART=not_run   AUTHORITY_CRASH_RESTART=not_run   SYNC_CRASH_RESTART=not_run   PRINT_CRASH_RESTART=not_run
SECOND_CASHIER_PC=absent   TLS_TRUST=not_run   REAL_CASHIER_EDGE_POS=not_run
REAL_NETWORK_PRINTER=absent   RAW_TCP_9100=none_on_LAN   PHYSICAL_KOT=not_run   PHYSICAL_RECEIPT=not_run
DUPLICATE_PRINT_FROM_CLOUD_SYNC=0 in software (EdgeNoDuplicatePrintAfterSyncMySqlTest); physical run pending
LAN_WITH_WAN_DISABLED=not_run   CASHIER_TO_EDGE_LAN=not_run   EDGE_TO_PRINTER_LAN=not_run   CLOUD_UNREACHABLE=not_run
SECRETS_IN_PROCESS_LIST=no (by construction; physical inspection pending)   SECRETS_IN_TASK_COMMANDS=no (plan)   SECRETS_IN_LOGS=no (gate)
P0_OPEN=0   P1_OPEN=0   P2_RELEASE_BLOCKERS=0 software; 1 operational (LAB hardware)
READY_FOR_WAN_UNPLUG_PILOT=no
AUTO_FAILOVER_ENABLED=no   LOCAL_MODE_ACTIVATED=no   PRODUCTION_MUTATED=no   P6_NOT_STARTED=yes
```

Missing physical requirements (unchanged from 14 Sep): (1) an Administrator-capable Windows 10/11 LAB machine that is not the
developer laptop (or the owner's explicit decision to run the kit elevated on it), (2) a second Windows PC for the cashier browser,
(3) a thermal network printer with raw TCP 9100 on a DHCP-reserved IP, (4) a router where Internet can be disabled while the LAN stays
up. STOP.
