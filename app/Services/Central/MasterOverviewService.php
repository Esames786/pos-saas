<?php

namespace App\Services\Central;

use App\Models\Master\Tenant;
use App\Support\Business;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * MASTER-UNIVERSE-1 — everything the "Bingoo Universe" dashboard shows, from master tables only.
 *
 * Fast on purpose: tenant numbers come from the newest usage snapshot (`system:usage-snapshot`),
 * never from opening each tenant database on page load. Anything unknown is null → "—".
 */
class MasterOverviewService
{
    /** A live tenant whose newest backup is older than this is flagged. */
    public const BACKUP_STALE_DAYS = 2;

    /** Disk use above this percent is flagged. */
    public const DISK_ALERT_PERCENT = 80;

    /** Master shows times in Pakistan time (the owner's clock); the database keeps UTC. */
    public const DISPLAY_TZ = 'Asia/Karachi';

    public static function pkt(?\DateTimeInterface $at): string
    {
        return $at ? Carbon::instance($at)->copy()->timezone(self::DISPLAY_TZ)->format('d M Y, H:i') . ' PKT' : '—';
    }

    public function __construct(
        private SystemUsageService $usage,
        private ModuleCatalogService $modules,
    ) {}

    /** @return array<string,mixed> */
    public function dashboard(): array
    {
        $server = $this->usage->latestServer();
        $snapshots = $this->usage->latestPerTenant();
        $backups = $this->usage->lastBackups();
        $invoices = $this->invoicesDue();

        // Live customers first, then demos; within each, by status (active → cancelled) and name.
        $statusOrder = ['active' => 0, 'pending' => 1, 'suspended' => 2, 'cancelled' => 3];
        $tenants = Tenant::query()->with(['subscription.plan', 'database', 'domains'])->get()
            ->sortBy(fn (Tenant $t) => sprintf('%d-%d-%s', $t->is_demo ? 1 : 0, $statusOrder[$t->status] ?? 9, mb_strtolower($t->business_name)))
            ->values();

        $salesTotal = $snapshots->sum(fn ($s) => (int) ($s->sales_30d ?? 0));

        $cards = $tenants->map(fn (Tenant $t) => $this->card($t, $snapshots->get($t->id), $backups[$t->id] ?? null, $invoices[$t->id] ?? null, $salesTotal));

        return [
            'server'   => $this->serverView($server),
            'forecast' => $this->usage->forecast(),
            'cards'    => $cards,
            'alerts'   => $this->alerts($cards, $server),
            'modules'  => $this->modules->all(true),
            'counts'   => [
                'all'     => $tenants->count(),
                'active'  => $tenants->where('status', 'active')->count(),
                'pending' => $tenants->where('status', 'pending')->count(),
                'demo'    => $tenants->where('is_demo', true)->count(),
            ],
            'salesTotal' => $salesTotal,
        ];
    }

    /** One tenant's card (also used by the tenant hub's overview). */
    public function card(Tenant $t, ?object $snap, ?array $backup, ?array $due, int $salesTotal): array
    {
        $business = Business::exists($t->primary_business) ? Business::all()[$t->primary_business] : null;
        $sales = $snap?->sales_30d;

        return [
            'tenant'      => $t,
            'business'    => $business,
            'plan'        => $t->subscription?->plan?->name,
            'db_bytes'    => $snap?->db_bytes !== null ? (int) $snap->db_bytes : null,
            'files_bytes' => $snap?->files_bytes !== null ? (int) $snap->files_bytes : null,
            'sales_30d'   => $sales !== null ? (int) $sales : null,
            'sales_amount'=> isset($snap?->meta['sales_30d_amount']) ? (float) $snap->meta['sales_30d_amount'] : null,
            'events_30d'  => $snap?->meta['catering_events_30d'] ?? null,
            'products'    => $snap?->products !== null ? (int) $snap->products : null,
            'users'       => $snap?->users !== null ? (int) $snap->users : null,
            'share'       => ($sales !== null && $salesTotal > 0) ? round(100 * $sales / $salesTotal, 1) : null,
            'read_error'  => $snap ? ($snap->meta['error'] ?? null) : 'No usage reading yet — press "Refresh now".',
            'read_at'     => $snap ? Carbon::parse($snap->taken_at) : null,
            'skipped'     => $snap ? (array) ($snap->meta['skipped'] ?? []) : [],
            'backup'      => $backup,
            'due'         => $due,
        ];
    }

