<?php

namespace App\Services\Reports;

use App\Models\Tenant\KitchenProduction;
use App\Models\Tenant\KitchenWastage;
use App\Models\Tenant\RecipeConsumption;

class KitchenReportService
{
    /**
     * Recipe consumptions.
     * Finished product = consumption.recipe.product
     * Ingredient       = consumption.product
     */
    public function recipeConsumption(array $filters)
    {
        return RecipeConsumption::query()
            ->with([
                'recipe.product',   // finished product
                'product',          // ingredient consumed
                'unit',
                'salesOrder',
            ])
            ->when(!empty($filters['date_from']),   fn ($q) => $q->whereDate('consumed_at', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),     fn ($q) => $q->whereDate('consumed_at', '<=', $filters['date_to']))
            ->when(!empty($filters['branch_id']),   fn ($q) => $q->whereHas('salesOrder', fn ($s) => $s->where('branch_id', $filters['branch_id'])))
            ->orderByDesc('consumed_at')
            ->paginate(30)
            ->withQueryString();
    }

    public function wastage(array $filters)
    {
        return KitchenWastage::query()
            ->with(['branch', 'product', 'variant', 'unit', 'recordedBy'])
            ->when(!empty($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->when(!empty($filters['date_from']), fn ($q) => $q->whereDate('wastage_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),   fn ($q) => $q->whereDate('wastage_date', '<=', $filters['date_to']))
            ->orderByDesc('wastage_date')
            ->paginate(25)
            ->withQueryString();
    }

    public function production(array $filters)
    {
        return KitchenProduction::query()
            ->with(['branch', 'recipe.product', 'producedBy'])
            ->when(!empty($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->when(!empty($filters['status']),    fn ($q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['date_from']), fn ($q) => $q->whereDate('production_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),   fn ($q) => $q->whereDate('production_date', '<=', $filters['date_to']))
            ->orderByDesc('production_date')
            ->paginate(25)
            ->withQueryString();
    }
}
