<?php

namespace App\Services\Edge;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Printer;
use App\Models\Tenant\ReceiptLayoutSetting;
use App\Models\Tenant\RestaurantTableSession;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesOrderLine;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\TerminalPrinterSetting;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Schema;

/**
 * W5 (Team 5) — printable documents and print preferences that are not a queued job:
 *
 *  - D-10 BILL PREVIEW document: the Online `POST /api/pos/bill-preview` (POSController::billPreview :626-800)
 *    renders the CANONICAL receipt Blade (`tenant.printing.documents.receipt`, isPreview → "BILL PREVIEW") for a
 *    transient, never-saved sale. The appliance renders the same Blade from the Edge server-side price/totals truth
 *    (EdgeLocalPosService::previewBill — zero mutation), or — for a SAVED held check — from the real order row
 *    (the per-table bill). "Send to network" is the existing receipt reprint on the saved order (Online: same).
 *  - D-24 PRINT PREFERENCES: Online feeds `terminalPrintConfig` (terminal_printer_settings.auto_print_receipt /
 *    auto_print_kot, POSController :484-489) into the page; the appliance serves the synced rows (plus the routed
 *    printers and, when present, the user's printer settings) so the Printing panel can show the same state.
 */
class EdgeLocalPrintDocumentService
{
    public function __construct(private readonly EdgeLocalPosService $pos)
    {
    }

    /** Render the canonical BILL PREVIEW for the current (unsaved) cart — Online POSController::billPreview parity. */
    public function cartPreviewHtml(array $data, User $user, Terminal $terminal): string
    {
        $preview = $this->pos->previewBill($data, $user, (int) $terminal->id); // server prices + totals, zero mutation
        $branch = Branch::on('tenant')->findOrFail((int) $terminal->branch_id);
        $totals = (array) ($preview['totals'] ?? []);
        $orderType = (string) ($preview['order_type'] ?? ($data['order_type'] ?? 'quick_sale'));

        $sale = new SalesOrder([
            'branch_id' => $branch->id,
            'terminal_id' => $terminal->id,
            'order_type' => $orderType,
            'customer_name' => $data['customer_name'] ?? null,
            'customer_phone' => $data['customer_phone'] ?? null,
            'delivery_address' => $orderType === 'delivery' ? ($data['delivery_address'] ?? null) : null,
            'vehicle_number' => $orderType === 'quick_sale' ? ($data['vehicle_number'] ?? null) : null,
            'subtotal' => (float) ($totals['subtotal'] ?? 0),
            'discount_amount' => (float) ($totals['discount_amount'] ?? 0),
            'tax_amount' => (float) ($totals['tax_amount'] ?? 0),
            'service_charge_amount' => (float) ($totals['service_charge_amount'] ?? 0),
            'delivery_charge_amount' => (float) ($totals['delivery_charge_amount'] ?? 0),
            'tip_amount' => (float) ($totals['tip_amount'] ?? 0),
            'grand_total' => (float) ($totals['grand_total'] ?? 0),
            'paid_amount' => 0,
            'change_amount' => 0,
        ]);
        $sale->sale_no = 'PREVIEW';
        $sale->sale_date = now();
        $sale->setRelation('branch', $branch);
        $sale->setRelation('customer', null);
        $sale->setRelation('createdBy', $user);
        $sale->setRelation('payments', collect());
        $session = ! empty($data['restaurant_table_session_id'])
            ? RestaurantTableSession::on('tenant')->with(['waiter', 'table.floor'])->where('branch_id', $branch->id)
                ->find((int) $data['restaurant_table_session_id'])
            : null;
        $sale->setRelation('restaurantTable', $session?->table);
        $sale->setRelation('restaurantWaiter', $session?->waiter);
        $sale->setRelation('restaurantTableSession', $session);
        $sale->setRelation('deliveryChannel', null);
        $sale->setRelation('deliveryRider', null);
        $sale->setRelation('shift', null);

        // Display lines with deal linkage (header → components) so the preview reads like the printed bill. Accepts both the
        // JSON-safe line view (product_name / variant_name / unit_code) and the raw resolved entry (_product / _line_name / _key).
        $keyToId = [];
        $headerByCombo = [];
        $productIds = collect($preview['lines'] ?? [])->map(fn ($r) => (int) (((array) $r)['product_id'] ?? 0))->filter()->unique()->all();
        $products = \App\Models\Tenant\Product::on('tenant')->with('unit')->whereIn('id', $productIds ?: [0])->get()->keyBy('id');
        $lines = collect($preview['lines'] ?? [])->values()->map(function ($r, $i) use (&$keyToId, &$headerByCombo, $products) {
            $r = (array) $r;
            $product = $r['_product'] ?? $products->get((int) ($r['product_id'] ?? 0));
            $qty = (float) ($r['quantity'] ?? 0);
            $price = (float) ($r['unit_price'] ?? 0);
            $kind = (string) ($r['line_kind'] ?? 'standard');
            $line = new SalesOrderLine([
                'product_id' => $r['product_id'] ?? null,
                'product_name' => $r['product_name'] ?? ($r['_line_name'] ?? ($product?->name ?? 'Item')),
                'variant_name' => $r['variant_name'] ?? (($r['_variant'] ?? null)?->name ?? null),
                'unit_code' => $r['unit_code'] ?? $product?->unit?->code,
                'line_kind' => $kind,
                'quantity' => $qty,
                'unit_price' => $price,
                'discount_amount' => (float) ($r['discount_amount'] ?? 0),
                'tax_amount' => (float) ($r['tax_amount'] ?? 0),
                'line_total' => $qty * $price - (float) ($r['discount_amount'] ?? 0) + (float) ($r['tax_amount'] ?? 0),
            ]);
            $line->id = $i + 1;
            $line->combo_id = $r['combo_id'] ?? null;
            $line->kitchen_note = $r['kitchen_note'] ?? null;
            if (! empty($r['_key'])) {
                $keyToId[$r['_key']] = $i + 1;
            }
            if ($kind === 'combo_header' && ! empty($r['combo_id'])) {
                $headerByCombo[(int) $r['combo_id']] = $i + 1;
            }
            $line->parent_sales_order_line_id = ! empty($r['_component_of'])
                ? ($keyToId[$r['_component_of']] ?? null)
                : ($kind === 'component' && ! empty($r['combo_id']) ? ($headerByCombo[(int) $r['combo_id']] ?? null) : null);
            $line->modifiers = $r['modifiers'] ?? [];
            $line->setRelation('product', $product);

            return $line;
        });
        $sale->setRelation('lines', $lines);

        return $this->render($sale, (int) $branch->id);
    }

