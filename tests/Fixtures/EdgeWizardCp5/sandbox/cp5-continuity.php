<?php

/**
 * CP5 scenario h (stream A, Edge 0.9 continuity) — helper for Invoke-Cp5Continuity.ps1, INSIDE the Windows Sandbox.
 *
 * Cloud side (boots the sandbox Cloud exactly like cp5-cloud.php: cp5-env.php + the read-only tree; REAL services, no raw rows):
 *   cloud-seed  <stateOut>            counter X (the 2nd seeded terminal): ShiftService::open, a paid sale (models + payments +
 *                                     SalesService::finalizePaidSale), an open table order with its KOT sent
 *                                     (PrintJobService::queueKot + applyKotSentBookkeeping) and a held bill — Eloquent models only,
 *                                     so every canonical identity (sale_uuid / line_uuid / session_uuid / shift_uuid) is generated
 *   cloud-facts <stateFile>           the Cloud invariants (shifts per counter, orders, payments, KOT, print jobs, cash movements,
 *                                     stock, ingestion registry, lease) as one JSON document
 *
 * Appliance DB (127.0.0.1:3306 bingoo_edge_local, root password from the FILE named by CP5_EDGE_ROOT_PASSFILE — never printed):
 *   edge-db mirror <stateFile>        is the mirror fresh with counter X's live state + is the standby fresh enough to take over
 *   edge-db lapse [skew]              seconds until the Cloud lease lapses on the appliance clock (last ack + TTL + skew)
 *   edge-db facts                     authority, shifts, adoptions, outbox, sales, KOT lines (no secrets)
 *   edge-db stale-inject <seconds>    FAULT INJECTION (labelled in the evidence): mirrored_at = last ack − <seconds>
 *
 * Appliance over the real gateway, FULL TLS against the branch CA (CP5_RESOLVE = "host:port:ip" for the by-name check):
 *   edge-owner   <base> <code> <pinFile> <caFile> switch-offline|return-online   the status-page buttons (CSRF + confirm=yes)
 *   edge-cashier <base> <code> <pinFile> <caFile> <specFile>                    continue the shift, Add Round + KOT, settle the
 *                                                                                adopted table order, ring a new sale
 *   edge-stale   <base> <code> <pinFile> <caFile> <specFile>                    refused adoption → normal open / close shift
 *   edge-close   <base> <code> <pinFile> <caFile> <specFile>                    close the open shifts with the drawer count
 * PINs are read from files (never argv, never printed); session cookies never leave this process. One JSON document on stdout.
 */

$cmd = $argv[1] ?? '';
$emit = function (array $doc, int $code = 0): never {
    echo json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit($code);
};

