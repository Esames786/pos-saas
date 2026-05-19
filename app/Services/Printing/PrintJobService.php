<?php

namespace App\Services\Printing;

use App\Models\Tenant\PrintJob;
use App\Models\Tenant\Printer;
use App\Models\Tenant\SalesOrder;
use Illuminate\Support\Facades\Auth;

class PrintJobService
{
    public function nextJobNo(): string
    {
        $last = PrintJob::orderByDesc('id')->lockForUpdate()->first();
        $seq  = $last ? ((int) substr($last->job_no, -5)) + 1 : 1;
        return 'PJ-' . now()->format('Ymd') . '-' . str_pad($seq, 5, '0', STR_PAD_LEFT);
    }

    public function queueReceipt(SalesOrder $sale, ?Printer $printer, ?string $terminalId = null): PrintJob
    {
        return PrintJob::create([
            'job_no'             => $this->nextJobNo(),
            'branch_id'          => $sale->branch_id,
            'terminal_id'        => $terminalId,
            'printer_id'         => $printer?->id,
            'document_type'      => 'receipt',
            'print_status'       => 'queued',
            'reference_type'     => SalesOrder::class,
            'reference_id'       => $sale->id,
            'reference_no'       => $sale->order_no,
            'payload'            => ['sales_order_id' => $sale->id],
            'attempts'           => 0,
            'created_by_user_id' => Auth::id(),
        ]);
    }

    public function queueKot(SalesOrder $sale, ?Printer $printer, array $lineIds = [], ?string $terminalId = null): PrintJob
    {
        return PrintJob::create([
            'job_no'             => $this->nextJobNo(),
            'branch_id'          => $sale->branch_id,
            'terminal_id'        => $terminalId,
            'printer_id'         => $printer?->id,
            'document_type'      => 'kot',
            'print_status'       => 'queued',
            'reference_type'     => SalesOrder::class,
            'reference_id'       => $sale->id,
            'reference_no'       => $sale->order_no,
            'payload'            => ['sales_order_id' => $sale->id, 'line_ids' => $lineIds],
            'attempts'           => 0,
            'created_by_user_id' => Auth::id(),
        ]);
    }

    public function markPrinted(PrintJob $job): void
    {
        $job->update([
            'print_status' => 'printed',
            'printed_at'   => now(),
            'attempts'     => $job->attempts + 1,
        ]);

        if ($job->document_type === 'receipt' && $job->reference_id) {
            SalesOrder::where('id', $job->reference_id)->increment('receipt_print_count');
            SalesOrder::where('id', $job->reference_id)->update(['last_receipt_printed_at' => now()]);
        }

        if ($job->document_type === 'kot' && $job->reference_id) {
            SalesOrder::where('id', $job->reference_id)->increment('kot_print_count');
            SalesOrder::where('id', $job->reference_id)->update(['last_kot_printed_at' => now()]);
        }
    }

    public function markFailed(PrintJob $job, string $error): void
    {
        $job->update([
            'print_status'  => 'failed',
            'failed_at'     => now(),
            'error_message' => $error,
            'attempts'      => $job->attempts + 1,
        ]);
    }
}
