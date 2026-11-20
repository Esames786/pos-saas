# Khatri Cloud/Edge integration — GO/NO-GO deployment report (21 Nov 2026, owner review)

Candidate `integ/khatri-cloud-edge-20261121` head **f4e0cd95** (worktree `D:/laragon2/www/pos-saas-integ-khatri`, pushed).
Production pinned `be3693e4`, Edge pinned `d15c1320`, merge-base `5c74107f`. Nothing executed against production, the real LAB,
Khatri or any tenant. Plan: `docs/plans/khatri-cloud-edge-production-deployment-plan-2026-11-21.md`.

## Verdict

**GO for owner review of a production deployment of the Edge server half — with the deployment itself still behind a separate GO.**
No regression attributable to the integration was found. Every red test is pre-existing debt reproduced on the untouched Edge branch.
The deployment changes nothing for tenants without `offline_edge` (gate 2 `EDGE_FEATURE_ENABLED` stays off), and Khatri's own
offline mode still needs D-B (module), D-C (PC) and D-D (LAB acceptance) afterwards.

## What was done on the candidate (3 code commits over the merge)

| commit | what |
|---|---|
| `4c4894d1` | merge production `be3693e4` (no-ff): SLIP-END-LINES-1 + PRINT-OFFLINE-HERE-1 (30 files), only `docs/system-map` conflicted |
| `12ee36f5`, `f4e0cd95` | shared-view contract: PRINT-OFFLINE-HERE-1's three `url()` literals → runtime-map keys `printHealth` / `printHereTake` (Cloud paths, Edge null), identical hidden pill skeleton in both runtimes, poller exits on a null route; `PrintOfflineHereMySqlTest` reads the runtime map instead of literals. Cloud endpoints unchanged. |
| `ae8db6ae`, `8e0374c9` | deployment plan + system map rebuilt from fully migrated production-schema copies |

## Gates (all on the candidate, isolated DB sets, logs in the session scratchpad `integ-gates*.log`, `prodschema.log`)

| gate | result |
|---|---|
| Critical MySQL: `EdgeTenantMigration*` (gate + upgrade), `EdgeCleanMachine*`, `PrintOfflineHereMySqlTest`, `EdgeNoDuplicatePrintAfterSyncMySqlTest` | **65 tests green** (1 skipped by design) |
| Critical MySQL chain 1: `ThermalSlipEnd`, `EdgeLocalPrintDelivery`, `EdgeSyncIngestionHttp`, `EdgeAuthorityLeaseHttp`, `EdgeCompatibilityReportHttp`, `EdgeBootstrap*` | green (the only reds in that chain were the Edge-local DB-name guard of my first DB set, re-run green with compliant names) |
| Shared-view contract (SQLite): `SharedPosViewRenderTest`, `EdgeSharedPosRegressionStaticGateTest`, `PosRuntimeTest`, `SharedSecondaryScreensTest` | **24 tests green** |
| Shared-view contract (MySQL): `EdgeSharedPosRegressionGateMySqlTest`, `EdgeSharedPosViewMySqlTest`, `PrintOfflineHereMySqlTest`; `EdgeCashierPrintingHttp*` | **30 + 41 tests green** on the final code |
| Full MySQL suite (`phpunit.mysql.xml`, 1 process) | running at report time: **1242 passed, 0 errors, 1 failed** = `CateringKitchenSheetLanguageMySqlTest::…print_time…` — reproduced identically on the untouched Edge branch `d15c1320` (pre-existing canonical debt, catering sheet, not Edge/Cloud). Final line appended below when the run ends. |
| Fast SQLite suites (Feature + Unit, 426 tests) | 9 failures: 3 were the shared-view contract (fixed above, now green); 6 unit regressions (`CateringClientFeedbackUi`, `ClosureCapturesItsVariables` SupplierController:200, `EscPosReportPayload` two-decimals, `AgentDownloadIsVersioned` ×2, `DeliveryRiderReassignment`) **fail identically on the untouched Edge branch** (7 methods there; the merge even fixes one) → pre-existing debt, not integration |
| Cloud migrations on disposable production-schema copies (built from `be3693e4`'s own migration set: master 49, tenant 230) | master 4 migrations **0.4 s**, tenant 7 migrations **0.9 s**; re-run "Nothing to migrate"; `migrate:rollback --step=7/4` clean; re-migrate clean (final 53 / 237) |
| Tenant isolation / permissions on the Edge Cloud endpoints | device-auth middleware + per-branch binding exercised by `EdgeSyncIngestionHttp`, `EdgeAuthorityLeaseHttp`, `EdgeCompatibilityReportHttp`, `EdgeLocalAuthMySqlTest`, `EdgeRecoveryAuthorityBoundaryTest`, `EdgeRuntimeCacheIsolationTest` (all in the green sets above); unauthenticated / wrong-device → 401, cross-branch → refused |

## Print duplication contract (counter-prints-own-slip vs Edge ingestion)

PRINT-OFFLINE-HERE-1 only acts on EXISTING Cloud print jobs (`PrintJobService::printHere` takes a `queued`/`failed` job under a row
lock, mirrors the agent's claim lease, answers `already_printed` / `in_flight`); it creates no job. Edge ingestion creates no print job and
no KOT batch for an ingested sale (`EdgeNoDuplicatePrintAfterSyncMySqlTest`: exactly two payloads reach the printer for the whole
lifecycle — ingestion, ACK replay, lost-ACK recovery, handback). Therefore an Edge sale can never appear in the counter's "print here"
queue, and a Cloud sale printed here is marked printed so the agent never re-prints it. Both tests green on the candidate.

## Online POS / Online Print Agent unaffected for tenants without offline_edge

Gate 2 stays off at deployment; the Offline Edge menu is hidden for every tenant; the new Edge routes answer 401 without a paired
device. The Cloud POS blade carries the same endpoints as production (now through the runtime map); `PrintOfflineHereMySqlTest`,
`ThermalSlipEndMySqlTest`, `CloudPrintAgentContractMySqlTest`, `PrintRouting*`, `KotPerCategory`, `PrinterHealthLifecycle` are in the
green sets. The print agent itself (`tools/print-agent`, 2.7.1) is unchanged by the merge.

## Open items before the deployment GO

1. Full MySQL suite final line (appended below).
2. Owner GO to merge the candidate into `feat/14d-2-plan-upgrade-requests` (`Merge KHATRI-CLOUD-EDGE: …`) — not done.
3. Owner GO for the deployment window (plan §4): verified backups → pre-flight → `deploy.sh` → post-checks.

## Full MySQL suite — final line

(pending at the time of writing)
