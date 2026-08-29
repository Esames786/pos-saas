<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Reports\SalesReportCenterController;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * REPORT-SEND-TO-NETWORK-1 — the Report Center queues a Sales Report to a network thermal printer via
 * the print agent, exactly like a receipt: one `report` print_jobs row, status queued, a network
 * printer with an IP, and a populated raw_payload. The agent (which filters only on
 * queued + network printer + ip, never on document_type) will pick it up and stream it.
 */
class ReportSendToNetworkMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'print_jobs', 'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods',
            'products', 'categories', 'printers', 'terminals', 'branches', 'users',
        ]);
    }

    private function actAsOwner(int $branchId): void
    {
        $userId = $this->makeUser(['default_branch_id' => $branchId]);
        $this->actingAs(User::on('tenant')->find($userId), 'tenant');
        Auth::shouldUse('tenant');
    }

    public function test_it_queues_a_report_job_the_agent_can_stream(): void
    {
        $branchId = $this->makeBranch();
        $printerId = $this->makePrinter([
            'code' => 'RPT', 'name' => 'Counter Printer', 'print_role' => 'both',
            'printer_type' => 'network', 'ip_address' => '192.168.1.9', 'branch_id' => $branchId, 'is_active' => 1,
        ]);
        $this->actAsOwner($branchId);

        $req = Request::create('/reports/center/send-to-network', 'POST', [
            'printer_id' => $printerId, 'date_from' => '2026-08-18', 'date_to' => '2026-08-18',
            'sections' => ['overview'],
        ]);
        $req->headers->set('Accept', 'application/json');

        $resp = app()->call([app(SalesReportCenterController::class), 'sendToNetwork'], ['request' => $req]);
        $data = json_decode($resp->getContent(), true);

        $this->assertTrue($data['ok'] ?? false, 'send-to-network succeeds: ' . $resp->getContent());
        $this->assertSame('Counter Printer', $data['printer']);

        $job = PrintJob::on('tenant')->latest('id')->firstOrFail();
        $this->assertSame('report', $job->document_type);
        $this->assertSame('queued', $job->print_status);
        $this->assertSame($printerId, (int) $job->printer_id);
        $this->assertNull($job->terminal_id, 'a report is not terminal-specific');
        $this->assertNotEmpty($job->raw_payload);
        $this->assertStringContainsString('Sales Report', (string) $job->raw_payload, 'the report header is in the payload');

        // The exact predicate the print-agent poll uses — this job qualifies.
        $this->assertTrue(
            PrintJob::on('tenant')->where('id', $job->id)->where('print_status', 'queued')->whereNotNull('printer_id')
                ->whereHas('printer', fn ($q) => $q->where('is_active', true)->where('printer_type', 'network')->whereNotNull('ip_address'))
                ->exists(),
            'the queued report job is eligible for the agent poll'
        );
    }

    public function test_it_refuses_a_non_network_printer(): void
    {
        $branchId = $this->makeBranch();
        $printerId = $this->makePrinter(['code' => 'USB', 'name' => 'USB', 'print_role' => 'both', 'printer_type' => 'usb', 'ip_address' => null, 'branch_id' => $branchId]);
        $this->actAsOwner($branchId);

        $req = Request::create('/reports/center/send-to-network', 'POST', ['printer_id' => $printerId, 'date_from' => '2026-08-18', 'date_to' => '2026-08-18']);
        $req->headers->set('Accept', 'application/json');
        $resp = app()->call([app(SalesReportCenterController::class), 'sendToNetwork'], ['request' => $req]);

        $this->assertSame(422, $resp->getStatusCode());
        $this->assertSame(0, PrintJob::on('tenant')->count(), 'no job is queued for a non-network printer');
    }
}
