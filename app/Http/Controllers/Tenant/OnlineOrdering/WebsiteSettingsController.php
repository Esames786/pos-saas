<?php

namespace App\Http\Controllers\Tenant\OnlineOrdering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\WebOrderRoute;
use App\Models\Tenant\WebSetting;
use App\Services\OnlineOrdering\WebsiteBranding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ONLINE-ORDERING-1 — Online Ordering → Website settings.
 *
 * Website kab khuli hai, orders kis terminal par aate hain (order type → terminal), confirm na ho to
 * kya, aur customer ko WhatsApp kab. Sab client ka faisla; kuch bhi khud on nahi hota.
 */
class WebsiteSettingsController extends Controller
{
    public function edit()
    {
        $branches = Branch::where('status', 'active')->orderBy('name')->get();

        return view('tenant.online-ordering.settings', [
            'settings' => WebSetting::tenantDefault(),
            'branches' => $branches,
            'terminals' => Terminal::where('status', 'active')->orderBy('name')->get()->groupBy('branch_id'),
            'routes' => WebOrderRoute::all()->keyBy(fn ($r) => $r->branch_id.'.'.$r->order_type),
            'categories' => Category::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request)
    {
        // Website ki shakl (logo, banners, footer) ka apna form — wohi save ka haq, alag permission nahi.
        if ($request->input('section') === 'branding') {
            return $this->updateBranding($request);
        }

        $branchIds = Branch::where('status', 'active')->pluck('id')->all();

        $data = $request->validate([
            'is_enabled' => ['nullable', 'boolean'],
            'branch_mode' => ['required', 'in:area,single'],
            'default_branch_id' => ['nullable', 'required_if:branch_mode,single', 'integer', 'in:'.implode(',', $branchIds ?: [0])],
            'default_eta_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'pickup_eta_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'hours' => ['required', 'array'],
            'hours.*.open' => ['nullable', 'date_format:H:i'],
            'hours.*.close' => ['nullable', 'date_format:H:i'],
            'hours.*.closed' => ['nullable', 'boolean'],
            'closed_message' => ['nullable', 'string', 'max:255'],
            'auto_cancel_enabled' => ['nullable', 'boolean'],
            'auto_cancel_minutes' => ['nullable', 'required_if:auto_cancel_enabled,1', 'integer', 'min:5', 'max:240'],
            'wa_notify_received' => ['nullable', 'boolean'],
            'wa_notify_confirmed' => ['nullable', 'boolean'],
            'wa_notify_cancelled' => ['nullable', 'boolean'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'routes' => ['nullable', 'array'],
            'routes.*.*.terminal_ids' => ['nullable', 'array'],
            'routes.*.*.terminal_ids.*' => ['nullable', 'integer'],
        ], [
            'default_branch_id.required_if' => 'Choose the branch the website takes orders for.',
            'auto_cancel_minutes.required_if' => 'Enter after how many minutes an unconfirmed order is cancelled, or turn auto-cancel off.',
        ]);

        $hours = [];
        foreach (WebSetting::DAYS as $day => $label) {
            $row = $data['hours'][$day] ?? [];
            $closed = ! empty($row['closed']);
            if (! $closed && (empty($row['open']) || empty($row['close']))) {
                throw ValidationException::withMessages([
                    "hours.$day.open" => "$label: enter opening and closing time, or mark the day closed.",
                ]);
            }
            $hours[$day] = ['open' => $row['open'] ?? null, 'close' => $row['close'] ?? null, 'closed' => $closed];
        }

        $routes = $this->routes($data['routes'] ?? [], $branchIds);

        $enabled = $request->boolean('is_enabled');
        if ($enabled && ! collect($routes)->contains(fn ($r) => $r['order_type'] === 'delivery' && $r['terminal_ids'] !== [])) {
            // Bina terminal ke website kholna = order aaye aur kisi ko ghanti na baje.
            throw ValidationException::withMessages([
                'is_enabled' => 'Choose the terminal that receives delivery orders before turning the website on.',
            ]);
        }

        DB::connection('tenant')->transaction(function () use ($request, $data, $hours, $routes, $enabled) {
            WebSetting::tenantDefault()->update([
                'is_enabled' => $enabled,
                'branch_mode' => $data['branch_mode'],
                'default_branch_id' => $data['default_branch_id'] ?? null,
                'default_eta_minutes' => (int) $data['default_eta_minutes'],
                'pickup_eta_minutes' => (int) $data['pickup_eta_minutes'],
                'hours' => $hours,
                'closed_message' => $data['closed_message'] ?? null,
                'auto_cancel_minutes' => $request->boolean('auto_cancel_enabled') ? (int) $data['auto_cancel_minutes'] : null,
                // Ann-tick checkbox kuch post nahi karta — na aana = band.
                'wa_notify_received' => $request->boolean('wa_notify_received'),
                'wa_notify_confirmed' => $request->boolean('wa_notify_confirmed'),
                'wa_notify_cancelled' => $request->boolean('wa_notify_cancelled'),
                'contact_phone' => $data['contact_phone'] ?? null,
            ]);

            foreach ($routes as $route) {
                WebOrderRoute::updateOrCreate(
                    ['branch_id' => $route['branch_id'], 'order_type' => $route['order_type']],
                    // Pehle do purane khanon me bhi — jo bhi unhe parhe, waisa hi chale.
                    ['terminal_ids' => $route['terminal_ids'], 'terminal_id' => $route['terminal_ids'][0] ?? null, 'backup_terminal_id' => $route['terminal_ids'][1] ?? null],
                );
            }
        });

        return redirect('/online-ordering/settings')->with('status', 'Website settings saved.');
    }

    /**
     * routes[branch_id][order_type][terminal_ids][] → saaf rows. Har terminal usi branch ka; tarteeb wohi jo
     * screen par (pehla khula = draft wahin).
     *
     * @return list<array{branch_id: int, order_type: string, terminal_ids: list<int>}>
     */
    private function routes(array $input, array $branchIds): array
    {
        $terminalBranch = Terminal::where('status', 'active')->pluck('branch_id', 'id')->all();
        $out = [];

        foreach ($input as $branchId => $types) {
            if (! in_array((int) $branchId, $branchIds, true) || ! is_array($types)) {
                continue;
            }
            foreach (array_keys(WebOrderRoute::ORDER_TYPES) as $type) {
                $ids = array_values(array_unique(array_map('intval', array_filter((array) ($types[$type]['terminal_ids'] ?? []), fn ($v) => $v !== '' && $v !== null))));
                foreach ($ids as $terminalId) {
                    if ((int) ($terminalBranch[$terminalId] ?? 0) !== (int) $branchId) {
                        throw ValidationException::withMessages([
                            "routes.$branchId.$type.terminal_ids" => 'Choose terminals of the same branch.',
                        ]);
                    }
                }

                $out[] = ['branch_id' => (int) $branchId, 'order_type' => $type, 'terminal_ids' => $ids];
            }
        }

        return $out;
    }
    /**
     * Malik: purani website jaisi — logo, banner slides, WhatsApp, footer, cart me "Popular with your order".
     * Tasveerein chhoti WebP me (WebsiteBranding).
     */
    private function updateBranding(Request $request)
    {
        $data = $request->validate([
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'remove_logo' => ['nullable', 'boolean'],
            'banners' => ['nullable', 'array', 'max:'.WebsiteBranding::MAX_BANNERS],
            'banners.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:20480'],
            'remove_banners' => ['nullable', 'array'],
            'remove_banners.*' => ['integer', 'min:0'],
            'move_banner' => ['nullable', 'regex:/^\d+:(up|down)$/'],
            'whatsapp_number' => ['nullable', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email', 'max:150'],
            'contact_address' => ['nullable', 'string', 'max:255'],
            'facebook_url' => ['nullable', 'url', 'max:255'],
            'instagram_url' => ['nullable', 'url', 'max:255'],
            'about_text' => ['nullable', 'string', 'max:2000'],
            'upsell_category_id' => ['nullable', 'integer', 'exists:tenant.categories,id'],
            'popular_auto' => ['nullable', 'boolean'],
        ]);

        $settings = WebSetting::tenantDefault();
        $branding = app(WebsiteBranding::class);
        $code = (string) app('tenant')->tenant_code;

        $settings->update([
            'whatsapp_number' => $data['whatsapp_number'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'contact_address' => $data['contact_address'] ?? null,
            'facebook_url' => $data['facebook_url'] ?? null,
            'instagram_url' => $data['instagram_url'] ?? null,
            'about_text' => $data['about_text'] ?? null,
            'upsell_category_id' => $data['upsell_category_id'] ?? null,
            'popular_auto' => $request->boolean('popular_auto'),
        ]);
        if ($request->boolean('remove_logo')) {
            $branding->removeLogo($settings);
        }
        if ($request->hasFile('logo')) {
            $branding->setLogo($settings, $request->file('logo')->getRealPath(), $code);
        }
        $branding->removeBanners($settings, array_map('intval', $data['remove_banners'] ?? []));
        foreach ($request->file('banners', []) as $file) {
            $branding->addBanner($settings->fresh(), $file->getRealPath(), $code);
        }
        if (! empty($data['move_banner'])) {
            [$i, $dir] = explode(':', $data['move_banner']);
            $branding->moveBanner($settings->fresh(), (int) $i, (int) $i + ($dir === 'up' ? -1 : 1));
        }

        return redirect('/online-ordering/settings#branding')->with('status', 'Website look saved.');
    }
}
