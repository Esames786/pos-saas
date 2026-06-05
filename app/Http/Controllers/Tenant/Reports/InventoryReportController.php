<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Services\Reports\InventoryReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryReportController extends Controller
{
    public function __construct(private readonly InventoryReportService $service) {}

    public function valuation(Request $request)
    {
        $filters = [
            'branch_id' => $request->input('branch_id'),
        ];

        $rows   = $this->service->valuation($filters);
        $totals = $this->service->valuationTotals($filters);

        if ($request->boolean('export_csv')) {
            return $this->csvValuation($rows);
        }

        $branches = Branch::where('status', 'active')->orderBy('name')->get();

        return view('tenant.reports.inventory.valuation', compact('rows', 'totals', 'filters', 'branches'));
    }

    public function movements(Request $request)
    {
        $filters = [
            'branch_id'     => $request->input('branch_id'),
            'product_id'    => $request->input('product_id'),
            'movement_type' => $request->input('movement_type'),
            'date_from'     => $request->input('date_from', today()->format('Y-m-d')),
            'date_to'       => $request->input('date_to',   today()->format('Y-m-d')),
        ];

        $rows     = $this->service->movements($filters);
        $branches = Branch::where('status', 'active')->orderBy('name')->get();
        $movementTypes = ['purchase_receive', 'sale', 'sale_return', 'adjustment',
                          'transfer_in', 'transfer_out', 'recipe_consumption',
                          'production_in', 'production_out', 'wastage'];

        return view('tenant.reports.inventory.movements', compact('rows', 'filters', 'branches', 'movementTypes'));
    }

    public function lowStock(Request $request)
    {
        $filters  = ['branch_id' => $request->input('branch_id')];
        $products = $this->service->lowStock($filters);
        $branches = Branch::where('status', 'active')->orderBy('name')->get();

        return view('tenant.reports.inventory.low-stock', compact('products', 'filters', 'branches'));
    }

    public function expiry(Request $request)
    {
        $filters = [
            'branch_id' => $request->input('branch_id'),
            'days'      => $request->input('days', 30),
        ];

        $batches  = $this->service->expiry($filters);
        $branches = Branch::where('status', 'active')->orderBy('name')->get();

        return view('tenant.reports.inventory.expiry', compact('batches', 'filters', 'branches'));
    }

    private function csvValuation($rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $fp = fopen('php://output', 'w');
            fputcsv($fp, ['Branch', 'Product', 'Variant', 'SKU', 'Category', 'Qty On Hand', 'Avg Cost', 'Total Value']);
            foreach ($rows as $row) {
                fputcsv($fp, [
                    $row['branch'],
                    $row['product'],
                    $row['variant'],
                    $row['sku'],
                    $row['category'],
                    number_format($row['qty_on_hand'], 3),
                    number_format($row['average_cost'], 4),
                    number_format($row['total_value'], 2),
                ]);
            }
            fclose($fp);
        }, 'stock-valuation-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
    }
}
