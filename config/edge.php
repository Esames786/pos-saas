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
    'app_version'             => env('EDGE_APP_VERSION', '0.1.0-edge'),
    'git_commit'              => env('EDGE_GIT_COMMIT'), // stamped into a built artifact's manifest
    'artifact_format_version' => '1',
    'bootstrap_schema'        => EdgeBootstrapService::SCHEMA_VERSION,        // edge-bootstrap-v5
    'config_schema'           => EdgeBootstrapService::CONFIG_SCHEMA_VERSION, // edge-config-v1
    'sync_protocol'           => 'edge-sync-v0', // placeholder ONLY — offline sync is not built yet
    'min_php'                 => '8.2.0',

    /*
    | EDGE-COMPATIBILITY-CONTRACT-1 — the capabilities THIS BUILD actually implements offline.
    | GROUNDED list: add a capability only when the feature genuinely works on a Branch Server.
    | Consumed by EdgeCompatibilityService::manifest(); the Cloud classifies anything absent here as
    | feature_unavailable_offline (explicitly — never silent partial behavior).
    */
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
        'edge.local.pos.terminals',
        'edge.local.pos.terminal.select',
        'edge.local.pos.shift.status',
        'edge.local.pos.shift.open',
        'edge.local.pos.shift.close',
        'edge.local.pos.sales.store',
        // EDGE-LOCAL-POS-1 restaurant layer (each name deliberate).
        'edge.local.pos.restaurant.board',
        'edge.local.pos.restaurant.table.open',
        'edge.local.pos.restaurant.session.close',
        'edge.local.pos.held.store',
        'edge.local.pos.held.kot',
        'edge.local.pos.held.settle',
        'edge.local.pos.held.cancel',
        'edge.local.pos.manager.verify',
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
        'exclude' => [
            '.git', '.git/*',
            '.github', '.github/*',
            '.env', '.env.*',
            'tests', 'tests/*',
            'docs', 'docs/*',
            'node_modules', 'node_modules/*',
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
        ],
    ],

    /*
    | LAN name contract for the future Branch Server appliance. mDNS `.local` is NOT reliable on every
    | Windows LAN, so the PILOT mechanism is a DHCP-reserved IP + a hosts-file/router-DNS entry. The
    | certificate SAN (see scripts/edge) must cover BOTH the hostname and the reserved IP.
    */
    'lan' => [
        'hostname'       => env('EDGE_LAN_HOSTNAME', 'bingoo-edge.local'),
        'reserved_ip'    => env('EDGE_LAN_IP'), // DHCP-reserved; set per branch
        'name_mechanism' => env('EDGE_LAN_NAME_MECHANISM', 'hosts_file'), // hosts_file | router_dns | mdns
    ],
];
