<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Api\PrintAgentApiController;
use App\Models\Tenant\PrintAgentCommand;
use App\Models\Tenant\PrintJob;
use App\Services\Printing\PrintJobService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\MySql\Support\TenantFixtures;

/**
 * PRINTER-HEALTH-1 hardening — the server half of the audit fixes, against real MySQL and the REAL
 * controller methods (agent auth headers included):
 *
 *   P1#1 defer      — a ticket for a cooling printer is RE-QUEUED (deferred), never failed, never
 *                     loses an attempt, drops out of the fetch, and reprints when the window lapses.
 *   P1#5 fair fetch — one dead printer's backlog can no longer starve a healthy printer's ticket.
 *   P1#4 commands   — claim is branch-scoped; a result reflects onto the printer; a claimed-but-never
 *                     -reported command is failed by the lease; a result is ownership-gated.
 */
class PrinterHealthLifecycleMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $printerId;
    private int $agentId;
    private string $agentCode;
    private string $agentToken = 'agent-token-health-1';

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['print_agent_commands', 'print_jobs', 'print_agents', 'printers', 'terminals', 'branches', 'users']);

        $this->branchId  = $this->makeBranch();
        $this->printerId = $this->makePrinter(['branch_id' => $this->branchId, 'printer_type' => 'network', 'ip_address' => '127.0.0.1', 'is_active' => 1]);
        $this->agentCode = 'AGT-' . Str::random(6);
        $this->agentId   = (int) DB::connection('tenant')->table('print_agents')->insertGetId([
            'name' => 'Counter agent', 'agent_code' => $this->agentCode,
            'token_hash' => Hash::make($this->agentToken), 'is_active' => 1, 'branch_id' => $this->branchId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function agentRequest(string $path = '/api/print-agent/commands', string $method = 'GET', array $params = []): Request
    {
        $r = Request::create($path, $method, $params);
        $r->headers->set('X-Print-Agent-Code', $this->agentCode);
        $r->headers->set('X-Print-Agent-Token', $this->agentToken);

        return $r;
    }

    private function controller(): PrintAgentApiController
    {
        return app(PrintAgentApiController::class);
    }

    private function queued(?int $printerId, array $a = []): int
    {
        return $this->makePrintJob($printerId ?? $this->printerId, array_merge([
            'document_type' => 'kot', 'print_status' => 'queued', 'attempts' => 0, 'printed_at' => null,
            'branch_id' => $this->branchId, 'raw_payload' => "X\n\n\n", 'created_at' => now(), 'updated_at' => now(),
        ], $a));
    }

    private function insertCommand(array $a = []): int
    {
        return (int) DB::connection('tenant')->table('print_agent_commands')->insertGetId(array_merge([
            'printer_id' => $this->printerId, 'branch_id' => $this->branchId, 'type' => 'ping', 'status' => 'queued',
            'created_at' => now(), 'updated_at' => now(),
        ], $a));
    }

    /* ── P1#1 defer ─────────────────────────────────────────────────────────────────────────────── */
    public function test_defer_reparks_a_ticket_without_losing_it_or_counting_an_attempt(): void
    {
        $jobId = $this->queued($this->printerId);
        $this->controller()->pending($this->agentRequest('/api/print-agent/pending')); // agent claims it

        $req = $this->agentRequest('/api/print-agent/jobs/x/defer', 'POST', ['reason' => 'unreachable', 'cooldown_seconds' => 30]);
        $this->controller()->defer($req, PrintJob::findOrFail($jobId), app(PrintJobService::class));

        $row = DB::connection('tenant')->table('print_jobs')->where('id', $jobId)->first();
        $this->assertSame('queued', $row->print_status, 'a deferred ticket stays queued, never failed');
        $this->assertSame(0, (int) $row->attempts, 'a printer being off must NOT count against the ticket');
        $this->assertNull($row->claimed_at, 'defer releases the claim so any agent can pick it up later');
        $this->assertNotNull($row->deferred_until);

        // Deferred → excluded from the fetch (so it cannot starve other printers while it waits).
        $this->assertCount(0, $this->controller()->pending($this->agentRequest('/api/print-agent/pending'))->getData(true)['jobs'], 'a deferred ticket stays out of the fetch');

        // Window lapses (printer is back) → re-offered and printable again.
        DB::connection('tenant')->table('print_jobs')->where('id', $jobId)->update(['deferred_until' => now()->subSecond()]);
        $ids = collect($this->controller()->pending($this->agentRequest('/api/print-agent/pending'))->getData(true)['jobs'])->pluck('id');
        $this->assertSame([$jobId], $ids->all(), 'the ticket reprints the moment the defer window lapses — nothing is lost');
    }

    /* ── P1#5 fair fetch ────────────────────────────────────────────────────────────────────────── */
    public function test_one_dead_printers_backlog_cannot_starve_a_healthy_printer(): void
    {
        $printerB = $this->makePrinter(['branch_id' => $this->branchId, 'printer_type' => 'network', 'ip_address' => '127.0.0.2', 'is_active' => 1]);

        // Printer A: a wall of 12 OLDER tickets. Printer B: a single NEWER ticket.
        for ($i = 0; $i < 12; $i++) {
            $this->queued($this->printerId, ['created_at' => now()->subMinutes(30)->addSeconds($i)]);
        }
        $bJob = $this->queued($printerB, ['created_at' => now()]);

        $jobs = collect($this->controller()->pending($this->agentRequest('/api/print-agent/pending'))->getData(true)['jobs']);
        $byPrinter = $jobs->groupBy(fn ($j) => $j['printer']['id']);

        $this->assertLessThanOrEqual(5, $byPrinter->get($this->printerId)->count(), 'a single printer takes at most PER_PRINTER_LIMIT slots per poll');
        $this->assertTrue($jobs->pluck('id')->contains($bJob), 'the healthy printer B ticket is served despite printer A\'s backlog');
    }

    /* ── P1#4 command claim + branch scope + result + lease ─────────────────────────────────────── */
    public function test_command_claim_branch_scope_result_reflection_and_lease_expiry(): void
    {
        $cmdId = $this->insertCommand(['type' => 'ping']);

        // A command for ANOTHER branch must never be handed to our agent.
        $otherBranch  = $this->makeBranch();
        $otherPrinter = $this->makePrinter(['branch_id' => $otherBranch, 'printer_type' => 'network', 'ip_address' => '127.0.0.9', 'is_active' => 1]);
        $otherCmd     = $this->insertCommand(['printer_id' => $otherPrinter, 'branch_id' => $otherBranch]);

        $out = $this->controller()->commands($this->agentRequest())->getData(true);
        $ids = collect($out['commands'])->pluck('id');
        $this->assertTrue($ids->contains($cmdId), 'own-branch command is claimed');
        $this->assertFalse($ids->contains($otherCmd), 'another branch\'s command is never handed over');
        $this->assertSame('running', DB::connection('tenant')->table('print_agent_commands')->where('id', $cmdId)->value('status'));

        // A "done" ping result reflects straight onto the printer's health so the pill updates at once.
        $resReq = $this->agentRequest('/api/print-agent/commands/x/result', 'POST', ['status' => 'done', 'result' => 'reachable', 'latency_ms' => 7]);
        $this->controller()->commandResult($resReq, PrintAgentCommand::findOrFail($cmdId));

        $this->assertSame('done', DB::connection('tenant')->table('print_agent_commands')->where('id', $cmdId)->value('status'));
        $pr = DB::connection('tenant')->table('printers')->where('id', $this->printerId)->first();
        $this->assertSame(1, (int) $pr->last_ping_ok);
        $this->assertSame(7, (int) $pr->last_ping_ms);

        // LEASE: a command claimed but never reported (crash / lost POST) is failed, not stuck running.
        $stale = $this->insertCommand([
            'type' => 'reboot', 'status' => 'running', 'claimed_by_agent_id' => $this->agentId,
            'claimed_at' => now()->subSeconds(200), 'created_at' => now()->subSeconds(210), 'updated_at' => now()->subSeconds(200),
        ]);
        PrintAgentCommand::expireStale();
        $this->assertSame('failed', DB::connection('tenant')->table('print_agent_commands')->where('id', $stale)->value('status'), 'a stuck running command is failed by the lease');
    }

    public function test_command_result_is_ownership_gated(): void
    {
        $cmdId = $this->insertCommand(['status' => 'running', 'claimed_by_agent_id' => $this->agentId, 'claimed_at' => now()]);

        DB::connection('tenant')->table('print_agents')->insert([
            'name' => 'Intruder', 'agent_code' => 'AGTX-' . Str::random(5), 'token_hash' => Hash::make('tokX'),
            'is_active' => 1, 'branch_id' => $this->branchId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $intruderCode = DB::connection('tenant')->table('print_agents')->where('name', 'Intruder')->value('agent_code');

        $req = Request::create('/api/print-agent/commands/x/result', 'POST', ['status' => 'done']);
        $req->headers->set('X-Print-Agent-Code', $intruderCode);
        $req->headers->set('X-Print-Agent-Token', 'tokX');

        try {
            $this->controller()->commandResult($req, PrintAgentCommand::findOrFail($cmdId));
            $this->fail('another agent must not post a result for a command it did not claim');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }
}
