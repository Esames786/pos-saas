<?php

namespace App\Services\Products;

use App\Http\Controllers\Tenant\Catering\CateringCommercialRateController;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\ManufacturingBom;
use App\Models\Tenant\Product;
use App\Models\Tenant\Recipe;
use App\Models\Tenant\RecipeIngredient;
use App\Models\Tenant\Unit;
use App\Services\Catering\CateringCostBlockService;
use App\Services\Catering\CateringCostBlockWriter;
use App\Services\Catering\CateringProfileWriter;
use App\Services\Kitchen\RecipeWriter;
use App\Services\Manufacturing\BomWriter;
use App\Support\Business;
use App\Support\ProductCards;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * W3/W4 S3c — a product's recipe, bill of materials and event costing, edited inside the product form and saved in the
 * product's own transaction, through the same writers the Recipes, Bill of materials and Catering screens use.
 *
 * The SERVER decides every section. One is offered only when this business has a card that needs it, the plan has the
 * module and the user holds the permission of that section's own screen — the two checks that screen's route makes —
 * and both are checked again on save. A section is written only when the product, as saved, needs it AND the user
 * changed it: each section posts a fingerprint of what it showed (`_was`), so an untouched section writes nothing (its
 * lines keep their ids), and a section changed on its own screen after this page opened is refused, never overwritten.
 * Advanced recipe/BOM metadata, ingredient settings and block rate source/basis/role are editable in collapsed
 * sections. They participate in stale-edit detection just like the visible lines. The profile's dormant pricing mode
 * and saved ingredient variant stay preserved. A block already following the house rate book keeps its applied rate;
 * linking and unlinking use the existing writer and audit.
 */
final class ProductWorkspace
{
    /** Each section posts under its own name; `cost_blocks` is the blocks part of Event costing. */
    public const SECTIONS = ['recipe', 'bom', 'catering', 'cost_blocks'];

    private const STALE = [
        'recipe'      => 'This recipe was changed on the Recipes screen after you opened this page, so your recipe changes were not saved. The recipe below is the saved one now — make your change again.',
        'bom'         => 'This bill of materials was changed on its own screen after you opened this page, so your changes to it were not saved. It now shows the saved one — make your change again.',
        'catering'    => 'The event costing of this dish was changed on the Catering screen after you opened this page, so your changes to it were not saved. It now shows the saved one — make your change again.',
        'cost_blocks' => 'The cost blocks of this dish were changed on the Cost blocks screen after you opened this page, so your changes to them were not saved. They now show the saved ones — make your change again.',
    ];

    public function __construct(
        private readonly RecipeWriter $recipes,
        private readonly BomWriter $boms,
        private readonly CateringCostBlockWriter $blocks,
        private readonly CateringProfileWriter $profiles,
    ) {}

    // ── Who may use which section ────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, bool>  section => this user may edit it here, for this product */
    public function access(?Product $product, ?string $business, ?array $cards = null): array
    {
        $cards ??= ProductCards::forBusiness($business);
        $profile = $product ? CateringProductProfile::where('product_id', $product->id)->first() : null;
        // W3/W4 S3d: a caterer's dish card offers it (Catering Materials offers no dish card), or a saved profile.
        $catering = (($business === Business::CATERING && collect($cards)->contains(fn ($c) => $c['kind'] === Product::KIND_SALE_ITEM)) || $profile !== null)
            && $this->allows($profile ? 'tenant.catering.profiles.update' : 'tenant.catering.profiles.store');

        return [
            'recipe' => collect($cards)->contains(fn ($c) => ! empty($c['ask']['recipe']))
                && $this->allows($product && $this->currentRecipe($product) ? 'tenant.recipes.update' : 'tenant.recipes.store'),
            'bom' => collect($cards)->contains(fn ($c) => $c['mfg'] === 'finished')
                && $this->allows($product && $this->currentBom($product) ? 'tenant.manufacturing.bom.update' : 'tenant.manufacturing.bom.store'),
            'catering' => $catering,
            'cost_blocks' => $catering && $this->allows('tenant.catering.cost-blocks.update'),
        ];
    }

