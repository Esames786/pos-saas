<?php

namespace App\Services\OnlineOrdering;

use App\Models\Tenant\Category;
use App\Models\Tenant\Combo;
use App\Models\Tenant\ModifierGroup;
use App\Models\Tenant\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ONLINE-ORDERING-1 — website ka menu MOJOODA catalog se, aik branch ke liye.
 *
 * Koi alag menu nahi: jo product/deal "Website par dikhao" ho wo apni website category (khaali = POS
 * category) me jata hai; jin ka `website_card_name` (khaali = naam) aik ho wo aik card ke size ban
 * jate hain. Add-ons = us product/deal ke modifier groups jin ka channel "website" ya "both" ho.
 *
 * Qeemat (POS ki qeemat kabhi nahi badalti):
 *   product: website price → us branch ki POS qeemat → product ki POS qeemat
 *   deal:    website price → deal ki qeemat
 * Dikhe ya nahi: product active + bikne wala + branch par available; deal active + sab/isi branch ki;
 * category active + website par + apne waqt me. Card me kam az kam aik size bikta ho.
 *
 * Website, preview aur checkout teeno yahin se parhte hain — jo customer ne dekha wohi order me jaye.
 */
class WebMenuService
{
    /** @var array<int, object>|null product_id → branch price row */
    private ?array $branchPrices = null;

    private ?int $branchPricesFor = null;

    /**
     * @return list<array<string, mixed>> categories → cards → options (sizes) → addons
     */
    public function menu(int $branchId, ?string $hhmm = null, bool $preview = false): array
    {
        $this->loadBranchPrices($branchId);

        $categories = Category::where('show_on_website', true)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->orderByRaw('website_sort_order IS NULL')->orderBy('website_sort_order')->orderBy('sort_order')->orderBy('name')
            ->get()->keyBy('id');

        $addonScope = fn ($q) => $q->whereIn('channel', ['website', 'both'])->where('status', 'active')
            ->with(['modifiers' => fn ($m) => $m->where('status', 'active')]);

        $members = Product::where('show_on_website', true)->with(['modifierGroups' => $addonScope])->get()
            ->map(fn ($p) => ['kind' => 'product', 'model' => $p])
            ->concat(Combo::where('show_on_website', true)->with(['modifierGroups' => $addonScope])->get()
                ->map(fn ($c) => ['kind' => 'combo', 'model' => $c]));

        $cards = [];
        foreach ($members as $m) {
            $model = $m['model'];
            $categoryId = (int) ($model->website_category_id ?: $model->category_id);
            if (! $categories->has($categoryId)) {
                continue;
            }

            [$price, $available, $posPrice] = $m['kind'] === 'combo'
                ? $this->quoteCombo($model, $branchId)
                : $this->quoteProduct($model);
            if ($price === null || (! $available && ! $preview)) {
                continue;
            }

            $cardName = trim((string) ($model->website_card_name ?: $model->name));
            $key = $categoryId.'|'.mb_strtolower($cardName);
            $card = $cards[$key] ?? [
                'category_id' => $categoryId, 'name' => $cardName, 'description' => null,
                'image_path' => null, 'tag' => null, 'popular' => false, 'sort' => PHP_INT_MAX, 'options' => [],
            ];

            $card['description'] ??= $model->website_description ?: ($model->description ?: null);
            $card['image_path'] ??= $model->website_image_path ?: ($m['kind'] === 'product' ? $model->image_path : null);
            $card['tag'] ??= $model->website_tag ?: null;
            $card['popular'] = $card['popular'] || (bool) $model->website_popular;
            $card['sort'] = min($card['sort'], $model->website_sort_order ?? PHP_INT_MAX);
            $card['options'][] = [
                'kind' => $m['kind'], 'pos_id' => (int) $model->id, 'pos_name' => $model->name,
                'label' => $model->website_size_label ?: null, 'price' => $price, 'pos_price' => $posPrice,
                'available' => $available, 'sort' => $model->website_sort_order ?? PHP_INT_MAX,
                'addons' => $this->addons($model->modifierGroups),
            ];
            $cards[$key] = $card;
        }

        $out = [];
        foreach ($categories as $category) {
            if (! $preview && $hhmm !== null && ! $category->websiteVisibleAt($hhmm)) {
                continue;
            }

            $items = collect($cards)->where('category_id', $category->id)
                ->map(function ($card) {
                    usort($card['options'], fn ($a, $b) => [$a['sort'], $a['price']] <=> [$b['sort'], $b['price']]);
                    $card['from_price'] = min(array_column($card['options'], 'price'));

                    return $card;
                })
                ->sortBy([['sort', 'asc'], ['name', 'asc']])->values()->all();

            if ($items === [] && ! $preview) {
                continue;
            }

            $out[] = [
                'id' => $category->id, 'name' => $category->website_name ?: $category->name,
                'visible_from' => $category->website_visible_from, 'visible_to' => $category->website_visible_to,
                'items' => $items,
            ];
        }

        return $out;
    }

