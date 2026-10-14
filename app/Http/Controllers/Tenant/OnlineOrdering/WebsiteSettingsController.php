<?php

namespace App\Http\Controllers\Tenant\OnlineOrdering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\WebOrderRoute;
use App\Models\Tenant\WebSetting;
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
        ]);
    }

    public function update(Request $request)
    {
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
            'routes.*.*.terminal_id' => ['nullable', 'integer'],
            'routes.*.*.backup_terminal_id' => ['nullable', 'integer'],
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
        if ($enabled && ! collect($routes)->contains(fn ($r) => $r['order_type'] === 'delivery' && $r['terminal_id'])) {
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
                    ['terminal_id' => $route['terminal_id'], 'backup_terminal_id' => $route['backup_terminal_id']],
                );
            }
        });

        return redirect('/online-ordering/settings')->with('status', 'Website settings saved.');
    }

    /**
     * routes[branch_id][order_type][terminal_id|backup_terminal_id] → saaf rows. Terminal usi branch ka
     * ho, aur backup wohi na ho jo pehla hai.
     *
     * @return list<array{branch_id: int, order_type: string, terminal_id: ?int, backup_terminal_id: ?int}>
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
                $primary = isset($types[$type]['terminal_id']) && $types[$type]['terminal_id'] !== '' ? (int) $types[$type]['terminal_id'] : null;
                $backup = isset($types[$type]['backup_terminal_id']) && $types[$type]['backup_terminal_id'] !== '' ? (int) $types[$type]['backup_terminal_id'] : null;

                foreach (['terminal_id' => $primary, 'backup_terminal_id' => $backup] as $field => $terminalId) {
                    if ($terminalId !== null && (int) ($terminalBranch[$terminalId] ?? 0) !== (int) $branchId) {
                        throw ValidationException::withMessages([
                            "routes.$branchId.$type.$field" => 'Choose a terminal of the same branch.',
                        ]);
                    }
                }
                if ($backup !== null && $backup === $primary) {
                    throw ValidationException::withMessages([
                        "routes.$branchId.$type.backup_terminal_id" => 'The backup terminal must be a different terminal.',
                    ]);
                }

                $out[] = ['branch_id' => (int) $branchId, 'order_type' => $type, 'terminal_id' => $primary, 'backup_terminal_id' => $backup];
            }
        }

        return $out;
    }
}
