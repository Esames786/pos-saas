<?php

namespace App\Services\Catering;

use App\Models\Tenant\Customer;
use App\Models\Tenant\CustomerTranslation;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductTranslation;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierTranslation;

/**
 * CATERING-SLICE-1: read/write helper over the platform's per-entity
 * translation tables (product_translations, category_translations,
 * customer_translations, supplier_translations).
 *
 * Rules (spec §4): the base row's name is always the fallback; localized
 * values are optional and hand-entered — never machine-translated. This
 * service only touches translation tables, never the stable base tables.
 */
class CateringLocalizationService
{
    public const LOCALE_URDU = 'ur';

    public function productName(Product $product, string $locale): string
    {
        if ($locale !== 'en') {
            $translated = $product->relationLoaded('translations')
                ? optional($product->translations->firstWhere('language_code', $locale))->name
                : ProductTranslation::query()
                    ->where('product_id', $product->id)
                    ->where('language_code', $locale)
                    ->value('name');
            if (! empty($translated)) {
                return $translated;
            }
        }

        return $product->name;
    }

    public function customerName(Customer $customer, string $locale): string
    {
        if ($locale !== 'en') {
            $translated = CustomerTranslation::query()
                ->where('customer_id', $customer->id)
                ->where('language_code', $locale)
                ->value('name');
            if (! empty($translated)) {
                return $translated;
            }
        }

        return $customer->name;
    }

    public function supplierName(Supplier $supplier, string $locale): string
    {
        if ($locale !== 'en') {
            $translated = SupplierTranslation::query()
                ->where('supplier_id', $supplier->id)
                ->where('language_code', $locale)
                ->value('name');
            if (! empty($translated)) {
                return $translated;
            }
        }

        return $supplier->name;
    }

    public function setProductName(Product $product, string $locale, ?string $name): void
    {
        $this->upsert(ProductTranslation::query()->getModel(), 'product_id', $product->id, $locale, $name);
    }

    public function setCustomerName(Customer $customer, string $locale, ?string $name): void
    {
        $this->upsert(new CustomerTranslation, 'customer_id', $customer->id, $locale, $name);
    }

    public function setSupplierName(Supplier $supplier, string $locale, ?string $name): void
    {
        $this->upsert(new SupplierTranslation, 'supplier_id', $supplier->id, $locale, $name);
    }

    /** Empty value removes the override so the base name becomes the fallback again. */
    private function upsert($model, string $foreignKey, int $id, string $locale, ?string $name): void
    {
        $query = $model->newQuery()->where($foreignKey, $id)->where('language_code', $locale);

        if ($name === null || trim($name) === '') {
            $query->delete();

            return;
        }

        $model->newQuery()->updateOrCreate(
            [$foreignKey => $id, 'language_code' => $locale],
            ['name' => trim($name)]
        );
    }
}
