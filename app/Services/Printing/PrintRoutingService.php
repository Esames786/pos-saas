<?php

namespace App\Services\Printing;

use App\Models\Tenant\CategoryPrinterMapping;
use App\Models\Tenant\Printer;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\TerminalPrinterSetting;
use Illuminate\Support\Collection;

class PrintRoutingService
{
    /**
     * Resolve explicit Reminder destinations. There is deliberately no default
     * printer or browser fallback for this operational document.
     *
     * @return array<int, array{printer: Printer, ask_on_addition: bool}>
     */
    public function reminderRoutesForSale(SalesOrder $sale, array $effectiveQuantities = []): array
    {
        $sale->loadMissing(['lines.product.category.parent']);

        $categoryIds = $sale->lines
            ->filter(function ($line) use ($effectiveQuantities) {
                $quantity = array_key_exists((string) $line->id, $effectiveQuantities)
                    ? (float) $effectiveQuantities[(string) $line->id]
                    : (float) $line->quantity;

                return $quantity > 0 && $line->product?->category_id;
            })
            ->pluck('product.category_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        // A reminder fires when at least one line is active this round. Category-specific
        // routes match the order's categories; an "All categories" (NULL) route fires on
        // any active order — even when no line carries a category.
        $hasActiveLine = $sale->lines->contains(function ($line) use ($effectiveQuantities) {
            $quantity = array_key_exists((string) $line->id, $effectiveQuantities)
                ? (float) $effectiveQuantities[(string) $line->id]
                : (float) $line->quantity;

            return $quantity > 0;
        });

        if (! $hasActiveLine) {
            return [];
        }

        $mappings = CategoryPrinterMapping::with('printer')
            ->where(function ($query) use ($sale) {
                $query->whereNull('branch_id')->orWhere('branch_id', $sale->branch_id);
            })
            ->where(function ($query) use ($categoryIds) {
                $query->whereNull('category_id');
                if ($categoryIds->isNotEmpty()) {
                    $query->orWhereIn('category_id', $categoryIds->all());
                }
            })
            ->where('print_role', 'reminder')
            ->where(function ($query) use ($sale) {
                $query->where('order_type', 'all')->orWhere('order_type', $sale->order_type);
            })
            ->where('is_active', true)
            ->get()
            ->filter(fn ($mapping) => $mapping->printer?->is_active && $mapping->printer?->supports_reminder);

        return $mappings
            ->groupBy('printer_id')
            ->map(fn (Collection $rules) => [
                'printer' => $rules->first()->printer,
                'ask_on_addition' => $rules->contains(
                    fn ($rule) => (bool) $rule->reminder_confirm_on_addition
                ),
            ])
            ->values()
            ->all();
    }

    /**
     * Ticket label for a line's category. Child categories are qualified by their parent —
     * "Non-Saada" alone is ambiguous when two parents each have one.
     */
    private function categoryLabel($line): ?string
    {
        $category = $line->product?->category;
        if (! $category) {
            return null;
        }

        return $category->parent
            ? $category->parent->name . ' / ' . $category->name
            : $category->name;
    }

    public function receiptPrinter(SalesOrder $sale): ?Printer
    {
        if ($sale->terminal_id) {
            $setting = TerminalPrinterSetting::where('terminal_id', $sale->terminal_id)->first();
            if ($setting?->receipt_printer_id) {
                $printer = Printer::find($setting->receipt_printer_id);
                if ($printer?->is_active) {
                    return $printer;
                }
            }
        }

        return Printer::where(function ($q) use ($sale) {
                $q->whereNull('branch_id')->orWhere('branch_id', $sale->branch_id);
            })
            ->where('is_active', true)
            ->whereIn('print_role', ['receipt', 'both'])
            ->orderByDesc('is_default')
            ->first();
    }

    public function defaultKotPrinter(SalesOrder $sale): ?Printer
    {
        if ($sale->terminal_id) {
            $setting = TerminalPrinterSetting::where('terminal_id', $sale->terminal_id)->first();
            if ($setting?->kot_printer_id) {
                $printer = Printer::find($setting->kot_printer_id);
                if ($printer?->is_active) {
                    return $printer;
                }
            }
        }

        return Printer::where(function ($q) use ($sale) {
                $q->whereNull('branch_id')->orWhere('branch_id', $sale->branch_id);
            })
            ->where('is_active', true)
            ->whereIn('print_role', ['kot', 'both'])
            ->orderByDesc('is_default')
            ->first();
    }

    /**
     * Resolve KOT printer routes for a sale.
     * Returns array of [printer, line_ids, line_quantities] groups.
     * Priority per line: category mapping → terminal KOT printer → branch default → browser fallback.
     */
    public function kotRoutesForSale(SalesOrder $sale, array $onlyLineIds = [], bool $isReprint = false): array
    {
        $sale->loadMissing(['lines.product.category.parent']);

        $lines = $sale->lines;

        if (!empty($onlyLineIds)) {
            $onlyLineIds = collect($onlyLineIds)->map(fn ($id) => (int) $id)->all();
            $lines = $lines->whereIn('id', $onlyLineIds);
        }

        $routes = [];

        foreach ($lines as $line) {
            $qtyToPrint = $isReprint
                ? (float) $line->quantity
                : max((float) $line->quantity - (float) ($line->kot_sent_quantity ?? 0), 0);

            if ($qtyToPrint <= 0) {
                continue;
            }

            $printers  = collect();
            $categoryId = $line->product?->category_id;

            // Match this line's category route OR an "All categories" (NULL) wildcard route.
            $printerIds = CategoryPrinterMapping::where(function ($q) use ($sale) {
                    $q->whereNull('branch_id')->orWhere('branch_id', $sale->branch_id);
                })
                ->where(function ($q) use ($categoryId) {
                    $q->whereNull('category_id');
                    if ($categoryId) {
                        $q->orWhere('category_id', $categoryId);
                    }
                })
                ->whereIn('print_role', ['kot', 'both'])
                ->where(function ($query) use ($sale) {
                    $query->where('order_type', 'all')->orWhere('order_type', $sale->order_type);
                })
                ->where('is_active', true)
                ->pluck('printer_id')
                ->unique();

            if ($printerIds->isNotEmpty()) {
                $printers = Printer::whereIn('id', $printerIds)
                    ->whereIn('print_role', ['kot', 'both'])
                    ->where('is_active', true)
                    ->get();
            }

            if ($printers->isEmpty()) {
                $default = $this->defaultKotPrinter($sale);
                if ($default) {
                    $printers = collect([$default]);
                }
            }

            if ($printers->isEmpty()) {
                $key = 'browser_cat_' . ($categoryId ?: 0);
                $routes[$key]['printer']                           = null;
                $routes[$key]['category_id']                       = $categoryId ? (int) $categoryId : null;
                $routes[$key]['category_name']                     = $this->categoryLabel($line);
                $routes[$key]['line_ids'][]                        = $line->id;
                $routes[$key]['line_quantities'][(string) $line->id] = $qtyToPrint;
                continue;
            }

            // ONE KOT PER CATEGORY (client request 2026-08-11): the kitchen wants a separate
            // ticket per category, not every category merged onto one slip per printer. The
            // category is part of the group key AND of the job's logical key downstream, so two
            // categories sharing a printer produce two tickets instead of deduping into one.
            foreach ($printers as $printer) {
                $key = 'printer_' . $printer->id . '_cat_' . ($categoryId ?: 0);
                $routes[$key]['printer']                           = $printer;
                $routes[$key]['category_id']                       = $categoryId ? (int) $categoryId : null;
                $routes[$key]['category_name']                     = $this->categoryLabel($line);
                $routes[$key]['line_ids'][]                        = $line->id;
                $routes[$key]['line_quantities'][(string) $line->id] = $qtyToPrint;
            }
        }

        return array_values($routes);
    }

    /** Route explicit immutable quantities, used by cancellation KOT events. */
    public function kotRoutesForQuantities(SalesOrder $sale, array $lineQuantities): array
    {
        $sale->loadMissing(['lines.product.category.parent']);
        $routes = [];

        foreach ($sale->lines->whereIn('id', array_map('intval', array_keys($lineQuantities))) as $line) {
            $quantity = (float) ($lineQuantities[(string) $line->id] ?? $lineQuantities[$line->id] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            $printers = collect();
            $categoryId = $line->product?->category_id;

            if ($categoryId) {
                $printerIds = CategoryPrinterMapping::where(function ($query) use ($sale) {
                        $query->whereNull('branch_id')->orWhere('branch_id', $sale->branch_id);
                    })
                    ->where('category_id', $categoryId)
                    ->whereIn('print_role', ['kot', 'both'])
                    ->where(function ($query) use ($sale) {
                        $query->where('order_type', 'all')->orWhere('order_type', $sale->order_type);
                    })
                    ->where('is_active', true)
                    ->pluck('printer_id')
                    ->unique();

                if ($printerIds->isNotEmpty()) {
                    $printers = Printer::whereIn('id', $printerIds)
                        ->whereIn('print_role', ['kot', 'both'])
                        ->where('is_active', true)
                        ->get();
                }
            }

            if ($printers->isEmpty() && ($default = $this->defaultKotPrinter($sale))) {
                $printers = collect([$default]);
            }

            if ($printers->isEmpty()) {
                $key = 'browser_cat_' . ($categoryId ?: 0);
                $routes[$key]['printer'] = null;
                $routes[$key]['category_id'] = $categoryId ? (int) $categoryId : null;
                $routes[$key]['category_name'] = $this->categoryLabel($line);
                $routes[$key]['line_ids'][] = $line->id;
                $routes[$key]['line_quantities'][(string) $line->id] = $quantity;
                continue;
            }

            // one cancellation ticket per category too — same grouping as a normal KOT.
            foreach ($printers as $printer) {
                $key = 'printer_' . $printer->id . '_cat_' . ($categoryId ?: 0);
                $routes[$key]['printer'] = $printer;
                $routes[$key]['category_id'] = $categoryId ? (int) $categoryId : null;
                $routes[$key]['category_name'] = $this->categoryLabel($line);
                $routes[$key]['line_ids'][] = $line->id;
                $routes[$key]['line_quantities'][(string) $line->id] = $quantity;
            }
        }

        return array_values($routes);
    }
}
