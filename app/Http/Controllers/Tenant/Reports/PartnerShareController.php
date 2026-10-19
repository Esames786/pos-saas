<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Combo;
use App\Models\Tenant\Product;
use App\Models\Tenant\SharePartner;
use App\Models\Tenant\ShareRate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * PARTNER-SHARE-1 — the rate list behind the Report Center's "Partner Share" section.
 *
 * Partners (any number) and, per partner, what one side owes the other each time a dish sells. A
 * rate is never edited: changing it adds a version with a later "effective from", so a period
 * already reported keeps its figures. Nothing here touches sales, stock or the books.
 */
class PartnerShareController extends Controller
{
    public function index(Request $request)
    {
        $partners = SharePartner::orderByDesc('is_active')->orderBy('name')->get();
        $partner = $partners->firstWhere('id', $request->integer('partner_id')) ?? $partners->first();
        $today = now()->toDateString();

        $versions = $partner
            ? ShareRate::with(['product:id,name,default_selling_price', 'combo:id,name,price'])
                ->where('share_partner_id', $partner->id)
                ->orderBy('item_key')->orderBy('effective_from')->get()
            : collect();

        // The version in force today per item, plus any dated later (shown as upcoming).
        $current = $versions->groupBy('item_key')->map(function ($list) use ($today) {
            return $list->filter(fn ($v) => $v->effective_from->toDateString() <= $today)->last()
                ?? $list->first();
        })->filter(fn ($v) => (float) $v->amount > 0)
            ->sortBy(fn ($v) => [$v->payer, $this->itemName($v)])->values();

        return view('tenant.reports.partner-share.index', [
            'partners' => $partners,
            'partner' => $partner,
            'current' => $current,
            'history' => $versions->sortByDesc('effective_from')->values(),
            'today' => $today,
            'products' => Product::where('status', 'active')->orderBy('name')->get(['id', 'name', 'default_selling_price']),
            'combos' => Combo::where('status', 'active')->orderBy('name')->get(['id', 'name', 'price']),
            'itemName' => fn ($v) => $this->itemName($v),
            'itemPrice' => fn ($v) => (float) ($v->combo_id ? ($v->combo?->price ?? 0) : ($v->product?->default_selling_price ?? 0)),
        ]);
    }

    public function storePartner(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('tenant.share_partners', 'name')],
            'count_free_lines' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $partner = SharePartner::create([
            'name' => trim($data['name']),
            'count_free_lines' => $request->boolean('count_free_lines'),
            'is_active' => true,
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect('/reports/partner-share?partner_id=' . $partner->id)->with('status', 'Partner added.');
    }

    public function updatePartner(Request $request, SharePartner $sharePartner)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('tenant.share_partners', 'name')->ignore($sharePartner->id)],
            'count_free_lines' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $sharePartner->update([
            'name' => trim($data['name']),
            'count_free_lines' => $request->boolean('count_free_lines'),
            'is_active' => $request->boolean('is_active'),
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect('/reports/partner-share?partner_id=' . $sharePartner->id)->with('status', 'Partner saved.');
    }

    public function storeRate(Request $request)
    {
        $data = $request->validate([
            'share_partner_id' => ['required', 'integer', Rule::exists('tenant.share_partners', 'id')],
            'item' => ['required', 'string', 'regex:/^[pc]:\d+$/'],
            'payer' => ['required', Rule::in([ShareRate::PAYER_PARTNER, ShareRate::PAYER_US])],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'effective_from' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        [$type, $id] = explode(':', $data['item']);
        $id = (int) $id;
        $exists = $type === 'c' ? Combo::whereKey($id)->exists() : Product::whereKey($id)->exists();
        if (! $exists) {
            throw ValidationException::withMessages(['item' => 'That dish or deal no longer exists.']);
        }

        $productId = $type === 'p' ? $id : null;
        $comboId = $type === 'c' ? $id : null;
        $key = ShareRate::itemKey($productId, $comboId);
        $from = \Illuminate\Support\Carbon::parse($data['effective_from'])->toDateString();

        if (ShareRate::where('share_partner_id', $data['share_partner_id'])->where('item_key', $key)
            ->whereDate('effective_from', $from)->exists()) {
            throw ValidationException::withMessages([
                'effective_from' => 'This item already has a rate from ' . $from . '. Delete that one first, or pick another date.',
            ]);
        }

        ShareRate::create([
            'share_partner_id' => $data['share_partner_id'],
            'item_key' => $key,
            'product_id' => $productId,
            'combo_id' => $comboId,
            'payer' => $data['payer'],
            'amount' => $data['amount'],
            'effective_from' => $from,
            'notes' => $data['notes'] ?? null,
            'created_by' => Auth::id(),
        ]);

        return redirect('/reports/partner-share?partner_id=' . $data['share_partner_id'])->with('status', 'Rate saved.');
    }

    /** For a row entered by mistake. The report reads live, so this changes past periods too. */
    public function destroyRate(ShareRate $shareRate)
    {
        $partnerId = $shareRate->share_partner_id;
        $shareRate->delete();

        return redirect('/reports/partner-share?partner_id=' . $partnerId)->with('status', 'Rate deleted.');
    }

    private function itemName(ShareRate $v): string
    {
        return $v->combo_id
            ? ($v->combo?->name ?? 'Deal #' . $v->combo_id)
            : ($v->product?->name ?? 'Product #' . $v->product_id);
    }
}
