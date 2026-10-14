<?php

namespace App\Services\OnlineOrdering;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Combo;
use App\Models\Tenant\CustomerAddress;
use App\Models\Tenant\DeliveryChannel;
use App\Models\Tenant\DeliveryZone;
use App\Models\Tenant\Product;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Shift;
use App\Models\Tenant\WebOrderRoute;
use App\Models\Tenant\WebSetting;
use App\Services\Sales\SalesTotalsService;
use App\Services\Tenant\CustomerDirectory;
use App\Support\TenantClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ONLINE-ORDERING-1 (Phase 2c) — website ka order POS par DRAFT ban kar utarta hai.
 *
 * Shakl HUBAHU wohi jo POS ka "Save as draft" (HeldSaleController::store) likhta hai — status `held`,
 * `is_draft` true, deal = `combo_header` (poori qeemat) + `component` lines (0), modifiers ka JSON,
 * `unit_price` me add-on shamil, totals `SalesTotalsService` se — taake cashier ka recall, Hold (KOT),
 * Pay, stock aur reports bilkul waise chalen jaise counter ke draft par. Guard test dono ko line-by-line
 * milata hai.
 *
 * Farq sirf ye (jaan boojh kar): order_source `website`; koi user nahi (customer ne banaya); rider nahi
 * (POS ka Hold rider maangta hai — cashier wahin chunega); website ki tafseel `web_meta` me.
 *
 * Qeemat customer ka browser tay NAHI karta: har line server par `WebMenuService` se dobara nikalti hai.
 */
class WebsiteOrderService
{
    public function __construct(
        private readonly WebMenuService $menu,
        private readonly WebsiteAvailability $availability,
        private readonly SalesTotalsService $totals,
        private readonly CustomerDirectory $customers,
        private readonly TenantClock $clock,
    ) {}

