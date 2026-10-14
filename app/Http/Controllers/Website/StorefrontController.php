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
                'logo' => ($logo = ReceiptLayoutSetting::whereNotNull('logo_path')->value('logo_path')) ? asset('storage/'.$logo) : null,
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
            'endpoints' => ['menu' => url('/order/menu.json')],
        ];

        return response()
            ->view('website.order', ['payload' => $payload, 'businessName' => $tenant->business_name])
            ->header('Cache-Control', 'no-store');
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
}
