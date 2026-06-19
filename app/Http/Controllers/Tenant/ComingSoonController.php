<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Reusable "Coming Soon" placeholder for planned ERP extensions (ERP-SOON-1).
 *
 * These modules are NOT built — every page is a read-only roadmap card. The
 * feature key arrives via the route's ->defaults('feature', '…') so route:cache
 * still works (no closures). No business logic, no writes, no tables.
 */
class ComingSoonController extends Controller
{
    /** @var array<string, array{title:string, category:string, description:string, workflow:array<int,string>}> */
    private const MODULES = [
        // ── Finance extension ──────────────────────────────────────────────
        'bank-reconciliation' => [
            'title'       => 'Bank Reconciliation',
            'category'    => 'Finance',
            'description' => 'Match your recorded cash/bank transactions against the bank statement so every entry is confirmed cleared.',
            'workflow'    => [
                'Import or enter bank statement lines for a period',
                'Auto-match statement lines against cash/bank account transactions',
                'Flag, investigate, and clear unmatched items',
                'Lock a reconciled period with a confirmed closing balance',
                'Tie reconciled balances back to the General Ledger',
            ],
        ],

        // ── Supply Chain extension ─────────────────────────────────────────
        'quotations' => [
            'title'       => 'Quotation Management',
            'category'    => 'Supply Chain',
            'description' => 'Capture and compare supplier/customer quotations before committing to an order.',
            'workflow'    => [
                'Create quotations with line items, prices, and validity dates',
                'Compare multiple supplier quotes side by side',
                'Convert an accepted quotation into a Purchase Order or Sales Order',
                'Track quotation status: draft, sent, accepted, expired',
            ],
        ],
        'purchase-requisitions' => [
            'title'       => 'Purchase Requisition',
            'category'    => 'Supply Chain',
            'description' => 'Let departments request materials internally and route them for approval before purchasing.',
            'workflow'    => [
                'Raise an internal request for items/materials needed',
                'Route requisitions for manager approval',
                'Convert approved requisitions into Purchase Orders',
                'Track requisition status and fulfilment',
            ],
        ],
        'purchase-returns' => [
            'title'       => 'Purchase Returns',
            'category'    => 'Supply Chain',
            'description' => 'Return received goods to a supplier and keep inventory and payables accurate.',
            'workflow'    => [
                'Return damaged, wrong, or excess goods to a supplier',
                'Reference the original GRN / purchase bill',
                'Adjust inventory and the supplier payable on return',
                'Issue and track debit notes against suppliers',
            ],
        ],

        // ── Manufacturing extension ────────────────────────────────────────
        'bom' => [
            'title'       => 'Bill of Materials (BOM)',
            'category'    => 'Manufacturing',
            'description' => 'Define the raw materials and standard quantities needed to produce each finished product.',
            'workflow'    => [
                'Define raw materials required for each finished product',
                'Set standard quantities and wastage allowance',
                'Link BOM to production orders',
                'Auto-calculate expected material consumption',
                'Connect production costing with inventory and finance',
            ],
        ],
        'material-requisitions' => [
            'title'       => 'Material Requisition Challan (MRC)',
            'category'    => 'Manufacturing',
            'description' => 'Issue raw materials from the store to production against an order.',
            'workflow'    => [
                'Issue raw materials from store to production against an order',
                'Generate a Material Requisition Challan (MRC)',
                'Move issued material value into Work in Process',
                'Track material issued vs BOM standard',
            ],
        ],
        'production-orders' => [
            'title'       => 'Production Orders',
            'category'    => 'Manufacturing',
            'description' => 'Plan and track the manufacture of finished products from raw materials.',
            'workflow'    => [
                'Create a production order for a finished product + quantity',
                'Attach the BOM and a target completion date',
                'Track status: planned, in-process, completed',
                'Roll up material, labour, and overhead cost',
            ],
        ],
        'wip' => [
            'title'       => 'Work in Process (WIP)',
            'category'    => 'Manufacturing',
            'description' => 'Track the value of material currently inside production but not yet finished.',
            'workflow'    => [
                'Track raw material issued to production',
                'Move value from Raw Material Inventory to WIP',
                'Complete production into Finished Goods',
                'Report open WIP by production order',
            ],
        ],
        'finished-goods' => [
            'title'       => 'Finished Goods',
            'category'    => 'Manufacturing',
            'description' => 'Receive completed production output into stock, valued at production cost.',
            'workflow'    => [
                'Receive completed output from production into finished-goods stock',
                'Value finished goods at accumulated production cost',
                'Make finished goods available for sale',
                'Track finished-goods stock by branch / warehouse',
            ],
        ],
        'scrap' => [
            'title'       => 'Hard Waste / Scrap',
            'category'    => 'Manufacturing',
            'description' => 'Record scrap and hard waste generated during production and adjust value accordingly.',
            'workflow'    => [
                'Record scrap / hard waste generated during production',
                'Separate normal (allowed) vs abnormal waste',
                'Adjust WIP / inventory for the waste',
                'Report waste percentage by product / order',
            ],
        ],
        'rejections' => [
            'title'       => 'Rejections',
            'category'    => 'Manufacturing',
            'description' => 'Capture rejected/defective units at quality control and decide their disposition.',
            'workflow'    => [
                'Record rejected / defective units at QC',
                'Decide rework, scrap, or return',
                'Adjust production yield and cost',
                'Track rejection reasons and trends',
            ],
        ],
        'consumption' => [
            'title'       => 'Production Consumption',
            'category'    => 'Manufacturing',
            'description' => 'Record actual material consumed per production order and compare to the BOM standard.',
            'workflow'    => [
                'Record actual material consumed per production order',
                'Compare actual vs BOM standard consumption',
                'Post consumption to WIP / COGS',
                'Surface variances for costing review',
            ],
        ],
        'reports' => [
            'title'       => 'Production Reporting',
            'category'    => 'Manufacturing',
            'description' => 'Analyse production output, consumption variance, WIP, and yield.',
            'workflow'    => [
                'Production output by order / product / period',
                'Material consumption vs standard (variance)',
                'WIP and finished-goods valuation',
                'Scrap, rejection, and yield analysis',
            ],
        ],
    ];

    public function show(Request $request)
    {
        $feature = (string) $request->route('feature');
        $module  = self::MODULES[$feature] ?? null;

        abort_if($module === null, 404);

        return view('tenant.coming-soon.show', [
            'module'  => $module,
            'feature' => $feature,
        ]);
    }
}
