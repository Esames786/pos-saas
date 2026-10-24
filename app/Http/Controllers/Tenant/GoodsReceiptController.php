<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\GoodsReceiptLine;
use App\Models\Tenant\Product;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\Supplier;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\PurchasingService;
use App\Support\SubmissionKey;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class GoodsReceiptController extends Controller
{
    public function __construct(protected PurchasingService $purchasingService, private GoodsReceiptService $receipts) {}

    public function index(Request $request)
    {
        // GRN-VOID-LIST-1: `bill` is eager-loaded now. The Bill column already read it per row,
        // which was one extra query per receipt, and the Void button reads it again.
        $query = GoodsReceipt::with(['branch', 'supplier', 'purchaseOrder', 'postedBy', 'bill'])
            ->orderByDesc('receipt_date')
            ->orderByDesc('id');

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        $receipts  = $query->paginate(20)->withQueryString();
        $branches  = Branch::orderBy('name')->get();
        $suppliers = Supplier::where('status', 'active')->orderBy('name')->get();

        return view('tenant.goods-receipts.index', compact('receipts', 'branches', 'suppliers'));
    }

    public function create(Request $request)
    {
        $branches     = Branch::orderBy('name')->get();
        $suppliers    = Supplier::where('status', 'active')->orderBy('name')->get();
        // GRN-PICKER-GUARD-1: a GRN receives PURCHASED goods, so the picker offers only what is
        // bought. It used to offer every active product — the whole menu — and on two tenants
        // literally every item in the list blew up on Post, because receiving calls
        // InventoryService and a sale-only item is not stock tracked. Same rule the purchase
        // lookup already applies (ProductLookupController, context=purchase).
        $products     = Product::with(['unit', 'variants'])
            ->where('status', 'active')
            ->where('is_purchasable', true)
            ->orderBy('name')
            ->get();
        $openOrders   = PurchaseOrder::with(['supplier', 'lines.product'])
            ->whereIn('status', ['approved'])
            ->orderByDesc('order_date')
            ->get();
        $selectedPo   = null;
        $prefillLines = [];

        if ($request->filled('purchase_order_id')) {
            $selectedPo = PurchaseOrder::with(['supplier', 'branch', 'lines.product', 'lines.variant'])
                ->find($request->purchase_order_id);

            if ($selectedPo) {
                // Already-received qty per PO line (supports partial receiving).
                $received = GoodsReceiptLine::whereIn('purchase_order_line_id', $selectedPo->lines->pluck('id'))
                    ->selectRaw('purchase_order_line_id, SUM(quantity_received) as qty')
                    ->groupBy('purchase_order_line_id')
                    ->pluck('qty', 'purchase_order_line_id');

                foreach ($selectedPo->lines as $line) {
                    $remaining = (float) $line->quantity_ordered - (float) ($received[$line->id] ?? 0);
                    if ($remaining <= 0) {
                        continue; // fully received — skip
                    }

                    $prefillLines[] = [
                        'purchase_order_line_id' => $line->id,
                        'product_id'             => $line->product_id,
                        'product_variant_id'     => $line->product_variant_id,
                        'quantity_received'      => 0 + $remaining,
                        'unit_cost'              => 0 + (float) $line->unit_cost,
                        'discount_amount'        => 0 + (float) $line->discount_amount,
                        'tax_amount'             => 0 + (float) $line->tax_amount,
                        'batch_no'               => '',
                        'expiry_date'            => '',
                        'pack_unit_id'           => $line->pack_unit_id ?? null,
                        // PURCHASE-DISCOUNTS-1: what the order said — disc and scheme come with "Receive".
                        'entry_json'             => $line->entry_json ?? null,
                    ];
                }
            }
        }

        return view('tenant.goods-receipts.create', [
            'branches'      => $branches,
            'suppliers'     => $suppliers,
            'products'      => $products,
            'openOrders'    => $openOrders,
            'purchaseOrder' => $selectedPo,
            'prefillLines'  => $prefillLines,
            'entryV2'       => $entryV2 = \App\Support\TenantFeatures::on(\App\Support\TenantFeatures::PURCHASING_V2),
            'supplierBox'   => $entryV2 ? \App\Support\SupplierBox::data($suppliers, true) : null,
        ]);
    }

    public function store(Request $request)
    {
        // W3/W4 S4: the checks and the post are GoodsReceiptService's (shared with the purchase workspace), moved
        // unchanged: the new entry area's packs into today's lines, blank rows dropped, the rules, batch / expiry.
        $prepared = $this->receipts->prepare($request->all());

        $userId = auth('tenant')->id();

        // W3/W4 S0: one form, one receipt. The same form posted again — a double click, or a retry after an answer
        // that never arrived (the screen itself says "press Post again") — gets the receipt it already made, never a
        // second one with the stock doubled. The same key with other lines is refused. A form without a key (a tab
        // opened before this release) posts exactly as before.
        $request->validate([SubmissionKey::FIELD => SubmissionKey::rule()]);
        $key = SubmissionKey::usable($request->input(SubmissionKey::FIELD), 'goods_receipts');
        $hash = $key ? SubmissionKey::hash(Arr::except($prepared['input'], ['_token', SubmissionKey::FIELD])) : null;
        if ($key && ($existing = GoodsReceipt::query()->where('submission_key', $key)->first())) {
            return $this->alreadyPosted($request, $existing, hash_equals((string) $existing->submission_hash, (string) $hash));
        }

        try {
            $grn = $this->receipts->post($prepared, $userId, $key, $hash);
        } catch (QueryException $e) {
            // Two posts of one form raced past the look above: the unique key let only one through.
            if ($key && SubmissionKey::isDuplicate($e, $key)
                && ($existing = GoodsReceipt::query()->where('submission_key', $key)->first())) {
                return $this->alreadyPosted($request, $existing, hash_equals((string) $existing->submission_hash, (string) $hash));
            }
            throw $e;
        }

        // PURCHASING-V2-2: the new screen saves without leaving the page.
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'id' => $grn->id, 'number' => $grn->grn_no, 'url' => url('/goods-receipts/' . $grn->id),
                'next_key' => SubmissionKey::fresh()]);
        }

        return redirect(url('/goods-receipts'))->with('status', 'Goods receipt posted.');
    }

    /**
     * W3/W4 S0: this form already made a receipt. The same lines → that receipt (nothing posted twice). Other lines
     * under the same key → refused, with a new key, so pressing Post again makes a second receipt on purpose.
     */
    private function alreadyPosted(Request $request, GoodsReceipt $existing, bool $same)
    {
        if (! $same) {
            $message = 'This form was already posted as goods receipt ' . $existing->grn_no . '. Nothing more was posted — press Post again to make a second receipt with these lines.';

            return $request->expectsJson()
                ? response()->json(['message' => $message, 'errors' => ['receipt' => [$message]], 'next_key' => SubmissionKey::fresh()], 422)
                : back()->withInput($request->except(SubmissionKey::FIELD))->withErrors(['receipt' => $message]);
        }
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'already' => true, 'id' => $existing->id, 'number' => $existing->grn_no,
                'url' => url('/goods-receipts/' . $existing->id), 'next_key' => SubmissionKey::fresh()]);
        }

        return redirect(url('/goods-receipts'))->with('status', 'Goods receipt ' . $existing->grn_no . ' was already posted — nothing was posted twice.');
    }

    /**
     * GRN-VOID-1 — take back a receipt that has not been billed yet.
     *
     * A posted receipt stays un-editable on purpose: changing a quantity afterwards would rewrite
     * stock history that sales and costs already lean on. Voiding is the honest alternative — the
     * receipt stays on the record and the stock is undone by its own reversal entries. To correct
     * a mistake: void, then create the receipt again with the right figures.
     */
    public function void(Request $request, GoodsReceipt $goodsReceipt)
    {
        $goodsReceipt->load('lines.product', 'bill');

        try {
            $this->purchasingService->voidGrn(
                $goodsReceipt,
                auth('tenant')->id(),
                $request->input('void_reason')
            );
        } catch (\RuntimeException $e) {
            // The service's refusals are written for the person reading them, so they are shown
            // rather than turned into a 500.
            return back()->withErrors(['void' => $e->getMessage()]);
        }

        return redirect(url('/goods-receipts'))->with('success', 'Goods receipt ' . $goodsReceipt->grn_no . ' voided.');
    }
    public function show(GoodsReceipt $goodsReceipt)
    {
        $goodsReceipt->load([
            'branch', 'supplier', 'purchaseOrder', 'postedBy',
            'lines.product', 'lines.variant',
        ]);

        $ledgers = \App\Models\Tenant\StockLedger::where('reference_type', GoodsReceipt::class)
            ->where('reference_id', $goodsReceipt->id)
            ->with(['branch', 'product'])
            ->get();

        return view('tenant.goods-receipts.show', compact('goodsReceipt', 'ledgers'));
    }
}
