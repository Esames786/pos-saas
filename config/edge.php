<?php

use App\Services\Edge\EdgeBootstrapService;

/**
 * EDGE-RUNTIME-BOUNDARY-1 — the canonical Branch Server runtime manifest.
 *
 * ONE source of truth for: what version/schema/protocol this build speaks, which routes may execute
 * on a Branch Server (default DENY), and which files an Edge artifact may/again-never contain. The
 * runtime MODE itself is NOT here — it stays `config('app.role')` (cloud | branch_server), read via
 * App\Support\EdgeRuntime so there is exactly one role reader.
 *
 * No secrets live in this file. It is safe to ship inside an Edge artifact and is the input to the
 * future signed update channel.
 */
return [

    /*
    | Version / compatibility surface (reported by EdgeBuildInfoService + the health endpoint, and
    | consumed by the future signed updater to decide "can this build accept schema X / speak
    | protocol Y"). Bootstrap schema is reused from the existing bootstrap service — single source.
    */
    // The installed appliance reports the version of the runtime it actually runs (edge-build-manifest.json written by
    // the artifact builder); EDGE_APP_VERSION overrides only on a build host / dev tree. Never a stale hard-coded value
    // after an update.
    'app_version'             => env('EDGE_APP_VERSION') ?: (\App\Support\EdgeRuntime::artifactVersion() ?? '0.1.0-edge'),
    'git_commit'              => env('EDGE_GIT_COMMIT'), // stamped into a built artifact's manifest
    'artifact_format_version' => '1',
    'bootstrap_schema'        => EdgeBootstrapService::SCHEMA_VERSION,        // edge-bootstrap-v8 (Phase 3 approver eligibility; v7 = W6)
    'config_schema'           => EdgeBootstrapService::CONFIG_SCHEMA_VERSION, // edge-config-v1
    'sync_protocol'           => 'edge-sync-v0', // placeholder ONLY — offline sync is not built yet
    'min_php'                 => '8.2.0',
    'min_db'                  => env('EDGE_MIN_DB', '10.4'), // minimum MariaDB (appliance local DB)

    /*
    | EDGE-COMPATIBILITY-CONTRACT-1 — the capabilities THIS BUILD actually implements offline.
    | GROUNDED list: add a capability only when the feature genuinely works on a Branch Server.
    | Consumed by EdgeCompatibilityService::manifest(); the Cloud classifies anything absent here as
    | feature_unavailable_offline (explicitly — never silent partial behavior).
    */
    /*
    | OFFLINE-SYNC-ENGINE-1D — Edge -> Cloud sync transport. No secrets in git: the appliance is
    | provisioned with these at runtime (env). TLS verification is ON in the sender and is never disabled.
    */
    /*
    | OFFLINE EDGE — P0 BRANCH AUTHORITY LEASE (operating-model lock §5–6). Lease mode is ON only when the
    | appliance is provisioned with the Cloud authority URLs; without them the manual Local-Mode switch governs.
    | The Cloud renews its per-branch lease on every accepted heartbeat (Cloud clock, TTL); the appliance may
    | take over only after last_ack + TTL + skew margin on ITS clock and every readiness gate — never on one
    | failed request. Pilot posture: a supervisor confirms the takeover.
    */
    'authority' => [
        'heartbeat_url'          => env('EDGE_AUTHORITY_HEARTBEAT_URL'),   // Cloud device-authed: POST heartbeat
        'handback_url'           => env('EDGE_AUTHORITY_HANDBACK_URL'),    // Cloud device-authed: POST handback
        'ttl_seconds'            => (int) env('EDGE_AUTHORITY_TTL', 120),
        'skew_margin_seconds'    => (int) env('EDGE_AUTHORITY_SKEW_MARGIN', 30),
        'interval_seconds'       => (int) env('EDGE_AUTHORITY_INTERVAL', 20),
        'require_confirmation'   => filter_var(env('EDGE_AUTHORITY_REQUIRE_CONFIRMATION', true), FILTER_VALIDATE_BOOL),
        // Q — connection state machine thresholds (consecutive failed heartbeats). ONE failure is a blip: the
        // cashier still sees ONLINE; the lease alone decides authority (TTL + skew margin), never a counter.
        'unstable_after_failures' => (int) env('EDGE_AUTHORITY_UNSTABLE_AFTER', 2),
        'lost_after_failures'     => (int) env('EDGE_AUTHORITY_LOST_AFTER', 4),
        // Q — handback needs a STABLE connection: this many consecutive acknowledged heartbeats first.
        'handback_min_consecutive_acks' => (int) env('EDGE_AUTHORITY_HANDBACK_MIN_ACKS', 2),
        // Q — how many outbox envelopes one worker tick may drain while local (bounded work per tick).
        'drain_batch'             => (int) env('EDGE_AUTHORITY_DRAIN_BATCH', 25),
        // EDGE 0.9 (lead decisions 21 Nov 2026 §2) — the appliance KILL SWITCH for the optional Automatic Offline Takeover.
        // Effective only when the Cloud branch setting is ALSO on (AutoTakeoverEvaluator). Default false in every 0.9 package:
        // enabling it at a real site needs the owner's GO twice (Cloud setting + this env).
        'auto_takeover_allowed'   => filter_var(env('EDGE_AUTO_TAKEOVER_ALLOWED', false), FILTER_VALIDATE_BOOL),
    ],

    /*
    | Q — WARM STANDBY FRESHNESS. While the Cloud is the writer the appliance keeps ITSELF current: every accepted
    | heartbeat carries the Cloud's config revision and official-stock watermark; the worker pulls a config refresh
    | when the revision moved and a fresh stock baseline when the watermark moved. At takeover the accepted baseline
    | must EQUAL the last advertised watermark (or be no older than max_stock_age relative to the last ack) and the
    | applied config revision must equal the last advertised revision — otherwise STANDBY_FRESH_ENOUGH fails closed.
    */
    'standby' => [
        'config_refresh_url'     => env('EDGE_STANDBY_CONFIG_REFRESH_URL'), // Cloud device-authed: POST config refresh package
        'max_stock_age_seconds'  => (int) env('EDGE_STANDBY_MAX_STOCK_AGE', 300),
        // F1 — RETURNABLE-SALE WARM CACHE: the Cloud's returnable sales for the branch (window below), pulled when the
        // advertised returnable watermark moved, so a sale made ONLINE can be returned offline.
        'returnable_refresh_url' => env('EDGE_STANDBY_RETURNABLE_REFRESH_URL'),
        'supplier_finance_refresh_url' => env('EDGE_STANDBY_SUPPLIER_FINANCE_REFRESH_URL'), // F2: POST supplier-finance projection
        'purchase_return_refresh_url' => env('EDGE_STANDBY_PURCHASE_RETURN_REFRESH_URL'),   // F3: POST purchase-return projection
    ],

    /*
    | F1 — SALES RETURNS. The appliance returns only what it can PROVE: local sales it made, and Cloud sales whose
    | returnable projection is fresh (cache watermark = advertised, or refreshed after the last acknowledged heartbeat).
    | Offline refunds are CASH only (the local till); card / bank / provider refunds need the Online POS.
    */
    'returns' => [
        'cache_window_days'      => (int) env('EDGE_RETURNS_CACHE_WINDOW_DAYS', 14),  // returnable Cloud sales mirrored to the appliance
        // Gap C (18 Oct 2026): the SAME projection also mirrors Online's Recent Orders population — the branch's newest N
        // non-held, numbered sales (cancelled included) — so a STANDBY appliance's Recent Orders reads like Online's.
        // N is a superset of any Online list of 50 after the operator's order-type / data-scope narrowing.
        'recent_orders_mirror_limit' => (int) env('EDGE_RECENT_ORDERS_MIRROR_LIMIT', 200),
        'offline_refund_methods' => ['cash'],
    ],

    /*
    | F2 — SUPPLIER FINANCE PARITY. The appliance holds a READ-ONLY warm projection of the Cloud's supplier finance
    | (suppliers + authoritative payable, open purchase bills, cash/bank accounts, chart + AP family, recent ledger
    | rows, the Edge events the Cloud already applied). It never posts AP / GL / cash-bank itself: it queues
    | immutable SUPPLIER_PAYMENT / SUPPLIER_AP_JOURNAL_ADJUSTMENT events the Cloud posts exactly once.
    */
    /*
    | F3 — PURCHASE RETURN PARITY. Read-only warm projection of the branch's goods receipts (received / returned
    | quantities, unit costs), the applied Edge events; immutable PURCHASE_RETURN events the Cloud posts exactly once.
    */
    'purchase_returns' => [
        'grn_window_days' => (int) env('EDGE_PURCHASE_RETURNS_GRN_WINDOW_DAYS', 90),
        'applied_window_days' => (int) env('EDGE_PURCHASE_RETURNS_APPLIED_WINDOW_DAYS', 45),
    ],

    'supplier_finance' => [
        'ledger_window_days' => (int) env('EDGE_SUPPLIER_FINANCE_LEDGER_WINDOW_DAYS', 30),        // recent official ledger rows mirrored
        'ledger_max_rows_per_supplier' => (int) env('EDGE_SUPPLIER_FINANCE_LEDGER_MAX_ROWS', 100),
        'applied_window_days' => (int) env('EDGE_SUPPLIER_FINANCE_APPLIED_WINDOW_DAYS', 45),      // applied Edge events listed for exact pending math
        // Offline a supplier payment is internal bookkeeping (cash from the safe / a recorded bank transfer / a cheque);
        // 'card' needs a provider authorisation the appliance cannot perform → ONLINE_REQUIRED.
        'offline_payment_methods' => ['cash', 'bank_transfer', 'cheque', 'other'],
    ],

    'sync' => [
        'url'             => env('EDGE_SYNC_URL'),            // Cloud device-authed ingestion endpoint (sales)
        'returns_url'     => env('EDGE_SYNC_RETURNS_URL'),    // F1: Cloud device-authed ingestion endpoint (sales returns)
        'supplier_finance_url' => env('EDGE_SYNC_SUPPLIER_FINANCE_URL'), // F2: Cloud device-authed ingestion endpoint (supplier payments / AP journals)
        'purchase_returns_url' => env('EDGE_SYNC_PURCHASE_RETURNS_URL'),   // F3: Cloud device-authed ingestion endpoint (purchase returns)
        'reconcile_url'   => env('EDGE_SYNC_RECONCILE_URL'),  // Cloud device-authed READ-ONLY reconciliation status
        'baseline_url'    => env('EDGE_SYNC_BASELINE_URL'),   // Cloud device-authed operational-baseline issuance
        'device_id'       => env('EDGE_SYNC_DEVICE_ID'),      // this appliance public_uuid
        'device_secret'   => env('EDGE_SYNC_DEVICE_SECRET'),  // paired device bearer secret
        'connect_timeout' => (int) env('EDGE_SYNC_CONNECT_TIMEOUT', 10),
        'timeout'         => (int) env('EDGE_SYNC_TIMEOUT', 20),
        // W6: a Cloud that answers SCHEMA_UNSUPPORTED (not upgraded yet — Cloud-first deploy ordering) is retried with a
        // bounded exponential backoff (base·2^(attempts−1), capped) instead of parking the row failed_permanent.
        'schema_retry_base_seconds' => (int) env('EDGE_SYNC_SCHEMA_RETRY_BASE_SECONDS', 60),
        'schema_retry_max_seconds'  => (int) env('EDGE_SYNC_SCHEMA_RETRY_MAX_SECONDS', 900),
    ],

    /*
    | OFFLINE EDGE PRODUCTIZATION — encrypted local appliance backup. A consistent logical snapshot of the
    | appliance's RECOVERABLE local state (binding, local users, local sales, sync outbox, operational
    | baseline + cutover audit) is written temp-first, integrity-verified, encrypted (Crypt / APP_KEY — a
    | dedicated key is a productization refinement), atomically promoted, and pruned to a rolling window.
    | Config catalog (products/categories/…) is intentionally NOT backed up: it is re-derivable from the
    | Cloud bootstrap on a replacement box. Nothing here is a secret.
    */
    /*
    | OFFLINE EDGE PRODUCTIZATION — signed appliance updates. ASYMMETRIC (Ed25519): the appliance ships the
    | PUBLIC verification key ONLY; the private signing key lives on the Cloud/build side and is NEVER on the
    | appliance and NEVER in git. A tampered package/artifact/version fails verification before any mutation.
    */
    'update' => [
        'format'       => 'edge-update-v1',
        'public_key'   => env('EDGE_UPDATE_PUBLIC_KEY'),   // appliance: verify (base64 Ed25519 public)
        'signing_key'  => env('EDGE_UPDATE_SIGNING_KEY'),  // Cloud/build ONLY: sign (base64 Ed25519 secret)
        'install_root' => env('EDGE_UPDATE_INSTALL_ROOT'), // versioned runtime dirs + the atomic `current` pointer
        'allow_downgrade' => (bool) env('EDGE_UPDATE_ALLOW_DOWNGRADE', false),
    ],

    /*
    | EDGE 0.9 (stream B) — AUTO-UPDATE of an installed appliance from the Cloud (contract K6) + the device reports the setup
    | wizard and the updater send (K3 bulk enrollment, K4 status, K5 LAN profile). The BingooEdgeUpdater task runs
    | `edge:local:auto-update` every check_interval_minutes: check K6 → download over HTTPS (TLS verified, never disabled) →
    | verify (manifest sha256, package hash, every file, boundary audit, Ed25519 signature with edge.update.public_key, signing
    | key id) → stage under work_dir → APPLY ONLY when authority = standby AND outbox pending/in-flight = 0 AND (inside the
    | maintenance window OR "Update now" was requested) → health → rollback (runtime pointer when the plan had no schema
    | change; the full pre-update DB snapshot otherwise) → K4 status. Never in Local Mode, never with unsynced events.
    | No secret lives here (device credentials stay in edge.sync.*).
    */
    /*
    | EDGE 0.9 (stream B, lead decisions 21 Nov 2026 §5) — the OPTIONAL appliance endpoint POST /edge/local/enroll through which
    | a counter wizard sets the logged-in cashier's OWN Edge PIN with a Cloud-signed assertion (EdgeEnrollmentConsumer: signature,
    | expiry, replay, binding, PIN strength). Off by default; LAN + TLS only; 5/min/IP; the PIN is never logged.
    */
    'local_enroll_endpoint' => [
        'enabled' => filter_var(env('EDGE_LOCAL_ENROLL_ENDPOINT_ENABLED', false), FILTER_VALIDATE_BOOL),
    ],

    // EDGE 0.9 CONTINUITY (stream A) — read-only live-state mirror pulled in the standby phase; adoption refuses a mirror older
    // than max_mirror_age_seconds measured from the last acknowledged heartbeat (stale fails closed → normal open-shift flow).
    // live_state_url: when empty it is derived from EDGE_STANDBY_CONFIG_REFRESH_URL (…/config/refresh → …/live-state).
    'continuity' => [
        'live_state_url'          => env('EDGE_CONTINUITY_LIVE_STATE_URL'),
        'poll_seconds'            => (int) env('EDGE_CONTINUITY_POLL_SECONDS', 30),
        'max_mirror_age_seconds'  => (int) env('EDGE_CONTINUITY_MAX_MIRROR_AGE', 120),
        'connect_timeout'         => 5,
        'timeout'                 => 15,
    ],

    'auto_update' => [
        'enabled'                => filter_var(env('EDGE_AUTO_UPDATE_ENABLED', true), FILTER_VALIDATE_BOOL),
        // The central Cloud base URL (written by edge:local:pair) — every endpoint below defaults to it + the contract path.
        'cloud_base_url'         => env('EDGE_CLOUD_BASE_URL'),
        'latest_url'             => env('EDGE_AUTO_UPDATE_LATEST_URL'),            // K6 GET  /api/edge/updates/latest
        'status_url'             => env('EDGE_DEVICE_STATUS_URL'),                 // K4 POST /api/edge/device/status
        'lan_profile_url'        => env('EDGE_DEVICE_LAN_PROFILE_URL'),            // K5 POST /api/edge/device/lan-profile
        'enrollment_assertions_url' => env('EDGE_SETUP_ENROLLMENT_ASSERTIONS_URL'), // K3 POST /api/edge/setup/enrollment-assertions
        'check_interval_minutes' => max(5, (int) env('EDGE_AUTO_UPDATE_CHECK_MINUTES', 15)),
        // The owner-set maintenance window (HH:MM, may wrap midnight); a K6 response carrying maintenance_window overrides it.
        'window_start'           => env('EDGE_AUTO_UPDATE_WINDOW_START', '03:00'),
        'window_end'             => env('EDGE_AUTO_UPDATE_WINDOW_END', '05:00'),
        'window_tz'              => env('EDGE_AUTO_UPDATE_WINDOW_TZ'),             // null = the appliance timezone
        // Downloads, staged packages, DB snapshots, the state file and the "Update now" request (default <data_root>/updates).
        'work_dir'               => env('EDGE_AUTO_UPDATE_DIR'),
        'max_download_bytes'     => (int) env('EDGE_AUTO_UPDATE_MAX_BYTES', 2147483647),
        'download_timeout'       => (int) env('EDGE_AUTO_UPDATE_DOWNLOAD_TIMEOUT', 1800),
        'keep_staged'            => max(1, (int) env('EDGE_AUTO_UPDATE_KEEP_STAGED', 2)),
        'health_wait_seconds'    => max(10, (int) env('EDGE_AUTO_UPDATE_HEALTH_WAIT', 150)),
        // How the updater restarts the supervised tasks around the switch: 'tasks' (Register-EdgeServices.ps1 Stop/Start with
        // -Exclude BingooEdgeUpdater -KeepEnabled, from the runtime being started) on an appliance; 'none' on a dev tree / tests.
        'service_control'        => env('EDGE_AUTO_UPDATE_SERVICE_CONTROL', 'tasks'),
        // An "Update now" request (status page / Cloud flag) stays valid this long; afterwards the window rule applies again.
        'update_now_ttl_minutes' => max(15, (int) env('EDGE_AUTO_UPDATE_NOW_TTL', 240)),
    ],

    'backup' => [
        'path'      => env('EDGE_BACKUP_PATH', storage_path('app/edge-backups')),
        'retention' => (int) env('EDGE_BACKUP_RETENTION', 24), // rolling copies to keep (hourly ≈ 1 day)

        // Recovery wrapping key — provisioned per branch from the Cloud recovery authority, NEVER the
        // APP_KEY and never committed to git. A replacement machine recovers this key independently of the
        // dead appliance, so its backups stay decryptable. base64 of 32 bytes.
        'recovery_key'    => env('EDGE_BACKUP_RECOVERY_KEY'),
        'recovery_key_id' => env('EDGE_BACKUP_RECOVERY_KEY_ID', 'k1'),
        // Retained older wrapping keys for recovering older backups after rotation: JSON { key_id: base64 }.
        'retired_keys'    => json_decode((string) env('EDGE_BACKUP_RETIRED_KEYS', '{}'), true) ?: [],
    ],

    'capabilities' => [
        'local_auth',                 // EDGE-LOCAL-AUTH-1 (Edge credentials, Argon2id, epoch-fenced)
        'local_pos_cash_sales',       // EDGE-LOCAL-POS-1 (cash quick_sale/takeaway/dine-in runtime)
        'held_sales',                 // held/settle/cancel + Add Round
        'dine_in_tables',             // floors/tables/sessions
        'kot',                        // KOT batches + per-category routing
        'local_printing',             // EDGE-LOCAL-PRINT-1 (lease-safe local print worker)
        'operational_stock_baseline', // EDGE operational stock (device-bound baseline; not official stock)
        'local_manager_approval',     // offline manager re-auth (verifyManager)
        'config_refresh',             // EDGE-CONFIG-REFRESH-1 (revisioned upsert/tombstone refresh)
        // W4 (audit side finding: shipped but undeclared). Names are deliberately NOT the Cloud's broader feature words
        // ('returns' / 'purchasing' in EdgeCompatibilityService::CLOUD_OFFLINE_FEATURES): each declares exactly what this
        // build does offline. Mapping them onto the Cloud classifier is a Team 6 / coordinator change (W4 report).
        'sales_returns_cash',         // F1: returns of local + warm-cached Online sales, CASH refund from this till only
        'supplier_finance_events',    // F2: supplier payment (cash/bank/cheque/other) + AP journal as pending-sync events
        'purchase_returns_grn',       // F3: purchase return against a mirrored goods receipt, posted pending sync (no drafts)
        'quick_report',               // Quick Report view / print here / network on the canonical engine (e-mail = Internet)
        'shift_denomination_count',   // W4: shift close with the Online denomination count + closing notes (no shortage voucher)
        'offline_customer_create',    // PHASE 4: add customer / address at the till (customer_uuid + phone_normalized + address_uuid), new_customer envelope v3 when the Cloud advertises customer_create
        'local_reminder_pause',       // D2 (20 Oct 2026): POS-REMINDER-PAUSE-1 on the local shift row (shared ReminderPauseService; shift-scoped, never crosses a boundary)
    ],

    /*
    | EDGE-LOCAL-AUTH-1 — Cloud-authorised one-time enrollment assertion (Ed25519). The CLOUD holds
    | the private signing key ONLY (EDGE_ENROLLMENT_SIGNING_KEY, base64); a BRANCH SERVER holds the
    | public verification key ONLY (EDGE_ENROLLMENT_PUBLIC_KEY, base64). Never both on one host, never
    | a shared HMAC secret. The Cloud issuer fails closed if its signing key is absent; the Edge
    | consumer fails closed if the public key is absent. TTL is short.
    */
    'enrollment' => [
        'signing_key'   => env('EDGE_ENROLLMENT_SIGNING_KEY'),  // CLOUD only (private, base64)
        'public_key'    => env('EDGE_ENROLLMENT_PUBLIC_KEY'),   // BRANCH SERVER only (public, base64)
        'assertion_ttl' => (int) env('EDGE_ENROLLMENT_TTL', 900), // seconds
    ],

    /*
    | Restricted route boundary (DEFENCE-IN-DEPTH, first line). In branch_server runtime, ONLY routes
    | whose name matches one of these patterns may execute; everything else is denied (default DENY).
    | It is DELIBERATELY minimal this sprint because offline POS/auth/sales are not built — do not add
    | future POS routes here in anticipation; each future Edge sprint expands this on purpose.
    | '*' is a suffix wildcard on the route name.
    */
    'route_allowlist' => [
        // EXPLICIT names (no wildcard) — a future edge.local.* route is NOT auto-exposed; each must
        // be added here deliberately. Default remains DENY.
        'edge.local.health',
        'edge.local.ready',
        'edge.local.build-info',
        // EDGE-LOCAL-AUTH-1 — local login/logout/status (Edge-credential auth; NOT POS).
        'edge.local.auth.login',
        'edge.local.auth.login.post',
        'edge.local.auth.logout',
        'edge.local.auth.status',
        // EDGE-LOCAL-POS-1 — the branch-local POS surface (each name deliberate; edge.auth + edge.branch).
        'edge.local.pos.screen',                          // THE cashier page = tenant.pos.index + the Edge runtime (Phase 3 Stage A)
        'edge.local.pos.terminals',
        'edge.local.pos.terminal.select',
        'edge.local.pos.shift.status',
        'edge.local.pos.shift.summary',
        'edge.local.pos.sync.summary',
        'edge.local.pos.shift.open',
        'edge.local.pos.shift.close',
        'edge.local.pos.reminder.pause',                  // D2 (20 Oct 2026): POS-REMINDER-PAUSE-1 twin — local shift row, shared ReminderPauseService
        'edge.local.pos.sales.store',
        'edge.local.pos.preview.bill',
        // EDGE-LOCAL-POS-1 restaurant layer (each name deliberate).
        'edge.local.pos.restaurant.board',
        'edge.local.pos.restaurant.table.open',
        'edge.local.pos.restaurant.table.reservation',
        'edge.local.pos.restaurant.table.reserve',
        'edge.local.pos.restaurant.table.unreserve',
        'edge.local.pos.restaurant.session.close',
        'edge.local.pos.held.index',
        'edge.local.pos.held.show',
        'edge.local.pos.void-reasons',
        'edge.local.pos.customers.search',
        'edge.local.pos.customers.quick-store',      // EDGE PHASE 4 — offline add-customer (Online quick-store twin)
        'edge.local.pos.customers.addresses.store',  // EDGE PHASE 4 — offline add-address (Online add-address twin)
        'edge.local.pos.returns.search',   // F1 sales returns
        'edge.local.pos.returns.sale',
        'edge.local.pos.returns.store',
        'edge.local.pos.returns.show',
        'edge.local.pos.suppliers.screen',  // F2 supplier finance (Suppliers → Supplier Ledger → Record Payment; General Journal)
        'edge.local.pos.suppliers.options',
        'edge.local.pos.suppliers.ledger',
        'edge.local.pos.suppliers.payments.store',
        'edge.local.pos.finance.events.show',
        'edge.local.pos.finance.journal.screen',
        'edge.local.pos.finance.journal.options',
        'edge.local.pos.finance.journal.store',
        'edge.local.pos.purchase-returns.screen',  // F3 purchase returns (source GRN → received lines → post)
        'edge.local.pos.purchase-returns.options',
        'edge.local.pos.purchase-returns.grn',
        'edge.local.pos.purchase-returns.store',
        'edge.local.pos.purchase-returns.show',
        'edge.local.pos.held.store',
        'edge.local.pos.held.kot',
        'edge.local.pos.held.settle',
        'edge.local.pos.held.cancel',
        'edge.local.pos.held.split',
        'edge.local.pos.manager.verify',
        // EDGE-CASHIER-UI-4 — printing surface.
        'edge.local.pos.sales.receipt',
        'edge.local.pos.sales.kot-reprint',
        'edge.local.pos.print-jobs.index',
        'edge.local.pos.print-jobs.document',
        'edge.local.pos.print-jobs.printed',
        'edge.local.pos.print-jobs.retry',
        // EDGE-CASHIER-UI-5 — Quick Report surface.
        'edge.local.pos.quick-report.options',
        'edge.local.pos.quick-report.view',
        'edge.local.pos.quick-report.network',
        'edge.local.pos.quick-report.email',
        // P4 — the ONE operator/admin health page (non-secret).
        'edge.local.pos.health.view',
        // KHATRI OWNER CONTROL (21 Nov 2026): Switch to Offline / Return to Online on the health page (EdgeLocalAuthorityControlController).
        'edge.local.pos.authority.switch-offline',
        'edge.local.pos.authority.return-online',
        // EDGE 0.9 (21 Nov 2026): status-page "Update now" (stream B) and the counter self-enrolment endpoint (stream B / D).
        'edge.local.pos.updates.update-now',
        'edge.local.enroll',
        // ── W1 (Team 1, parity directive 20 Sep 2026) — shell (each name deliberate; mirrors routes/edge_runtime.php W1) ──
        'edge.local.assets',   // whitelisted public/assets streamer (EdgeLocalAssetController; unauthenticated, no session)
        'edge.local.storage',  // W-C: whitelisted storage/app/public image streamer (EdgeLocalAssetController::storage; images only, no session)
        // ── W3 (Team 3, parity directive 20 Sep 2026) — tables & order lifecycle (each name deliberate; mirrors routes/edge_runtime.php W3) ──
        'edge.local.pos.restaurant.sessions.index',
        'edge.local.pos.restaurant.session.show',
        'edge.local.pos.restaurant.session.bill-preview',
        'edge.local.pos.restaurant.session.bill-requested',
        'edge.local.pos.restaurant.session.move',
        'edge.local.pos.restaurant.session.merge',
        'edge.local.pos.held.reattach-table',
        'edge.local.pos.recent-sales',
        // ── W4 (Team 4, parity directive 20 Sep 2026) — shifts / finance list+detail screens, Quick Report saved selection
        //    (each name deliberate; mirrors routes/edge_runtime.php W4; each action enforces the Online route permission) ──
        'edge.local.pos.shifts.index',
        'edge.local.pos.shifts.show',
        'edge.local.pos.sales-returns.index',
        'edge.local.pos.sales-returns.show',
        'edge.local.pos.supplier-payments.index',
        'edge.local.pos.supplier-payments.show',
        'edge.local.pos.finance.manual-journals.index',
        'edge.local.pos.finance.manual-journals.show',
        'edge.local.pos.purchase-returns.list',
        'edge.local.pos.purchase-returns.detail',
        'edge.local.pos.quick-report.settings',
        'edge.local.pos.quick-report.save-settings',
        // ── W5 (Team 5, parity directive 20 Sep 2026) — printing (each name deliberate; mirrors routes/edge_runtime.php W5) ──
        'edge.local.pos.sales.kot',
        'edge.local.pos.sales.reminders.confirm',
        'edge.local.pos.print-jobs.reminder-reprint',
        'edge.local.pos.print-jobs.dismiss',
        'edge.local.pos.sales.printing.retry',
        'edge.local.pos.bill-preview.document',
        'edge.local.pos.print-preferences',
        // ── W-B (next release — ONE shared cashier view; each name deliberate; mirrors routes/edge_runtime.php W-B) ──
        'edge.local.pos.shared',                          // Phase 2 alias of edge.local.pos.screen — Stage B: a 301 redirect to /edge/local/pos
        'edge.local.pos.server-time',                     // Online /api/server-time twin
        'edge.local.pos.totals.quote',                    // Online /api/pos/totals/quote twin (O16)
        'edge.local.pos.promotions.quote',                // Online /api/pos/promotions/quote twin (O17)
        'edge.local.pos.restaurant.board.html',           // Online /api/pos/table-board twin ({ok, html}, shared partial)
        'edge.local.pos.restaurant.session.open-orders',  // Online /api/pos/table-sessions/{s}/open-orders twin (O14)
        'edge.local.pos.shifts.create-page',              // separate screens: the SAME tenant views (owner requirement)
        'edge.local.pos.shifts.store-page',
        'edge.local.pos.shifts.close-page',
        'edge.local.pos.shifts.close-store-page',
        'edge.local.pos.shifts.index-page',
        'edge.local.pos.shifts.show-page',
        'edge.local.pos.sales-returns.create-page',
        'edge.local.pos.sales-returns.store-page',
        'edge.local.pos.sales-returns.index-page',
        'edge.local.pos.sales-returns.show-page',
        'edge.local.pos.split-bill.page',
        'edge.local.pos.split-bill.store-page',
    ],

    /*
    | EDGE-LOCAL-RUNTIME-1 (Section E) — Branch Server CLI boundary (DEFAULT DENY). Now that a real
    | local database exists, restricting HTTP routes is not enough: a Cloud-only Artisan command
    | (tenant provisioning, system:reset, demo reset, master seed, central sync, finance/mfg jobs,
    | Cloud backup, or a raw tenant migrate against the local DB) must not be runnable on the
    | appliance. On a branch_server runtime ONLY the names below may execute; everything else is
    | refused. Raw destructive migrate/reset is intentionally NOT here — the guarded edge:local:*
    | commands perform the appliance's schema/import work. '*' is a suffix wildcard on the command name.
    */
    'cli_allowlist' => [
        // Guarded Edge-local operator commands.
        'edge:local:db-init',
        'edge:local:schema-upgrade', // EDGE-SCHEMA-UPGRADE-1: non-destructive upgrade of a live appliance
        'edge:local:bootstrap-import',
        'edge:local:status',
        'edge:local:enroll',
        // EDGE-LOCAL-PRINT-1 — the local lease-safe physical print worker (deliberately allowlisted).
        'edge:local:print-worker',
        'edge:local:print-status',
        'edge:local:sync-send', // OFFLINE-SYNC-ENGINE-1D: drain the sale outbox to Cloud
        'edge:local:sync-status', // OFFLINE-SYNC-ENGINE-1E: read-only sync/exception/cutover status
        'edge:local:backup', // PRODUCTIZATION: encrypted local appliance backup
        'edge:local:restore', // PRODUCTIZATION: guarded restore of a local appliance backup
        'edge:local:recovery-key', // P5B §3: Cloud-escrowed backup recovery material → appliance.env (ids printed, never material)
        'edge:local:update', // PRODUCTIZATION: apply a signed appliance update
        'edge:local:authority-heartbeat', // P0 lease: renew the Cloud's lease / record a missed beat
        'edge:local:authority-status',    // P0 lease: gates + state (read-only)
        'edge:local:authority-takeover',  // P0 lease: supervised Local Mode activation (fail closed on any gate)
        'edge:local:authority-handback',  // P0 lease: return authority to the Cloud when sync is clean
        'edge:local:authority-worker',    // Q: the ONE supervised heartbeat / state-machine / standby-freshness worker
        // P4 WINDOWS APPLIANCE — packaging / install / operate (no business features).
        'edge:local:serve',          // loopback PHP web backend (one per BingooEdgeWebN task; the TLS gateway fronts them)
        'edge:local:service-plan',   // the deterministic Windows service plan + rendered gateway config (JSON for the installer)
        'edge:local:health',         // the ONE operator health command (non-secret)
        'edge:local:pair',           // first-boot: exchange a one-time pairing code for the device identity (secret → env file, never argv)
        'edge:local:bootstrap-pull', // first-boot: pull + verify + import the Cloud bootstrap snapshot, then acknowledge
        'edge:local:gateway-cert',   // import the LAN TLS certificate (PFX → PEM files under the data root) for the gateway
        'edge:local:uninstall-data', // EXPLICIT data removal at uninstall (typed confirmation; refuses while events are unsynced)
        // 0.8.3 SERVICE RELIABILITY (read-only / wait-only operator commands).
        'edge:local:wait-db',        // bounded, classified wait for the Edge-local database (task command lines, updater, reboot drill)
        'edge:local:worker-status',  // singleton worker slots + recorded process identity (+ OS liveness with --probe)
        // EDGE 0.9 (stream B) — setup wizard + auto-update (read-only lists, device reports, the gated signed updater).
        'edge:local:users',              // wizard: local users (id, name, employee code, roles, enrolled) + K3 bulk assertions to temp files
        'edge:local:report-status',      // wizard/updater: K4 device status phase to the Cloud (non-secret)
        'edge:local:report-lan-profile', // wizard: K5 LAN profile (hostname, IP, port, PUBLIC CA certificate) to the Cloud
        'edge:local:auto-update',        // BingooEdgeUpdater: check K6 → verify → stage → apply ONLY in standby / outbox 0 / window or Update now
        // Framework cache/runtime operations the appliance explicitly needs.
        'config:cache', 'config:clear',
        'route:cache', 'route:clear',
        'view:cache', 'view:clear',
        'event:cache', 'event:clear',
        'optimize', 'optimize:clear',
        'package:discover',
        'storage:link',
        // Harmless introspection + the framework internals artisan itself invokes.
        'about', 'list', 'help', 'completion', 'env', 'inspire',
    ],

    /*
    | Restricted artifact contents. The builder copies ONLY these top-level paths (allowlist), then
    | prunes the exclude globs, then FAILS if any forbidden pattern survives (secret/source audit).
    | We ship the Laravel app + vendor closure so the appliance can boot and answer its health
    | endpoint; finer-grained exclusion of cloud-only application classes is deferred to a later Edge
    | packaging sprint and is defended at runtime by the route boundary until then.
    */
    'artifact' => [
        'include' => [
            'app',
            'bootstrap',
            'config',
            'database/migrations',
            // P5: a canonical tenant migration RUNS a seeder (schema coherence on a fresh appliance) — ship EXACTLY that seeder
            // and the model it needs (see 'keep' below); never a whole seeders directory, never master/demo seeders.
            'database/seeders/Tenant/CateringServiceTimePresetSeeder.php',
            'lang',
            'public',
            'resources',
            'routes',
            // EDGE-LOCAL-PRINT-1 Slice 2: ONLY the reviewed Edge appliance scripts ship (supervision
            // Install/Stop/Uninstall Scheduled Task + TLS helpers) — the include list is a hard
            // allowlist and previously dropped them entirely; the whole scripts/ tree would over-ship
            // unreviewed Cloud/dev scripts. Non-secret; the forbidden scan still runs.
            'scripts/edge',
            'vendor',
            'artisan',
            'composer.json',
            'composer.lock',
        ],
        /*
        | KEEP — files the exclude globs would prune but a canonical tenant MIGRATION needs to apply the schema on a fresh
        | appliance (P5 release-shape finding: 2026_08_24_000002_create_catering_service_time_presets runs this seeder, which
        | loads this model). Data-preset classes only — no Catering runtime logic. EdgeArtifactTest derives the list from the
        | migrations and fails when a new migration references a seeder that is not kept.
        */
        'keep' => [
            'database/seeders/Tenant/CateringServiceTimePresetSeeder.php',
            'app/Models/Tenant/CateringServiceTimePreset.php',
        ],
        'exclude' => [
            '.git', '.git/*',
            '.github', '.github/*',
            '.env', '.env.*',
            'tests', 'tests/*',
            'docs', 'docs/*',
            'node_modules', 'node_modules/*',
            // PRODUCTIZATION — physically remove Cloud-only subsystems the Branch Server never executes
            // (routes/web.php loads only edge_runtime.php on a branch_server; these are reachable only via
            // the Cloud route groups). Prefix-matched by EdgeArtifactBuilder::isExcluded. See the exclusion
            // analysis: none is referenced by any Edge runtime path.
            'app/Services/Catering',
            'app/Services/Saas',
            'app/Services/Central',
            'app/Services/Manufacturing',
            'app/Services/Purchasing',
            'app/Http/Controllers/Central',
            'app/Http/Controllers/Tenant/Manufacturing',
            'app/Http/Controllers/Tenant/Reports',
            // Cloud-side Edge INGESTION/ISSUANCE authorities — they live under app/*/Edge but the appliance
            // SENDS to them, it never hosts them (EdgeInboundSaleIngestionService self-guards it must never
            // run on a Branch Server). Excluded by exact path so the surrounding Edge runtime stays.
            'app/Services/Edge/EdgeInboundSaleIngestionService.php',
            'app/Services/Edge/EdgeFinancePostingVerifier.php',
            'app/Services/Edge/EdgeBaselineIssuanceService.php',
            'app/Http/Controllers/Edge/EdgeSyncIngestionApiController.php',
            'app/Http/Controllers/Edge/EdgeSyncReconciliationApiController.php',
            'app/Http/Controllers/Edge/EdgeBaselineApiController.php',
            'app/Models/Tenant/EdgeInboundSaleIngestion.php',
            // Cloud AP posting — sits beside the KEPT JournalPostingService, so excluded by exact path only.
            'app/Services/Finance/SupplierPayableService.php',
            // F1 / P4 boundary gate — the Cloud return ingestion + returnable projection + the Cloud heartbeat API
            // (with its standby advertiser) are hosted by the Cloud only; the appliance SENDS to them.
            'app/Services/Edge/EdgeInboundReturnIngestionService.php',
            'app/Services/Edge/EdgeReturnableSaleProjectionService.php',
            'app/Services/Edge/EdgeStandbyAdvertiser.php',
            'app/Http/Controllers/Edge/EdgeInboundReturnApiController.php',
            'app/Http/Controllers/Edge/EdgeReturnableCacheApiController.php',
            'app/Http/Controllers/Edge/EdgeAuthorityApiController.php',
            'app/Models/Tenant/EdgeInboundReturnIngestion.php',
            // F2 — Cloud supplier-finance authority stays physically out of the appliance artifact.
            'app/Services/Finance/ManualJournalService.php',
            'app/Services/Edge/EdgeSupplierFinanceProjectionService.php',
            'app/Services/Edge/EdgeInboundSupplierFinanceIngestionService.php',
            'app/Http/Controllers/Edge/EdgeSupplierFinanceCacheApiController.php',
            'app/Http/Controllers/Edge/EdgeInboundSupplierFinanceApiController.php',
            'app/Models/Tenant/EdgeInboundSupplierFinanceIngestion.php',
            // F3 — Cloud purchase-return authority, projection and ingestion stay out of the appliance.
            'app/Services/Edge/EdgePurchaseReturnProjectionService.php',
            'app/Services/Edge/EdgeInboundPurchaseReturnIngestionService.php',
            'app/Http/Controllers/Edge/EdgeInboundPurchaseReturnApiController.php',
            'app/Http/Controllers/Edge/EdgePurchaseReturnCacheApiController.php',
            'app/Models/Tenant/EdgeInboundPurchaseReturnIngestion.php',
            // P5B §3 — the Cloud BACKUP RECOVERY AUTHORITY (escrow, retrieval, rotation, audit) is hosted by the Cloud
            // only; the appliance carries the client + provisioner + edge:local:recovery-key and never the escrow.
            'app/Services/Edge/EdgeBackupRecoveryAuthority.php',
            'app/Http/Controllers/Edge/EdgeBackupRecoveryApiController.php',
            'app/Console/Commands/EdgeRecoveryKeyCommand.php',
            'app/Models/Master/EdgeBackupRecoveryKey.php',
            'app/Models/Master/EdgeBackupRecoveryAudit.php',
            'app/Http/Controllers/Tenant/Finance/ManualJournalController.php',
            'resources/views/tenant/finance/manual-journals',
            // EDGE 0.9 (21 Nov 2026) — Cloud-only setup/release/live-state/device-status authority (stream C) and the
            // Cloud-rendered counter-setup templates (stream D) never ship on the appliance.
            'app/Services/Edge/Cloud',
            'app/Http/Controllers/Edge/EdgeSetupApiController.php',
            'app/Http/Controllers/Edge/EdgeDeviceStatusApiController.php',
            'app/Http/Controllers/Edge/EdgeUpdatesApiController.php',
            'app/Http/Controllers/Edge/EdgeLiveStateApiController.php',
            'app/Console/Commands/EdgeReleasePublishCommand.php',
            'resources/edge/counter-setup',
            // CANONICAL REHEARSAL Phase E (30 Oct 2026, C-7): VOID-PURGE-1/2/3 - the Owner's permanent deletion of a voided
            // finance entry is a Cloud back-office feature (Master switch void_purge; routes only in the Cloud tenant group;
            // VoidPurgeService imports the EXCLUDED CateringAdvance / CateringEvent / SupplierPayment models). No Edge runtime
            // path references any of the four; the shared PurgedNumbers helper and the PurgedEntry model stay (lazy, table-guarded).
            'app/Services/Finance/VoidPurgeService.php',
            'app/Services/Finance/VoidPurgeNeedsConfirmation.php',
            'app/Http/Controllers/Tenant/Finance/VoidPurgeController.php',
            'resources/views/tenant/finance/voided-entries',

            // POST-RECONCILIATION — the canonical merge brought the full Cloud business domains into the dev
            // branch. The Branch Server never executes Catering / manufacturing / purchasing-AP / SaaS billing,
            // so they are physically excluded from the DISTRIBUTED artifact (basename globs + view/asset dir
            // prefixes; the physical scan + branch-server boot proof enforce it). Migrations are KEPT (schema
            // coherence) and Reports SERVICES are KEPT (the offline Quick Report reuses the canonical report
            // engine). Product::cateringProfile()/isManufacturingOnly() are lazy method bodies — no boot autoload.
            'Catering*',
            'Manufacturing*', 'HasManufacturingPostingStatus*', 'ProductionOrder*', 'FinishedGood*', 'Wip*', 'MaterialRequisition*',
            'Purchase*', 'Supplier*', 'GoodsReceipt*',
            'Subscription*', 'TenantBilling*', 'SelfSignup*', 'ExpireSubscriptions*', 'EnsureTenantSubscriptionAccess*',
            'app/Http/Controllers/Tenant/Catering',
            'resources/views/tenant/catering',
            'resources/views/tenant/manufacturing',
            'resources/views/tenant/suppliers',
            'resources/views/tenant/billing',
            'resources/views/tenant/purchase-bills', 'resources/views/tenant/purchase-orders', 'resources/views/tenant/purchase-returns',
            'resources/views/tenant/reports/purchases',
            'public/assets/img/supplier',
            'app/Console/Commands/DispatchCateringRemindersCommand.php',
            // W6 reconcile (canonical b529c95): Catering job + support classes sit under basenames / directories the
            // globs above do not reach (SendCateringCustomerMailJob, CourseOrder); no Edge runtime path references them.
            'app/Jobs/Catering',
            'app/Support/Catering',
            'catering-*',
            'resources/views/emails/catering',
            'resources/views/central/subscription-requests',
            'resources/views/tenant/supplier-payments',
            // CANONICAL MERGE (D8, 19 Oct 2026) — ONLINE-ORDERING-1 is a Cloud-only domain (the customer's ordering website, the
            // POS website-orders inbox, delivery areas / places + OpenStreetMap lookups, the WhatsApp notifier, the menu / branding
            // importers, the 1% usage billing). A website order needs the Cloud; the Branch Server never hosts any of it
            // (PosRuntime capability websiteOrders is OFF on Edge — owner D4). KEPT on purpose: App\Support\OnlineOrdering (the
            // plan-module check the shared cashier view reads), the Web* / DeliveryZone models (schema coherence, never
            // instantiated on the appliance) and the WebsiteDomainOnly middleware (a lazy alias string the branch-server route
            // set never resolves). The tenant manifest records AreaLocator as the CLOUD_ONLY class dependency of the
            // delivery-zone-places migration, so this exclusion is also what EdgeTenantMigrationManifestTest proves.
            'app/Services/OnlineOrdering',
            'app/Http/Controllers/Website',
            'app/Http/Controllers/Tenant/OnlineOrdering',
            'app/Console/Commands/OnlineOrdering*.php',
            'resources/views/website',
            'resources/views/tenant/online-ordering',
            'storage/logs/*',
            'storage/framework/cache/*',
            'storage/framework/sessions/*',
            'storage/framework/views/*',
            'storage/app/backups/*',
            'bootstrap/cache/*',
            'tools/print-agent/dist/*',
            // vendor dev cruft (docs/tests/CI) — NOT secrets, just reduces artifact bloat/surface.
            // NOTE: a release build must run `composer install --no-dev` first so dev packages
            // (phpunit/mockery/etc.) are absent entirely; this only trims incidental cruft.
            '*/docs/*', '*/tests/*', '*/test/*', '*/.github/*', '*/examples/*',
            '*.pem', '*.key', '*.pfx', '*.p12', '*.crt',
            '*.sql', '*.dump', '*.sqlite',
            'id_rsa', 'id_rsa.*', 'id_ed25519', 'id_ed25519.*',
            '.psysh_history', '.bash_history',
        ],
        /*
        | Forbidden — if ANY packaged file matches one of these, the build FAILS. These are the
        | never-ship classes of file (secrets, VCS, dev, dumps). The scan reports paths only, never
        | file contents.
        */
        /*
        | Empty writable runtime directories the appliance needs to boot. An allowlisted file-copy
        | omits empty dirs, so the builder creates these fresh (empty — never copied dev logs/cache).
        */
        'runtime_dirs' => [
            'storage/framework/cache/data',
            'storage/framework/sessions',
            'storage/framework/views',
            'storage/framework/testing',
            'storage/logs',
            'storage/app/public',
            'bootstrap/cache',
        ],
        'forbidden' => [
            '#(^|/)\.env(\..+)?$#',
            '#(^|/)\.git(/|$)#',
            '#\.(pem|key|pfx|p12|crt)$#i',
            '#(^|/)id_rsa(\..+)?$#',
            '#(^|/)id_ed25519(\..+)?$#',
            '#\.(sql|dump|sqlite)$#i',
            '#(^|/)tests(/|$)#',
            // Forbid copied LOG/BACKUP content, but allow the empty runtime-dir .gitkeep marker the
            // builder creates for storage/logs.
            '#(^|/)storage/logs/(?!\.gitkeep$).#',
            '#(^|/)storage/app/backups/(?!\.gitkeep$).#',
            '#FakePrinter\.exe$#i',
            '#(^|/)\.psysh_history$#',
            // P5B §2 — release-signing custody: neither the keystore nor its passphrase may ever be in an artifact.
            '#keystore[^/]*\.json$#i',
            '#\.(keystore|passphrase)$#i',
        ],
    ],

    /*
    | LAN name contract for the future Branch Server appliance. mDNS `.local` is NOT reliable on every
    | Windows LAN, so the PILOT mechanism is a DHCP-reserved IP + a hosts-file/router-DNS entry. The
    | certificate SAN (see scripts/edge) must cover BOTH the hostname and the reserved IP.
    */
    /*
    | P4 WINDOWS APPLIANCE — web runtime + TLS gateway + configuration storage + print architecture lock.
    |
    | The PHP web backends (edge:local:serve, one per supervised BingooEdgeWebN task) bind LOOPBACK ONLY. The LAN
    | listener is the TLS gateway (nginx) that terminates HTTPS with the branch-CA server certificate and proxies to
    | the loopback backends; port 80 only redirects. Plain HTTP on the LAN is never the normal mode (locked contract).
    */
    'web' => [
        'bind'      => env('EDGE_WEB_BIND', '127.0.0.1'),
        'port_base' => (int) env('EDGE_WEB_PORT_BASE', 8090),
        'workers'   => max(1, min(8, (int) env('EDGE_WEB_WORKERS', 2))),
    ],
    'gateway' => [
        'kind'       => 'nginx',
        'https_port' => (int) env('EDGE_GATEWAY_HTTPS_PORT', 443),
        'http_port'  => (int) env('EDGE_GATEWAY_HTTP_PORT', 80), // redirect-only listener
    ],
    /*
    | 0.8.3 SERVICE RELIABILITY — supervised worker start-up. After a Windows boot the AtStartup tasks run in
    | parallel with MySQL's own start; every worker waits (bounded, classified: EdgeLocalDbReadiness) for the Edge-local
    | database before touching its singleton slot, then exits 1 so the task's restart-on-failure re-runs it. A
    | NON-retryable class (wrong credentials, unknown database, schema not initialised) ends the wait at once.
    */
    'workers' => [
        'db_startup_wait_seconds' => max(0, (int) env('EDGE_WORKER_DB_STARTUP_WAIT', 180)),
        'db_startup_interval_seconds' => max(1, (int) env('EDGE_WORKER_DB_STARTUP_INTERVAL', 3)),
    ],
    /*
    | 0.8.3 — printer reachability in health/print-status: a bounded TCP connect per Edge-capable network printer
    | (never a byte written). Disable only for diagnostics; failed print jobs surface as problems regardless.
    */
    'print' => [
        'reachability_probe' => filter_var(env('EDGE_PRINT_REACHABILITY_PROBE', true), FILTER_VALIDATE_BOOL),
        'reachability_timeout_seconds' => max(2.5, (float) env('EDGE_PRINT_REACHABILITY_TIMEOUT', 3.0)), // >= 2.5 s: Windows reports REFUSED only after its SYN retransmit (measured 2.0 s)
    ],
    /*
    | Where the appliance keeps its state OUTSIDE the versioned runtime (survives updates; excluded from uninstall
    | unless data removal is explicitly chosen): <data_root>/config/appliance.env (the ONLY secrets file),
    | <data_root>/certs, <data_root>/logs, <data_root>/backups, <data_root>/runtime (pointer + versions).
    */
    'appliance' => [
        'data_root' => env('EDGE_DATA_ROOT'),
        'env_file'  => 'appliance.env',
    ],
    /*
    | PRINT ARCHITECTURE LOCK (P4 §3). Network (LAN) printers: Online = the Cloud print path; Local Mode = the
    | appliance prints DIRECTLY to the printer IP through its supervised local print worker. There is never a
    | second Edge agent for a network printer, and never two agents on one printer. USB printers are served by
    | the ONE Bingoo Print Agent per host, which is Cloud-only today → USB printing is ONLINE_REQUIRED for the
    | pilot until the dual-mode agent exists.
    */
    'print_architecture' => [
        'network_printer_edge_direct'           => true,
        'second_edge_agent_for_network_printer' => false,
        'usb_dual_mode_agent'                   => 'not_built',
        'usb_status_for_pilot'                  => 'ONLINE_REQUIRED',
    ],

    /*
    | TEST-ONLY seams. Every key here is inert unless APP_ENV=testing; production reads false. The in-process
    | MySQL proofs flip the fail_after_* keys through config(); the cross-process clean-machine install proof
    | (a real Cloud `php -S` + a real installed appliance) needs assume_entitled from the environment because a
    | separate process cannot swap the entitlement service instance.
    */
    'testing' => [
        'assume_entitled' => env('APP_ENV') === 'testing' && filter_var(env('EDGE_TESTING_ASSUME_ENTITLED', false), FILTER_VALIDATE_BOOL),
    ],

    'lan' => [
        'hostname'       => env('EDGE_LAN_HOSTNAME', 'bingoo-edge.local'),
        'reserved_ip'    => env('EDGE_LAN_IP'), // DHCP-reserved; set per branch
        'name_mechanism' => env('EDGE_LAN_NAME_MECHANISM', 'hosts_file'), // hosts_file | router_dns | mdns
    ],
];
