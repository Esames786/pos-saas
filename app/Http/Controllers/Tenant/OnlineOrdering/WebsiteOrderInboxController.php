<?php

namespace App\Http\Controllers\Tenant\OnlineOrdering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\DeliveryRider;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\WebOrderRoute;
use App\Models\Tenant\WebSetting;
use App\Services\OnlineOrdering\WebsiteOrderNotifier;
use App\Services\Reports\SalesReportEngine;
use App\Services\Security\UserDataScope;
use App\Support\OnlineOrdering;
use App\Support\TenantClock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ONLINE-ORDERING-1 (Phase 3) — POS ka "Website orders" modal: sirf RECEIVE (malik: "POS pe jo order
 * modal hoga wahan to bs order receive hore hon gay").
 *
 * - index: status-wise tabs (malik, 15 Oct: "proper status wise tabs") — New (Draft), Confirmed (Held, Paid
 *   hone tak), Paid aur Cancelled (aaj ka business din). Har 15 second ki poll sirf New + Confirmed laati hai
 *   aur baqi do ki ginti; Paid/Cancelled ki list tab khulne par (`?tab=`). Badge = jin par kaam baqi: naye +
 *   confirmed jin par rider nahi. Ghanti sirf naye (Draft) par.
 * - verify (malik, 15 Oct): admin ka "Confirm" — order asli lagta hai, ab customer ko "Order mila" WhatsApp
 *   (Confirm Order button ke saath) jaye, agar Website Settings me wo message on ho. Bhejne se pehle admin ka
 *   control; order Draft hi rehta hai jab tak customer link se confirm na kare (phir Held + KOT, pehle jaisa).
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
        // Ghanti har us terminal par jo kisi order type ke liye chuna gaya (malik: multi-select).
        $routed = $terminalId > 0 && WebOrderRoute::where('branch_id', $branchId)->get()
            ->contains(fn (WebOrderRoute $r) => in_array($terminalId, $r->ids(), true));

        $user = auth('tenant')->user();
        $branch = Branch::find($branchId);
        $today = app(TenantClock::class)->currentBusinessDate($branch);
        $base = function () use ($branchId, $user) {
            $q = SalesOrder::query()->where('order_source', 'website')->where('branch_id', $branchId);
            app(UserDataScope::class)->applyToSales($q, $user);

            return $q;
        };
        $closed = [
            'paid' => fn () => $base()->whereIn('status', SalesReportEngine::POPULATION),
            'cancelled' => fn () => $base()->where('status', 'cancelled'),
        ];
        $onToday = fn ($q) => $q->whereRaw('COALESCE(business_date, DATE(sale_date)) = ?', [$today]);

        $tab = (string) $request->query('tab', '');
        if (isset($closed[$tab])) {
            $list = $onToday($closed[$tab]())->latest('updated_at');
        } else {
            // New + Confirmed: khule orders, din ki qaid nahi — jab tak Paid/Cancel na hon.
            $list = $base()->where('status', 'held')->orderByDesc('is_draft')->orderBy('created_at');
        }
        $orders = $list->with(['lines', 'deliveryRider:id,name', 'deliveryChannel:id,type'])->limit(50)->get()
            ->map(fn (SalesOrder $s) => $this->row($s))->values();

        $open = $base()->where('status', 'held');
        $drafts = (clone $open)->where('is_draft', true)->get(['id', 'web_meta']);
        $counts = [
            'new' => $drafts->count(),
            // Jin par admin ne abhi "Confirm" nahi dabaya — ghanti aur badge inhi par.
            'to_check' => $drafts->filter(fn (SalesOrder $s) => ! self::verifiedAt($s))->count(),
            'confirmed' => (clone $open)->where('is_draft', false)->count(),
            'paid' => $onToday($closed['paid']())->count(),
            'cancelled' => $onToday($closed['cancelled']())->count(),
            'no_rider' => (clone $open)->where('is_draft', false)->where('order_type', 'delivery')->whereNull('delivery_rider_id')
                ->whereHas('deliveryChannel', fn ($c) => $c->where('type', 'own'))->count(),
        ];

        return response()->json([
            'enabled' => true,
            'routed' => $routed,
            // "Confirm" button tabhi jab us ke baad customer ko kuch jaye (message on + WhatsApp laga hua).
            'wa_received' => self::receivedMessageOn(),
            'tab' => isset($closed[$tab]) ? $tab : 'open',
            'business_date' => $today,
            'counts' => $counts,
            'orders' => $orders,
        ]);
    }

    /**
     * Admin ka "Confirm": order dekh liya, asli hai — ab (message on ho to) customer ko WhatsApp. Order Draft hi
     * rehta hai; customer ka Confirm link Held + KOT karta hai (StorefrontController::confirm). Dobara dabana =
     * kuch nahi (message aik hi dafa).
     */
    public function verify(SalesOrder $salesOrder)
    {
        abort_unless(OnlineOrdering::enabledForTenant(), 404);
        $user = auth('tenant')->user();
        abort_unless($user && $user->can('tenant.held-sales.store'), 403, 'You cannot confirm orders.');

        $first = DB::connection('tenant')->transaction(function () use ($salesOrder, $user) {
            $sale = SalesOrder::whereKey($salesOrder->id)->lockForUpdate()->firstOrFail();
            abort_unless($sale->order_source === 'website' && $sale->status === 'held' && $sale->is_draft, 422, 'This order is no longer waiting.');
            abort_if(app(UserDataScope::class)->deniesSale($user, $sale), 403, 'This order belongs to another terminal or order type.');
            abort_unless($user->allowsOrderType($sale->order_type), 403, 'Your account is not allowed to use this order type.');

            if (self::verifiedAt($sale)) {
                return false;
            }
            $meta = $sale->web_meta ?: [];
            $meta['verified'] = ['at' => now()->toIso8601String(), 'by' => $user->id, 'name' => $user->name];
            // Query builder se — model events (status ke WhatsApp) yahan nahi chalne chahiye.
            SalesOrder::whereKey($sale->id)->update(['web_meta' => json_encode($meta)]);
            // Commit aur jawab ke baad; message off ho / WhatsApp laga na ho to notifier khud kuch nahi karta.
            WebsiteOrderNotifier::later('received', $sale->id);

            return true;
        });

        return response()->json([
            'sale_id' => $salesOrder->id,
            'sale_no' => $salesOrder->sale_no,
            'already' => ! $first,
            'whatsapp' => self::receivedMessageOn() && ($salesOrder->customer_phone ?: $salesOrder->customer?->phone) ? 'sending' : 'off',
        ]);
    }

    /** Admin ne kab "Confirm" kiya. Pehle wale orders: jab "Order mila" WhatsApp gaya tha wahi waqt. */
    public static function verifiedAt(SalesOrder $s): ?string
    {
        $meta = $s->web_meta ?: [];

        return $meta['verified']['at'] ?? ($meta['wa']['received']['at'] ?? null);
    }

    /** Website Settings me "Order received" on, aur WhatsApp laga hua (token + number). */
    private static function receivedMessageOn(): bool
    {
        return (bool) WebSetting::tenantDefault()->wa_notify_received
            && config('services.whatsapp.token') && config('services.whatsapp.phone_number_id');
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

        $status = match (true) {
            $s->status === 'held' => $s->is_draft ? 'new' : 'confirmed',
            $s->status === 'cancelled' => 'cancelled',
            default => 'paid',
        };
        $needsRider = $s->order_type === 'delivery' && (bool) $s->deliveryChannel?->isOwn();
        $verifiedAt = self::verifiedAt($s);
        $wa = $meta['wa']['received'] ?? null;

        return [
            'id' => $s->id,
            'sale_no' => $s->sale_no,
            'status' => $status,
            'is_draft' => (bool) $s->is_draft,
            // Admin ka Confirm, aur us ke baad customer ka WhatsApp: sent / failed / (null = abhi nahi ya off).
            'verified' => $verifiedAt !== null,
            'verified_minutes' => $verifiedAt ? (int) floor(\Illuminate\Support\Carbon::parse($verifiedAt)->diffInMinutes(now(), true)) : null,
            'verified_by' => $meta['verified']['name'] ?? null,
            'wa_received' => $wa ? (! empty($wa['failed']) ? 'failed' : 'sent') : null,
            'order_type' => $s->order_type,
            'minutes' => (int) floor($s->created_at->diffInMinutes(now(), true)),
            'customer' => $s->customer_name,
            'phone' => $s->customer_phone,
            'alt_phone' => $meta['alt_phone'] ?? null,
            'area' => $meta['area'] ?? null,
            // Customer ki location se mili jagah (malik: POS par bhi dikhe).
            'place' => $meta['place'] ?? null,
            'address' => $s->delivery_address,
            'landmark' => $meta['landmark'] ?? null,
            'map' => (! empty($meta['lat']) && ! empty($meta['lng'])) ? 'https://maps.google.com/?q='.$meta['lat'].','.$meta['lng'] : null,
            'note' => $meta['note'] ?? null,
            'item_notes' => $meta['item_notes'] ?? [],
            'total' => (float) $s->grand_total,
            'rider_id' => $s->delivery_rider_id,
            'rider' => $s->deliveryRider?->name,
            'needs_rider' => $needsRider,
            'missing_rider' => $status === 'confirmed' && $needsRider && ! $s->delivery_rider_id,
            'cancelled_by' => $meta['cancelled_by'] ?? null,
            'payment' => ($meta['payment'] ?? 'cash') === 'cash' ? ($s->order_type === 'takeaway' ? 'Cash on pick-up' : 'Cash on delivery') : (string) $meta['payment'],
            'time' => $s->created_at->format('h:i A'),
            'items' => $s->lines->where('line_kind', '!=', 'component')->map(fn ($l) => [
                'name' => $l->product_name,
                'qty' => (float) $l->quantity,
                'modifiers' => collect($l->modifiers ?: [])->pluck('name')->values(),
            ])->values(),
        ];
    }
}