    /**
     * Preview ki doosri list: POS par bikne wali cheezein jo website par nahi — taake koi bhool na jaye.
     *
     * @return Collection<int, array{kind: string, id: int, name: string, category: ?string}>
     */
    public function notOnWebsite(): Collection
    {
        $products = Product::with('category:id,name')->where('status', 'active')->where('is_sellable', true)
            ->where('is_pos_visible', true)->where('show_on_website', false)->orderBy('name')->get()
            ->map(fn ($p) => ['kind' => 'product', 'id' => $p->id, 'name' => $p->name, 'category' => $p->category?->name]);
        $combos = Combo::with('category:id,name')->where('status', 'active')->where('show_on_website', false)->orderBy('name')->get()
            ->map(fn ($c) => ['kind' => 'combo', 'id' => $c->id, 'name' => $c->name, 'category' => $c->category?->name ?? 'Deals']);

        return $products->concat($combos)->values();
    }

    /** @return array{0: ?float, 1: bool, 2: ?float} [website qeemat, bikta hai, POS qeemat] */
    public function quoteProduct(Product $product): array
    {
        $row = $this->branchPrices[$product->id] ?? null;
        $ok = $product->status === 'active' && $product->is_sellable && (! $row || (bool) $row->is_available);
        $pos = $row ? (float) $row->selling_price : (float) $product->default_selling_price;

        return [$product->website_price !== null ? (float) $product->website_price : $pos, $ok, $pos];
    }

    /** @return array{0: ?float, 1: bool, 2: ?float} */
    public function quoteCombo(Combo $combo, int $branchId): array
    {
        $ok = $combo->status === 'active' && ($combo->branch_id === null || (int) $combo->branch_id === $branchId);
        $pos = (float) $combo->price;

        return [$combo->website_price !== null ? (float) $combo->website_price : $pos, $ok, $pos];
    }

    /** @param  Collection<int, ModifierGroup>  $groups */
    private function addons(Collection $groups): array
    {
        return $groups->map(function (ModifierGroup $g) {
            $options = $g->modifiers->map(fn ($m) => [
                'id' => (int) $m->id, 'name' => $m->name, 'price' => (float) $m->price_delta,
            ])->values()->all();

            return $options === [] ? null : [
                'id' => (int) $g->id, 'name' => $g->name,
                'min' => min((int) $g->min_select, count($options)),
                'max' => $g->max_select !== null ? max(1, min((int) $g->max_select, count($options))) : count($options),
                'required' => (bool) $g->is_required || (int) $g->min_select > 0,
                'options' => $options,
            ];
        })->filter()->values()->all();
    }

    public function loadBranchPrices(int $branchId): void
    {
        if ($this->branchPricesFor === $branchId) {
            return;
        }

        $this->branchPrices = DB::connection('tenant')->table('product_branch_prices')
            ->where('branch_id', $branchId)->whereNull('product_variant_id')
            ->get(['product_id', 'selling_price', 'is_available'])
            ->keyBy('product_id')->all();
        $this->branchPricesFor = $branchId;
    }
}
