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
        $sale->loadMissing(['lines.product.category']);

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

        if ($categoryIds->isEmpty()) {
            return [];
        }

        $mappings = CategoryPrinterMapping::with('printer')
            ->where(function ($query) use ($sale) {
                $query->whereNull('branch_id')->orWhere('branch_id', $sale->branch_id);
            })
            ->whereIn('category_id', $categoryIds)
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
        $sale->loadMissing(['lines.product.category']);

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

            if ($categoryId) {
                $printerIds = CategoryPrinterMapping::where(function ($q) use ($sale) {
                        $q->whereNull('branch_id')->orWhere('branch_id', $sale->branch_id);
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

            if ($printers->isEmpty()) {
                $default = $this->defaultKotPrinter($sale);
                if ($default) {
                    $printers = collect([$default]);
                }
            }

            if ($printers->isEmpty()) {
                $key = 'browser';
                $routes[$key]['printer']                           = null;
                $routes[$key]['line_ids'][]                        = $line->id;
                $routes[$key]['line_quantities'][(string) $line->id] = $qtyToPrint;
                continue;
            }

            foreach ($printers as $printer) {
                $key = 'printer_' . $printer->id;
                $routes[$key]['printer']                           = $printer;
                $routes[$key]['line_ids'][]                        = $line->id;
                $routes[$key]['line_quantities'][(string) $line->id] = $qtyToPrint;
            }
        }

        return array_values($routes);
    }

    /** Route explicit immutable quantities, used by cancellation KOT events. */
    public function kotRoutesForQuantities(SalesOrder $sale, array $lineQuantities): array
    {
        $sale->loadMissing(['lines.product.category']);
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
                $routes['browser']['printer'] = null;
                $routes['browser']['line_ids'][] = $line->id;
                $routes['browser']['line_quantities'][(string) $line->id] = $quantity;
                continue;
            }

            foreach ($printers as $printer) {
                $key = 'printer_' . $printer->id;
                $routes[$key]['printer'] = $printer;
                $routes[$key]['line_ids'][] = $line->id;
                $routes[$key]['line_quantities'][(string) $line->id] = $quantity;
            }
        }

        return array_values($routes);
    }
}
