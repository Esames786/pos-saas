<?php

namespace Tests\Feature\Printing;

use App\Models\Tenant\SalesOrder;
use App\Services\Printing\DirectPayPrintOrchestrator;
use App\Services\Printing\PrintJobService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class DirectPayPrintOrchestratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for the isolated orchestration database.');
        }

        config()->set('database.connections.tenant', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('tenant');

        Schema::connection('tenant')->create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->nullable();
            $table->string('sale_no')->nullable();
            $table->string('status')->default('paid');
            $table->unsignedBigInteger('terminal_id')->nullable();
            $table->json('direct_pay_print_state')->nullable();
            $table->timestamp('direct_pay_print_orchestrated_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('tenant')->create('kot_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_uuid')->nullable();
            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedInteger('sequence_no')->default(1);
            $table->string('event_type')->default('normal');
            $table->timestamps();
        });
    }

    public function test_skip_is_durable_and_never_queues_kot_or_reminder(): void
    {
        $printing = Mockery::mock(PrintJobService::class);
        $printing->shouldNotReceive('queueKot');
        $printing->shouldNotReceive('queueReceipt');
        $printing->shouldNotReceive('planRemindersForKotJobs');

        $sale = $this->paidSale(DirectPayPrintOrchestrator::initialState('skip', 'skip'));
        $result = (new DirectPayPrintOrchestrator($printing))->orchestrate($sale);
        $sale->refresh();

        $this->assertTrue($result['sale_paid']);
        $this->assertSame('paid', $sale->status);
        $this->assertSame('skipped', $sale->direct_pay_print_state['kot_status']);
        $this->assertSame('not_applicable', $sale->direct_pay_print_state['reminder_status']);
        $this->assertFalse($result['retry_available']);
    }

    public function test_kot_failure_keeps_payment_paid_and_intent_retryable(): void
    {
        $printing = Mockery::mock(PrintJobService::class);
        $printing->shouldReceive('queueKot')->once()->andThrow(new \RuntimeException('temporary queue failure'));
        $printing->shouldNotReceive('queueReceipt');

        $sale = $this->paidSale(DirectPayPrintOrchestrator::initialState('print', 'skip'));
        $result = (new DirectPayPrintOrchestrator($printing))->orchestrate($sale);
        $sale->refresh();

        $this->assertSame('paid', $sale->status);
        $this->assertSame('print', $sale->direct_pay_print_state['kot_intent']);
        $this->assertSame('pending_retry', $sale->direct_pay_print_state['kot_status']);
        $this->assertSame('temporary queue failure', $sale->direct_pay_print_state['errors']['kot']);
        $this->assertTrue($result['retry_available']);
    }

    public function test_accepted_kot_with_no_new_delta_does_not_reuse_a_historical_batch(): void
    {
        $printing = Mockery::mock(PrintJobService::class);
        $printing->shouldReceive('queueKot')->once()->andReturn([]);
        $printing->shouldNotReceive('queueReceipt');
        $printing->shouldNotReceive('planRemindersForKotJobs');

        $sale = $this->paidSale(DirectPayPrintOrchestrator::initialState('print', 'skip'));
        $result = (new DirectPayPrintOrchestrator($printing))->orchestrate($sale);
        $sale->refresh();

        $this->assertSame('not_required', $sale->direct_pay_print_state['kot_status']);
        $this->assertSame('not_applicable', $sale->direct_pay_print_state['reminder_status']);
        $this->assertSame([], $result['kot_jobs']);
        $this->assertFalse($result['retry_available']);
    }

    private function paidSale(array $state): SalesOrder
    {
        $id = DB::connection('tenant')->table('sales_orders')->insertGetId([
            'client_uuid' => '11111111-1111-4111-8111-111111111111',
            'sale_no' => 'SO-DIRECT-PAY-1',
            'status' => 'paid',
            'direct_pay_print_state' => json_encode($state, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return SalesOrder::findOrFail($id);
    }
}
