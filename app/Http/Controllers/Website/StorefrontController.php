<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\Master\GeoArea;
use App\Models\Tenant\Branch;
use App\Models\Tenant\DeliveryZone;
use App\Models\Tenant\ReceiptLayoutSetting;
use App\Models\Tenant\WebBranchSetting;
use App\Models\Tenant\WebSetting;
use App\Services\OnlineOrdering\DeliveryFee;
use App\Services\OnlineOrdering\WebImageService;
use App\Services\OnlineOrdering\WebMenuService;
use App\Services\OnlineOrdering\WebsiteAvailability;
use App\Services\OnlineOrdering\WebsiteBranding;
use App\Services\OnlineOrdering\WebsiteSeo;
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
        // DELIVERY-RADIUS-1: fee branch se doori ke hisaab se ho to browser ko wohi bands aur nuqte (checkout phir khud ginta hai).
        $delivery = app(DeliveryFee::class)->payload($branches->pluck('id'), $settings);
        $centres = $delivery['mode'] === DeliveryFee::MODE_DISTANCE
            ? GeoArea::whereIn('id', $zones->pluck('geo_area_id')->filter()->all())->get(['id', 'lat', 'lng'])->keyBy('id') : collect();
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
                // WEB-PICKUP-SWITCH-1: band ho to website par Pick-Up ka button hi nahi.
                'pickup' => $settings->pickupOn(),
                'defaultBranch' => $branchId,
            ],
            'areas' => $zones->map(fn ($z) => [
                'id' => $z->id, 'name' => $z->area_name, 'city' => $z->city, 'branch' => $z->branch_id,
                'fee' => (float) $z->fee, 'min' => $z->min_order !== null ? (float) $z->min_order : null,
                'freeAbove' => $z->free_delivery_above !== null ? (float) $z->free_delivery_above : null,
                'eta' => $z->eta_minutes ?: $settings->default_eta_minutes,
                // Pin na ho to fee area ke markaz ki doori se.
                'lat' => $centres->get($z->geo_area_id)?->lat, 'lng' => $centres->get($z->geo_area_id)?->lng,
            ])->values(),
            'delivery' => $delivery,
            'branches' => $branches->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'address' => $b->address])->values(),
            'menuBranch' => $branchId,
            'menu' => $this->present($this->best($settings, $menu->menu($branchId, $this->hhmm($clock, $branches->firstWhere('id', $branchId))), $branchId)),
            'popularAuto' => (bool) ($settings->popular_auto ?? true),
            // Restaurant ki ghari ("closes in 20 min", Midnight Deals ka countdown) — customer ke phone ki ghari nahi.
            'clock' => ['epoch' => (int) floor(microtime(true) * 1000), 'offset' => $this->offsetMinutes($clock, $branches->firstWhere('id', $branchId))],
            'hours' => $settings->hours ?: WebSetting::defaultHours(),
            'windows' => \App\Models\Tenant\Category::where('show_on_website', true)->where('is_active', true)->whereNotNull('website_visible_from')->whereNotNull('website_visible_to')
                ->get()->map(fn ($c) => ['id' => $c->id, 'name' => $c->website_name ?: $c->name, 'from' => substr((string) $c->website_visible_from, 0, 5), 'to' => substr((string) $c->website_visible_to, 0, 5)])->values(),
            // "Add to home screen" sirf client ke apne domain par (wahan poora origin website hai — POS nahi).
            'installable' => $request->attributes->get('tenant_domain_kind') === \App\Models\Master\TenantDomain::KIND_WEBSITE,
            'endpoints' => ['menu' => url('/order/menu.json'), 'checkout' => url('/order/checkout'), 'locate' => url('/order/locate')],
            'banners' => WebsiteBranding::bannerUrls($settings),
            'upsellCategory' => $settings->upsell_category_id,
            // Footer ke links — sirf jab restaurant ne text likha ho.
            'pages' => [
                'privacy' => trim((string) $settings->privacy_policy) !== '' ? WebsiteSeo::path($request, 'privacy-policy') : null,
                'faqs' => $settings->faqList() !== [] ? WebsiteSeo::path($request, 'faqs') : null,
            ],
        ];
        // Pick-up: branch ka pata + "Get directions": restaurant ka diya link, warna branch ka nuqta, warna naam aur pata.
        $locs = WebBranchSetting::whereIn('branch_id', $branches->pluck('id'))->get()->keyBy('branch_id');
        $payload['branches'] = $branches->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'address' => $b->address,
            'directions' => $locs->get($b->id)?->map_url
                ?: ($locs->get($b->id)?->hasPoint() ? 'https://www.google.com/maps/search/?api=1&query='.$locs->get($b->id)->lat.','.$locs->get($b->id)->lng
                    : 'https://www.google.com/maps/search/?api=1&query='.rawurlencode(trim($tenant->business_name.' '.$b->name.', '.$b->address)))])->values();

        $canonical = WebsiteSeo::canonical($request);
        $seo = WebsiteSeo::meta($settings, (string) $tenant->business_name) + [
            'canonical' => $canonical,
            'indexable' => WebsiteSeo::onOwnDomain($request),
            'image' => $payload['banners'][0]['src'] ?? $payload['business']['logo'],
            'ld' => WebsiteSeo::restaurantLd($settings, (string) $tenant->business_name, $canonical, $payload['business']['logo'], $payload['banners']),
        ];

        return response()
            ->view('website.order', ['payload' => $payload, 'businessName' => $tenant->business_name, 'seo' => $seo])
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

        return response()->json(['branch' => $branch->id, 'menu' => $this->present($this->best(WebSetting::tenantDefault(), $menu->menu($branch->id, $this->hhmm($clock, $branch)), $branch->id))])
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
            // Location se mili jagah ("Chappal Sun City, New Rizvia Society") — pate ke saath jurti hai (malik).
            'customer.place' => ['nullable', 'string', 'max:200'],
            // Phone/computer ki location kitni pakki (metre) — staff ko pata ho pin par kitna bharosa.
            'customer.acc' => ['nullable', 'integer', 'min:0', 'max:1000000'],
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
            // Malik (15 Oct): pehle admin POS par "Confirm" karta hai, tab customer ka link chalta hai — aur 30
            // minute usi waqt se (jab WhatsApp gaya), order ke waqt se nahi.
            $sentAt = \App\Http\Controllers\Tenant\OnlineOrdering\WebsiteOrderInboxController::verifiedAt($sale);
            if (! $sentAt) {
                return ['pending', $sale->id];
            }
            if (\Illuminate\Support\Carbon::parse($sentAt)->lt(now()->subMinutes(self::CONFIRM_LINK_MINUTES))) {
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
                'rank' => $card['popular_rank'] ?? null,
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
    /** "Popular Items" asli bikri se jab setting on ho (default). */
    private function best(WebSetting $settings, array $menu, int $branchId): array
    {
        return ($settings->popular_auto ?? true) ? app(\App\Services\OnlineOrdering\WebBestSellers::class)->apply($menu, $branchId) : $menu;
    }

    private function offsetMinutes(TenantClock $clock, ?Branch $branch): int
    {
        return (int) round(\Carbon\CarbonImmutable::now($clock->businessTimezone($branch))->utcOffset());
    }

    /** "Add to home screen" ka manifest — naam, logo, rang tenant ke. */
    public function manifest()
    {
        abort_unless(OnlineOrdering::enabledForTenant(), 404);
        $settings = WebSetting::tenantDefault();
        $name = (string) app('tenant')->business_name;
        $icons = [];
        if ($settings->logo_path) {
            $base = \App\Services\OnlineOrdering\WebsiteBranding::iconBase($settings->logo_path);
            foreach ([192, 512] as $size) {
                if (\Illuminate\Support\Facades\Storage::disk('public')->exists($base.'-'.$size.'.png')) {
                    $icons[] = ['src' => asset('storage/'.$base.'-'.$size.'.png'), 'sizes' => $size.'x'.$size, 'type' => 'image/png', 'purpose' => 'any'];
                }
            }
        }

        return response()->json([
            'name' => $name, 'short_name' => mb_substr($name, 0, 12),
            'start_url' => url('/').'/?source=app', 'scope' => url('/').'/', 'display' => 'standalone',
            'background_color' => '#FFFFFF', 'theme_color' => '#A60D12', 'icons' => $icons,
        ])->header('Content-Type', 'application/manifest+json')->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * Service worker — sirf menu ki tasveerein aur fonts yaad rakhta hai (dobara kholne par foran). Orders,
     * checkout, status: kabhi nahi chhuta — seedha server. Sirf client ke apne domain par register hota hai.
     */
    public function serviceWorker()
    {
        $js = <<<'JS'
const CACHE = 'web-img-v1';
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', (e) => {
  const r = e.request;
  if (r.method !== 'GET') { return; }
  const u = new URL(r.url);
  const img = u.origin === location.origin && /^\/storage\/web-(menu|site)\//.test(u.pathname);
  const font = /fonts\.(googleapis|gstatic)\.com$/.test(u.hostname);
  if (!img && !font) { return; }
  e.respondWith(caches.open(CACHE).then((c) => c.match(r).then((hit) => hit || fetch(r).then((res) => { if (res.ok || res.type === 'opaque') { c.put(r, res.clone()); } return res; }))));
});
JS;

        return response($js, 200, ['Content-Type' => 'application/javascript', 'Cache-Control' => 'no-cache', 'Service-Worker-Allowed' => '/']);
    }
}
