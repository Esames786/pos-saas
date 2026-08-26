<?php

namespace App\Http\Controllers\Tenant\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\PrintAgent;
use App\Models\Tenant\PrintAgentCommand;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\Printer;
use App\Services\Printing\PrintAgentPairingService;
use App\Services\Printing\PrintJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class PrintAgentApiController extends Controller
{
    /**
     * PRINT-AGENT-INSTALLER-1: exchange a 6-digit pairing code for the
     * permanent agent token. Unauthenticated by design (the code IS the
     * credential) but tenant-scoped by subdomain and rate limited like login.
     */
    public function pair(Request $request, PrintAgentPairingService $pairing): JsonResponse
    {
        $data = $request->validate([
            'pairing_code'    => ['required', 'string', 'max:12'],
            'device_name'     => ['nullable', 'string', 'max:190'],
            'device_platform' => ['nullable', 'string', 'max:190'],
            'agent_version'   => ['nullable', 'string', 'max:50'],
        ]);

        $limiterKey = 'print-agent-pair|' . app('tenant')->tenant_code . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($limiterKey, PrintAgentPairingService::MAX_ATTEMPTS)) {
            return response()->json([
                'ok'      => false,
                'message' => 'Too many pairing attempts. Try again in ' . RateLimiter::availableIn($limiterKey) . ' seconds.',
            ], 429);
        }

        try {
            $result = $pairing->pair($data['pairing_code'], [
                'device_name'     => $data['device_name'] ?? null,
                'device_platform' => $data['device_platform'] ?? null,
                'ip'              => $request->ip(),
            ]);
        } catch (\RuntimeException $e) {
            RateLimiter::hit($limiterKey, PrintAgentPairingService::CODE_TTL_MINUTES * 60);

            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        RateLimiter::clear($limiterKey);

        return response()->json([
            'ok'                    => true,
            'agent_code'            => $result['agent_code'],
            'token'                 => $result['token'],
            'poll_interval_seconds' => 3,
        ]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $agent = $this->authenticate($request);

        $data = $request->validate([
            'device_name' => ['nullable', 'string', 'max:190'],
            'device_os'   => ['nullable', 'string', 'max:190'],
            'local_ip'    => ['nullable', 'string', 'max:100'],
        ]);

        $agent->update([
            'device_name'  => $data['device_name'] ?? $agent->device_name,
            'device_os'    => $data['device_os']   ?? $agent->device_os,
            'local_ip'     => $data['local_ip']    ?? $agent->local_ip,
            'last_seen_at' => now(),
            'last_error'   => null,
        ]);

        // v2.5.0 LIVE STATUS: the agent folds its keep-awake poke results onto each printer it reached,
        // so the Printers screen shows Online/Offline + latency without anyone touching a PC. Scoped to
        // the agent's branch so it can never write another branch's printer.
        foreach ((array) $request->input('printers_status', []) as $s) {
            if (! is_array($s) || empty($s['id'])) {
                continue;
            }
            $reachable = (bool) ($s['reachable'] ?? false);
            $update = ['last_ping_ok' => $reachable, 'last_ping_at' => now()];
            if ($reachable) {
                $update['last_ping_ms'] = isset($s['latency_ms']) ? (int) $s['latency_ms'] : null;
                $update['last_seen_at'] = now();
            }
            Printer::where('id', (int) $s['id'])
                ->when($agent->branch_id, fn ($q) => $q->where(fn ($w) => $w
                    ->whereNull('branch_id')->orWhere('branch_id', $agent->branch_id)))
                ->update($update);
        }

        return response()->json([
            'ok'          => true,
            'server_time' => now()->toDateTimeString(),
            // Thermal printers drop their network interface to sleep when idle. The FIRST job
            // after a quiet spell then burns the whole connect timeout waking it — measured at
            // Khatri as 17s and 34s for a KOT, while a receipt to the SAME printer moments later
            // took 3s. KOTs always pay it because they are the first document of an order.
            // Returning the printer list lets the agent hold them awake from startup, instead of
            // only learning about a printer once a ticket has already been delayed by it.
            'printers'    => \App\Models\Tenant\Printer::query()
                ->where('is_active', true)
                ->where('printer_type', 'network')
                ->whereNotNull('ip_address')
                ->when($agent->branch_id, fn ($q) => $q->where(fn ($w) => $w
                    ->whereNull('branch_id')->orWhere('branch_id', $agent->branch_id)))
                ->get(['id', 'name', 'ip_address', 'port'])
                ->map(fn ($p) => [
                    'id'   => (int) $p->id,
                    'name' => $p->name,
                    'ip'   => $p->ip_address,
                    'port' => (int) ($p->port ?: 9100),
                ])->values(),
        ]);
    }

    public function pending(Request $request): JsonResponse
    {
        $agent = $this->authenticate($request);

        $agent->update(['last_seen_at' => now(), 'last_error' => null]);

        $jobs = DB::connection('tenant')->transaction(function () use ($agent) {
            $query = PrintJob::with('printer')
                ->where('print_status', 'queued')
                ->whereNotNull('printer_id')
                ->whereHas('printer', function ($q) {
                    $q->where('is_active', true)
                      ->where('printer_type', 'network')
                      ->whereNotNull('ip_address');
                })
                ->where(function ($q) use ($agent) {
                    if ($agent->branch_id) {
                        $q->whereNull('branch_id')->orWhere('branch_id', $agent->branch_id);
                    }
                })
                ->where(function ($q) use ($agent) {
                    if ($agent->terminal_id) {
                        $q->whereNull('terminal_id')->orWhere('terminal_id', $agent->terminal_id);
                    }
                })
                ->where(function ($q) {
                    $q->whereNull('claimed_at')
                      ->orWhere('claimed_at', '<', now()->subMinutes(2));
                })
                ->orderBy('created_at')
                ->limit(10)
                ->lockForUpdate()
                ->get();

            foreach ($query as $job) {
                $job->update([
                    'claimed_by_agent_id' => $agent->id,
                    'claimed_at'          => now(),
                ]);
            }

            return $query;
        });

        return response()->json([
            'ok'   => true,
            'jobs' => $jobs->map(fn (PrintJob $job) => [
                'id'            => $job->id,
                'job_no'        => $job->job_no,
                'document_type' => $job->document_type,
                'reference_no'  => $job->reference_no,
                'printer'       => [
                    'id'           => $job->printer?->id,
                    'name'         => $job->printer?->name,
                    'printer_type' => $job->printer?->printer_type,
                    'ip_address'   => $job->printer?->ip_address,
                    'port'         => $job->printer?->port,
                    'paper_size'   => $job->printer?->paper_size,
                ],
                'raw_payload' => $job->raw_payload,
                'payload'     => $job->payload,
            ])->values(),
        ]);
    }

    public function printed(Request $request, PrintJob $printJob, PrintJobService $printJobService): JsonResponse
    {
        $agent = $this->authenticate($request);

        if ($printJob->claimed_by_agent_id && (int) $printJob->claimed_by_agent_id !== (int) $agent->id) {
            abort(403, 'Job claimed by another agent.');
        }

        $printJobService->markPrinted($printJob);

        $printJob->printer?->update(['last_seen_at' => now(), 'last_error' => null]);

        return response()->json(['ok' => true]);
    }

    public function failed(Request $request, PrintJob $printJob, PrintJobService $printJobService): JsonResponse
    {
        $agent = $this->authenticate($request);

        $data = $request->validate([
            'error_message' => ['required', 'string'],
        ]);

        if ($printJob->claimed_by_agent_id && (int) $printJob->claimed_by_agent_id !== (int) $agent->id) {
            abort(403, 'Job claimed by another agent.');
        }

        $printJobService->markFailed($printJob, $data['error_message']);

        $printJob->printer?->update(['last_error' => $data['error_message']]);
        $agent->update(['last_error' => $data['error_message']]);

        return response()->json(['ok' => true]);
    }

    /**
     * v2.5.0 REMOTE COMMANDS: the agent claims queued Test/Reboot commands for its branch, runs them
     * on the LAN printer, and posts the outcome to commandResult(). Claimed like print jobs.
     */
    public function commands(Request $request): JsonResponse
    {
        $agent = $this->authenticate($request);

        $commands = DB::connection('tenant')->transaction(function () use ($agent) {
            $rows = PrintAgentCommand::with('printer')
                ->where('status', 'queued')
                ->whereHas('printer', function ($q) {
                    $q->where('is_active', true)
                      ->where('printer_type', 'network')
                      ->whereNotNull('ip_address');
                })
                ->where(function ($q) use ($agent) {
                    if ($agent->branch_id) {
                        $q->whereNull('branch_id')->orWhere('branch_id', $agent->branch_id);
                    }
                })
                ->orderBy('created_at')
                ->limit(10)
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                $row->update(['status' => 'running', 'claimed_by_agent_id' => $agent->id, 'claimed_at' => now()]);
            }

            return $rows;
        });

        return response()->json([
            'ok'       => true,
            'commands' => $commands->map(fn (PrintAgentCommand $cmd) => [
                'id'      => $cmd->id,
                'type'    => $cmd->type,
                'printer' => [
                    'id'   => $cmd->printer?->id,
                    'name' => $cmd->printer?->name,
                    'ip'   => $cmd->printer?->ip_address,
                    'port' => (int) ($cmd->printer?->port ?: 9100),
                ],
            ])->values(),
        ]);
    }

    public function commandResult(Request $request, PrintAgentCommand $printAgentCommand): JsonResponse
    {
        $agent = $this->authenticate($request);

        if ($printAgentCommand->claimed_by_agent_id && (int) $printAgentCommand->claimed_by_agent_id !== (int) $agent->id) {
            abort(403, 'Command claimed by another agent.');
        }

        $data = $request->validate([
            'status'     => ['required', 'in:done,failed'],
            'result'     => ['nullable', 'string', 'max:500'],
            'latency_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        $printAgentCommand->update([
            'status'       => $data['status'],
            'result'       => $data['result'] ?? null,
            'latency_ms'   => $data['latency_ms'] ?? null,
            'completed_at' => now(),
        ]);

        // A ping result IS a fresh reachability reading — reflect it on the printer so the pill updates
        // the instant the operator presses Test, not only on the next 20s keep-awake sweep.
        if ($printAgentCommand->type === 'ping' && $printAgentCommand->printer) {
            $reachable = $data['status'] === 'done';
            $printAgentCommand->printer->update([
                'last_ping_ok' => $reachable,
                'last_ping_ms' => $reachable ? ($data['latency_ms'] ?? null) : null,
                'last_ping_at' => now(),
                'last_seen_at' => $reachable ? now() : $printAgentCommand->printer->last_seen_at,
                'last_error'   => $reachable ? null : ($data['result'] ?? 'unreachable'),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    private function authenticate(Request $request): PrintAgent
    {
        $agentCode = $request->header('X-Print-Agent-Code');
        $token     = $request->header('X-Print-Agent-Token');

        abort_if(!$agentCode || !$token, 401, 'Missing print agent credentials.');

        $agent = PrintAgent::where('agent_code', $agentCode)
            ->where('is_active', true)
            ->first();

        abort_if(!$agent, 401, 'Invalid print agent.');
        abort_if(!Hash::check($token, $agent->token_hash), 401, 'Invalid print token.');

        return $agent;
    }
}
