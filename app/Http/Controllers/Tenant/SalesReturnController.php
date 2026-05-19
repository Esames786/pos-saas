<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesReturn;
use App\Services\Sales\SalesReturnService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SalesReturnController extends Controller
{
    public function index(Request $request)
    {
        $query = SalesReturn::with(['order', 'branch', 'createdBy'])
            ->orderByDesc('return_date')
            ->orderByDesc('id');

        return view('tenant.sales-returns.index', [
            'returns' => $query->paginate(15)->withQueryString(),
        ]);
    }

    public function create(Request $request)
    {
        $salesOrder = null;

        if ($request->filled('sales_order_id')) {
            $salesOrder = SalesOrder::with(['branch', 'lines.product', 'lines.variant'])
                ->whereIn('status', ['paid', 'partially_returned'])
                ->find($request->sales_order_id);
        }

        return view('tenant.sales-returns.create', compact('salesOrder'));
    }

    public function store(Request $request, SalesReturnService $salesReturnService)
    {
        $data = $request->validate([
            'sales_order_id'              => ['required', 'exists:sales_orders,id'],
            'reason'                      => ['nullable', 'string'],
            'refund_method'               => ['nullable', Rule::in(['cash', 'bank_transfer', 'card', 'other'])],
            'refund_amount'               => ['nullable', 'numeric', 'min:0'],
            'lines'                       => ['required', 'array', 'min:1'],
            'lines.*.sales_order_line_id' => ['required', 'exists:sales_order_lines,id'],
            'lines.*.quantity'            => ['required', 'numeric', 'min:0.001'],
        ]);

        $salesOrder = SalesOrder::with(['branch', 'lines.product', 'lines.variant'])
            ->whereIn('status', ['paid', 'partially_returned'])
            ->findOrFail($data['sales_order_id']);

        try {
            $salesReturn = $salesReturnService->processReturn(
                salesOrder:   $salesOrder,
                lines:        $data['lines'],
                reason:       $data['reason'] ?? null,
                refundMethod: $data['refund_method'] ?? null,
                refundAmount: isset($data['refund_amount']) ? (float) $data['refund_amount'] : null,
                userId:       auth('tenant')->id(),
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['return' => $e->getMessage()])->withInput();
        }

        return redirect(url('/sales-returns/' . $salesReturn->id))->with('status', 'Sales return posted.');
    }

    public function show(SalesReturn $salesReturn)
    {
        $salesReturn->load(['order', 'branch', 'lines.product', 'lines.variant', 'createdBy']);

        return view('tenant.sales-returns.show', compact('salesReturn'));
    }
}
