<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgeLocalPrintWorkerSupervisor;
use App\Support\EdgeLocalDatabase;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * EDGE-LOCAL-PRINT-1 Slice 2 (§14) — read-only operator diagnostics for the local print runtime.
 * Mutates NOTHING; never prints credentials/tokens/secrets (printer name/ip/port are bootstrapped
 * LAN facts, not secrets; lease tokens are deliberately omitted).
 */
class EdgeLocalPrintStatusCommand extends Command
{
    protected $signature = 'edge:local:print-status {--json : Emit as JSON}';

    protected $description = 'Branch Server local print runtime diagnostics (read-only).';

    public function handle(EdgeBranchContext $context, EdgeLocalPrintWorkerSupervisor $supervisor): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:print-status only runs on a Branch Server.');

            return self::FAILURE;
        }
        EdgeLocalDatabase::useAsTenantConnection();

        $meta = $context->tryCurrent();
        $branchId = $meta ? (int) $meta->branch_id : null;
        $conn = DB::connection('tenant');

        $printers = $branchId === null ? collect() : collect($conn->table('printers')
            ->where('is_active', 1)->where('printer_type', 'network')->whereNotNull('ip_address')
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->get(['id', 'name', 'ip_address', 'port', 'print_role', 'is_default']));

        $deliveryCounts = collect($conn->table('edge_local_print_deliveries')
            ->selectRaw('delivery_state, COUNT(*) as c')->groupBy('delivery_state')->pluck('c', 'delivery_state'));
        $queuedTotal = (int) $conn->table('print_jobs')->where('print_status', 'queued')->whereNotNull('printer_id')->count();
        $queuedWaiting = (int) $conn->table('print_jobs')->where('print_status', 'queued')->whereNotNull('printer_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('edge_local_print_deliveries as d')->whereColumn('d.print_job_id', 'print_jobs.id'))
            ->count();
        $oldestWaiting = $conn->table('print_jobs')->where('print_status', 'queued')->whereNotNull('printer_id')->min('created_at');

        $status = [
            'runtime_mode' => EdgeRuntime::mode(),
            'bound_branch_id' => $branchId,
            'worker' => $supervisor->health(),
            'printers' => $printers->values()->all(),
            'queue' => [
                'queued_printer_jobs' => $queuedTotal,
                'waiting_never_attempted' => $queuedWaiting,
                'leased' => (int) ($deliveryCounts['leased'] ?? 0),
                'retry_wait' => (int) ($deliveryCounts['retry_wait'] ?? 0),
                'delivered' => (int) ($deliveryCounts['delivered'] ?? 0),
                'terminal_failed' => (int) ($deliveryCounts['terminal_failed'] ?? 0),
                'unresolved_null_printer_intents' => (int) $conn->table('print_jobs')->where('print_status', 'queued')->whereNull('printer_id')->count(),
                'oldest_queued_at' => $oldestWaiting,
            ],
        ];

        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Local print runtime — branch ' . ($branchId ?? 'UNBOUND'));
        $w = $status['worker'];
        $this->line('worker: ' . $w['state'] . ($w['heartbeat_age_seconds'] !== null ? " (heartbeat {$w['heartbeat_age_seconds']}s ago)" : '') . ($w['last_error'] ? " last_error={$w['last_error']}" : ''));
        foreach ($status['printers'] as $p) {
            $this->line("printer #{$p->id} {$p->name} {$p->ip_address}:{$p->port} role={$p->print_role}" . ($p->is_default ? ' [default]' : ''));
        }
        foreach ($status['queue'] as $k => $v) {
            $this->line("queue.{$k}: " . (is_scalar($v) ? (string) $v : json_encode($v)));
        }

        return self::SUCCESS;
    }
}
