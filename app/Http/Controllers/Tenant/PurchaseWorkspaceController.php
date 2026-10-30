<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\GoodsReceiptLine;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\Supplier;
use App\Services\Purchasing\PurchaseBillService;
use App\Services\Purchasing\PurchaseWorkspaceService;
use App\Support\SubmissionKey;
use App\Support\TenantFeatures;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * W3/W4 S4 — the purchase workspace: one screen, one Post, for goods arriving now (receipt; invoice and payment if
 * chosen) or goods already received (their invoice; payment if chosen). Behind the Master switch "purchase_workspace",
 * which needs "purchasing_v2"; without them the route is not there (404). The route permission opens the screen and
 * grants nothing else: each step is checked against its own screen's permission on every Post.
 */
class PurchaseWorkspaceController extends Controller
{
    public function create(Request $request)
    {
        abort_unless(self::on(), 404);

        $suppliers = Supplier::where('status', 'active')->orderBy('name')->get();
        $po = $request->filled('purchase_order_id')
            ? PurchaseOrder::with(['supplier', 'branch', 'lines.product', 'lines.variant'])->find($request->purchase_order_id)
            : null;

        // SUPPLIER-CREDIT-CLARITY-1: for suppliers paid ahead, the payments and returns a new invoice would use, oldest
        // first — the invoice card names them and asks for a tick.
        $running = app(\App\Services\Purchasing\SupplierRunningAccountService::class);
        $advances = $running->enabled()
            ? $suppliers->filter(fn ($s) => (float) $s->current_balance < -0.005)->mapWithKeys(fn ($s) => [$s->id => array_map(
                fn ($c) => ['type' => $c['type'], 'id' => $c['id'], 'label' => $c['label'], 'date' => $c['date'], 'remaining' => round($c['remaining'], 2)],
                $running->creditSources((int) $s->id))])->all()
            : [];

        return view('tenant.purchases.workspace', [
            'branches'      => Branch::orderBy('name')->get(),
            'suppliers'     => $suppliers,
            'supplierBox'   => \App\Support\SupplierBox::data($suppliers, true),
            'advances'      => $advances,
            'purchaseOrder' => $po,
            'prefillLines'  => $po ? $this->orderLinesDue($po) : [],
            // Goods already received: posted receipts that still wait for their invoice (one receipt = one invoice).
            'waiting'       => GoodsReceipt::query()->where('status', 'posted')->whereDoesntHave('bill')
                ->with(['supplier:id,name', 'branch:id,name'])->orderByDesc('receipt_date')->orderByDesc('id')->limit(300)->get(),
            'cashAccounts'  => CashBankAccount::query()->where('is_active', true)->orderBy('name')->get(),
            'allowed'       => PurchaseWorkspaceService::allowedSteps(),
            'start'         => $request->query('start') === 'received' ? 'received' : 'new',
            'pickedReceipt' => $request->filled('goods_receipt_id') ? (int) $request->query('goods_receipt_id') : null,
        ]);
    }

    /** What a picked receipt's invoice will say before it is posted — the Supplier Invoice screen's own preview. */
    public function receipt(GoodsReceipt $goodsReceipt)
    {
        abort_unless(self::on(), 404);
        $preview = app(PurchaseBillService::class)->preview($goodsReceipt);

        return response()->json([
            'grn_no'   => $goodsReceipt->grn_no,
            'supplier' => $goodsReceipt->supplier?->name,
            'preview'  => $preview,
        ]);
    }

    public function store(Request $request, PurchaseWorkspaceService $workspace)
    {
        abort_unless(self::on(), 404);

        try {
            $result = $workspace->post($request->all(), auth('tenant')->id(),
                (bool) $request->user('tenant')?->can('tenant.supplier-payments.void'));
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $step = $errors['step'][0] ?? 'receipt';
            unset($errors['step']);

            return response()->json([
                'message' => collect($errors)->flatten()->first() ?? 'Some details need a fix.',
                'errors'  => $errors,
                'step'    => $step,
                'next_key' => SubmissionKey::fresh(),
            ], 422);
        }

        $first = $result['documents'][0] ?? null;

        return response()->json([
            'ok'        => true,
            'already'   => $result['already'],
            'documents' => $result['documents'],
            'number'    => $first['number'] ?? '',
            'url'       => $first['url'] ?? url('/purchases/new'),
            'next_key'  => SubmissionKey::fresh(),
        ]);
    }

    public static function on(): bool
    {
        return TenantFeatures::on(TenantFeatures::PURCHASE_WORKSPACE) && TenantFeatures::on(TenantFeatures::PURCHASING_V2);
    }

    /** An order's lines still due, as the Goods Receipt screen prefills them. */
    private function orderLinesDue(PurchaseOrder $po): array
    {
        $received = GoodsReceiptLine::whereIn('purchase_order_line_id', $po->lines->pluck('id'))
            ->selectRaw('purchase_order_line_id, SUM(quantity_received) as qty')
            ->groupBy('purchase_order_line_id')
            ->pluck('qty', 'purchase_order_line_id');
        $out = [];
        foreach ($po->lines as $line) {
            $remaining = (float) $line->quantity_ordered - (float) ($received[$line->id] ?? 0);
            if ($remaining <= 0) {
                continue;
            }
            $out[] = [
                'purchase_order_line_id' => $line->id, 'product_id' => $line->product_id, 'product_variant_id' => $line->product_variant_id,
                'quantity_received' => 0 + $remaining, 'unit_cost' => 0 + (float) $line->unit_cost,
                'discount_amount' => 0 + (float) $line->discount_amount, 'tax_amount' => 0 + (float) $line->tax_amount,
                'batch_no' => '', 'expiry_date' => '', 'pack_unit_id' => $line->pack_unit_id ?? null, 'entry_json' => $line->entry_json ?? null,
            ];
        }

        return $out;
    }
}