// ═══════════════════════════════════════════════════ Cloud side ═══════════════════════════════════════════════════
if (str_starts_with($cmd, 'cloud-')) {
    require __DIR__ . '/cp5-env.php';
    cp5_apply_env();
    require CP5_TREE . '/vendor/autoload.php';
    $app = require CP5_TREE . '/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $tenant = App\Models\Master\Tenant::where('tenant_code', CP5_TENANT_CODE)->firstOrFail();
    app(App\Services\Tenancy\TenancyManager::class)->activate($tenant);
    $c = Illuminate\Support\Facades\DB::connection('tenant');
    $s = json_decode((string) @file_get_contents(CP5_CLOUD_DIR . '/state.json'), true) ?: [];
    if (! $s) {
        $emit(['ok' => false, 'why' => 'no Cloud state.json (run cp5-cloud.php setup first)'], 1);
    }

    if ($cmd === 'cloud-seed') {
        $out = (string) ($argv[2] ?? CP5_CLOUD_DIR . '/continuity-state.json');
        $branch = App\Models\Tenant\Branch::findOrFail((int) $s['branch_id']);
        $counterX = App\Models\Tenant\Terminal::findOrFail((int) $s['terminals'][1]);
        $cashier = App\Models\Tenant\User::findOrFail((int) $s['users'][2]);
        $owner = App\Models\Tenant\User::findOrFail((int) $s['users'][0]);
        $product = App\Models\Tenant\Product::findOrFail((int) $s['product_id']);
        $price = (float) ($product->default_selling_price ?: 450);
        $cash = App\Models\Tenant\PaymentMethod::where('method_type', 'cash')->where('is_active', 1)->orderBy('id')->first();
        if (! $cash) {
            $emit(['ok' => false, 'why' => 'the provisioned tenant has no active cash payment method'], 1);
        }
        $shifts = app(App\Services\Sales\ShiftService::class);
        // 1. counter X's ONLINE shift (the shared, row-locked open)
        $shift = $shifts->activeShiftForTerminal($counterX) ?? $shifts->open($branch, $counterX, (int) $cashier->id, 2000.0, 'CP5 h: counter X online shift');
        $base = fn (array $v) => array_merge([
            'branch_id' => $branch->id, 'terminal_id' => $counterX->id, 'shift_id' => $shift->id, 'business_date' => $shift->business_date?->toDateString(),
            'order_source' => 'pos', 'sale_date' => now(), 'discount_type' => 'none', 'discount_value' => 0, 'discount_amount' => 0, 'tax_amount' => 0,
            'service_charge_amount' => 0, 'delivery_charge_amount' => 0, 'tip_amount' => 0, 'paid_amount' => 0, 'change_amount' => 0, 'is_draft' => false,
            'inventory_posted' => false, 'created_by_user_id' => $cashier->id,
        ], $v);
        $line = fn (float $qty) => ['product_id' => $product->id, 'product_name' => $product->name, 'quantity' => $qty, 'unit_price' => $price,
            'unit_cost' => 0, 'cost_total' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'line_total' => $qty * $price];
        $sales = app(App\Services\Sales\SalesService::class);

        // 2. one PAID sale online on counter X (Cloud finalize: FEFO stock, shift counters, GL, cash-bank)
        $paid = new App\Models\Tenant\SalesOrder($base(['sale_no' => $sales->nextSaleNo(), 'order_type' => 'takeaway', 'subtotal' => $price, 'grand_total' => $price, 'status' => 'draft']));
        $paid->save();
        $paid->lines()->create($line(1));
        $paid->payments()->create(['payment_method_id' => $cash->id, 'amount' => $price, 'tendered_amount' => $price + 50, 'change_amount' => 50]);
        $paid = $sales->finalizePaidSale($paid->fresh());

        // 3. an open TABLE with an unpaid order whose KOT went to the kitchen
        $floorId = (int) ($c->table('restaurant_floors')->where('branch_id', $branch->id)->orderBy('id')->value('id'));
        $table = App\Models\Tenant\RestaurantTable::firstOrCreate(['branch_id' => $branch->id, 'table_no' => 'T9H'], ['restaurant_floor_id' => $floorId, 'name' => 'Table 9 (CP5 h)', 'capacity' => 4, 'status' => 'available']);
        $session = new App\Models\Tenant\RestaurantTableSession(['session_no' => 'TS-CP5H-' . Illuminate\Support\Str::upper(Illuminate\Support\Str::random(6)), 'branch_id' => $branch->id,
            'restaurant_table_id' => $table->id, 'opened_by_user_id' => $cashier->id, 'opened_shift_id' => $shift->id, 'business_date' => $shift->business_date?->toDateString(),
            'guest_count' => 3, 'status' => 'open', 'opened_at' => now()]);
        $session->save();
        $table->update(['status' => 'occupied']);
        $order = new App\Models\Tenant\SalesOrder($base(['sale_no' => $sales->nextSaleNo(), 'order_type' => 'dine_in', 'subtotal' => 3 * $price, 'grand_total' => 3 * $price,
            'status' => 'held', 'restaurant_table_session_id' => $session->id, 'restaurant_table_id' => $table->id, 'restaurant_floor_id' => $table->restaurant_floor_id]));
        $order->save();
        $order->lines()->create($line(2));
        $order->lines()->create($line(1));
        $printJobs = app(App\Services\Printing\PrintJobService::class);
        $jobs = $printJobs->queueKot($order->fresh(), null, [], (string) $counterX->id);
        foreach ($jobs as $job) {
            if ($job->document_type === 'kot' && ! in_array((string) data_get($job->payload, 'kot_event_type', 'normal'), ['cancel', 'duplicate'], true)) {
                $printJobs->applyKotSentBookkeeping($order->fresh(), $job);   // what "Mark printed" / the print agent records
            }
        }

        // 4. a HELD bill on counter X
        $held = new App\Models\Tenant\SalesOrder($base(['sale_no' => $sales->nextSaleNo(), 'order_type' => 'takeaway', 'subtotal' => $price, 'grand_total' => $price, 'status' => 'held']));
        $held->save();
        $held->lines()->create($line(1));

        $order->refresh()->load('lines');
        $state = [
            'counter_x' => (int) $counterX->id, 'counter_x_code' => (string) $counterX->code, 'cashier_id' => (int) $cashier->id, 'cashier_code' => (string) $cashier->employee_code,
            'owner_id' => (int) $owner->id, 'owner_code' => (string) $owner->employee_code, 'product_id' => (int) $product->id, 'unit_price' => $price, 'cash_method_id' => (int) $cash->id,
            'shift' => ['id' => (int) $shift->id, 'uuid' => (string) $shift->shift_uuid, 'opening_cash' => (float) $shift->opening_cash, 'expected_cash' => (float) $shift->fresh()->expected_cash],
            'paid' => ['id' => (int) $paid->id, 'uuid' => (string) $paid->sale_uuid, 'sale_no' => (string) $paid->sale_no, 'status' => (string) $paid->status],
            'table_order' => ['id' => (int) $order->id, 'uuid' => (string) $order->sale_uuid, 'sale_no' => (string) $order->sale_no, 'session_uuid' => (string) $session->session_uuid,
                'table_id' => (int) $table->id, 'total' => (float) $order->grand_total,
                'lines' => $order->lines->map(fn ($l) => ['line_uuid' => $l->line_uuid, 'quantity' => (float) $l->quantity, 'kot_sent_quantity' => (float) $l->kot_sent_quantity])->values()->all(),
                'kot_batches' => (int) $c->table('kot_batches')->where('sales_order_id', $order->id)->count()],
            'held' => ['id' => (int) $held->id, 'uuid' => (string) $held->sale_uuid, 'sale_no' => (string) $held->sale_no],
            'seeded_at' => now()->toIso8601String(),
        ];
        file_put_contents($out, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $ok = $state['paid']['status'] === 'paid' && $state['table_order']['kot_batches'] >= 1
            && collect($state['table_order']['lines'])->every(fn ($l) => $l['line_uuid'] && $l['kot_sent_quantity'] >= $l['quantity']);
        $emit(['ok' => $ok] + $state, $ok ? 0 : 1);
    }

    if ($cmd === 'cloud-facts') {
        $h = json_decode((string) @file_get_contents((string) ($argv[2] ?? CP5_CLOUD_DIR . '/continuity-state.json')), true) ?: [];
        $orders = [];
        foreach (['paid', 'table_order', 'held'] as $k) {
            if (empty($h[$k]['id'])) {
                continue;
            }
            $o = $c->table('sales_orders')->where('id', (int) $h[$k]['id'])->first();
            $orders[$k] = $o ? ['id' => (int) $o->id, 'sale_no' => $o->sale_no, 'sale_uuid' => $o->sale_uuid, 'status' => $o->status, 'shift_id' => $o->shift_id !== null ? (int) $o->shift_id : null,
                'terminal_id' => $o->terminal_id !== null ? (int) $o->terminal_id : null, 'grand_total' => (string) $o->grand_total, 'paid_amount' => (string) $o->paid_amount,
                'lines' => (int) $c->table('sales_order_lines')->where('sales_order_id', $o->id)->count(),
                'kept_line_uuids' => $k === 'table_order' ? $c->table('sales_order_lines')->where('sales_order_id', $o->id)->whereIn('line_uuid', array_column($h['table_order']['lines'] ?? [], 'line_uuid'))->count() : null,
                'payments' => (int) $c->table('sale_payments')->where('sales_order_id', $o->id)->count(),
                'kot_batches' => (int) $c->table('kot_batches')->where('sales_order_id', $o->id)->count()] : null;
        }
        $shiftRows = $c->table('shifts')->orderBy('id')->get(['id', 'terminal_id', 'status', 'opening_cash', 'total_sales', 'total_cash', 'expected_cash', 'shift_uuid']);
        $perCounter = [];
        foreach ($shiftRows as $r) {
            $perCounter[(string) $r->terminal_id] ??= ['shifts' => 0, 'open' => 0];
            $perCounter[(string) $r->terminal_id]['shifts']++;
            $perCounter[(string) $r->terminal_id]['open'] += $r->status === 'open' ? 1 : 0;
        }
        $reg = $c->table('edge_inbound_sale_ingestions')->orderBy('id')->get(['sale_uuid', 'status', 'failure_code', 'ingested_sales_order_id', 'ack_payload']);
        $edgeSales = $c->table('sales_orders')->where('edge_sync_state', 'synced')->orderBy('id')->get(['id', 'sale_uuid', 'shift_id', 'status', 'grand_total']);
        $lease = $c->table('edge_branch_authority_leases')->first();
        $doc = [
            'at' => now()->toIso8601String(),
            'shifts_per_counter' => $perCounter,
            'counter_x_shift' => isset($h['shift']['id']) ? (array) $c->table('shifts')->where('id', (int) $h['shift']['id'])->first(['id', 'terminal_id', 'status', 'opening_cash', 'total_sales', 'total_cash', 'expected_cash', 'shift_uuid']) : null,
            'orders' => $orders,
            'counts' => [
                'sales_orders' => (int) $c->table('sales_orders')->count(),
                'sale_payments' => (int) $c->table('sale_payments')->count(),
                'kot_batches' => (int) $c->table('kot_batches')->count(),
                'kot_batch_lines' => (int) $c->table('kot_batch_lines')->count(),
                'print_jobs' => (int) $c->table('print_jobs')->count(),
                'cash_bank_account_transactions' => (int) $c->table('cash_bank_account_transactions')->count(),
                'sales_ledgers' => (int) $c->table('sales_ledgers')->count(),
                'journal_entries' => (int) $c->table('journal_entries')->count(),
                'stock_out_sale_qty' => (float) $c->table('stock_ledgers')->where('movement_type', 'sale')->where('direction', 'out')->sum('quantity'),
                'restaurant_sessions_open' => (int) $c->table('restaurant_table_sessions')->whereIn('status', ['open', 'bill_requested'])->count(),
            ],
            'edge_synced_sales' => $edgeSales->map(fn ($r) => ['id' => (int) $r->id, 'sale_uuid' => $r->sale_uuid, 'shift_id' => $r->shift_id !== null ? (int) $r->shift_id : null, 'status' => $r->status, 'grand_total' => (string) $r->grand_total])->all(),
            'ingestions' => $reg->map(fn ($r) => ['sale_uuid' => $r->sale_uuid, 'status' => $r->status, 'failure_code' => $r->failure_code, 'sales_order_id' => $r->ingested_sales_order_id !== null ? (int) $r->ingested_sales_order_id : null,
                'continuity' => (json_decode((string) $r->ack_payload, true) ?: [])['continuity'] ?? null])->all(),
            'table_session_status' => isset($h['table_order']['session_uuid']) ? $c->table('restaurant_table_sessions')->where('session_uuid', $h['table_order']['session_uuid'])->value('status') : null,
            'lease' => $lease ? ['holder' => $lease->holder, 'edge_state' => $lease->edge_state, 'expires_at' => $lease->expires_at, 'fenced_at' => $lease->fenced_at] : null,
        ];
        $emit($doc);
    }
    $emit(['ok' => false, 'why' => "unknown command [{$cmd}]"], 2);
}

// ═══════════════════════════════════════════════════ appliance DB ═══════════════════════════════════════════════════
if ($cmd === 'edge-db') {
    $what = $argv[2] ?? '';
    $passFile = (string) getenv('CP5_EDGE_ROOT_PASSFILE');
    if ($passFile === '' || ! is_file($passFile)) {
        $emit(['ok' => false, 'why' => 'CP5_EDGE_ROOT_PASSFILE is not set to a readable file'], 2);
    }
    $pdo = new PDO('mysql:host=127.0.0.1;port=' . (getenv('CP5_EDGE_DB_PORT') ?: '3306') . ';dbname=' . (getenv('CP5_EDGE_DB') ?: 'bingoo_edge_local') . ';charset=utf8mb4',
        'root', trim((string) file_get_contents($passFile)), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
    $one = fn (string $sql, array $b = []) => (function () use ($pdo, $sql, $b) { $st = $pdo->prepare($sql); $st->execute($b); return $st->fetch(PDO::FETCH_ASSOC) ?: null; })();
    $all = fn (string $sql, array $b = []) => (function () use ($pdo, $sql, $b) { $st = $pdo->prepare($sql); $st->execute($b); return $st->fetchAll(PDO::FETCH_ASSOC); })();
    $meta = $one('SELECT authority_state, authority_state_reason, authority_last_ack_at, authority_lease_ttl_seconds, authority_takeover_at, heartbeat_consecutive_acks, heartbeat_consecutive_failures,
        authority_cloud_holder_seen, reconcile_clean_at, last_applied_config_revision, standby_config_revision_seen, standby_stock_watermark_seen, handback_blocked_reason FROM edge_local_meta LIMIT 1');
    $mirror = $one('SELECT revision, generated_at, mirrored_at, last_attempt_at, last_success_at, last_error, shifts_count, table_sessions_count, held_sales_count,
        adopted_segment, adoption_status, adoption_reason, adoption_takeover_by FROM edge_mirror_meta WHERE singleton_guard = 1');
    $nowUtc = gmdate('Y-m-d H:i:s');

    if ($what === 'mirror') {
        $h = json_decode((string) @file_get_contents((string) ($argv[3] ?? '')), true) ?: [];
        $has = fn (string $table, string $col, ?string $v) => $v ? (bool) $one("SELECT 1 x FROM {$table} WHERE {$col} = ?", [$v]) : false;
        $baseline = $one("SELECT stock_watermark FROM edge_operational_stock_baselines WHERE status = 'accepted' ORDER BY id DESC LIMIT 1");
        $doc = ['now_utc' => $nowUtc, 'mirror' => $mirror, 'authority_state' => $meta['authority_state'] ?? null, 'last_ack_at' => $meta['authority_last_ack_at'] ?? null,
            'has_shift' => $has('edge_mirror_shifts', 'shift_uuid', $h['shift']['uuid'] ?? null),
            'has_table_order' => $has('edge_mirror_table_orders', 'order_uuid', $h['table_order']['uuid'] ?? null),
            'table_order_adoptable' => (bool) ($one('SELECT adoptable FROM edge_mirror_table_orders WHERE order_uuid = ?', [$h['table_order']['uuid'] ?? ''])['adoptable'] ?? false),
            'has_held' => $has('edge_mirror_held_sales', 'order_uuid', $h['held']['uuid'] ?? null),
            'mirrored_expected_cash' => $one('SELECT expected_cash FROM edge_mirror_shifts WHERE shift_uuid = ?', [$h['shift']['uuid'] ?? ''])['expected_cash'] ?? null,
            'config_current' => (int) ($meta['last_applied_config_revision'] ?? 0) >= (int) ($meta['standby_config_revision_seen'] ?? 0),
            'stock_current' => $baseline !== null && ($meta['standby_stock_watermark_seen'] ?? null) !== null && (string) $baseline['stock_watermark'] === (string) $meta['standby_stock_watermark_seen'],
        ];
        $doc['fresh'] = $doc['has_shift'] && $doc['has_table_order'] && $doc['table_order_adoptable'] && $doc['has_held'] && $doc['config_current'] && $doc['stock_current']
            && ($mirror['last_success_at'] ?? null) !== null && strtotime((string) $mirror['last_success_at'] . ' UTC') >= strtotime((string) ($h['seeded_at'] ?? '1970-01-01T00:00:00Z'));
        $doc['standby_fresh'] = $doc['config_current'] && $doc['stock_current'] && ($mirror['last_success_at'] ?? null) !== null
            && strtotime((string) $mirror['last_success_at'] . ' UTC') >= time() - 90;
        $emit($doc);
    }
    if ($what === 'lapse') {
        $skew = (int) ($argv[3] ?? 30);
        $ack = $meta['authority_last_ack_at'] ?? null;
        $lapseAt = $ack ? strtotime($ack . ' UTC') + (int) ($meta['authority_lease_ttl_seconds'] ?: 120) + $skew : null;
        $emit(['authority_state' => $meta['authority_state'] ?? null, 'last_ack_at' => $ack, 'ttl' => (int) ($meta['authority_lease_ttl_seconds'] ?? 0), 'skew' => $skew,
            'now_utc' => $nowUtc, 'lapse_in_s' => $lapseAt !== null ? $lapseAt - time() : null]);
    }
    if ($what === 'stale-inject') {
        $secs = max(1, (int) ($argv[3] ?? 300));
        $before = $mirror;
        $pdo->prepare('UPDATE edge_mirror_meta SET mirrored_at = DATE_SUB((SELECT authority_last_ack_at FROM edge_local_meta LIMIT 1), INTERVAL ? SECOND),
            last_success_at = DATE_SUB((SELECT authority_last_ack_at FROM edge_local_meta LIMIT 1), INTERVAL ? SECOND) WHERE singleton_guard = 1')->execute([$secs, $secs]);
        $after = $one('SELECT mirrored_at, last_success_at FROM edge_mirror_meta WHERE singleton_guard = 1');
        $emit(['fault_injection' => "mirror last refreshed {$secs} s before the last acknowledged heartbeat (the live-state poll had been failing)",
            'authority_state' => $meta['authority_state'] ?? null, 'last_ack_at' => $meta['authority_last_ack_at'] ?? null, 'before' => ['mirrored_at' => $before['mirrored_at'] ?? null], 'after' => $after]);
    }
    if ($what === 'facts') {
        $emit([
            'now_utc' => $nowUtc,
            'authority' => $meta,
            'mirror' => $mirror,
            'shifts' => $all('SELECT id, terminal_id, status, opening_cash, expected_cash, counted_cash, cash_variance, total_sales, continues_cloud_shift_uuid, continuity_segment, business_date FROM shifts ORDER BY id'),
            'adoptions' => $all('SELECT kind, status, reason, terminal_id, takeover_by, takeover_mode, COUNT(*) n FROM edge_mirror_adoptions GROUP BY kind, status, reason, terminal_id, takeover_by, takeover_mode ORDER BY kind'),
            'outbox' => $all('SELECT state, COUNT(*) n FROM edge_sync_outbox GROUP BY state'),
            'outbox_rows' => $all('SELECT sale_uuid, state, attempts, LEFT(last_error, 160) last_error FROM edge_sync_outbox ORDER BY id'),
            'sales' => $all("SELECT id, sale_no, sale_uuid, status, terminal_id, shift_id, grand_total, adopted_from_order_uuid, edge_sync_state FROM sales_orders WHERE status <> 'cloud_mirror' ORDER BY id"),
            'kot_lines' => $all('SELECT b.sales_order_id, b.sequence_no, l.product_id, l.quantity FROM kot_batch_lines l JOIN kot_batches b ON b.id = l.kot_batch_id ORDER BY b.id, l.id'),
            'print_jobs' => $all('SELECT document_type, print_status, COUNT(*) n FROM print_jobs GROUP BY document_type, print_status'),
        ]);
    }
    $emit(['ok' => false, 'why' => "unknown edge-db [{$what}]"], 2);
}

// ═══════════════════════════════════════ appliance over the gateway (full TLS) ═══════════════════════════════════════
[, , $base, $code, $pinFile, $caFile] = array_pad($argv, 6, null);
if (! in_array($cmd, ['edge-owner', 'edge-cashier', 'edge-stale', 'edge-close'], true) || ! $base || ! $code || ! $pinFile || ! is_file($pinFile)) {
    fwrite(STDERR, "usage: see the header of cp5-continuity.php\n");
    exit(2);
}
$base = rtrim($base, '/');
$jar = [];
$csrf = null;
$trace = [];
$http = function (string $method, string $path, $body = null, bool $json = false) use ($base, $caFile, &$jar, &$csrf, &$trace): array {
    $ch = curl_init($base . $path);
    $headers = ['Accept: ' . ($json ? 'application/json' : 'text/html,application/json')];
    if ($jar !== []) {
        $headers[] = 'Cookie: ' . implode('; ', array_map(fn ($k, $v) => $k . '=' . $v, array_keys($jar), $jar));
    }
    if ($json && $csrf) {
        $headers[] = 'X-CSRF-TOKEN: ' . $csrf;
        $headers[] = 'X-Requested-With: XMLHttpRequest';
    }
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 120, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2];
    if ($caFile) {
        $opts[CURLOPT_CAINFO] = $caFile;   // the branch CA ONLY: peer + host name / IP SAN verified
    }
    if ($resolve = getenv('CP5_RESOLVE')) {
        $opts[CURLOPT_RESOLVE] = [$resolve];
    }
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        if ($json) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? []);
        } else {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($body ?? []);
        }
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $verify = curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT);
    curl_close($ch);
    $hdrs = [];
    foreach (preg_split('/\r?\n/', substr((string) $raw, 0, $hsize)) as $l) {
        if (preg_match('/^set-cookie:\s*([^=]+)=([^;]*)/i', $l, $m)) {
            $jar[trim($m[1])] = $m[2];
        } elseif (preg_match('/^([A-Za-z-]+):\s*(.*)$/', $l, $m)) {
            $hdrs[strtolower($m[1])] = trim($m[2]);
        }
    }
    $bodyText = $raw === false ? '' : substr((string) $raw, $hsize);
    $trace[] = ['m' => $method, 'path' => preg_replace('/\?.*/', '', $path), 'status' => $status];

    return ['status' => $status, 'headers' => $hdrs, 'body' => $bodyText, 'json' => json_decode($bodyText, true), 'error' => $raw === false ? $err : null,
        'tls_verified' => $raw !== false && (int) $verify === 0];
};
$login = function () use ($http, $code, $pinFile, &$csrf, $emit, &$trace): array {
    $r = $http('GET', '/edge/local/login');
    $tls = ['verified' => $r['tls_verified'], 'error' => $r['error']];
    if (! preg_match('/name="_token"\s+value="([^"]+)"/', $r['body'], $m)) {
        $emit(['ok' => false, 'why' => 'no CSRF token on the login page', 'status' => $r['status'], 'tls' => $tls, 'trace' => $trace], 1);
    }
    $p = $http('POST', '/edge/local/login', ['_token' => $m[1], 'employee_code' => $code, 'credential' => trim((string) file_get_contents($pinFile))]);
    $loc = (string) ($p['headers']['location'] ?? '');
    $ok = $p['status'] === 302 && ! str_contains($loc, '/login');
    if (! $ok) {
        $emit(['ok' => false, 'why' => 'PIN login refused', 'login_status' => $p['status'], 'tls' => $tls, 'trace' => $trace], 1);
    }
    $page = $http('GET', '/edge/local/pos');
    if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $page['body'], $m2)) {
        $csrf = $m2[1];
    }

    return ['ok' => $ok && $csrf !== null, 'login_status' => $p['status'], 'pos_page_status' => $page['status'], 'tls' => $tls,
        'continuity_banner_in_page' => str_contains($page['body'], 'id="pos-continuity-banner"')];
};
$spec = isset($argv[6]) && is_file((string) $argv[6]) ? (json_decode((string) file_get_contents((string) $argv[6]), true) ?: []) : [];
$uuid = fn () => sprintf('%04x%04x-%04x-4%03x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0x8000, 0xbfff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
$pick = fn (?array $j, array $keys) => is_array($j) ? array_intersect_key($j, array_flip($keys)) : null;

if ($cmd === 'edge-owner') {
    $action = (string) ($argv[6] ?? '');
    if (! in_array($action, ['switch-offline', 'return-online'], true)) {
        $emit(['ok' => false, 'why' => 'action must be switch-offline or return-online'], 2);
    }
    $session = $login();
    $page = $http('GET', '/edge/local/pos/health');
    if (! preg_match('#action="[^"]*/authority/' . $action . '"[^>]*>\s*<input type="hidden" name="_token"[^>]*value="([^"]+)"#s', $page['body'], $m)
        && ! preg_match('/name="_token"\s+value="([^"]+)"/', $page['body'], $m)) {
        $emit(['ok' => false, 'why' => 'no form token on the status page', 'status' => $page['status'], 'session' => $session, 'trace' => $trace], 1);
    }
    $post = $http('POST', '/edge/local/pos/authority/' . $action, ['_token' => $m[1], 'confirm' => 'yes']);
    $after = $http('GET', '/edge/local/pos/health');
    $text = html_entity_decode(strip_tags($after['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    preg_match('/(Switch to Offline refused:[^\n]+|OFFLINE — Local Mode is active[^\n]*|This Branch Server is already OFFLINE[^\n]*|Return to Online is BLOCKED:[^\n]+|Return to Online (?:refused|failed):[^\n]+|ONLINE — authority handed back[^\n]*|The Cloud already sells[^\n]*|Another authority change[^\n]*|Not confirmed[^\n]*)/u', $text, $mm);
    $emit(['ok' => $post['status'] === 302, 'action' => $action, 'session' => $session, 'post_status' => $post['status'], 'message' => isset($mm[1]) ? trim(preg_replace('/\s+/', ' ', $mm[1])) : null, 'trace' => $trace]);
}

$session = $login();
$terminal = (int) ($spec['terminal_id'] ?? 0);
$select = $http('POST', '/edge/local/pos/terminal/select', ['terminal_id' => $terminal], true);
$shift = fn () => $http('GET', '/edge/local/pos/shift?terminal_id=' . $terminal, null, true);

if ($cmd === 'edge-cashier') {
    $doc = ['session' => $session, 'select_status' => $select['status']];
    $s1 = $shift();
    $doc['shift_before'] = $pick($s1['json'], ['open', 'shift_id', 'shift_uuid', 'business_date', 'continuity']);
    $held = $http('GET', '/edge/local/pos/held-sales?terminal_id=' . $terminal, null, true);
    $rows = (array) ($held['json']['sales'] ?? []);
    $order = collect_first($rows, fn ($r) => ($r['sale_no'] ?? null) === ($spec['table_order_sale_no'] ?? ''));
    $doc['held_list'] = ['status' => $held['status'], 'count' => count($rows), 'mirrored_badges' => count(array_filter($rows, fn ($r) => ! empty($r['mirrored']['badge']))),
        'table_order_found' => $order !== null, 'table_order_badge' => $order['mirrored']['badge'] ?? null];
    if ($order === null) {
        $emit(['ok' => false, 'why' => 'the adopted table order is not in the held list'] + $doc + ['trace' => $trace], 1);
    }
    // Add Round: every existing line carried + one new product line → only the new line may reach the kitchen.
    $lines = array_map(fn ($l) => ['sales_order_line_id' => (int) $l['id'], 'product_id' => (int) $l['product_id'], 'quantity' => (float) $l['quantity']], (array) $order['lines']);
    $lines[] = ['product_id' => (int) $spec['product_id'], 'quantity' => 1];
    $rev = $http('POST', '/edge/local/pos/held-sales', ['held_sale_id' => (int) $order['id'], 'order_type' => 'dine_in', 'terminal_id' => $terminal,
        'restaurant_table_session_id' => (int) $order['restaurant_table_session_id'], 'lines' => $lines], true);
    $doc['add_round'] = ['status' => $rev['status'], 'grand_total' => $rev['json']['grand_total'] ?? null, 'message' => $rev['json']['message'] ?? null,
        'lines' => array_map(fn ($l) => $pick($l, ['product_id', 'quantity', 'kot_sent', 'kot_sent_quantity']), (array) ($rev['json']['lines'] ?? []))];
    $kot = $http('POST', '/edge/local/pos/held-sales/' . (int) $order['id'] . '/kot', ['terminal_id' => $terminal], true);
    $doc['kot'] = ['status' => $kot['status'], 'sequence_no' => $kot['json']['batch']['sequence_no'] ?? null,
        'lines' => array_map(fn ($l) => $pick($l, ['product_name', 'quantity']), (array) ($kot['json']['batch']['lines'] ?? [])), 'message' => $kot['json']['message'] ?? null];
    $total = (float) ($rev['json']['grand_total'] ?? 0);
    $settle = $http('POST', '/edge/local/pos/held-sales/' . (int) $order['id'] . '/settle', ['client_uuid' => $uuid(), 'terminal_id' => $terminal,
        'kot_print_intent' => 'skip', 'receipt_print_intent' => 'skip',
        'payments' => [['payment_method_id' => (int) $spec['cash_method_id'], 'amount' => $total, 'tendered_amount' => $total]]], true);
    $doc['settle'] = ['status' => $settle['status'], 'body' => $pick($settle['json'], ['sale_uuid', 'sale_no', 'status', 'grand_total', 'paid_amount', 'edge_sync_state', 'message'])];
    $price = (float) $spec['unit_price'];
    $sale = $http('POST', '/edge/local/pos/sales', ['order_type' => 'takeaway', 'client_uuid' => $uuid(), 'terminal_id' => $terminal, 'kot_print_intent' => 'skip', 'receipt_print_intent' => 'skip',
        'lines' => [['product_id' => (int) $spec['product_id'], 'quantity' => 1]],
        'payments' => [['payment_method_id' => (int) $spec['cash_method_id'], 'amount' => $price, 'tendered_amount' => $price + 50]]], true);
    $doc['new_sale'] = ['status' => $sale['status'], 'body' => $pick($sale['json'], ['sale_uuid', 'sale_no', 'status', 'grand_total', 'message'])];
    $s2 = $shift();
    $doc['shift_after'] = $pick($s2['json'], ['open', 'shift_id', 'shift_uuid', 'continuity']);
    $doc['ok'] = $session['ok'] && ($s1['json']['open'] ?? false) && str_starts_with((string) ($s1['json']['continuity']['banner'] ?? ''), 'Continuing online shift')
        && ($s1['json']['continuity']['cloud_shift_uuid'] ?? null) === ($spec['cloud_shift_uuid'] ?? 'x') && $rev['status'] === 200 && $kot['status'] === 200
        && $settle['status'] === 200 && ($settle['json']['sale_uuid'] ?? null) === ($spec['table_order_uuid'] ?? 'x') && in_array($sale['status'], [200, 201], true);
    $doc['trace'] = $trace;
    $emit($doc, $doc['ok'] ? 0 : 1);
}

if ($cmd === 'edge-stale') {
    $doc = ['session' => $session, 'select_status' => $select['status']];
    $s1 = $shift();
    $doc['shift_before'] = $pick($s1['json'], ['open', 'shift_id', 'continuity']);
    $open = $http('POST', '/edge/local/pos/shift/open', ['opening_cash' => 0, 'terminal_id' => $terminal, 'opening_notes' => 'CP5 h stale case: normal open-shift flow'], true);
    $doc['open_by_hand'] = ['status' => $open['status'], 'body' => $pick($open['json'], ['shift_id', 'shift_uuid', 'message'])];
    $s2 = $shift();
    $doc['shift_after_open'] = $pick($s2['json'], ['open', 'shift_id', 'continuity']);
    $close = $http('POST', '/edge/local/pos/shift/close', ['terminal_id' => $terminal, 'closing_notes' => 'CP5 h stale case: zero drawer'], true);
    $doc['close'] = ['status' => $close['status'], 'body' => $pick($close['json'], ['status', 'count_source', 'message'])];
    $doc['ok'] = $session['ok'] && ! ($s1['json']['open'] ?? true) && in_array((string) ($s1['json']['continuity']['status'] ?? ''), ['refused_stale', 'refused_untrusted'], true)
        && $open['status'] === 201 && ($s2['json']['open'] ?? false) && ($s2['json']['continuity'] ?? null) === null && $close['status'] === 200;
    $doc['trace'] = $trace;
    $emit($doc, $doc['ok'] ? 0 : 1);
}

if ($cmd === 'edge-close') {
    // The owner closes every open shift with its drawer count (the counted cash = the drawer's expected cash: no variance).
    $doc = ['session' => $session, 'closed' => []];
    foreach ((array) ($spec['close'] ?? []) as $c) {
        $sel = $http('POST', '/edge/local/pos/terminal/select', ['terminal_id' => (int) $c['terminal_id']], true);
        $r = $http('POST', '/edge/local/pos/shift/close', ['terminal_id' => (int) $c['terminal_id'], 'counted_cash' => (float) $c['counted_cash'], 'closing_notes' => 'CP5 h: end of the offline segment'], true);
        $doc['closed'][] = ['terminal_id' => (int) $c['terminal_id'], 'select' => $sel['status'], 'status' => $r['status'], 'body' => $pick($r['json'], ['status', 'cash_variance', 'count_source', 'message'])];
    }
    $doc['ok'] = $session['ok'] && ! array_filter($doc['closed'], fn ($x) => $x['status'] !== 200);
    $doc['trace'] = $trace;
    $emit($doc, $doc['ok'] ? 0 : 1);
}

function collect_first(array $rows, callable $fn): ?array
{
    foreach ($rows as $r) {
        if (is_array($r) && $fn($r)) {
            return $r;
        }
    }

    return null;
}
