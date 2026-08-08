<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Api\PrintAgentApiController;
use App\Models\Tenant\PrintJob;
use App\Services\Printing\PrintJobService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE-LOCAL-PRINT-1 (§18) — pin the CURRENTLY-SHIPPING Cloud print-agent physical-delivery contract
 * BEFORE the Edge local transport lands: pending() claims only queued printer-bearing NETWORK jobs
 * with branch scoping and a 2-minute lease; ownership gates completion; markPrinted is idempotent;
 * markFailed increments attempts — and (§19 narrow shared guard) a PRINTED job can never be demoted
 * to failed by a stale/duplicate report. Exercises the REAL controller methods with real Requests
 * (the agent auth headers included) against real MySQL.
 */
class CloudPrintAgentContractMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $printerId;
    private int $agentId;
    private string $agentCode;
    private string $agentToken = 'agent-token-secret-1';

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['print_jobs', 'print_agents', 'printers', 'terminals', 'branches', 'users']);
        $this->branchId = $this->makeBranch();
        $this->printerId = $this->makePrinter(['branch_id' => $this->branchId, 'printer_type' => 'network', 'ip_address' => '127.0.0.1', 'is_active' => 1]);
        $this->agentCode = 'AGT-' . Str::random(6);
        $this->agentId = (int) DB::connection('tenant')->table('print_agents')->insertGetId([
            'name' => 'Counter agent', 'agent_code' => $this->agentCode,
            'token_hash' => Hash::make($this->agentToken), 'is_active' => 1,
            'branch_id' => $this->branchId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function agentRequest(array $overrides = []): Request
    {
        $request = Request::create('/api/print-agent/pending', 'GET');
        $request->headers->set('X-Print-Agent-Code', $overrides['code'] ?? $this->agentCode);
        $request->headers->set('X-Print-Agent-Token', $overrides['token'] ?? $this->agentToken);

        return $request;
    }

    private function controller(): PrintAgentApiController
    {
        return app(PrintAgentApiController::class);
    }

    private function queuedJob(array $attrs = []): int
    {
        return $this->makePrintJob($this->printerId, array_merge([
            'document_type' => 'kot', 'print_status' => 'queued', 'attempts' => 0,
            'printed_at' => null, 'branch_id' => $this->branchId,
            'raw_payload' => "KOT PAYLOAD\n\n\n",
        ], $attrs));
    }

    public function test_pending_claims_only_queued_network_jobs_and_leases_them(): void
    {
        $claimable = $this->queuedJob();
        $browserJob = $this->makePrintJob(null, ['print_status' => 'queued', 'printed_at' => null, 'branch_id' => $this->branchId]);
        $inactivePrinter = $this->makePrinter(['branch_id' => $this->branchId, 'is_active' => 0]);
        $deadPrinterJob = $this->makePrintJob($inactivePrinter, ['print_status' => 'queued', 'printed_at' => null, 'branch_id' => $this->branchId]);
        $otherBranchJob = $this->makePrintJob($this->printerId, ['print_status' => 'queued', 'printed_at' => null, 'branch_id' => $this->makeBranch()]);
        $printedJob = $this->makePrintJob($this->printerId, ['print_status' => 'printed', 'branch_id' => $this->branchId]);

        $ids = collect($this->controller()->pending($this->agentRequest())->getData(true)['jobs'])->pluck('id');
        $this->assertSame([$claimable], $ids->all(), 'ONLY the queued, printer-bearing, active-network, own-branch job is claimable');

        $row = DB::connection('tenant')->table('print_jobs')->where('id', $claimable)->first();
        $this->assertSame($this->agentId, (int) $row->claimed_by_agent_id);
        $this->assertNotNull($row->claimed_at);

        // within the 2-minute lease: NOT re-claimable (same or another agent).
        $this->assertCount(0, $this->controller()->pending($this->agentRequest())->getData(true)['jobs']);
        DB::connection('tenant')->table('print_agents')->insert([
            'name' => 'Second', 'agent_code' => 'AGT2-' . Str::random(5), 'token_hash' => Hash::make('tok2'),
            'is_active' => 1, 'branch_id' => $this->branchId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertCount(0, $this->controller()->pending($this->agentRequest(['code' => DB::connection('tenant')->table('print_agents')->where('name', 'Second')->value('agent_code'), 'token' => 'tok2']))->getData(true)['jobs']);

        // expired lease → redelivered (the at-least-once knob).
        DB::connection('tenant')->table('print_jobs')->where('id', $claimable)->update(['claimed_at' => now()->subMinutes(3)]);
        $again = collect($this->controller()->pending($this->agentRequest())->getData(true)['jobs'])->pluck('id');
        $this->assertSame([$claimable], $again->all(), 'an expired lease re-hands the SAME stored job out');
    }

    public function test_wrong_credentials_are_refused(): void
    {
        $this->queuedJob();
        try {
            $this->controller()->pending($this->agentRequest(['token' => 'wrong-token']));
            $this->fail('a wrong agent token must 401');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function test_completion_ownership_transitions_and_printed_is_never_demoted(): void
    {
        $jobId = $this->queuedJob();
        $this->controller()->pending($this->agentRequest()); // agent 1 claims

        // a DIFFERENT agent cannot acknowledge agent 1's claim.
        DB::connection('tenant')->table('print_agents')->insert([
            'name' => 'Intruder', 'agent_code' => 'AGTX-' . Str::random(5), 'token_hash' => Hash::make('tokX'),
            'is_active' => 1, 'branch_id' => $this->branchId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $intruderCode = DB::connection('tenant')->table('print_agents')->where('name', 'Intruder')->value('agent_code');
        $job = PrintJob::findOrFail($jobId);
        try {
            $this->controller()->printed($this->agentRequest(['code' => $intruderCode, 'token' => 'tokX']), $job, app(PrintJobService::class));
            $this->fail('another agent must not complete a claimed job');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // the claimant completes: queued → printed (+printed_at); repeat is idempotent.
        $this->controller()->printed($this->agentRequest(), $job->fresh(), app(PrintJobService::class));
        $row = DB::connection('tenant')->table('print_jobs')->where('id', $jobId)->first();
        $this->assertSame('printed', $row->print_status);
        $this->assertNotNull($row->printed_at);
        $this->controller()->printed($this->agentRequest(), PrintJob::findOrFail($jobId), app(PrintJobService::class));
        $this->assertSame('printed', DB::connection('tenant')->table('print_jobs')->where('id', $jobId)->value('print_status'));

        // §19 GUARD: the claimant's own stale/duplicate FAILURE report must NOT demote printed → failed.
        $failReq = Request::create('/api/print-agent/jobs/x/failed', 'POST', ['error_message' => 'stale duplicate report']);
        $failReq->headers->set('X-Print-Agent-Code', $this->agentCode);
        $failReq->headers->set('X-Print-Agent-Token', $this->agentToken);
        $this->controller()->failed($failReq, PrintJob::findOrFail($jobId), app(PrintJobService::class));
        $row = DB::connection('tenant')->table('print_jobs')->where('id', $jobId)->first();
        $this->assertSame('printed', $row->print_status, 'printed can NEVER transition back to failed');
        $this->assertNotNull($row->printed_at);
        $this->assertSame(0, (int) $row->attempts, 'the refused demotion must not count an attempt');
    }

    public function test_failed_transition_attempts_and_admin_retry(): void
    {
        $jobId = $this->queuedJob();
        $this->controller()->pending($this->agentRequest());

        $failReq = Request::create('/api/print-agent/jobs/x/failed', 'POST', ['error_message' => 'connection refused']);
        $failReq->headers->set('X-Print-Agent-Code', $this->agentCode);
        $failReq->headers->set('X-Print-Agent-Token', $this->agentToken);
        $this->controller()->failed($failReq, PrintJob::findOrFail($jobId), app(PrintJobService::class));

        $row = DB::connection('tenant')->table('print_jobs')->where('id', $jobId)->first();
        $this->assertSame('failed', $row->print_status);
        $this->assertSame(1, (int) $row->attempts, 'attempts counts markFailed transitions (Cloud semantic preserved)');
        $this->assertNotNull($row->failed_at);
        $this->assertSame('connection refused', $row->error_message);

        // admin retry contract: failed → queued, error/claim cleared (the ONLY requeue in the codebase).
        PrintJob::findOrFail($jobId)->update([
            'print_status' => 'queued', 'error_message' => null, 'failed_at' => null,
            'claimed_by_agent_id' => null, 'claimed_at' => null,
        ]);
        $ids = collect($this->controller()->pending($this->agentRequest())->getData(true)['jobs'])->pluck('id');
        $this->assertSame([$jobId], $ids->all(), 'a retried job is claimable again');
    }
}
