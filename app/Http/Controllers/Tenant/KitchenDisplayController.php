<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesOrderLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KitchenDisplayController extends Controller
{
    private const STATUSES = ['pending', 'preparing', 'ready', 'served'];

    public function index()
    {
        $branches   = Branch::where('status', 'active')->orderBy('name')->get();
        $categories = Category::where('is_active', true)->orderBy('name')->get();

        return view('tenant.kitchen-display.index', compact('branches', 'categories'));
    }

    public function orders(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id'   => ['nullable', 'integer', 'exists:branches,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'status'      => ['nullable', Rule::in(['all', 'pending', 'preparing', 'ready'])],
        ]);

        $lineQuery = SalesOrderLine::query()
            ->with([
                'product.category',
                'variant',
                'order.branch',
                'order.restaurantTable',
                'order.restaurantTableSession.table',
                'order.restaurantWaiter',
            ])
            ->whereNull('void_reason_id')
            ->whereRaw('(quantity - returned_quantity) > 0')
            ->whereHas('order', function ($query) use ($data) {
                $query->whereIn('status', ['held', 'paid']);

                if (!empty($data['branch_id'])) {
                    $query->where('branch_id', (int) $data['branch_id']);
                }
            })
            ->where(function ($query) {
                $query->whereNull('kitchen_status')
                    ->orWhereIn('kitchen_status', ['pending', 'preparing', 'ready']);
            });

        if (!empty($data['category_id'])) {
            $lineQuery->whereHas('product', function ($query) use ($data) {
                $query->where('category_id', (int) $data['category_id']);
            });
        }

        if (!empty($data['status']) && $data['status'] !== 'all') {
            $status = $data['status'];

            if ($status === 'pending') {
                $lineQuery->where(function ($query) {
                    $query->whereNull('kitchen_status')->orWhere('kitchen_status', 'pending');
                });
            } else {
                $lineQuery->where('kitchen_status', $status);
            }
        }

        $lines = $lineQuery->oldest('created_at')->get();

        $orders = $lines
            ->groupBy('sales_order_id')
            ->map(function ($orderLines) {
                /** @var SalesOrderLine $firstLine */
                $firstLine = $orderLines->first();
                $order     = $firstLine->order;

                $createdAt      = $order?->created_at ?? $firstLine->created_at;
                $elapsedMinutes = $createdAt ? $createdAt->diffInMinutes(now()) : 0;

                $tableLabel = $order?->restaurantTable?->table_no
                    ?? $order?->restaurantTableSession?->table?->table_no;

                return [
                    'id'              => $order?->id,
                    'sale_no'         => $order?->sale_no,
                    'status'          => $order?->status,
                    'order_type'      => $order?->order_type,
                    'branch'          => $order?->branch?->name,
                    'table'           => $tableLabel,
                    'waiter'          => $order?->restaurantWaiter?->name,
                    'created_at'      => optional($createdAt)->format('Y-m-d H:i:s'),
                    'elapsed_minutes' => $elapsedMinutes,
                    'is_late'         => $elapsedMinutes >= 15,
                    'lines'           => $orderLines->map(function (SalesOrderLine $line) {
                        $status       = $line->kitchen_status ?: 'pending';
                        $remainingQty = max(0, (float) $line->quantity - (float) $line->returned_quantity);

                        return [
                            'id'           => $line->id,
                            'product_name' => $line->product_name,
                            'variant_name' => $line->variant_name,
                            'category'     => $line->product?->category?->name,
                            'quantity'     => number_format($remainingQty, 3, '.', ''),
                            'unit_code'    => $line->unit_code,
                            'kitchen_note' => $line->kitchen_note,
                            'status'       => $status,
                            'started_at'   => optional($line->kitchen_started_at)->format('H:i'),
                            'ready_at'     => optional($line->kitchen_ready_at)->format('H:i'),
                            'completed_at' => optional($line->kitchen_completed_at)->format('H:i'),
                        ];
                    })->values(),
                ];
            })
            ->values();

        return response()->json([
            'orders'      => $orders,
            'server_time' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function updateLineStatus(Request $request, SalesOrderLine $line): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
        ]);

        $this->assertKitchenLineCanBeUpdated($line);
        $this->applyKitchenStatus($line, $data['status']);

        return response()->json([
            'success' => true,
            'line_id' => $line->id,
            'status'  => $line->kitchen_status,
        ]);
    }

    public function updateOrderStatus(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
        ]);

        abort_if(
            in_array($salesOrder->status, ['cancelled', 'returned'], true),
            422,
            'This order cannot be updated on KDS.'
        );

        $lines = $salesOrder->lines()
            ->whereNull('void_reason_id')
            ->whereRaw('(quantity - returned_quantity) > 0')
            ->get();

        foreach ($lines as $line) {
            $this->applyKitchenStatus($line, $data['status']);
        }

        return response()->json([
            'success'       => true,
            'order_id'      => $salesOrder->id,
            'status'        => $data['status'],
            'updated_lines' => $lines->count(),
        ]);
    }

    private function assertKitchenLineCanBeUpdated(SalesOrderLine $line): void
    {
        $line->loadMissing('order');

        abort_if(!$line->order, 404);

        abort_if(
            in_array($line->order->status, ['cancelled', 'returned'], true),
            422,
            'This line cannot be updated on KDS.'
        );

        abort_if($line->void_reason_id, 422, 'Voided line cannot be updated on KDS.');
    }

    private function applyKitchenStatus(SalesOrderLine $line, string $status): void
    {
        $updates = ['kitchen_status' => $status];

        if ($status === 'pending') {
            $updates['kitchen_started_at']   = null;
            $updates['kitchen_ready_at']     = null;
            $updates['kitchen_completed_at'] = null;
        }

        if ($status === 'preparing') {
            $updates['kitchen_started_at']   = $line->kitchen_started_at ?: now();
            $updates['kitchen_ready_at']     = null;
            $updates['kitchen_completed_at'] = null;
        }

        if ($status === 'ready') {
            $updates['kitchen_started_at']   = $line->kitchen_started_at ?: now();
            $updates['kitchen_ready_at']     = now();
            $updates['kitchen_completed_at'] = null;
        }

        if ($status === 'served') {
            $updates['kitchen_started_at']   = $line->kitchen_started_at ?: now();
            $updates['kitchen_ready_at']     = $line->kitchen_ready_at ?: now();
            $updates['kitchen_completed_at'] = now();
        }

        $line->update($updates);
    }
}
