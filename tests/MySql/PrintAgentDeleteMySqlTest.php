<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\PrintAgentController;
use App\Models\Tenant\PrintAgent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * PRINT-AGENT-DELETE — the owner can permanently remove a decommissioned agent, and doing so RELEASES
 * anything it had claimed (claimed_by_agent_id is nullable + un-constrained) so nothing dangles.
 */
class PrintAgentDeleteMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    public function test_delete_removes_the_agent_and_releases_its_claimed_jobs(): void
    {
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['print_jobs', 'print_agents', 'printers', 'branches']);
        $this->startSession(); // destroy() returns back()->with(...) which needs a session

        $branchId  = $this->makeBranch();
        $printerId = $this->makePrinter(['branch_id' => $branchId, 'printer_type' => 'network', 'ip_address' => '127.0.0.1']);
        $agentId   = DB::connection('tenant')->table('print_agents')->insertGetId([
            'name' => 'Old Counter', 'agent_code' => 'AG-' . Str::random(6), 'token_hash' => Hash::make('t'),
            'is_active' => 0, 'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // A job this agent had claimed (queued, not yet printed).
        $jobId = $this->makePrintJob($printerId, [
            'print_status' => 'queued', 'printed_at' => null, 'branch_id' => $branchId,
            'claimed_by_agent_id' => $agentId, 'claimed_at' => now(),
        ]);

        app(PrintAgentController::class)->destroy(PrintAgent::on('tenant')->findOrFail($agentId));

        $this->assertNull(PrintAgent::on('tenant')->find($agentId), 'the agent record is removed');
        $row = DB::connection('tenant')->table('print_jobs')->where('id', $jobId)->first();
        $this->assertNotNull($row, 'the job itself is NOT deleted');
        $this->assertNull($row->claimed_by_agent_id, 'the job it had claimed is released (no dangling reference)');
        $this->assertNull($row->claimed_at);
    }
}
