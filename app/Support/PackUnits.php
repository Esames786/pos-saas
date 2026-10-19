<?php

namespace App\Support;

use App\Models\Master\Tenant;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductBarcode;
use App\Models\Tenant\ProductPackUnit;
use App\Models\Tenant\ProductVariant;
use App\Models\Tenant\Unit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * PHARMACY-PACKS-1 — "1 Box = 10 Strip, 1 Strip = 10 Tablet" for one product.
 *
 * The product screen posts the packs as a chain: each row holds a whole number of the row before it (the
 * first row holds the product's own unit). This class turns that into sizes in the product's unit, checks
 * every rule on the SERVER, and saves them. Stock never moves here — it is always counted in the product's
 * own unit; a pack only names a bigger quantity of it.
 */
final class PackUnits
{
    public const MAX_PACKS = 3;
    public const MAX_NAME = 20;          // a receipt line has room for about this much
    public const MAX_FACTOR = 1000000;

    /** Lines that will carry a pack (steps 2–4). A pack on any of them is history: never removed or resized. */
    public const LINE_TABLES = [
        'sales_order_lines', 'sales_return_lines', 'goods_receipt_lines', 'purchase_order_lines',
        'purchase_bill_lines', 'purchase_return_lines', 'stock_adjustment_lines', 'stock_transfer_lines',
    ];

    /** The Master switch is on for this tenant and its database has the table. */
    public static function enabled(?Tenant $tenant = null): bool
    {
        return TenantFeatures::on(TenantFeatures::PACKS, $tenant)
            && Schema::connection('tenant')->hasTable('product_pack_units');
    }

    /**
     * One-click starting points per business: only the pack NAMES are filled — the owner types the numbers,
     * because a wrong guessed size would multiply every goods receipt.
     *
     * @return list<array{label:string, label_ur:string, names:list<string>, eg:list<int>, base:string}>
     */
    public static function presets(?string $business): array
    {
        $tablet = ['label' => 'Tablet → Strip → Box', 'label_ur' => 'گولی ← پتہ ← ڈبہ', 'names' => ['Strip', 'Box'], 'eg' => [10, 10], 'base' => 'Tablet'];
        $bottle = ['label' => 'Bottle → Carton', 'label_ur' => 'بوتل ← کارٹن', 'names' => ['Carton'], 'eg' => [12], 'base' => 'Bottle'];
        $packet = ['label' => 'Piece → Packet → Carton', 'label_ur' => 'دانہ ← پیکٹ ← کارٹن', 'names' => ['Packet', 'Carton'], 'eg' => [12, 24], 'base' => 'Piece'];
        $crate = ['label' => 'Bottle → Crate', 'label_ur' => 'بوتل ← کریٹ', 'names' => ['Crate'], 'eg' => [24], 'base' => 'Bottle'];
        $dozen = ['label' => 'Piece → Dozen', 'label_ur' => 'دانہ ← درجن', 'names' => ['Dozen'], 'eg' => [12], 'base' => 'Piece'];

        return match ($business) {
            Business::PHARMACY   => [$tablet, ['label' => 'Sachet → Box', 'label_ur' => 'ساشے ← ڈبہ', 'names' => ['Box'], 'eg' => [20], 'base' => 'Sachet'], $bottle],
            Business::MART       => [$packet, $dozen, $crate],
            Business::RESTAURANT => [$crate, $packet],
            default              => [$packet, $crate, $dozen],
        };
    }

    /** Name of the product's own unit as the screen shows it ("Tablet"), "Piece" when none is set. */
    public static function baseName(?int $unitId): string
    {
        return ($unitId ? Unit::whereKey($unitId)->value('name') : null) ?: 'Piece';
    }

    /** Units that hold part quantities (1.250 kg) cannot have packs. */
    public static function isPartUnit(?int $unitId): bool
    {
        return $unitId !== null && in_array(Unit::whereKey($unitId)->value('unit_type'), ['weight', 'volume', 'length'], true);
    }

    /**
     * The saved packs in the screen's chain form, smallest first (without the product's own unit).
     *
     * @return array{packs: list<array<string,mixed>>, base_sell: bool}
     */
    public static function forScreen(?Product $product): array
    {
        if (! $product) {
            return ['packs' => [], 'base_sell' => true];
        }
        $rows = $product->packUnits()->get();
        $base = $rows->firstWhere('factor', 1);
        $prev = 1;
        $packs = [];
        foreach ($rows->where('factor', '>', 1)->values() as $p) {
            $packs[] = [
                'id' => $p->id, 'name' => $p->name, 'contains' => intdiv($p->factor, $prev), 'factor' => $p->factor,
                'price' => $p->sale_price !== null ? rtrim(rtrim(number_format((float) $p->sale_price, 2, '.', ''), '0'), '.') : '',
                'barcode' => (string) $p->barcode, 'sell' => $p->can_sell, 'buy' => $p->can_buy,
            ];
            $prev = $p->factor;
        }

        return ['packs' => $packs, 'base_sell' => $base ? $base->can_sell : true];
    }

    /**
     * What the screen posted, checked. NULL = "it does not come in packs".
     *
     * @return array{base_name:string, base_sell:bool, packs:list<array{id:?int,name:string,factor:int,sale_price:?string,barcode:?string,can_sell:bool,can_buy:bool}>}|null
     */
    public static function fromRequest(array $in, ?Product $product): ?array
    {
        if (! filter_var($in['_packs'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }
        $unitId = isset($in['unit_id']) && $in['unit_id'] !== '' ? (int) $in['unit_id'] : null;
        $baseName = self::baseName($unitId);
        if (self::isPartUnit($unitId)) {
            throw ValidationException::withMessages(['_packs' => 'Packs are for items counted in pieces (tablet, bottle, piece) — not kg, litre or metre. (پیک صرف گنتی والی چیزوں کے لیے)']);
        }

        $filled = fn ($r) => is_array($r) && collect(['name', 'contains', 'price', 'barcode'])->contains(fn ($k) => trim((string) ($r[$k] ?? '')) !== '');
        $rows = array_values(array_filter((array) ($in['packs'] ?? []), $filled));
        if (! $rows) {
            throw ValidationException::withMessages(['_packs' => 'Add at least one pack (for example: 1 Strip = 10 ' . $baseName . ') — or answer "No". (کم از کم ایک پیک لکھیں)']);
        }
        if (count($rows) > self::MAX_PACKS) {
            throw ValidationException::withMessages(['_packs' => 'At most ' . self::MAX_PACKS . ' packs.']);
        }

        $ownIds = $product ? $product->packUnits()->pluck('id')->all() : [];
        $mainBarcode = trim((string) ($in['barcode'] ?? ''));
        $errors = [];
        $names = [mb_strtolower($baseName)];
        $codes = [];
        $factor = 1;
        $packs = [];
        $previous = $baseName;
        foreach ($rows as $i => $r) {
            $name = trim(preg_replace('/\s+/', ' ', (string) ($r['name'] ?? '')));
            $containsRaw = str_replace(',', '', trim((string) ($r['contains'] ?? '')));
            $priceRaw = str_replace(',', '', trim((string) ($r['price'] ?? '')));
            $code = trim((string) ($r['barcode'] ?? ''));

            if ($name === '') {
                $errors["packs.{$i}.name"] = 'Pack ' . ($i + 1) . ': give it a name, for example Strip or Box.';
            } elseif (mb_strlen($name) > self::MAX_NAME) {
                $errors["packs.{$i}.name"] = "Pack {$name}: keep the name to " . self::MAX_NAME . ' letters so it fits on the bill.';
            } elseif (in_array(mb_strtolower($name), $names, true)) {
                $errors["packs.{$i}.name"] = "Two packs cannot both be called {$name}.";
            }
            $names[] = mb_strtolower($name);

            $label = $name !== '' ? $name : 'Pack ' . ($i + 1);
            if (! preg_match('/^\d+$/', $containsRaw)) {
                $errors["packs.{$i}.contains"] = "{$label}: how many {$previous} are in one? Type a whole number, for example 10.";
            } elseif ((int) $containsRaw < 2) {
                $errors["packs.{$i}.contains"] = "{$label}: a pack holds at least 2 {$previous}.";
            } else {
                $factor *= (int) $containsRaw;
                if ($factor > self::MAX_FACTOR) {
                    $errors["packs.{$i}.contains"] = "{$label} is too big (more than " . number_format(self::MAX_FACTOR) . " {$baseName}).";
                }
            }

            $price = null;
            if ($priceRaw !== '') {
                if (! is_numeric($priceRaw) || (float) $priceRaw < 0) {
                    $errors["packs.{$i}.price"] = "{$label}: the price must be a number, for example 480. Leave it empty to use the {$baseName} price × the pack size.";
                } else {
                    $price = number_format((float) $priceRaw, 2, '.', '');
                }
            }

            if ($code !== '') {
                if (mb_strlen($code) > 100) {
                    $errors["packs.{$i}.barcode"] = "{$label}: the barcode is too long.";
                } elseif ($code === $mainBarcode || in_array($code, $codes, true)) {
                    $errors["packs.{$i}.barcode"] = "{$label}: every pack needs its own barcode — this one is already used on this screen.";
                } elseif ($where = self::barcodeTaken($code, $product?->id)) {
                    $errors["packs.{$i}.barcode"] = "{$label}: this barcode already belongs to {$where}.";
                }
                $codes[] = $code;
            }

            $id = isset($r['id']) && in_array((int) $r['id'], $ownIds, true) ? (int) $r['id'] : null;
            $packs[] = [
                'id' => $id, 'name' => $name, 'factor' => $factor, 'sale_price' => $price, 'barcode' => $code !== '' ? $code : null,
                'can_sell' => filter_var($r['sell'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'can_buy' => filter_var($r['buy'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
            $previous = $name !== '' ? $name : $previous;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'base_name' => $baseName,
            'base_sell' => filter_var($in['pack_base_sell'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'packs'     => $packs,
        ];
    }

    /**
     * Refuse what would rewrite history: removing or resizing a pack that is already on a bill, receipt or
     * return. Runs before the save transaction so nothing is half-written.
     */
    public static function guardChanges(Product $product, ?array $plan): void
    {
        $saved = $product->packUnits()->where('factor', '>', 1)->get();
        if ($saved->isEmpty()) {
            return;
        }
        $keep = collect($plan['packs'] ?? []);
        $touched = $saved->filter(function (ProductPackUnit $p) use ($keep) {
            $match = $keep->first(fn ($k) => $k['id'] === $p->id) ?? $keep->firstWhere('factor', $p->factor);

            return ! $match || $match['factor'] !== $p->factor;
        });
        $used = self::usedPackIds($touched->pluck('id')->all());
        if ($used) {
            $names = $saved->whereIn('id', $used)->pluck('name')->implode(', ');
            throw ValidationException::withMessages(['_packs' => "{$names} is already on past bills or receipts, so it cannot be removed or change size. You can still change its price, barcode and name. (پرانے بلوں پر موجود ہے)"]);
        }
    }

    /** Save the checked plan (NULL = no packs). Call inside the product's save transaction. */
    public static function sync(Product $product, ?array $plan): void
    {
        // toBase(): a model collection's except() means "except these model ids" and renumbers — keys must stay ids.
        $saved = $product->packUnits()->get()->keyBy('id')->toBase();
        if ($plan === null) {
            ProductPackUnit::whereIn('id', $saved->keys())->delete();

            return;
        }

        $assign = [];   // saved id => plan pack
        $new = [];
        $free = $saved->where('factor', '>', 1);
        foreach ($plan['packs'] as $p) {
            $match = ($p['id'] && $free->has($p['id'])) ? $free->get($p['id']) : $free->firstWhere('factor', $p['factor']);
            if ($match) {
                $assign[$match->id] = $p;
                $free = $free->except($match->id);
            } else {
                $new[] = $p;
            }
        }
        ProductPackUnit::whereIn('id', $free->keys())->delete();

        // Two steps so a swap of sizes never trips the (product, size) unique key.
        foreach (array_keys($assign) as $id) {
            ProductPackUnit::whereKey($id)->update(['factor' => 2000000 + $id]);
        }
        $order = 1;
        foreach ($assign as $id => $p) {
            ProductPackUnit::whereKey($id)->update(self::row($p) + ['sort_order' => $order++]);
        }
        foreach ($new as $p) {
            ProductPackUnit::create(self::row($p) + ['product_id' => $product->id, 'sort_order' => $order++]);
        }

        ProductPackUnit::updateOrCreate(
            ['product_id' => $product->id, 'factor' => 1],
            ['name' => mb_substr($plan['base_name'], 0, 40), 'sale_price' => null, 'barcode' => null,
             'can_sell' => $plan['base_sell'], 'can_buy' => true, 'sort_order' => 0]
        );
    }

    /**
     * A quantity in the product's own unit, said in packs: 435 → "4 Box 3 Strip 5 Tablet".
     *
     * @param  iterable<ProductPackUnit>|Collection  $packs
     */
    public static function describe(iterable $packs, float $quantity, string $baseName): string
    {
        $sign = $quantity < 0 ? '−' : '';
        $left = abs($quantity);
        $parts = [];
        foreach (collect($packs)->where('factor', '>', 1)->sortByDesc('factor') as $p) {
            $n = (int) floor($left / $p->factor);
            if ($n > 0) {
                $parts[] = $n . ' ' . $p->name;
                $left -= $n * $p->factor;
            }
        }
        if ($left > 0.0005 || ! $parts) {
            $parts[] = rtrim(rtrim(number_format($left, 3, '.', ''), '0'), '.') . ' ' . $baseName;
        }

        return $sign . implode(' ', $parts);
    }

    /** Where a barcode is already used, in words, or null. Packs of $productId itself do not count. */
    public static function barcodeTaken(string $code, ?int $productId): ?string
    {
        $other = fn ($q) => $productId ? $q->where('product_id', '!=', $productId) : $q;
        if ($other(ProductBarcode::where('barcode', $code))->exists() || $other(ProductVariant::where('barcode', $code))->exists()) {
            return 'another product';
        }
        if (Schema::connection('tenant')->hasTable('product_pack_units') && $other(ProductPackUnit::where('barcode', $code))->exists()) {
            return 'a pack of another product';
        }
        if ($productId && (ProductBarcode::where('product_id', $productId)->where('barcode', $code)->exists()
            || ProductVariant::where('product_id', $productId)->where('barcode', $code)->exists())) {
            return "this item's own (single) barcode";
        }

        return null;
    }

    /** Is this barcode a pack's (any product)? Used by the screens that add plain product barcodes. */
    public static function isPackBarcode(string $code): bool
    {
        return $code !== ''
            && Schema::connection('tenant')->hasTable('product_pack_units')
            && ProductPackUnit::where('barcode', $code)->exists();
    }

    /** @param  list<int>  $ids  @return list<int>  the ones already written on a line */
    public static function usedPackIds(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $schema = Schema::connection('tenant');
        $used = [];
        foreach (self::LINE_TABLES as $table) {
            if ($schema->hasTable($table) && $schema->hasColumn($table, 'pack_unit_id')) {
                $used = array_merge($used, \Illuminate\Support\Facades\DB::connection('tenant')->table($table)
                    ->whereIn('pack_unit_id', $ids)->distinct()->pluck('pack_unit_id')->map(fn ($v) => (int) $v)->all());
            }
        }

        return array_values(array_unique($used));
    }

    /** @param array{name:string,factor:int,sale_price:?string,barcode:?string,can_sell:bool,can_buy:bool} $p */
    private static function row(array $p): array
    {
        return ['name' => $p['name'], 'factor' => $p['factor'], 'sale_price' => $p['sale_price'], 'barcode' => $p['barcode'],
            'can_sell' => $p['can_sell'], 'can_buy' => $p['can_buy']];
    }
}