    /**
     * Unpaid subscription invoices per tenant.
     *
     * @return array<int, array{amount:float, count:int, overdue:int, currency:string}>
     */
    public function invoicesDue(?int $tenantId = null): array
    {
        $today = now()->toDateString();
        $rows = DB::connection('master')->table('subscription_invoices')
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->where('balance_amount', '>', 0)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->groupBy('tenant_id', 'currency_code')
            ->selectRaw("tenant_id, currency_code, SUM(balance_amount) AS amount, COUNT(*) AS n,
                SUM(CASE WHEN status = 'overdue' OR (due_date IS NOT NULL AND due_date < ?) THEN 1 ELSE 0 END) AS overdue", [$today])
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->tenant_id] = [
                'amount'   => (float) $r->amount + ($out[$r->tenant_id]['amount'] ?? 0),
                'count'    => (int) $r->n + ($out[$r->tenant_id]['count'] ?? 0),
                'overdue'  => (int) $r->overdue + ($out[$r->tenant_id]['overdue'] ?? 0),
                'currency' => (string) $r->currency_code,
            ];
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    private function serverView(?object $s): ?array
    {
        if (! $s) {
            return null;
        }
        $pct = fn ($part, $whole) => ($part !== null && $whole) ? round(100 * $part / $whole, 1) : null;
        $cpu = ($s->cpu_load_1 !== null && $s->cpu_cores) ? round(100 * (float) $s->cpu_load_1 / (int) $s->cpu_cores, 1) : null;
        $diskUsed = ($s->disk_total_bytes !== null && $s->disk_free_bytes !== null) ? (int) $s->disk_total_bytes - (int) $s->disk_free_bytes : null;

        return [
            'taken_at'   => Carbon::parse($s->taken_at),
            'cpu_pct'    => $cpu,
            'cpu_load'   => $s->cpu_load_1 !== null ? (float) $s->cpu_load_1 : null,
            'cpu_cores'  => $s->cpu_cores !== null ? (int) $s->cpu_cores : null,
            'ram_pct'    => $pct($s->ram_used_kb, $s->ram_total_kb),
            'ram_used'   => $s->ram_used_kb !== null ? (int) $s->ram_used_kb * 1024 : null,
            'ram_total'  => $s->ram_total_kb !== null ? (int) $s->ram_total_kb * 1024 : null,
            'disk_pct'   => $pct($diskUsed, $s->disk_total_bytes),
            'disk_free'  => $s->disk_free_bytes !== null ? (int) $s->disk_free_bytes : null,
            'disk_total' => $s->disk_total_bytes !== null ? (int) $s->disk_total_bytes : null,
            'mysql'      => $s->db_bytes !== null ? (int) $s->db_bytes : null,
            'meta'       => $s->meta,
        ];
    }

    /** @return array<int, array{level:string, title:string, text:string, tenants:Collection}> */
    private function alerts(Collection $cards, ?object $server): array
    {
        $alerts = [];
        $live = $cards->filter(fn ($c) => $c['tenant']->status !== 'cancelled');

        $noBusiness = $live->filter(fn ($c) => $c['business'] === null);
        if ($noBusiness->isNotEmpty()) {
            $alerts[] = ['level' => 'warn', 'icon' => 'ti-help-hexagon', 'title' => $noBusiness->count() . ' without a business type',
                'text' => 'Pick Restaurant, Catering, Medical Store, Mart or Industry on their page.', 'tenants' => $noBusiness->pluck('tenant')];
        }

        $stale = $cards->filter(fn ($c) => $c['tenant']->status === 'active' && ! $c['tenant']->is_demo
            && $c['tenant']->database?->migration_status === 'completed'
            && (! $c['backup'] || $c['backup']['at']->lt(now()->subDays(self::BACKUP_STALE_DAYS))));
        if ($stale->isNotEmpty()) {
            $alerts[] = ['level' => 'danger', 'icon' => 'ti-database-off', 'title' => $stale->count() . ' live customer(s) without a backup in ' . self::BACKUP_STALE_DAYS . ' days',
                'text' => 'Open the tenant and press "Backup now", or turn on automatic backups.', 'tenants' => $stale->pluck('tenant')];
        }

        if ($server && $server->disk_total_bytes) {
            $used = 100 * ((int) $server->disk_total_bytes - (int) $server->disk_free_bytes) / (int) $server->disk_total_bytes;
            if ($used > self::DISK_ALERT_PERCENT) {
                $alerts[] = ['level' => 'danger', 'icon' => 'ti-server-bolt', 'title' => 'Server disk ' . round($used) . '% full',
                    'text' => 'Free space: ' . SystemUsageService::bytes((int) $server->disk_free_bytes) . '. Delete old backups or add disk.', 'tenants' => collect()];
            }
        }

        $overdue = $cards->filter(fn ($c) => ($c['due']['overdue'] ?? 0) > 0);
        if ($overdue->isNotEmpty()) {
            $alerts[] = ['level' => 'danger', 'icon' => 'ti-file-alert', 'title' => $overdue->count() . ' tenant(s) with overdue invoices',
                'text' => 'Past their due date and not fully paid.', 'tenants' => $overdue->pluck('tenant')];
        }

        if (! $server) {
            $alerts[] = ['level' => 'info', 'icon' => 'ti-clock-pause', 'title' => 'No usage reading yet',
                'text' => 'Press "Refresh now" to read the server and every tenant (read-only). It also runs every morning at 05:30.', 'tenants' => collect()];
        } elseif (Carbon::parse($server->taken_at)->lt(now()->subDays(2))) {
            $alerts[] = ['level' => 'warn', 'icon' => 'ti-clock-exclamation', 'title' => 'Usage numbers are old',
                'text' => 'Last reading ' . Carbon::parse($server->taken_at)->diffForHumans() . '. Is the scheduler (cron) running?', 'tenants' => collect()];
        }

        $unread = $live->filter(fn ($c) => $c['read_at'] && $c['read_error']);
        if ($unread->isNotEmpty()) {
            $alerts[] = ['level' => 'warn', 'icon' => 'ti-plug-x', 'title' => $unread->count() . ' tenant(s) could not be read',
                'text' => 'Their numbers show "—". Open the tenant to see why.', 'tenants' => $unread->pluck('tenant')];
        }

        return $alerts;
    }
}
