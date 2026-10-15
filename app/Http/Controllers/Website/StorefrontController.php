<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\DeliveryZone;
use App\Models\Tenant\ReceiptLayoutSetting;
use App\Models\Tenant\WebSetting;
use App\Services\OnlineOrdering\WebImageService;
use App\Services\OnlineOrdering\WebMenuService;
use App\Services\OnlineOrdering\WebsiteAvailability;
use App\Services\OnlineOrdering\WebsiteBranding;
use App\Support\OnlineOrdering;
use App\Support\TenantClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * ONLINE-ORDERING-1 (Phase 2b) — customer ki ordering website (public, login nahi).
 *
 * Aaj `{tenant}.bingoopos.com/order` par; Phase 5 me tenant ka apna domain (jaise kashiffood.com) isi
 * controller ko chalayega. Plan me "Online Ordering" na ho to safha hai hi nahi (404). Website Settings
 * me band ho, waqt se bahar, ya terminal par shift band ho to menu dikhta hai magar order nahi hota.
 *
 * Customer ko POS ka andar ka kuch nahi bheja jata (POS naam/qeemat nahi) — sirf website ka card, size,
 * website qeemat aur add-ons.
 */
class StorefrontController extends Controller
{
    public function home(Request $request, WebMenuService $menu, WebsiteAvailability $availability, TenantClock $clock)
    {
        abort_unless(OnlineOrdering::enabledForTenant(), 404);

        $settings = WebSetting::tenantDefault();
        $branches = Branch::where('status', 'active')->orderBy('name')->get(['id', 'name', 'address', 'phone', 'timezone']);
        $zones = DeliveryZone::where('is_active', true)->whereIn('branch_id', $branches->pluck('id'))->orderBy('area_name')->get();
        $branchId = (int) ($settings->default_branch_id ?: ($zones->first()?->branch_id ?: $branches->first()?->id));
        abort_if($branchId === 0, 404);

        $tenant = app('tenant');
        $payload = [
            'business' => [
                'name' => $tenant->business_name,
                'phone' => $settings->contact_phone,
                // Website ka apna logo (Website Settings → Website look), warna receipt wala.
                'logo' => $settings->logo_path ? asset('storage/'.$settings->logo_path)
                    : (($logo = ReceiptLayoutSetting::whereNotNull('logo_path')->value('logo_path')) ? asset('storage/'.$logo) : null),
                'whatsapp' => $this->whatsapp($settings),
                'email' => $settings->contact_email,
                'address' => $settings->contact_address,
                'facebook' => $settings->facebook_url,
                'instagram' => $settings->instagram_url,
                'about' => $settings->about_text,
            ],
            'status' => $availability->status($settings),
            'settings' => [
                'mode' => $settings->branch_mode,
                'eta' => $settings->default_eta_minutes,
                'pickupEta' => $settings->pickup_eta_minutes,
                'defaultBranch' => $branchId,
            ],
            'areas' => $zones->map(fn ($z) => [
                'id' => $z->id, 'name' => $z->area_name, 'city' => $z->city, 'branch' => $z->branch_id,
                'fee' => (float) $z->fee, 'min' => $z->min_order !== null ? (float) $z->min_order : null,
                'freeAbove' => $z->free_delivery_above !== null ? (float) $z->free_delivery_above : null,
                'eta' => $z->eta_minutes ?: $settings->default_eta_minutes,
            ])->values(),
            'branches' => $branches->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'address' => $b->address])->values(),
            'menuBranch' => $branchId,
            'menu' => $this->present($menu->menu($branchId, $this->hhmm($clock, $branches->firstWhere('id', $branchId)))),
            'endpoints' => ['menu' => url('/order/menu.json'), 'checkout' => url('/order/checkout'), 'locate' => url('/order/locate')],
            'banners' => WebsiteBranding::bannerUrls($settings),
            'upsellCategory' => $settings->upsell_category_id,
        ];

        return response()
            ->view('website.order', ['payload' => $payload, 'businessName' => $tenant->business_name])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * "Use my current location" → delivery area (OpenStreetMap se naam, warna qareebi area) + jagah ka naam aur
     * gali (pate ka ishara). Browser OSM ko seedha nahi bulata — hamara server, cache ke saath.
     */
    public function locate(Request $request, \App\Services\OnlineOrdering\AreaLocator $locator)
    {
        abort_unless(OnlineOrdering::enabledForTenant(), 404);
        $data = $request->validate(['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180']]);

        return response()->json($locator->locate((float) $data['lat'], (float) $data['lng']))->header('Cache-Control', 'no-store');
    }

    /** Aik branch ka menu (area badalne par jab branch badle). */
    public function menu(Request $request, WebMenuService $menu, TenantClock $clock)
    {
        abort_unless(OnlineOrdering::enabledForTenant(), 404);

        $branch = Branch::where('status', 'active')->find((int) $request->query('branch'));
        abort_unless($branch, 404);

        return response()->json(['branch' => $branch->id, 'menu' => $this->present($menu->menu($branch->id, $this->hhmm($clock, $branch)))])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Order lagao: POS par draft (website order) — ghanti routed terminal par. Do dafa dabana = wohi order.
     */
    public function checkout(Request $request, \App\Services\OnlineOrdering\WebsiteOrderService $orders)
    {
        abort_unless(OnlineOrdering::enabledForTenant(), 404);

        $data = $request->validate([
            'key' => ['required', 'uuid'],
            'type' => ['required', 'in:delivery,pickup'],
            'area' => ['nullable', 'integer', 'required_if:type,delivery'],
            'branch' => ['nullable', 'integer'],
            'customer.name' => ['required', 'string', 'max:120'],
            // Pakistan mobile: 0300…, 300…, 92300…, +92 300… — khaali jagah/dash chhoR kar.
            'customer.phone' => ['required', 'string', 'max:20', function ($attr, $value, $fail) {
                if (! preg_match('/^(92|0)?3\d{9}$/', preg_replace('/[\s\-+]/', '', (string) $value))) {
                    $fail('Enter a valid mobile number, e.g. 0300 1234567.');
                }
            }],
            'customer.alt' => ['nullable', 'string', 'max:20'],
            'customer.address' => ['nullable', 'required_if:type,delivery', 'string', 'max:300'],
            'customer.landmark' => ['nullable', 'string', 'max:150'],
            'customer.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'customer.lng' => ['nullable', 'numeric', 'between:-180,180'],
            'note' => ['nullable', 'string', 'max:300'],
            // Malik: "cash on delivery — hamare system ka Cash payment type". Abhi sirf yehi.
            'payment' => ['nullable', 'in:cash'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.option' => ['required', 'string', 'regex:/^(product|combo):\d+$/'],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:50'],
            'lines.*.addons' => ['nullable', 'array', 'max:20'],
            'lines.*.addons.*' => ['integer'],
            'lines.*.note' => ['nullable', 'string', 'max:200'],
        ], [
            'customer.address.required_if' => 'Enter your address.',
            'area.required_if' => 'Choose your delivery area.',
        ]);

        try {
            $sale = $orders->place($data);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Do request bilkul saath: doosra wohi order lautaye.
            $sale = \App\Models\Tenant\SalesOrder::where('web_order_key', $data['key'])->firstOrFail();
        }

        return response()->json([
            'order' => $sale->sale_no,
            'status_url' => url('/order/status/'.$sale->web_token),
        ], 201);
    }

    /**
     * WhatsApp ke "Confirm Order" button ka safha. GET kuch nahi badalta — sirf aik form jo browser khud
     * POST kar deta hai. Link-preview bot ya scanner (jo script nahi chalate) order kabhi confirm nahi kar sakte;
     * insaan ke phone par aik tap kaafi.
     */
    public function confirmPage(string $token)
    {
        abort_unless(OnlineOrdering::enabledForTenant(), 404);
        $sale = \App\Models\Tenant\SalesOrder::where('web_token', $token)->where('order_source', 'website')->firstOrFail();

        return response()->view('website.confirm', ['sale' => $sale, 'businessName' => app('tenant')->business_name, 'token' => $token])
            ->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    /**
     * Customer ne confirm kiya: Draft → Held, aur KOT POS ke apne print raaste (PrintJobService::queueKot) se —
     * jaise cashier ke Hold par. Link aik dafa ka (dobara = "pehle hi confirm"), 30 minute tak; cancel hua
     * order confirm nahi hota. Rider baad me cashier POS ke Website orders se lagata hai.
     */
    public function confirm(string $token, \App\Services\Printing\PrintJobService $printing)
    {
        abort_unless(OnlineOrdering::enabledForTenant(), 404);

        [$outcome, $saleId] = \Illuminate\Support\Facades\DB::connection('tenant')->transaction(function () use ($token) {
            $sale = \App\Models\Tenant\SalesOrder::where('web_token', $token)->where('order_source', 'website')->lockForUpdate()->firstOrFail();

            if ($sale->status === 'cancelled') {
                return ['cancelled', $sale->id];
            }
            if ($sale->status !== 'held' || ! $sale->is_draft) {
                return ['already', $sale->id];
            }
            if ($sale->created_at->lt(now()->subMinutes(self::CONFIRM_LINK_MINUTES))) {
                return ['expired', $sale->id];
            }
            if (! \App\Models\Tenant\Shift::where('terminal_id', $sale->terminal_id)->where('status', 'open')->exists()) {
                return ['later', $sale->id];   // counter band — cashier khud dekhega
            }

            $sale->update(['is_draft' => false]);

            return ['confirmed', $sale->id];
        });

        if ($outcome === 'confirmed') {
            try {
                $sale = \App\Models\Tenant\SalesOrder::findOrFail($saleId);
                $printing->queueKot(sale: $sale, printer: null, lineIds: [], terminalId: $sale->terminal_id ? (string) $sale->terminal_id : null);
            } catch (\Throwable $e) {
                // Printer ka masla order ko nahi rokta: order Held hai, cashier POS se KOT dobara bhej sakta hai.
                report($e);
            }
        }

        return redirect('/order/status/'.$token)->with('confirm_outcome', $outcome);
    }

    /** WhatsApp ka Confirm link kitni der chalta hai. */
    public const CONFIRM_LINK_MINUTES = 30;

    /** Customer ka order-status safha (lamba random link; login nahi). */
    public function status(string $token)
    {
        abort_unless(OnlineOrdering::enabledForTenant(), 404);

        $sale = \App\Models\Tenant\SalesOrder::with('lines')->where('web_token', $token)->where('order_source', 'website')->firstOrFail();
        $settings = WebSetting::tenantDefault();

        return response()->view('website.status', [
            'sale' => $sale,
            'businessName' => app('tenant')->business_name,
            'phone' => $settings->contact_phone,
            'step' => match (true) {
                $sale->status === 'cancelled' => 'cancelled',
                in_array($sale->status, ['paid', 'partially_returned', 'returned'], true) => 'completed',
                $sale->status === 'held' && ! $sale->is_draft => 'confirmed',
                default => 'received',
            },
        ])->header('Cache-Control', 'no-store');
    }

    private function hhmm(TenantClock $clock, ?Branch $branch): string
    {
        return CarbonImmutable::now($clock->businessTimezone($branch))->format('H:i');
    }

    /**
     * Customer ke liye halka shakl: card, sizes (website qeemat), add-ons, tasveer ke do URL (card ke liye
     * 400px, popup ke liye 800px). Option ki id `product:12` / `combo:7` — checkout isi se POS cheez dhoondta hai.
     */
    private function present(array $menu): array
    {
        $url = fn (?string $p) => $p ? asset('storage/'.$p) : null;

        return array_map(fn ($c) => [
            'id' => $c['id'],
            'name' => $c['name'],
            'items' => array_map(fn ($card) => [
                'name' => $card['name'],
                'description' => $card['description'],
                'img' => $url($card['image_path']),
                'thumb' => $url($card['image_path'] && str_starts_with($card['image_path'], 'web-menu/') ? WebImageService::thumb($card['image_path']) : $card['image_path']),
                'tag' => $card['tag'],
                'popular' => $card['popular'],
                'from' => $card['from_price'],
                'options' => array_map(fn ($o) => [
                    'id' => $o['kind'].':'.$o['pos_id'],
                    'label' => $o['label'],
                    'price' => $o['price'],
                    'addons' => $o['addons'],
                ], $card['options']),
            ], $c['items']),
        ], $menu);
    }

    /** WhatsApp button: apna number, warna contact number agar mobile ho (03…). wa.me ko 92… chahiye. */
    private function whatsapp(WebSetting $settings): ?string
    {
        $raw = $settings->whatsapp_number ?: $settings->contact_phone;
        $intl = $raw ? \App\Services\OnlineOrdering\WebsiteOrderNotifier::international((string) $raw) : '';

        return preg_match('/^923\d{9}$/', $intl) ? $intl : null;
    }
}
