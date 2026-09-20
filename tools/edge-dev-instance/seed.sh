#!/usr/bin/env bash
# DEV EDGE INSTANCE — (re)create the disposable local database `bingoo_edge_devtest_local` with the real tenant + edge
# migrations and a PRODUCTION-SHAPED menu (parent/child categories, variants, modifiers, weighted item, deals, tables,
# waiters, void reasons, delivery, customers + addresses, network + browser printers, two terminals, cashier + manager).
# Runs the guarded seeding test in the MySQL harness. Never touches pos_test_tenant_edgewt, the LAB, or any tenant DB.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
export PATH="/d/laragon2/bin/php/php-8.3.16-Win32-vs16-x64:$PATH"
cd "$ROOT"
export DB_DATABASE=pos_test_master_edgewt
export EDGE_TEST_TENANT_DB=bingoo_edge_devtest_local
export EDGE_TEST_LOCAL_DB=pos_test_edge_local_edgewt
export EDGE_DEV_SEED=1
exec vendor/bin/phpunit -c phpunit.mysql.xml --filter EdgeDevInstanceSeedMySqlTest "$@"
