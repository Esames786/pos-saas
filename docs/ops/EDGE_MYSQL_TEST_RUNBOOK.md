# Edge MySQL test foundation — runbook (MYSQL-TEST-FOUNDATION-1)

The default `phpunit.xml` runs on **SQLite `:memory:`** — fine for fast, DB-agnostic unit tests,
but **not** an authority for Edge correctness (transactions, row locks, FK behaviour, config
cutover, UUID idempotency, operational stock). Those are proven on **real MySQL/MariaDB** via
`phpunit.mysql.xml` and the `tests/MySql/` suite.

## Requirements
- **MySQL 8.0+** (validated on **MySQL 8.0.30**) or MariaDB 10.6+.
- PHP with `pdo_mysql` (skips fail-closed if absent).
- Dedicated **test** databases — names **must contain `test`** or the harness refuses to run.

## Databases
The harness creates and migrates these automatically on first run (idempotent):
- `pos_test_master` — central schema (real `database/migrations`).
- `pos_test_tenant` — tenant schema (real `database/migrations/tenant`, all 132 tables incl.
  `categories`).

They are created via a **server-level PDO** (no database selected), so nothing needs to pre-exist.
The harness **refuses** any database whose name does not contain `test`, and refuses to run unless
`APP_ENV=testing`.

## Canonical command
```bash
# from the project root
vendor/bin/phpunit -c phpunit.mysql.xml --testdox
# or
php artisan test -c phpunit.mysql.xml
```
On this Windows/Laragon dev box:
```bash
/d/laragon2/bin/php/php-8.3.16-Win32-vs16-x64/php.exe vendor/bin/phpunit -c phpunit.mysql.xml --testdox
```

## Configuration
`phpunit.mysql.xml` sets the connection env (host/port/user 127.0.0.1:3306/root by default, empty
password for Laragon). Override per-environment with real env vars if your MySQL differs — do **not**
commit machine-specific credentials. The tenant DB name is `EDGE_TEST_TENANT_DB` (default
`pos_test_tenant`).

## Parallel-worktree isolation (PLATFORM TEST-ISOLATION)
Several suites DROP + recreate a dedicated **Edge-LOCAL appliance DB** (import/auth/db-init/refresh).
Its name is env-driven — **never a hard-coded literal** — resolved only through
`Tests\MySql\Support\EdgeTestDatabases::local()`:

- `EDGE_TEST_LOCAL_DB` — base name, default `pos_test_edge_local` (single-worktree dev).
- Suites that need their own DB suffix it via the resolver (e.g. `…_refresh`), so no class drops
  another class's DB either.
- Subprocess race workers (`edge_enroll_worker.php` / `edge_login_worker.php`) receive the resolved
  name via the `EDGE_TEST_LOCAL_DB` environment variable — they inherit exactly what the parent
  resolved.

Each concurrently-running worktree exports its own trio in its (untracked) `test-mysql.sh` wrapper:

```bash
# Edge worktree wrapper
export DB_DATABASE=pos_test_master_edge
export EDGE_TEST_TENANT_DB=pos_test_tenant_edge
export EDGE_TEST_LOCAL_DB=pos_test_edge_local_edge

# Catering worktree wrapper
export DB_DATABASE=pos_test_master_cat
export EDGE_TEST_TENANT_DB=pos_test_tenant_cat
export EDGE_TEST_LOCAL_DB=pos_test_edge_local_cat
```

The resolver fails closed: the resolved name must contain both `edge` and `test` and satisfy the
same `EdgeLocalDatabase` naming rules the runtime guard enforces (never a production-shaped name).
Two suites sharing one MySQL server but different names can run **simultaneously** without either
dropping the other's databases.

## What the suite proves today
| Test | Proves |
|---|---|
| `ConfigRefreshFkSpikeTest` | **§7 verdict:** config tables are referenced with CASCADE(39)/SET NULL(85)/RESTRICT+NO ACTION(5) rules; a blind `DELETE FROM products` **CASCADE-destroys** a historical sale line; UPSERT+tombstone preserves it. **Contract A blind DELETE+INSERT is REJECTED.** |
| `HistoricalReminderDestinationTest` | **§8:** `print_jobs.printer_id` has **no FK** and snapshots no destination; deleting a printer leaves a dangling id and **loses the destination**; tombstoning preserves it. |
| `SaleClientUuidRaceTest` | **§6:** two independent connections racing the same `client_uuid` converge to **one** sale (real InnoDB unique-index contention, not sequential calls). |
| `RecipeConsumptionReferenceTest` | **§9:** reference operational quantities for stock_item/recipe/yield; the **real `UnitConversionService`** converts 50 g→0.05 KG and **hard-blocks** on a missing rule; `allow_negative_stock` gates the block. |
| `PrintRoutingMySqlTest` | **§5:** the real `PrintRoutingService` runs against the real schema (with `categories`) — closes the SQLite "no such table: categories" gap. |

## Safety guards (fail closed)
- `APP_ENV !== 'testing'` → hard refusal.
- tenant or master DB name without `test` → hard refusal (create + migrate).
- No test connects to production/demo tenant DBs, calls deploy, activates Local Mode, contacts
  physical printers, or calls payment providers.

## Cleanup
Test databases are disposable. To reset:
```sql
DROP DATABASE IF EXISTS pos_test_tenant;
DROP DATABASE IF EXISTS pos_test_master;
```
The next run recreates and re-migrates them.

## CI direction (future)
Add a CI job with a MySQL 8 service, create the `pos_test_*` databases, and run
`vendor/bin/phpunit -c phpunit.mysql.xml`. Keep the SQLite `phpunit.xml` job for fast unit feedback;
**MySQL is authoritative for any Edge database-correctness claim.**

## Skipped-test policy
The old `PrintRoutingFoundationTest` skips when `pdo_sqlite` is missing and hand-rolls a 3-table
mini-schema — that is why it lost the `categories` table. The MySQL suite uses the **real** migrations
and does **not** skip on SQLite/PDO availability. **Zero critical Edge tests may be skipped** in the
MySQL suite; a skip there is a failure to fix, not a pass.