    /**
     * @param  array{key: string, type: string, area?: ?int, branch?: ?int, customer: array, note?: ?string,
     *               lines: list<array{option: string, qty: int, addons?: list<int>, note?: ?string}>}  $in
     */
    public function place(array $in): SalesOrder
    {
        if ($existing = SalesOrder::where('web_order_key', $in['key'])->first()) {
            return $existing;   // wohi order dobara — do dafa "Place order" = aik order
        }

        $settings = WebSetting::tenantDefault();
        $pickup = $in['type'] === 'pickup';

        $zone = null;
        if ($pickup) {
            $branch = Branch::where('status', 'active')->find((int) ($in['branch'] ?? $settings->default_branch_id));
        } else {
            $zone = DeliveryZone::where('is_active', true)->find((int) ($in['area'] ?? 0));
            $branch = $zone ? Branch::where('status', 'active')->find($zone->branch_id) : null;
            if (! $zone) {
                $this->fail('area', 'We do not deliver to this area. Please choose another area or Pick-Up.');
            }
        }
        if (! $branch) {
            $this->fail('branch', 'This branch is not taking orders.');
        }

        $status = $this->availability->status($settings);
        $staffed = $status['branches'][$branch->id] ?? ['delivery' => false, 'pickup' => false];
        if (! $status['open'] || ! ($pickup ? $staffed['pickup'] : $staffed['delivery'])) {
            $this->fail('closed', $status['message'] ?: 'We are not taking orders right now. Please try again shortly.');
        }

        [$lines, $itemNotes] = $this->resolveLines($in['lines'], $branch);
        $subtotal = array_sum(array_map(fn ($l) => $l['quantity'] * $l['unit_price'], $lines));

        $fee = 0.0;
        if ($zone) {
            if ($zone->min_order !== null && $subtotal < (float) $zone->min_order) {
                $this->fail('min_order', 'Minimum order for '.$zone->area_name.' is Rs. '.number_format((float) $zone->min_order).'.');
            }
            $fee = ($zone->free_delivery_above !== null && $subtotal >= (float) $zone->free_delivery_above) ? 0.0 : (float) $zone->fee;
        }

        $orderType = $pickup ? 'takeaway' : 'delivery';
        [$terminalId, $shift] = $this->terminalFor($branch->id, $orderType);
        if (! $shift) {
            $this->fail('closed', 'We are not taking orders right now. Please try again shortly.');
        }

        $phone = $this->localPhone((string) $in['customer']['phone']);
        $c = $in['customer'];

        return DB::connection('tenant')->transaction(function () use ($in, $branch, $zone, $fee, $orderType, $terminalId, $shift, $lines, $itemNotes, $phone, $c) {
            $customer = $this->customers->findOrCreateByPhone($phone, (string) $c['name']);
            $address = $orderType === 'delivery' ? trim(implode(', ', array_filter([$c['address'] ?? null, $c['landmark'] ?? null, $zone?->area_name]))) : null;
            if ($customer && $address) {
                $this->rememberAddress($customer->id, $address);
            }

            $productsById = Product::whereIn('id', array_column($lines, 'product_id'))->get(['id', 'category_id'])->keyBy('id');
            $totals = $this->totals->calculate(
                resolvedLines: array_map(fn ($l) => [
                    'product_id' => $l['product_id'],
                    'category_id' => (int) ($productsById->get($l['product_id'])?->category_id ?? 0),
                    'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'],
                    'discount_amount' => 0, 'tax_amount' => 0,
                ], $lines),
                discountType: 'none', discountValue: 0, branchId: $branch->id, orderType: $orderType,
                promoCode: null, tipAmount: 0, deliveryCharge: $orderType === 'delivery' ? $fee : 0,
            );

            $sale = SalesOrder::create([
                'sale_no' => 'HS-'.now()->format('YmdHis').'-'.random_int(100, 999),
                'branch_id' => $branch->id,
                'terminal_id' => $terminalId,
                'shift_id' => $shift->id,
                'business_date' => $shift->business_date?->toDateString(),
                'order_source' => 'website',
                'order_type' => $orderType,
                'delivery_channel_id' => $orderType === 'delivery' ? $this->websiteChannelId() : null,
                'delivery_rider_id' => null,
                'delivery_address' => $address ? Str::limit($address, 500, '') : null,
                'delivery_charge_amount' => $totals['delivery_charge_amount'] ?? 0,
                'customer_id' => $customer?->id,
                'customer_name' => trim((string) $c['name']),
                'customer_phone' => $phone,
                'promotion_id' => $totals['promotion_id'],
                'promo_code' => $totals['promo_code'],
                'sale_date' => now(),
                'subtotal' => $totals['subtotal'],
                'discount_type' => 'none',
                'discount_value' => 0,
                'discount_amount' => $totals['discount_amount'],
                'tax_amount' => $totals['tax_amount'],
                'service_charge_amount' => $totals['service_charge_amount'],
                'tip_amount' => 0,
                'grand_total' => $totals['grand_total'],
                'status' => 'held',
                'is_draft' => true,
                'inventory_posted' => false,
                'created_by_user_id' => null,
                'notes' => $this->readableNote($c, $in['note'] ?? null, $itemNotes),
                'web_order_key' => $in['key'],
                'web_token' => Str::random(40),
                'web_meta' => [
                    'area' => $zone?->area_name, 'area_id' => $zone?->id,
                    'address' => $c['address'] ?? null, 'landmark' => $c['landmark'] ?? null,
                    'lat' => $c['lat'] ?? null, 'lng' => $c['lng'] ?? null,
                    'alt_phone' => $c['alt'] ?? null, 'note' => $in['note'] ?? null, 'item_notes' => $itemNotes,
                    'eta_minutes' => $orderType === 'delivery' ? ($zone?->eta_minutes ?: WebSetting::tenantDefault()->default_eta_minutes) : WebSetting::tenantDefault()->pickup_eta_minutes,
                    'placed_at' => now()->toIso8601String(),
                ],
            ]);

            $headers = [];
            foreach ($lines as $l) {
                $created = $sale->lines()->create([
                    'parent_sales_order_line_id' => $l['parent'] !== null ? $headers[$l['parent']] : null,
                    'line_kind' => $l['line_kind'],
                    'combo_id' => $l['combo_id'],
                    'product_id' => $l['product_id'],
                    'product_variant_id' => null,
                    'product_name' => $l['product_name'],
                    'variant_name' => null,
                    'unit_code' => $l['unit_code'],
                    'quantity' => $l['quantity'],
                    'unit_price' => $l['unit_price'],
                    'unit_cost' => 0,
                    'cost_total' => 0,
                    'discount_amount' => 0,
                    'tax_amount' => 0,
                    'line_total' => $l['quantity'] * $l['unit_price'],
                    'modifiers' => $l['modifiers'],
                    'kot_sent' => false,
                    'kot_sent_quantity' => 0,
                ]);
                if ($l['line_kind'] === 'combo_header') {
                    $headers[$l['key']] = $created->id;
                }
            }

            return $sale->fresh('lines');
        });
    }

    /**
     * Cart ki har line ko POS lines me badlo — qeemat server ke menu se, customer ki bheji hui nahi.
     *
     * @return array{0: list<array>, 1: list<array{item: string, note: string}>}
     */
    private function resolveLines(array $cartLines, Branch $branch): array
    {
        $now = CarbonImmutable::now($this->clock->businessTimezone($branch))->format('H:i');
        $options = [];
        foreach ($this->menu->menu($branch->id, $now) as $category) {
            foreach ($category['items'] as $card) {
                foreach ($card['options'] as $o) {
                    $options[$o['kind'].':'.$o['pos_id']] = $o + ['card' => $card['name']];
                }
            }
        }

        $lines = [];
        $notes = [];
        $gone = [];
        foreach (array_values($cartLines) as $i => $cl) {
            $o = $options[$cl['option']] ?? null;
            if (! $o) {
                $gone[] = $cl['option'];

                continue;
            }
            $qty = max(1, min(50, (int) $cl['qty']));
            $modifiers = $this->resolveAddons($o, array_map('intval', $cl['addons'] ?? []));
            $unit = $o['price'] + array_sum(array_column($modifiers, 'price_delta'));
            if (! empty($cl['note'])) {
                $notes[] = ['item' => $o['card'].($o['label'] ? ' ('.$o['label'].')' : ''), 'note' => Str::limit(trim((string) $cl['note']), 200, '')];
            }

            if ($o['kind'] === 'combo') {
                $combo = Combo::with(['components' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'), 'components.product.unit'])->find($o['pos_id']);
                $first = $combo?->components->first();
                if (! $combo || ! $first) {
                    $gone[] = $cl['option'];

                    continue;
                }
                $key = 'combo:'.$combo->id.':'.$i;
                $lines[] = ['key' => $key, 'parent' => null, 'line_kind' => 'combo_header', 'combo_id' => $combo->id,
                    'product_id' => (int) $first->product_id, 'product_name' => $combo->name, 'unit_code' => $first->product?->unit?->code,
                    'quantity' => $qty, 'unit_price' => $unit, 'modifiers' => $modifiers];
                foreach ($combo->components as $comp) {
                    $lines[] = ['key' => null, 'parent' => $key, 'line_kind' => 'component', 'combo_id' => $combo->id,
                        'product_id' => (int) $comp->product_id, 'product_name' => $comp->product?->name ?? '', 'unit_code' => $comp->product?->unit?->code,
                        'quantity' => $qty * (float) $comp->quantity, 'unit_price' => 0, 'modifiers' => []];
                }
            } else {
                $product = Product::with('unit')->find($o['pos_id']);
                $lines[] = ['key' => null, 'parent' => null, 'line_kind' => 'standard', 'combo_id' => null,
                    'product_id' => (int) $product->id, 'product_name' => $product->name, 'unit_code' => $product->unit?->code,
                    'quantity' => $qty, 'unit_price' => $unit, 'modifiers' => $modifiers];
            }
        }

        if ($gone !== []) {
            $this->fail('lines', 'Some items in your cart are no longer available. Please review your cart.', ['unavailable' => $gone]);
        }
        if ($lines === []) {
            $this->fail('lines', 'Your cart is empty.');
        }

        return [$lines, $notes];
    }

    /**
     * Add-ons sirf usi size ke groups se, hadd (min/max) ke andar. Shakl wohi jo POS line par likhta hai.
     *
     * @return list<array{modifier_group_id: int, modifier_group_name: string, modifier_id: int, name: string, price_delta: float}>
     */
    private function resolveAddons(array $option, array $picked): array
    {
        $out = [];
        foreach ($option['addons'] as $g) {
            $ids = array_column($g['options'], 'id');
            $chosen = array_values(array_intersect($ids, $picked));
            if (count($chosen) < $g['min'] || count($chosen) > $g['max']) {
                $this->fail('lines', 'Please choose '.$g['name'].' for '.$option['card'].'.');
            }
            foreach ($g['options'] as $m) {
                if (in_array($m['id'], $chosen, true)) {
                    $out[] = ['modifier_group_id' => $g['id'], 'modifier_group_name' => $g['name'],
                        'modifier_id' => $m['id'], 'name' => $m['name'], 'price_delta' => round((float) $m['price'], 2)];
                }
            }
        }

        $allowed = array_merge(...array_map(fn ($g) => array_column($g['options'], 'id'), $option['addons'] ?: [['options' => []]]));
        if (array_diff($picked, $allowed) !== []) {
            $this->fail('lines', 'An add-on in your cart is no longer available. Please review your cart.');
        }

        return $out;
    }

    /** Us order type ka terminal (asal, warna backup) jis par shift khuli ho — draft wahin, ghanti wahin. */
    private function terminalFor(int $branchId, string $orderType): array
    {
        $routes = WebOrderRoute::where('branch_id', $branchId)->get()->keyBy('order_type');
        $route = $routes->get($orderType);
        $main = $route?->terminal_id;
        $backup = $route?->backup_terminal_id;
        if ($orderType === 'takeaway' && ! $main) {
            $main = $routes->get('delivery')?->terminal_id;
            $backup = $routes->get('delivery')?->backup_terminal_id;
        }

        foreach (array_filter([$main, $backup]) as $terminalId) {
            $shift = Shift::where('terminal_id', $terminalId)->where('status', 'open')->latest('id')->first();
            if ($shift) {
                return [$terminalId, $shift];
            }
        }

        return [null, null];
    }

    /** Restaurant ka mojooda "Website" channel (own); na ho to bana do — dobara kabhi nahi. */
    private function websiteChannelId(): int
    {
        return (int) (DeliveryChannel::whereRaw('LOWER(name) = ?', ['website'])->value('id')
            ?? DeliveryChannel::create(['name' => 'Website', 'type' => 'own', 'commission_percent' => 0, 'is_active' => true, 'sort_order' => 50])->id);
    }

    private function rememberAddress(int $customerId, string $address): void
    {
        $norm = fn ($s) => mb_strtolower(preg_replace('/\s+/', ' ', trim($s)));
        $exists = CustomerAddress::where('customer_id', $customerId)->get(['address'])
            ->contains(fn ($a) => $norm($a->address) === $norm($address));
        if (! $exists) {
            CustomerAddress::create([
                'customer_id' => $customerId, 'label' => 'Website', 'address' => Str::limit($address, 500, ''),
                'is_default' => ! CustomerAddress::where('customer_id', $customerId)->exists(),
            ]);
        }
    }

    /** POS ke Held/Draft list me cashier ko seedha dikhne wala note (asal tafseel web_meta me). */
    private function readableNote(array $c, ?string $note, array $itemNotes): string
    {
        $parts = ['WEBSITE ORDER'];
        if (! empty($c['landmark'])) {
            $parts[] = 'Landmark: '.$c['landmark'];
        }
        if (! empty($c['alt'])) {
            $parts[] = 'Alt phone: '.$c['alt'];
        }
        if (! empty($c['lat']) && ! empty($c['lng'])) {
            $parts[] = 'Map: https://maps.google.com/?q='.$c['lat'].','.$c['lng'];
        }
        foreach ($itemNotes as $n) {
            $parts[] = $n['item'].': '.$n['note'];
        }
        if ($note) {
            $parts[] = 'Note: '.$note;
        }

        return Str::limit(implode(' | ', $parts), 2000, '');
    }

    /** Pakistan mobile ko POS ki shakl me: +92 300… / 92300… → 0300… */
    public function localPhone(string $phone): string
    {
        $d = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($d, '92') && strlen($d) === 12) {
            $d = '0'.substr($d, 2);
        } elseif (strlen($d) === 10 && str_starts_with($d, '3')) {
            $d = '0'.$d;
        }

        return $d;
    }

    private function fail(string $field, string $message, array $extra = []): never
    {
        throw ValidationException::withMessages([$field => $message] + ($extra ? ['meta' => json_encode($extra)] : []));
    }
}