    /** Render the canonical BILL PREVIEW for a SAVED held check (the per-table / recalled bill). */
    public function heldPreviewHtml(SalesOrder $sale): string
    {
        $sale->load([
            'branch', 'shift', 'createdBy', 'customer', 'lines.product.category', 'lines.variant', 'payments.method',
            'restaurantTable.floor', 'restaurantTableSession.waiter', 'restaurantWaiter',
        ]);

        return $this->render($sale, (int) $sale->branch_id);
    }

    private function render(SalesOrder $sale, int $branchId): string
    {
        $layout = ReceiptLayoutSetting::on('tenant')->where('document_type', 'receipt')->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->orderByDesc('branch_id')->first();

        return view('tenant.printing.documents.receipt', [
            'job' => null, 'salesOrder' => $sale, 'layout' => $layout, 'isPreview' => true,
        ])->render();
    }

    /**
     * D-24 — the print preferences the Printing panel shows (Online terminalPrintConfig + the routed printers).
     * `terminals` is keyed by terminal id so a terminal switch needs no round trip (Online: same map).
     */
    public function preferences(int $branchId, ?Terminal $current, ?User $user): array
    {
        $terminals = Terminal::on('tenant')->where('branch_id', $branchId)->where('status', 'active')->orderBy('name')->get(['id', 'name']);
        $settings = TerminalPrinterSetting::on('tenant')->whereIn('terminal_id', $terminals->pluck('id'))->get()->keyBy('terminal_id');
        $printers = Printer::on('tenant')->whereIn('id', $settings->pluck('receipt_printer_id')->merge($settings->pluck('kot_printer_id'))->filter()->unique())
            ->get(['id', 'name', 'printer_type', 'is_active'])->keyBy('id');
        $printer = fn ($id) => $id && $printers->has($id)
            ? ['id' => (int) $id, 'name' => $printers[$id]->name, 'printer_type' => $printers[$id]->printer_type, 'is_active' => (bool) $printers[$id]->is_active]
            : null;

        $map = [];
        foreach ($terminals as $t) {
            $s = $settings->get($t->id);
            $map[(string) $t->id] = [
                'terminal_id' => (int) $t->id,
                'terminal_name' => $t->name,
                'configured' => $s !== null,
                'auto_print_receipt' => (bool) ($s?->auto_print_receipt ?? false),
                'auto_print_kot' => (bool) ($s?->auto_print_kot ?? false),
                'receipt_printer' => $printer($s?->receipt_printer_id),
                'kot_printer' => $printer($s?->kot_printer_id),
            ];
        }

        $userSettings = null;
        if ($user && Schema::connection('tenant')->hasTable('user_printer_settings')) {
            $u = \App\Models\Tenant\UserPrinterSetting::on('tenant')->where('user_id', $user->id)->first();
            $userSettings = $u ? [
                'receipt_printer_id' => $u->receipt_printer_id ? (int) $u->receipt_printer_id : null,
                'kot_printer_id' => $u->kot_printer_id ? (int) $u->kot_printer_id : null,
                'remember_last_kot_printers' => (bool) $u->remember_last_kot_printers,
            ] : null;
        }

        return [
            'current_terminal_id' => $current ? (int) $current->id : null,
            'terminals' => $map,
            'user' => $userSettings,
        ];
    }
}
