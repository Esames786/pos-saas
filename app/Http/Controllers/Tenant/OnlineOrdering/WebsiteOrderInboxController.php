<?php

namespace App\Http\Controllers\Tenant\OnlineOrdering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\DeliveryRider;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\WebOrderRoute;
use App\Services\Security\UserDataScope;
use App\Support\OnlineOrdering;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ONLINE-ORDERING-1 (Phase 3) — POS ka "Website orders" modal: sirf RECEIVE (malik: "POS pe jo order
 * modal hoga wahan to bs order receive hore hon gay").
 *
 * - index: is branch ke website orders jo cashier ka intezar kar rahe hain — naye (Draft) aur link se
 *   Held hue jin par rider baqi hai. Ghanti isi ki ginti par bajti hai.
 * - hold: Draft → Held (rider ke saath; own channel par rider lazmi — POS ka Hold bhi yehi maangta hai),
 *   ya Held order par rider lagao. KOT POS ka browser apne mojooda raaste se bhejta hai, bilkul normal
 *   Hold ki tarah. Cancel aur Recall POS ke apne mojooda raaste hain — yahan nahi.
 */
class WebsiteOrderInboxController extends Controller
{
    public function index(Request $request)
    {
        if (! OnlineOrdering::enabledForTenant()) {
            return response()->json(['enabled' => false, 'orders' => []]);
        }

        $branchId = (int) $request->query('branch_id');
        $terminalId = (int) $request->query('terminal_id');
        $routed = WebOrderRoute::where('branch_id', $branchId)
            ->where(fn ($q) => $q->where('terminal_id', $terminalId)->orWhere('backup_terminal_id', $terminalId))
            ->exists();

        $user = auth('tenant')->user();
        $query = SalesOrder::with(['lines', 'deliveryRider:id,name', 'deliveryChannel:id,type'])
            ->where('order_source', 'website')->where('status', 'held')->where('branch_id', $branchId)
            ->where(fn ($q) => $q->where('is_draft', true)
                ->orWhere(fn ($h) => $h->where('order_type', 'delivery')->whereNull('delivery_rider_id')))
            ->orderBy('created_at');
        app(UserDataScope::class)->applyToSales($query, $user);

        return response()->json([
            'enabled' => true,
            'routed' => $routed,
            'orders' => $query->limit(50)->get()->map(fn (SalesOrder $s) => $this->row($s))->values(),
        ]);
    }

    public function hold(Request $request, SalesOrder $salesOrder)
    {
        abort_unless(OnlineOrdering::enabledForTenant(), 404);
        $user = auth('tenant')->user();
        abort_unless($user && $user->can('tenant.held-sales.store'), 403, 'You cannot hold orders.');

        $data = $request->validate(['delivery_rider_id' => ['nullable', 'integer']]);

        $sale = DB::connection('tenant')->transaction(function () use ($salesOrder, $data, $user) {
            $sale = SalesOrder::whereKey($salesOrder->id)->lockForUpdate()->firstOrFail();
            abort_unless($sale->order_source === 'website' && $sale->status === 'held', 422, 'This order is no longer waiting.');
            abort_if(app(UserDataScope::class)->deniesSale($user, $sale), 403, 'This order belongs to another terminal or order type.');
            abort_unless($user->allowsOrderType($sale->order_type), 403, 'Your account is not allowed to use this order type.');

            $riderId = $sale->delivery_rider_id;
            if ($sale->order_type === 'delivery' && $sale->deliveryChannel()->first()?->isOwn()) {
                $riderId = (int) ($data['delivery_rider_id'] ?? 0) ?: $riderId;
                // Wohi usool jo POS ke Hold par hai: apne delivery channel par rider lazmi, active, isi branch ka.
                $rider = $riderId ? DeliveryRider::where('status', 'active')->find($riderId) : null;
                abort_unless($rider, 422, 'Select a rider for own-delivery orders.');
                abort_if($rider->branch_id && (int) $rider->branch_id !== (int) $sale->branch_id, 422, 'Selected rider is not active for this order branch.');
            }

            $wasDraft = (bool) $sale->is_draft;
            $sale->update(['is_draft' => false, 'delivery_rider_id' => $riderId]);

            return [$sale, $wasDraft];
        });

        [$sale, $wasDraft] = $sale;

        return response()->json([
            'sale_id' => $sale->id,
            'sale_no' => $sale->sale_no,
            'terminal_id' => $sale->terminal_id,
            // Sirf Draft → Held par KOT; pehle se Held (link se) order ki KOT ja chuki hoti hai.
            'send_kot' => $wasDraft,
        ]);
    }

    private function row(SalesOrder $s): array
    {
        $meta = $s->web_meta ?: [];

        return [
            'id' => $s->id,
            'sale_no' => $s->sale_no,
            'is_draft' => (bool) $s->is_draft,
            'order_type' => $s->order_type,
            'minutes' => (int) floor($s->created_at->diffInMinutes(now(), true)),
            'customer' => $s->customer_name,
            'phone' => $s->customer_phone,
            'alt_phone' => $meta['alt_phone'] ?? null,
            'area' => $meta['area'] ?? null,
            'address' => $s->delivery_address,
            'landmark' => $meta['landmark'] ?? null,
            'map' => (! empty($meta['lat']) && ! empty($meta['lng'])) ? 'https://maps.google.com/?q='.$meta['lat'].','.$meta['lng'] : null,
            'note' => $meta['note'] ?? null,
            'item_notes' => $meta['item_notes'] ?? [],
            'total' => (float) $s->grand_total,
            'rider_id' => $s->delivery_rider_id,
            'rider' => $s->deliveryRider?->name,
            'needs_rider' => $s->order_type === 'delivery' && (bool) $s->deliveryChannel?->isOwn(),
            'items' => $s->lines->where('line_kind', '!=', 'component')->map(fn ($l) => [
                'name' => $l->product_name,
                'qty' => (float) $l->quantity,
                'modifiers' => collect($l->modifiers ?: [])->pluck('name')->values(),
            ])->values(),
        ];
    }
}
