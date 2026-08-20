# Catering Rate Impact Independent Release Certification

Date: 2026-08-20

Status: STOPPED - P1 lifecycle race reproduced

## Certification Identity

- `CERT_BASE_HEAD=13bd7f026a6b8a0d9cc1a7b2dc65feb4d1bcfa1e`
- `CURRENT_CATERING_ORIGIN=13bd7f026a6b8a0d9cc1a7b2dc65feb4d1bcfa1e`
- `CURRENT_CANONICAL=89037425c087802f58fd2697c4655a5d966813f2`
- `CODEX_CERT_BRANCH=audit/catering-rate-impact-cert-v1`
- Worktree: `D:\laragon2\www\pos-saas-catering-codex-cert`

The original independent audit was preserved separately as commit `f8fa220` on
`audit/catering-e2e-qa-v1`. No canonical merge was made.

## Source and Database Isolation

- `SOURCE_CORRECT_AUTOLOAD=yes`
- `AUTOLOAD_APP_PATH=D:\laragon2\www\pos-saas-catering-codex-cert\app\Services\Catering\CateringCommercialRateImpactService.php`
- `AUTOLOAD_TEST_PATH=D:\laragon2\www\pos-saas-catering-codex-cert\tests\MySql\CateringRateImpactRaceMySqlTest.php`
- `ISOLATED_MASTER_DB=pos_test_master_codex_cat`
- `ISOLATED_TENANT_DB=pos_test_tenant_codex_cat`
- `ISOLATED_EDGE_DB=pos_test_edge_local_codex_cat`
- `NO_DB_COLLISION=yes` for the tests executed in this pass

Composer dependencies were installed inside this worktree. No vendor junction
or generated autoload from another worktree was used.

## Existing Rate Impact Race Result

Claude's focused lifecycle/Rate Impact race suite was rerun unchanged first:

```text
CateringRateImpactRaceMySqlTest
OK (8 tests, 39 assertions)
```

It uses separate OS processes and separate tenant connections through real
services. It proves the implemented Rate Impact operations serialize with Send,
cancellation, and the tested final-invoice boundary. It also proves quoted-rate
preservation and no stock/GL side effects for those operations.

This result is valid, but it is not sufficient to certify the quotation
lifecycle because Rate Impact is not the only draft writer.

## P1 Reproduction: Normal Draft Writers Can Mutate After Send

Five test-only worker modes were added to the existing real-process race harness.
For each test:

1. The quotation starts as draft.
2. A third PDO connection locks the exact child row the writer will update.
3. The writer starts in a separate OS process, passes its draft check, and waits
   on the child-row lock.
4. The real `CateringEstimateService::markSent()` commits `status=sent`.
5. The child lock is released.
6. The already-authorized writer resumes.
7. The test asserts that the sent quotation remained unchanged.

Result:

```text
FAILURES!
Tests: 5, Assertions: 20, Failures: 5.

normal saveDraftLines:
    expected quantity 20.0
    actual quantity   25.0

material quantity override:
    expected 10.0000
    actual   12.0000

Customer Supplied toggle:
    expected false
    actual   true

Quoted Rate override:
    expected 382.0
    actual   500.0

Use Calculated Rate:
    expected reason "Before concurrent send"
    actual reason   null
```

These are forbidden final states: the estimate is sent, yet commercial line or
snapshot state commits afterward.

## Root Cause

`CateringEstimateService::saveDraftLines()` calls `assertDraft()` before opening
its transaction, then reconciles lines and totals without taking the shared
`CateringDocumentLock`. Send can therefore commit between validation and write.

`CateringLineCostBlockService` does not participate in the shared lock contract.
Its mutation methods call `assertEditable()`, which trusts:

```php
$snapshot->line?->estimate?->isDraft()
```

Quoted Rate and Use Calculated similarly trust the line's cached estimate
relation. These in-memory checks happen before the blocked write. They are not a
current database authority and do not serialize with Send.

The model guards do not close the race. They can see cached relations or an
InnoDB REPEATABLE READ snapshot established before Send committed. The resumed
writer consequently commits against a document that is now sent.

## Lock Contract Verdict

- `RATE_IMPACT_SEND_RACE=PASS (8 tests / 39 assertions)`
- `NORMAL_DRAFT_SAVE_SEND_RACE=FAIL`
- `MATERIAL_OVERRIDE_SEND_RACE=FAIL`
- `CUSTOMER_SUPPLIED_SEND_RACE=FAIL`
- `QUOTED_OVERRIDE_SEND_RACE=FAIL`
- `USE_CALCULATED_SEND_RACE=FAIL`
- `REPEATABLE_READ_STALE_DECISION_GAP=OPEN outside Rate Impact`
- `LOCK_ORDER_VERIFIED=partial only`
- `LOCK_ORDER_INVERSIONS=not fully certified after P1 stop`

