<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * W3/W4 S3b — the one place a dish's catering profile (and its Urdu display name) is written, shared by the Catering
 * Profiles screen and (S3c) the product workspace. Moved here from CateringProductProfileController with two changes:
 *  - the refused costing switch is THROWN (a ValidationException on "costing_mode", same words), so a caller inside a
 *    bigger transaction rolls back; the screen sees the same redirect back with the same error;
 *  - the Urdu name is written only when "name_ur" was posted (empty still removes it). The screen always posts it, so
 *    it behaves as before; a caller that does not show the box can no longer wipe the name by leaving it out.
 */
final class CateringProfileWriter
{
    public function __construct(private readonly CateringLocalizationService $localization) {}

    /** The rules the Catering Profiles screen has always used; an existing profile does not need its product again. */
    public static function rules(bool $existing = false): array
    {
        return [
            'product_id' => [
                $existing ? 'nullable' : 'required',
                'exists:products,id',
            ],
            'catering_enabled' => ['nullable', 'boolean'],
            // KASHIF-ORDER-PUNCH §A: the legacy per-item switches.
            'allow_party_supply' => ['nullable', 'boolean'],
            'is_complimentary' => ['nullable', 'boolean'],
            'default_quote_unit_id' => ['nullable', 'exists:units,id'],
            'pricing_mode' => ['required', Rule::in(CateringProductProfile::PRICING_MODES)],
            // KASHIF-CATERING-COSTING-SOURCE-1: which authority decides this
            // dish's cost. Separate from pricing_mode above and never inferred
            // from it — the operator chooses it deliberately, per product.
            'costing_mode' => ['required', Rule::in(CateringProductProfile::COSTING_MODES)],
            'default_catering_rate' => ['nullable', 'numeric', 'min:0'],
            'production_station' => ['nullable', 'string', 'max:50'],
            'minimum_qty' => ['nullable', 'numeric', 'min:0'],
            'production_label' => ['nullable', 'string', 'max:255'],
            'production_label_ur' => ['nullable', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'name_ur' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array  $attributes  names for the messages
     * @throws ValidationException
     */
    public function validate(array $input, ?CateringProductProfile $profile = null, array $attributes = []): array
    {
        return Validator::make($input, self::rules($profile !== null), [], $attributes)->validate();
    }

    /**
     * Save a profile: the existing one ($profile), or the product's own (found by product_id, created when it has none).
     * The costing switch is checked first; a refusal writes nothing.
     *
     * @throws ValidationException
     */
    public function save(?CateringProductProfile $profile, array $data): CateringProductProfile
    {
        $productId = $profile ? (int) $profile->product_id : (int) $data['product_id'];
        $stored = ($profile ?? CateringProductProfile::where('product_id', $productId)->first())?->costingMode();

        $this->refuseUnreadySwitch($productId, $data['costing_mode'], $stored);

        return DB::connection('tenant')->transaction(function () use ($profile, $data, $productId) {
            $fields = collect($data)->except(['product_id', 'name_ur'])->all();
            if ($profile) {
                $profile->update($fields);
            } else {
                $profile = CateringProductProfile::updateOrCreate(['product_id' => $productId], $fields);
            }

            if (array_key_exists('name_ur', $data)) {
                $this->localization->setProductName(
                    $profile->product ?? Product::findOrFail($productId),
                    CateringLocalizationService::LOCALE_URDU,
                    $data['name_ur']
                );
            }

            return $profile;
        });
    }

    /**
     * KASHIF-CATERING-COSTING-SOURCE-1 — a dish may not be moved into a costing
     * source that cannot yet cost it.
     *
     * Without this the operator could save "Cost Blocks" on a dish with no
     * blocks. Nothing would break at that moment; the dish would simply become
     * unquotable, and they would find out at send time, having forgotten what
     * they changed. The refusal belongs where the decision is made.
     *
     * It fires ONLY when the source actually changes. Editing a station or a
     * label on a dish whose recipe has always been incomplete must not suddenly
     * fail — this guards the switch, not the history.
     *
     * Neither dataset is touched by a refusal. The blocks an operator was part
     * way through configuring are still there when they come back to finish.
     *
     * @throws ValidationException
     */
    private function refuseUnreadySwitch(int $productId, string $requested, ?string $stored): void
    {
        if ($stored !== null && $requested === $stored) {
            return;
        }

        // A brand-new profile defaulting to recipe is the state every dish is
        // already in; nothing is being moved, so nothing is validated.
        if ($stored === null && $requested === CateringProductProfile::COSTING_RECIPE) {
            return;
        }

        $product = Product::find($productId);
        if (! $product) {
            return; // the validator's exists: rule already owns this case
        }

        $readiness = app(CateringEstimateCostingService::class)->productReadinessFor($product, $requested);

        if ($readiness['ready']) {
            return;
        }

        $sourceName = $requested === CateringProductProfile::COSTING_BLOCKS ? 'Cost Blocks' : 'Recipe';

        throw ValidationException::withMessages([
            'costing_mode' => "{$product->name} cannot switch to {$sourceName} yet: "
                .implode(' ', $readiness['blockers'])
                .' Nothing has been changed, and the configuration you already have is untouched.',
        ]);
    }
}