    /**
     * CATERING-SIMPLIFY-1 (owner, 27 Oct) — "selling price disable hona chahiye tha". A dish priced by its cost blocks
     * takes its event price from them (CateringCostBlockService::rateFor, the event screen's order rate); the product's
     * Selling price is the counter (POS) price, which no catering screen reads. For a CATERER that box is locked to the
     * block price, so nobody changes the wrong number, and saving the dish makes its counter price the block price
     * (ProductController::blockPriceAfterSave; not while the blocks give no price yet).
     * Elsewhere (a restaurant with an event dish) the box stays open and says what events charge.
     *
     * The costing that decides is the one posted on the Event costing tab when this user may edit it, else the saved one.
     *
     * @return array{rate: float, catering: bool, blocks: bool, locked: bool}|null  null: an item with no event costing
     */
    public function blockPrice(?Product $product, ?string $business, ?string $postedMode = null): ?array
    {
        if (! $product || $product->product_kind !== Product::KIND_SALE_ITEM) {
            return null;
        }
        $profile = CateringProductProfile::where('product_id', $product->id)->first();
        if (! $profile) {
            return null;
        }
        $blocks = (in_array($postedMode, CateringProductProfile::COSTING_MODES, true) ? $postedMode : $profile->costingMode())
            === CateringProductProfile::COSTING_BLOCKS;
        $catering = $business === Business::CATERING;

        return [
            'rate' => app(CateringCostBlockService::class)->rateFor($product->id),
            'catering' => $catering,
            'blocks' => $blocks,
            'locked' => $catering && $blocks,
        ];
    }

    /** The two checks a screen's own route makes: the plan has its module, and the user holds its permission. */
    public function allows(string $routeName): bool
    {
        return \App\Support\ScreenAccess::allows($routeName);
    }

    /** The recipe the workspace edits: the product's active one, else its newest. */
    public function currentRecipe(Product $product): ?Recipe
    {
        return Recipe::where('product_id', $product->id)->orderByDesc('is_active')->orderByDesc('id')->first();
    }

    /** The BOM the workspace edits: the product's active one, else its newest that is not archived. */
    public function currentBom(Product $product): ?ManufacturingBom
    {
        return ManufacturingBom::where('finished_product_id', $product->id)->where('status', '!=', 'archived')
            ->orderByRaw("status = 'active' desc")->orderByDesc('id')->first();
    }

    // ── What the form shows ──────────────────────────────────────────────────────────────────────────────────────

    /**
     * Everything the three tabs need. A section the user may not edit is absent; `kept` names the ones this product
     * has and keeps as saved because of that.
     */
    public function screen(?Product $product, ?string $business, ?array $cards = null): array
    {
        $access = $this->access($product, $business, $cards);
        $units = Unit::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
        $out = ['access' => $access, 'units' => $units, 'kept' => []];

        if ($access['recipe']) {
            $recipe = $product ? $this->currentRecipe($product) : null;
            $state = $this->recipeSaved($recipe);
            $out['recipe'] = [
                'state' => $state, 'was' => self::fingerprint($state), 'recipe' => $recipe,
                'options' => $this->itemOptions($product, collect($state['lines'])->pluck('product_id')->all(), null),
                'sections' => RecipeIngredient::SECTIONS,
            ];
        } elseif ($product && $this->currentRecipe($product)) {
            $out['kept'][] = 'Recipe';
        }

        if ($access['bom']) {
            $bom = $product ? $this->currentBom($product) : null;
            $state = $this->bomSaved($bom);
            $out['bom'] = [
                'state' => $state, 'was' => self::fingerprint($state), 'bom' => $bom,
                'options' => $this->itemOptions($product, collect($state['lines'])->pluck('component_product_id')->all(), [
                    Product::KIND_RAW_MATERIAL, Product::KIND_SEMI_FINISHED, Product::KIND_PACKAGING_MATERIAL,
                ]),
            ];
        } elseif ($product && $this->currentBom($product)) {
            $out['kept'][] = 'Bill of materials';
        }

        if ($access['catering']) {
            $profile = $product ? CateringProductProfile::where('product_id', $product->id)->first() : null;
            $state = $this->profileSaved($profile, $product);
            $out['catering'] = ['state' => $state, 'was' => self::fingerprint($state), 'profile' => $profile];
            $blocks = $product ? app(CateringCostBlockService::class)->blocksFor($product->id) : collect();
            if ($access['cost_blocks']) {
                $bstate = $this->blocksSaved($blocks);
                $out['cost_blocks'] = [
                    'state' => $bstate, 'was' => self::fingerprint($bstate), 'blocks' => $blocks->keyBy('id'),
                    'materials' => $this->itemOptions($product, collect($bstate['lines'])->pluck('material_product_id')->all(), CateringCommercialRateController::MATERIAL_KINDS),
                ];
            } else {
                $out['cost_blocks_readonly'] = $blocks;
                if ($blocks->isNotEmpty()) {
                    $out['kept'][] = 'Cost blocks';
                }
            }
        } elseif ($product && CateringProductProfile::where('product_id', $product->id)->exists()) {
            $out['kept'][] = 'Event costing';
        }

        return $out;
    }