The new lock helper is materially correct for the paths wired to it, including
current/locking reads after a wait. The vertical does not yet have one shared
commercial-document lock contract because normal draft writers bypass it.

## Original Finding Status at Stop Point

- `CAT-RATE-001=NOT RE-CERTIFIED` - stopped after P1; hardening code exists
- `CAT-RATE-002=OPEN` - Rate Impact subcase closed, normal draft writers fail
- `CAT-RATE-003=NOT RE-CERTIFIED` - stopped after P1; append-only code exists
- `CAT-RATE-004=NOT RE-CERTIFIED` - stopped after P1; audit code exists
- `CAT-RATE-005=NOT RE-CERTIFIED`
- `CAT-RATE-006=NOT RE-CERTIFIED`
- `CAT-RATE-007=NOT RE-CERTIFIED`
- `CAT-RATE-008=NOT RE-CERTIFIED`
- `CAT-RATE-009=NOT RE-CERTIFIED`
- `CAT-RATE-010=NOT RE-CERTIFIED`
- `CAT-TEST-001=OPEN` - the missing normal-writer races were added and fail
- `CAT-DOC-001=NOT RE-CERTIFIED`

`NOT RE-CERTIFIED` does not mean open or broken. It means the mandatory P1 stop
prevented an independent release claim for that item.

## Gates Not Claimed

Per the certification instruction, discovery of a P1 stops release
certification. Therefore these were deliberately not claimed or completed:

- Commercial Rate hardening/impact/isolation suites
- Cost Block source HTTP suite
- estimate lifecycle and line snapshot gates
- final invoice authority and atomicity certification
- reset classification and UAT seeder certification
- sent revision apply/rollback certification
- two-tenant isolation certification
- final operator UI certification
- full Catering regression
- independent full MySQL pass
- FAST suite

The two changed test files passed PHP lint before execution. Application code was
not modified.

## Required Application Fix Boundary

All commercial draft mutation entry points must acquire the same document lock
before deciding editability and before touching lines, snapshots, or totals:

- normal `saveDraftLines()`
- material quantity override and reset
- Customer Supplied toggle
- Quoted Rate override
- Use Calculated Rate
- quantity recalculation/reprice paths reachable from HTTP or form save

The safe contract is either:

- mutation wins completely under the lock, then Send reads and sends that whole
  coherent state; or
- Send wins, the writer re-reads sent status under the lock and refuses without
  any partial child writes.

Use the same deterministic order already documented by
`CateringDocumentLock`: event, final invoice, estimate, lines, snapshots. The
five failing certification tests must become green without weakening their
post-Send invariants.

## Final Certification Report

- `P0_OPEN=0`
- `P1_OPEN=1` (one shared root cause, five reproduced write paths)
- `P2_RELEASE_BLOCKERS=not evaluated after P1 stop`
- `P3_ONLY=no`
- `FOCUSED_CERTIFICATION=FAIL (existing 8/39 pass; adversarial 0/5 pass)`
- `CATERING_REGRESSION=NOT RUN`
- `INDEPENDENT_FULL_MYSQL=NOT RUN`
- `FAST=NOT RUN`
- `PHP_LINT=PASS for test-only certification changes`
- `DIFF_CHECK=PASS before this report`
- `REAL_FINAL_INVOICE_AUTHORITY=NOT CERTIFIED`
- `FINAL_INVOICE_ATOMICITY=NOT CERTIFIED`
- `UNIT_SAFETY=NOT RE-CERTIFIED`
- `SAME_DAY_HISTORY=NOT RE-CERTIFIED`
- `FUTURE_RATE_NOT_CURRENT=NOT RE-CERTIFIED`
- `AUDIT_ATOMICITY=NOT RE-CERTIFIED`
- `RESET_CLASSIFICATION=NOT RE-CERTIFIED`
- `SENT_REVISION_APPLY=NOT RE-CERTIFIED`
- `SENT_REVISION_ROLLBACK=NOT RE-CERTIFIED`
- `TENANT_ISOLATION=NOT RE-CERTIFIED`

Safety:

- `KHATRI_MUTATED=no`
- `KASHIF_MUTATED=no`
- `KASHIF_RESET_EXECUTED=no`
- `KASHIF_RESEED_EXECUTED=no`
- `PRODUCTION_MUTATED=no`
- `PRODUCTION_DEPLOYED=no`

- `INDEPENDENT_RELEASE_VERDICT=FAIL - P1 immutable quotation race remains`
- `READY_FOR_DEFAULT_MAIN_INTEGRATION=no`
