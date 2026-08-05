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