    /** Items a line may use: active ones (never the product itself or a deal), plus whatever a saved line already uses. */
    private function itemOptions(?Product $product, array $usedIds, ?array $kinds): Collection
    {
        $usedIds = array_values(array_filter($usedIds));

        return Product::query()
            ->with('unit:id,code')
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('status', 'active')
                // An older item may have no kind at all; "!= deal" alone would drop it (NULL never compares).
                ->where(fn ($k) => $k->whereNull('product_kind')->orWhere('product_kind', '!=', Product::KIND_COMBO_VIRTUAL))
                ->when($kinds, fn ($k) => $k->whereIn('product_kind', $kinds)))
                ->when($usedIds, fn ($w) => $w->orWhereIn('id', $usedIds)))
            ->when($product, fn ($q) => $q->where('id', '!=', $product->id))
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'unit_id', 'product_kind', 'default_purchase_price', 'purchase_pack_size']);
    }

    // ── The saved and the posted state of each section, in one shape ────────────────────────────────────────────

    private function recipeSaved(?Recipe $recipe): array
    {
        return $this->recipeState(
            $recipe ? $recipe->only(['name', 'is_active', 'notes', 'doc_no', 'recipe_no', 'revision_no', 'overhead_percent', 'yield_quantity', 'yield_unit_id'])
                + ['review_date' => $recipe->review_date?->format('Y-m-d')] : [],
            $recipe ? $recipe->ingredients()->orderBy('sort_order')->orderBy('id')->get()->map(fn ($l) => $l->only(['id', 'product_id', 'quantity', 'unit_id', 'line_section', 'cost_override', 'applicable_order_types']))->all() : []
        );
    }

    private function recipeState(array $header, array $lines): array
    {
        return [
            'name' => self::text($header['name'] ?? null),
            'is_active' => self::flag($header['is_active'] ?? null, true),
            'notes' => self::text($header['notes'] ?? null),
            'doc_no' => self::text($header['doc_no'] ?? null),
            'recipe_no' => self::text($header['recipe_no'] ?? null),
            'revision_no' => self::num($header['revision_no'] ?? null) ?? '1',
            'review_date' => self::text($header['review_date'] ?? null),
            'overhead_percent' => self::num($header['overhead_percent'] ?? null) ?? '0',
            'yield_quantity' => self::num($header['yield_quantity'] ?? null) ?? '1',
            'yield_unit_id'  => self::id($header['yield_unit_id'] ?? null),
            'lines' => self::rows($lines, ['product_id', 'quantity'], fn ($l) => [
                'id' => self::id($l['id'] ?? null), 'product_id' => self::id($l['product_id'] ?? null),
                'quantity' => self::num($l['quantity'] ?? null), 'unit_id' => self::id($l['unit_id'] ?? null),
                'line_section' => (string) (($l['line_section'] ?? null) ?: 'food_cost'),
                'cost_override' => self::num($l['cost_override'] ?? null),
                'applicable_order_types' => self::orderTypes($l['applicable_order_types'] ?? null),
            ]),
        ];
    }

    private function bomSaved(?ManufacturingBom $bom): array
    {
        return $this->bomState(
            $bom ? $bom->only(['bom_no', 'name', 'version', 'status', 'notes', 'output_quantity'])
                + ['effective_from' => $bom->effective_from?->format('Y-m-d')] : [],
            $bom ? $bom->lines()->orderBy('sort_order')->orderBy('id')->get()->map(fn ($l) => $l->only(['id', 'component_product_id', 'quantity', 'unit_id', 'wastage_percent', 'notes']))->all() : []
        );
    }

    private function bomState(array $header, array $lines): array
    {
        return [
            'bom_no' => self::text($header['bom_no'] ?? null),
            'name' => self::text($header['name'] ?? null),
            'version' => self::text($header['version'] ?? null) ?? '1',
            'status' => self::text($header['status'] ?? null) ?? 'active',
            'effective_from' => self::text($header['effective_from'] ?? null),
            'notes' => self::text($header['notes'] ?? null),
            'output_quantity' => self::num($header['output_quantity'] ?? null) ?? '1',
            'lines' => self::rows($lines, ['component_product_id', 'quantity'], fn ($l) => [
                'id' => self::id($l['id'] ?? null), 'component_product_id' => self::id($l['component_product_id'] ?? null),
                'quantity' => self::num($l['quantity'] ?? null), 'unit_id' => self::id($l['unit_id'] ?? null),
                'wastage_percent' => self::num($l['wastage_percent'] ?? null) ?? '0',
                'notes' => self::text($l['notes'] ?? null),
            ]),
        ];
    }

    private const PROFILE_SHOWN = ['costing_mode', 'default_catering_rate', 'default_quote_unit_id', 'minimum_qty', 'production_station',
        // CATERING-SIMPLIFY-1: what the Catering Products pop-up edited.
        'catering_enabled', 'allow_party_supply', 'is_complimentary', 'production_label', 'production_label_ur', 'instructions'];

    /** The profile as saved, with the product's Urdu name (a product translation, written by the same writer). */
    private function profileSaved(?CateringProductProfile $profile, ?Product $product): array
    {
        $urdu = $product ? \App\Models\Tenant\ProductTranslation::where('product_id', $product->id)->where('language_code', 'ur')->value('name') : null;

        return $this->profileState((['name_ur' => $urdu]) + ($profile ? ['costing_mode' => $profile->costingMode()] + $profile->only(self::PROFILE_SHOWN) : []));
    }

    private function profileState(array $p): array
    {
        return [
            'costing_mode'          => in_array($p['costing_mode'] ?? null, CateringProductProfile::COSTING_MODES, true) ? $p['costing_mode'] : CateringProductProfile::COSTING_RECIPE,
            'default_catering_rate' => self::num($p['default_catering_rate'] ?? null),
            'default_quote_unit_id' => self::id($p['default_quote_unit_id'] ?? null),
            'minimum_qty'           => self::num($p['minimum_qty'] ?? null),
            'production_station'    => self::text($p['production_station'] ?? null),
            // A new profile starts as the Catering Products screen starts one: on, party supply allowed, not free.
            'catering_enabled'      => self::flag($p['catering_enabled'] ?? null, true),
            'allow_party_supply'    => self::flag($p['allow_party_supply'] ?? null, true),
            'is_complimentary'      => self::flag($p['is_complimentary'] ?? null, false),
            'production_label'      => self::text($p['production_label'] ?? null),
            'production_label_ur'   => self::text($p['production_label_ur'] ?? null),
            'instructions'          => self::text($p['instructions'] ?? null),
            'name_ur'               => self::text($p['name_ur'] ?? null),
        ];
    }

    private static function text($v): ?string
    {
        return is_scalar($v) && trim((string) $v) !== '' ? trim(str_replace("
", "
", (string) $v)) : null;
    }

    /** A yes / no as posted ("1" / "0") or as saved; nothing at all is the default. */
    private static function flag($v, bool $default): bool
    {
        return $v === null || $v === '' ? $default : filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    private function blocksSaved(Collection $blocks): array
    {
        return $this->blocksState($blocks->map(fn ($b) => $b->only(['id', 'label', 'block_type', 'charge_basis', 'rate', 'material_product_id', 'quantity_per_unit', 'unit_id', 'rate_basis', 'commercial_rate_source', 'charge_role']))->all());
    }

    private function blocksState(array $lines): array
    {
        return ['lines' => self::rows($lines, ['label', 'rate'], fn ($b) => [
            'id' => self::id($b['id'] ?? null), 'label' => trim((string) ($b['label'] ?? '')),
            'block_type' => (string) (($b['block_type'] ?? null) ?: CateringProductCostBlock::TYPE_CHARGE),
            'charge_basis' => (string) (($b['charge_basis'] ?? null) ?: CateringProductCostBlock::BASIS_PER_UNIT),
            'rate' => self::num($b['rate'] ?? null),
            'material_product_id' => self::id($b['material_product_id'] ?? null),
            'quantity_per_unit' => self::num($b['quantity_per_unit'] ?? null),
            'unit_id' => self::id($b['unit_id'] ?? null),
            'rate_basis' => self::text($b['rate_basis'] ?? null),
            'commercial_rate_source' => self::text($b['commercial_rate_source'] ?? null),
            'charge_role' => self::text($b['charge_role'] ?? null),
        ])];
    }

    /** Selection order is immaterial. Retain invalid values for the writer to reject. */
    private static function orderTypes($types): mixed
    {
        if (! is_array($types)) {
            return $types === '' ? null : $types;
        }
        if (collect($types)->contains(fn ($type) => ! is_string($type))) {
            return $types; // Leave malformed values to the writer's validation.
        }
        $types = array_values(array_unique($types));
        sort($types);

        return $types === [] || $types === ['all'] ? null : $types;
    }

    /** The posted rows that say something (an empty added row is not a line), each in the section's shape. Keys stay. */
    private static function rows(array $lines, array $any, callable $shape): array
    {
        $out = [];
        foreach ($lines as $k => $l) {
            if (! is_array($l) || collect($any)->every(fn ($f) => trim((string) ($l[$f] ?? '')) === '')) {
                continue;
            }
            $out[$k] = $shape($l);
        }

        return $out;
    }

    private static function num($v): ?string
    {
        if ($v === null || trim((string) $v) === '') {
            return null;
        }

        return is_numeric($v) ? rtrim(rtrim(number_format((float) $v, 6, '.', ''), '0'), '.') : trim((string) $v);
    }

    private static function id($v): ?int
    {
        return is_numeric($v) && (int) $v > 0 ? (int) $v : null;
    }

    /** Order-sensitive and key-blind: the same rows in the same order give the same print. */
    public static function fingerprint(array $state): string
    {
        array_walk($state, function (&$v, $k) { if ($k === 'lines') { $v = array_values($v); } });

        return sha1(json_encode($state));
    }

    // ── Saving ───────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Before anything is written: the sections this save changes, as posted. A section the user may not edit, did not
     * post, or did not change is left out — and so never written. One changed on its own screen since the page opened
     * is refused.
     *
     * @return array<string, array>
     * @throws ValidationException
     */
    public function touched(Request $request, ?Product $product, ?string $business, ?array $cards = null): array
    {
        $access = $this->access($product, $business, $cards);
        $stale = [];
        $out = [];
        foreach (self::SECTIONS as $s) {
            $in = $request->input($s);
            if (! $access[$s] || ! is_array($in) || ! is_string($in['_was'] ?? null)) {
                continue;
            }
            $posted = match ($s) {
                'recipe' => $this->recipeState($in, (array) ($in['lines'] ?? [])),
                'bom' => $this->bomState($in, (array) ($in['lines'] ?? [])),
                'catering' => $this->profileState($in),
                'cost_blocks' => $this->blocksState((array) ($in['lines'] ?? [])),
            };
            if (self::fingerprint($posted) === $in['_was']) {
                continue;   // shown, not changed: nothing to write
            }
            $saved = match ($s) {
                'recipe' => $this->recipeSaved($product ? $this->currentRecipe($product) : null),
                'bom' => $this->bomSaved($product ? $this->currentBom($product) : null),
                'catering' => $this->profileSaved($product ? CateringProductProfile::where('product_id', $product->id)->first() : null, $product),
                'cost_blocks' => $this->blocksSaved($product ? app(CateringCostBlockService::class)->blocksFor($product->id) : collect()),
            };
            if (self::fingerprint($saved) !== $in['_was']) {
                $stale[$s . '.stale'] = self::STALE[$s];
                continue;
            }
            $out[$s] = $in;
        }
        if ($stale) {
            throw ValidationException::withMessages($stale);
        }

        return $out;
    }

    /**
     * Write the changed sections the product, as now saved, needs — inside the product's own transaction, so any
     * refusal rolls the whole save back. Order: recipe, BOM, cost blocks, then the profile, whose costing switch is
     * checked against what was just written.
     *
     * @throws ValidationException  refusals keyed under their section ("recipe.lines.3.quantity")
     */
    public function apply(Product $product, array $touched, ?int $userId): void
    {
        $product->refresh();
        $dish = $product->product_kind === Product::KIND_SALE_ITEM;

        if (isset($touched['recipe']) && $product->inventory_consumption_method === 'recipe') {
            $this->under('recipe', ['ingredients' => 'lines'], fn () => $this->saveRecipe($product, $touched['recipe']));
        }
        if (isset($touched['bom']) && $product->can_be_bom_output) {
            $this->under('bom', ['lines' => 'lines'], fn () => $this->saveBom($product, $touched['bom'], $userId));
        }
        if (isset($touched['cost_blocks']) && $dish) {
            $this->under('cost_blocks', ['blocks' => 'lines'], fn () => $this->saveBlocks($product, $touched['cost_blocks'], $userId));
        }
        if (isset($touched['catering']) && $dish) {
            $this->under('catering', [], fn () => $this->saveProfile($product, $touched['catering']));
        }
    }

    /** Run one section's save; its refusals come back under the section's own name, as the form's boxes are named. */
    private function under(string $section, array $rename, callable $save): void
    {
        try {
            $save();
        } catch (ValidationException $e) {
            $errors = [];
            foreach ($e->errors() as $key => $messages) {
                $head = explode('.', $key, 2);
                $key = isset($rename[$head[0]]) ? $rename[$head[0]] . (isset($head[1]) ? '.' . $head[1] : '') : $key;
                $errors[$section . '.' . $key] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }

    private function saveRecipe(Product $product, array $in): void
    {
        $recipe = $this->currentRecipe($product);
        $saved = $recipe ? $recipe->ingredients()->get()->keyBy('id') : collect();
        $lines = [];
        $pos = 0;
        foreach ($this->recipeState($in, (array) ($in['lines'] ?? []))['lines'] as $k => $l) {
            $old = $l['id'] ? $saved->get($l['id']) : null;
            $lines[$k] = [
                'product_id' => $l['product_id'], 'quantity' => $l['quantity'], 'unit_id' => $l['unit_id'], 'line_section' => $l['line_section'],
                // Not shown here — kept from the saved line (a variant only while the line keeps its item).
                'product_variant_id' => $old && (int) $old->product_id === $l['product_id'] ? $old->product_variant_id : null,
                'cost_override' => $l['cost_override'],
                'applicable_order_types' => $l['applicable_order_types'],
                'sort_order' => $pos++,
            ];
        }
        if ($lines === []) {
            throw ValidationException::withMessages(['ingredients' => 'Keep at least one ingredient. If it is no longer made from a recipe, answer No to "Is it made from other items?" on Selling & stock.']);
        }
        $header = array_intersect_key($in, array_flip(['name', 'is_active', 'notes', 'doc_no', 'recipe_no', 'revision_no', 'review_date', 'overhead_percent']))
            + $this->recipeSaved($recipe);
        if (! $recipe && empty($header['name'])) {
            $header['name'] = mb_substr($product->name, 0, 190);
        }
        $data = $this->recipes->validate([
            'product_id' => $product->id,
            'yield_quantity' => $in['yield_quantity'] ?? null,
            'yield_unit_id' => $in['yield_unit_id'] ?? null,
            'ingredients' => $lines,
        ] + $header, ['ingredients.*.product_id' => 'ingredient', 'ingredients.*.quantity' => 'quantity', 'ingredients.*.unit_id' => 'unit', 'yield_quantity' => '"makes"']);
        $active = (bool) $data['is_active'];
        if ($recipe && $this->recipes->unchanged($recipe, $data, $active)) {
            return;
        }
        $this->recipes->save($recipe, $data, $active);
    }

    private function saveBom(Product $product, array $in, ?int $userId): void
    {
        $bom = $this->currentBom($product);
        $lines = [];
        $pos = 0;
        foreach ($this->bomState($in, (array) ($in['lines'] ?? []))['lines'] as $k => $l) {
            $lines[$k] = [
                'component_product_id' => $l['component_product_id'], 'quantity' => $l['quantity'], 'unit_id' => $l['unit_id'],
                'wastage_percent' => $l['wastage_percent'],
                'notes' => $l['notes'],
                'sort_order' => $pos++,
            ];
        }
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Keep at least one material. To stop using this bill of materials, archive it on the Bill of materials screen.']);
        }
        $details = array_intersect_key($in, array_flip(['bom_no', 'name', 'version', 'status', 'effective_from', 'notes']))
            + $this->bomSaved($bom);
        if (! $bom && empty($details['name'])) {
            $details['name'] = mb_substr($product->name, 0, 255);
        }
        [$header, $valid] = $this->boms->validate([
            'finished_product_id' => $product->id,
            'output_quantity' => $in['output_quantity'] ?? null,
            'lines' => $lines,
        ] + $details, $bom, ['lines.*.component_product_id' => 'material', 'lines.*.quantity' => 'quantity', 'lines.*.wastage_percent' => 'waste %', 'output_quantity' => '"makes"']);
        if ($bom && $this->boms->unchanged($bom, $header, $valid)) {
            return;
        }
        $this->boms->save($bom, $header, $valid, $userId);
    }

    private function saveBlocks(Product $product, array $in, ?int $userId): void
    {
        $existing = CateringProductCostBlock::where('product_id', $product->id)->get()->keyBy('id');
        $rows = [];
        foreach ($this->blocksState((array) ($in['lines'] ?? []))['lines'] as $k => $b) {
            $old = $b['id'] ? $existing->get($b['id']) : null;
            $material = $b['block_type'] === CateringProductCostBlock::TYPE_MATERIAL;
            $source = $b['commercial_rate_source'] ?? $old?->commercial_rate_source;
            $staysLinked = $old && $old->followsCommercialBook() && $source === CateringProductCostBlock::SOURCE_COMMERCIAL_BOOK;
            if ($staysLinked && ((int) $old->material_product_id !== $b['material_product_id'] || (int) $old->unit_id !== (int) $b['unit_id'])) {
                throw ValidationException::withMessages(['blocks' => 'To change a linked material or its unit, choose Manual rate first, save, then link the new material to the house rate book.']);
            }
            $rows[$k] = [
                'id' => $old?->id, 'label' => $b['label'], 'block_type' => $b['block_type'], 'charge_basis' => $b['charge_basis'],
                // A block that follows the house rate book keeps its rate: only the book (or the Cost blocks screen) moves it.
                'rate' => $staysLinked ? $old->rate : $b['rate'],
                'material_product_id' => $material ? $b['material_product_id'] : null,
                'quantity_per_unit' => $material ? $b['quantity_per_unit'] : null,
                'unit_id' => $material ? $b['unit_id'] : null,
                'rate_basis' => $b['rate_basis'] ?? $old?->rate_basis,
                'commercial_rate_source' => $source,
                'charge_role' => $b['charge_role'],
            ];
        }
        $data = $this->blocks->validate(['blocks' => $rows], ['blocks.*.label' => 'name', 'blocks.*.rate' => 'rate', 'blocks.*.material_product_id' => 'material', 'blocks.*.unit_id' => 'unit']);
        $plan = $this->blocks->plan($product->id, $data['blocks'] ?? []);
        if ($this->blocks->unchanged($product->id, $plan)) {
            return;
        }
        // Blocks belong to a dish's catering profile: a dish without one gets one, set as the Catering screen sets a new one.
        if (! CateringProductProfile::where('product_id', $product->id)->exists()) {
            $this->saveProfile($product, []);
        }
        $this->blocks->apply($product->id, $plan, $userId);
    }

    private function saveProfile(Product $product, array $in): void
    {
        $profile = CateringProductProfile::where('product_id', $product->id)->first();
        // What was not posted (the blocks part making a new profile posts nothing) keeps its saved value — the Urdu name
        // included, so a dish that only gets its first block never loses it.
        $shown = $this->profileState($in + $this->profileSaved($profile, $product));
        $data = $this->profiles->validate($shown + [
            'product_id' => $product->id,
            // Not shown here — kept from the saved profile; a new one starts as the Catering Profiles screen starts it.
            'pricing_mode'        => $profile?->pricing_mode ?? CateringProductProfile::PRICING_FIXED,
        ], $profile, ['default_catering_rate' => 'catering rate', 'minimum_qty' => 'minimum', 'default_quote_unit_id' => 'quoted in',
            'production_label' => 'kitchen label', 'production_label_ur' => 'Urdu kitchen label', 'name_ur' => 'Urdu name']);
        $this->profiles->save($profile, $data);
    }
}
