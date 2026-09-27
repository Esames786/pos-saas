<?php

namespace App\Support\Pos;

/**
 * W-A — the page-data contract of the shared cashier view `tenant.pos.index` (architecture report §1.4).
 *
 * The view consumes exactly these server-side variables (plus `$posRuntime`, carried separately). Both page providers
 * — `POSController::index()` on the Cloud and the Edge screen provider (Team B) — build ONE instance of this class, so
 * a variable the view starts to depend on can never be present in one runtime and missing in the other: a missing key
 * fails here, loudly, instead of as an "Undefined variable" deep in a 7,000-line Blade.
 *
 * Values are passed through untouched (Eloquent models/collections on the Cloud; plain arrays/objects exposing the same
 * properties on the Edge). The view is the documentation of each value's shape; the notes below are the summary.
 */
final class PosPageData
{
    /** Every variable the view reads → one-line shape note. */
    public const KEYS = [
        'deadSession' => 'array|null — held sale whose table session died (recovery modal); null when none',
        'branches' => 'Collection<Branch> — branches the operator may sell in (id, name, allow_negative_stock, default_delivery_charge, delivery_charge_locked, held_kot_*_approval_mode, manual_discount_approval_mode)',
        'selectedBranchId' => 'int — the branch the page renders for',
        'terminals' => 'Collection<Terminal> — selectable terminals (id, name, branch_id, branch.name)',
        'categories' => 'Collection<Category> — parent categories with children',
        'pillCategoryIds' => 'int[] — parent category ids that carry sellable content',
        'contentCategoryIds' => 'int[] — every category id that carries sellable content',
        'hasUncategorizedCombos' => 'bool — show the legacy flat "Deals" pill',
        'productsPayload' => 'array[] — POS product tiles (variants, modifiers, stock, image_url via PosRuntime::asset)',
        'combosPayload' => 'Collection|array — deal tiles',
        'paymentMethods' => 'Collection<PaymentMethod> — active tenders, cash first (id, name, method_type)',
        'floors' => 'Collection<RestaurantFloor> — dine-in board (tables.openSession.waiter/salesOrders)',
        'waiters' => 'Collection<RestaurantWaiter> — branch waiters (id, name)',
        'quickReportBranches' => 'Collection — Quick Report branch picker (id, name); empty without the permission',
        'quickReportPrinters' => 'Collection — Quick Report network printers (id, name, paper_size); empty without the permission',
        'deliveryChannels' => 'Collection<DeliveryChannel> — (id, name, type)',
        'deliveryRiders' => 'Collection<DeliveryRider> — (id, name, phone, branch_id)',
        'allowedOrderTypes' => 'string[] — order types this operator may run (dine_in|takeaway|quick_sale|delivery)',
        'tableSession' => 'RestaurantTableSession|null — the dine-in session the page opened on',
        'heldSale' => 'SalesOrder|null — the held sale being recalled',
        'receiptLayouts' => 'Collection|array — branch_id → {header_text, footer_text, paper_size, show_branch_name}',
        'terminalPrintConfig' => 'Collection|array — terminal_id → {auto_print_receipt, auto_print_kot}',
        'activeMode' => 'string — the order type tab active on load',
    ];

    /** @param  array<string,mixed>  $data */
    private function __construct(private readonly array $data)
    {
    }

    /**
     * @param  array<string,mixed>  $data  exactly the KEYS (extra keys are refused too: the contract is closed)
     */
    public static function fromArray(array $data): self
    {
        $missing = array_diff(array_keys(self::KEYS), array_keys($data));
        if ($missing !== []) {
            throw new \InvalidArgumentException('PosPageData: missing [' . implode(', ', $missing) . '].');
        }
        $extra = array_diff(array_keys($data), array_keys(self::KEYS));
        if ($extra !== []) {
            throw new \InvalidArgumentException('PosPageData: unknown [' . implode(', ', $extra) . '] — add it to PosPageData::KEYS first.');
        }

        return new self($data);
    }

    public function get(string $key): mixed
    {
        if (! array_key_exists($key, self::KEYS)) {
            throw new \InvalidArgumentException("PosPageData: unknown [{$key}].");
        }

        return $this->data[$key];
    }

    /**
     * The view data for `view('tenant.pos.index', …)`: the page variables plus the runtime.
     *
     * @return array<string,mixed>
     */
    public function toViewData(PosRuntime $runtime): array
    {
        return $this->data + ['posRuntime' => $runtime];
    }
}
