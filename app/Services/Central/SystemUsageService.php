<?php

namespace App\Services\Central;

use App\Models\Master\Tenant;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * MASTER-UNIVERSE-1 — the honest numbers behind the Bingoo Master dashboard.
 *
 * `snapshot()` reads the server (CPU load, RAM, disk, MySQL size) and every tenant (database size,
 * files, 30-day sales, products, users) and writes them to `system_usage_snapshots`. It only READS
 * tenant databases. The screens read the newest rows, so opening the dashboard never opens 15
 * tenant databases.
 *
 * Rules: a number that could not be read is stored as NULL and shown as "—" with the reason;
 * per-tenant CPU / RAM is never invented (it cannot be measured on a shared server).
 */
class SystemUsageService
{
    public const TABLE = 'system_usage_snapshots';

    /** Paid bills that count as a sale (draft / held / cancelled do not). */
    private const SALE_STATUSES = ['paid', 'partially_returned'];

    public function __construct(private TenancyManager $tenancy) {}

    /**
     * Read everything and write one server row + one row per tenant (same `taken_at`).
     *
     * @return array{taken_at:Carbon, tenants:int, failed:array<string,string>}
     */
    public function snapshot(?callable $progress = null): array
    {
        $takenAt = now();
        $rows = [];
        $failed = [];
        $tenantDbTotal = 0;

        $tenants = Tenant::query()->with('database')->orderBy('id')->get();
        foreach ($tenants as $tenant) {
            $reading = $this->tenantReading($tenant);
            if (isset($reading['meta']['error'])) {
                $failed[$tenant->tenant_code] = $reading['meta']['error'];
            }
            $tenantDbTotal += (int) ($reading['db_bytes'] ?? 0);
            $rows[] = $reading + ['tenant_id' => $tenant->id];
            if ($progress) {
                $progress($tenant, $reading);
            }
        }

        $server = $this->serverReading();
        $masterBytes = $server['meta']['master_db_bytes'] ?? null;
        $server['db_bytes'] = $masterBytes === null && $tenantDbTotal === 0 ? null : (int) $masterBytes + $tenantDbTotal;
        $server['meta']['tenant_db_bytes'] = $tenantDbTotal;
        $server['meta']['tenants_read'] = count($tenants) - count($failed);
        $server['meta']['tenants_failed'] = count($failed);
        $rows[] = $server + ['tenant_id' => null];

        $db = DB::connection('master');
        $db->transaction(function () use ($db, $rows, $takenAt) {
            foreach ($rows as $row) {
                $row['meta'] = json_encode($row['meta'] ?? [], JSON_UNESCAPED_SLASHES);
                $db->table(self::TABLE)->insert($row + ['taken_at' => $takenAt, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        return ['taken_at' => $takenAt, 'tenants' => count($tenants), 'failed' => $failed];
    }

    /**
     * The machine itself. On Windows (no /proc, no load average) CPU and RAM are NULL.
     *
     * @return array<string,mixed>
     */
    public function serverReading(): array
    {
        $load = null;
        if (function_exists('sys_getloadavg')) {
            $avg = @sys_getloadavg();
            $load = is_array($avg) && isset($avg[0]) ? round((float) $avg[0], 2) : null;
        }

        $mem = $this->memInfo();
        $diskTotal = @disk_total_space(base_path());
        $diskFree = @disk_free_space(base_path());

        $meta = ['os' => PHP_OS_FAMILY, 'php' => PHP_VERSION];
        $master = DB::connection('master');
        try {
            $this->freshStats($master);
            $meta['master_db_bytes'] = (int) $master->selectOne(
                'SELECT COALESCE(SUM(data_length + index_length), 0) AS b FROM information_schema.tables WHERE table_schema = DATABASE()'
            )->b;
        } catch (Throwable $e) {
            $meta['master_db_error'] = mb_substr($e->getMessage(), 0, 160);
        }
        if ($load === null) {
            $meta['cpu_note'] = 'This server does not report a CPU load (Windows or restricted PHP).';
        }
        if ($mem === null) {
            $meta['ram_note'] = 'This server does not expose /proc/meminfo.';
        }

        return [
            'cpu_load_1'       => $load,
            'cpu_cores'        => $this->cpuCores(),
            'ram_total_kb'     => $mem['total'] ?? null,
            'ram_used_kb'      => $mem !== null ? max(0, $mem['total'] - $mem['available']) : null,
            'disk_total_bytes' => $diskTotal === false ? null : (int) $diskTotal,
            'disk_free_bytes'  => $diskFree === false ? null : (int) $diskFree,
            'db_bytes'         => null,
            'files_bytes'      => null,
            'sales_30d'        => null,
            'products'         => null,
            'users'            => null,
            'meta'             => $meta,
        ];
    }

    /**
     * One tenant, read-only. Always deactivates, even when the tenant database is missing.
     *
     * @return array<string,mixed>
     */
    public function tenantReading(Tenant $tenant): array
    {
        $out = [
            'db_bytes' => null, 'files_bytes' => null, 'sales_30d' => null, 'products' => null, 'users' => null,
            'cpu_load_1' => null, 'cpu_cores' => null, 'ram_total_kb' => null, 'ram_used_kb' => null,
            'disk_total_bytes' => null, 'disk_free_bytes' => null, 'meta' => [],
        ];
        $meta = [];

        $meta['backups_bytes'] = $this->folderBytes((string) config('backup.disk', 'local'), 'backups/tenants/' . $tenant->tenant_code);

        if (! $tenant->database || $tenant->database->migration_status !== 'completed') {
            $meta['error'] = 'No database yet (not provisioned).';
            $out['meta'] = $meta;

            return $out;
        }

        try {
            $this->tenancy->activate($tenant);
            $c = DB::connection('tenant');
            $schema = $c->getSchemaBuilder();
            $this->freshStats($c);

            // Each number on its own: a table or column an old database lacks blanks only that number
            // (written to meta.skipped), never the whole tenant.
            $read = function (string $what, callable $fn) use (&$meta) {
                try {
                    return $fn();
                } catch (Throwable $e) {
                    $meta['skipped'][$what] = mb_substr($e->getMessage(), 0, 140);

                    return null;
                }
            };

            $out['db_bytes'] = $read('db_bytes', fn () => (int) $c->selectOne(
                'SELECT COALESCE(SUM(data_length + index_length), 0) AS b FROM information_schema.tables WHERE table_schema = DATABASE()'
            )->b);

            $from = now()->subDays(29)->toDateString();
            if ($schema->hasTable('sales_orders')) {
                // Business date when the database has it (shift-aware); else the sale's own date.
                $dateSql = $schema->hasColumn('sales_orders', 'business_date') ? 'COALESCE(business_date, DATE(sale_date))' : 'DATE(sale_date)';
                $sales = $read('sales_30d', fn () => $c->table('sales_orders')
                    ->whereIn('status', self::SALE_STATUSES)
                    ->whereRaw($dateSql . ' >= ?', [$from])
                    ->selectRaw('COUNT(*) AS n, COALESCE(SUM(grand_total), 0) AS amount')
                    ->first());
                if ($sales) {
                    $out['sales_30d'] = (int) $sales->n;
                    $meta['sales_30d_amount'] = round((float) $sales->amount, 2);
                }
            }
            if ($schema->hasTable('catering_events')) {
                $meta['catering_events_30d'] = $read('catering_events_30d', fn () => $c->table('catering_events')
                    ->where('event_date', '>=', $from)->where('event_date', '<=', now()->toDateString())
                    ->where('status', '!=', 'cancelled')->count());
            }
            $out['products'] = $schema->hasTable('products') ? $read('products', fn () => $c->table('products')->count()) : null;
            $out['users'] = $schema->hasTable('users') ? $read('users', fn () => $c->table('users')->count()) : null;
            $meta['branches'] = $schema->hasTable('branches') ? $read('branches', fn () => $c->table('branches')->count()) : null;

            // Files: the tenant's own website folders + the product pictures its products point to.
            $out['files_bytes'] = $read('files_bytes', function () use ($tenant, $c, $schema, &$meta) {
                $slug = Str::slug($tenant->tenant_code);
                $website = $this->folderBytes('public', 'web-menu/' . $slug) + $this->folderBytes('public', 'web-site/' . $slug);
                $pictures = 0;
                if ($schema->hasTable('products') && $schema->hasColumn('products', 'image_path')) {
                    $paths = $c->table('products')->whereNotNull('image_path')->where('image_path', '!=', '')
                        ->distinct()->pluck('image_path');
                    $disk = Storage::disk('public');
                    foreach ($paths as $p) {
                        $p = ltrim((string) $p, '/');
                        if (str_starts_with($p, 'web-') || str_contains($p, '..') || str_starts_with($p, 'http')) {
                            continue; // website pictures are already counted in their folder; URLs are not ours
                        }
                        $abs = $disk->path($p);
                        if (is_file($abs)) {
                            $pictures += (int) filesize($abs);
                        }
                    }
                }
                $meta['website_files_bytes'] = $website;
                $meta['product_pictures_bytes'] = $pictures;

                return $website + $pictures;
            });
        } catch (Throwable $e) {
            $meta['error'] = 'Could not read this tenant: ' . mb_substr($e->getMessage(), 0, 160);
        } finally {
            $this->tenancy->deactivate();
            DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        }

        $out['meta'] = $meta;

        return $out;
    }

    /** The newest server row, or null before the first snapshot. */
    public function latestServer(): ?object
    {
        $row = DB::connection('master')->table(self::TABLE)->whereNull('tenant_id')->orderByDesc('taken_at')->orderByDesc('id')->first();

        return $row ? $this->decode($row) : null;
    }

    /** @return Collection<int, object> newest row per tenant, keyed by tenant_id */
    public function latestPerTenant(): Collection
    {
        $db = DB::connection('master');
        $ids = $db->table(self::TABLE)->whereNotNull('tenant_id')->groupBy('tenant_id')->selectRaw('MAX(id) AS id')->pluck('id');

        return $db->table(self::TABLE)->whereIn('id', $ids)->get()
            ->map(fn ($r) => $this->decode($r))->keyBy('tenant_id');
    }

    public function latestForTenant(int $tenantId): ?object
    {
        $row = DB::connection('master')->table(self::TABLE)->where('tenant_id', $tenantId)->orderByDesc('id')->first();

        return $row ? $this->decode($row) : null;
    }

    /**
     * "Disk full in ~N days": the fall in free space between the newest server row and the oldest one
     * from the last 30 days that is at least a day older. Until two such rows exist: collecting.
     *
     * @return array{state:string, days?:int, per_day_bytes?:int, since?:string, text:string}
     */
    public function forecast(): array
    {
        $db = DB::connection('master');
        $newest = $db->table(self::TABLE)->whereNull('tenant_id')->whereNotNull('disk_free_bytes')->orderByDesc('taken_at')->first();
        $oldest = $newest
            ? $db->table(self::TABLE)->whereNull('tenant_id')->whereNotNull('disk_free_bytes')
                ->where('taken_at', '<=', Carbon::parse($newest->taken_at)->subDay())
                ->where('taken_at', '>=', Carbon::parse($newest->taken_at)->subDays(30))
                ->orderBy('taken_at')->first()
            : null;

        if (! $newest || ! $oldest) {
            return ['state' => 'collecting', 'text' => 'Collecting data — first forecast after 2 daily snapshots.'];
        }

        $days = max(1 / 24, Carbon::parse($oldest->taken_at)->diffInSeconds(Carbon::parse($newest->taken_at)) / 86400);
        $perDay = (int) round(((int) $oldest->disk_free_bytes - (int) $newest->disk_free_bytes) / $days);
        $since = Carbon::parse($oldest->taken_at)->toDateString();

        if ($perDay <= 0) {
            return ['state' => 'steady', 'per_day_bytes' => $perDay, 'since' => $since,
                'text' => 'Disk use is not growing (since ' . $since . ').'];
        }

        $left = (int) floor((int) $newest->disk_free_bytes / $perDay);

        return ['state' => 'ok', 'days' => $left, 'per_day_bytes' => $perDay, 'since' => $since,
            'text' => 'Disk full in ~' . number_format($left) . ' days at ' . self::bytes($perDay) . ' a day (since ' . $since . ').'];
    }

    /**
     * When each tenant was last backed up — its own backups (manual / scheduled) AND the nightly
     * whole-server backup (`tenants:backup`, read from its manifest files).
     *
     * @return array<int, array{at:Carbon, kind:string}>
     */
    public function lastBackups(): array
    {
        $out = [];
        $rows = DB::connection('master')->table('tenant_backups')
            ->where('status', 'completed')->whereNotNull('tenant_id')
            ->groupBy('tenant_id', 'backup_type')
            ->selectRaw('tenant_id, backup_type, MAX(created_at) AS at')->get();
        foreach ($rows as $r) {
            $at = Carbon::parse($r->at);
            if (! isset($out[$r->tenant_id]) || $at->gt($out[$r->tenant_id]['at'])) {
                $out[$r->tenant_id] = ['at' => $at, 'kind' => str_replace('_', ' ', (string) $r->backup_type)];
            }
        }

        foreach ($this->nightlyBackups() as $tenantId => $at) {
            if (! isset($out[$tenantId]) || $at->gt($out[$tenantId]['at'])) {
                $out[$tenantId] = ['at' => $at, 'kind' => 'nightly'];
            }
        }

        return $out;
    }

    /** @return array<int, Carbon> tenant id → newest nightly run that dumped it OK */
    private function nightlyBackups(): array
    {
        $out = [];
        try {
            $disk = Storage::disk((string) config('backup.disk', 'local'));
            $base = trim((string) config('backup.path', 'backups'), '/');
            $dirs = collect($disk->directories($base))
                ->filter(fn ($d) => preg_match('/^\d{8}_\d{6}$/', basename($d)))
                ->sortDesc()->take(5);
            foreach ($dirs as $dir) {
                if (! $disk->exists($dir . '/manifest.json')) {
                    continue;
                }
                $m = json_decode((string) $disk->get($dir . '/manifest.json'), true);
                $at = isset($m['created_at']) ? Carbon::parse($m['created_at']) : null;
                foreach ((array) ($m['tenants'] ?? []) as $t) {
                    if ($at && ($t['status'] ?? null) === 'ok' && isset($t['id']) && ! isset($out[(int) $t['id']])) {
                        $out[(int) $t['id']] = $at;
                    }
                }
            }
        } catch (Throwable) {
            // No backup folder on this machine — only the per-tenant rows count.
        }

        return $out;
    }

    public static function bytes(?int $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = $bytes > 0 ? min(count($units) - 1, (int) floor(log($bytes, 1024))) : 0;

        return round($bytes / (1024 ** $i), $i >= 3 ? 1 : 0) . ' ' . $units[$i];
    }

    private function decode(object $row): object
    {
        $row->meta = is_string($row->meta) ? (json_decode($row->meta, true) ?: []) : (array) ($row->meta ?? []);

        return $row;
    }

    /** MySQL 8 caches table sizes for a day; ask for today's numbers (ignored on MariaDB). */
    private function freshStats($connection): void
    {
        try {
            $connection->statement('SET SESSION information_schema_stats_expiry = 0');
        } catch (Throwable) {
        }
    }

    /** @return array{total:int, available:int}|null kB */
    private function memInfo(): ?array
    {
        if (! @is_readable('/proc/meminfo')) {
            return null;
        }
        $text = (string) @file_get_contents('/proc/meminfo');
        if (! preg_match('/^MemTotal:\s+(\d+)/m', $text, $t) || ! preg_match('/^MemAvailable:\s+(\d+)/m', $text, $a)) {
            return null;
        }

        return ['total' => (int) $t[1], 'available' => (int) $a[1]];
    }

    private function cpuCores(): ?int
    {
        if (@is_readable('/proc/cpuinfo')) {
            $n = preg_match_all('/^processor\s*:/m', (string) @file_get_contents('/proc/cpuinfo'));
            if ($n > 0) {
                return $n;
            }
        }
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('shell_exec')) {
            $n = (int) trim((string) @shell_exec('nproc 2>/dev/null'));
            if ($n > 0) {
                return $n;
            }
        }

        return null;
    }

    private function folderBytes(string $disk, string $path): int
    {
        try {
            $abs = Storage::disk($disk)->path($path);
        } catch (Throwable) {
            return 0;
        }
        if (! is_dir($abs)) {
            return 0;
        }
        $sum = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                $sum += $file->getSize();
            }
        }

        return $sum;
    }
}
