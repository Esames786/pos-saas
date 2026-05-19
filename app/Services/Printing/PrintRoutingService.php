<?php

namespace App\Services\Printing;

use App\Models\Tenant\CategoryPrinterMapping;
use App\Models\Tenant\Printer;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\TerminalPrinterSetting;

class PrintRoutingService
{
    /**
     * Resolve receipt printer for a sale.
     * Priority: terminal setting → branch default receipt printer → null
     */
    public function receiptPrinter(SalesOrder $sale, ?string $terminalId = null): ?Printer
    {
        if ($terminalId) {
            $setting = TerminalPrinterSetting::where('terminal_id', $terminalId)->first();
            if ($setting?->receipt_printer_id) {
                $printer = Printer::find($setting->receipt_printer_id);
                if ($printer?->is_active) {
                    return $printer;
                }
            }
        }

        return Printer::where('branch_id', $sale->branch_id)
            ->where('is_active', true)
            ->whereIn('print_role', ['receipt', 'both'])
            ->where('is_default', true)
            ->first()
            ?? Printer::where('branch_id', $sale->branch_id)
                ->where('is_active', true)
                ->whereIn('print_role', ['receipt', 'both'])
                ->first();
    }

    /**
     * Resolve KOT printers for a sale, grouped by category mapping.
     * Returns collection of [printer, line_ids] pairs.
     * Priority: category mapping → terminal KOT setting → branch default KOT printer
     */
    public function kotPrintersForSale(SalesOrder $sale, ?string $terminalId = null): array
    {
        $sale->loadMissing('lines.product.category');

        $printerGroups = [];

        foreach ($sale->lines as $line) {
            $categoryId = $line->product?->category_id;

            $printer = null;

            if ($categoryId) {
                $mapping = CategoryPrinterMapping::where('branch_id', $sale->branch_id)
                    ->where('category_id', $categoryId)
                    ->where('print_role', 'kot')
                    ->where('is_active', true)
                    ->with('printer')
                    ->first();

                if ($mapping?->printer?->is_active) {
                    $printer = $mapping->printer;
                }
            }

            if (!$printer && $terminalId) {
                $setting = TerminalPrinterSetting::where('terminal_id', $terminalId)->first();
                if ($setting?->kot_printer_id) {
                    $p = Printer::find($setting->kot_printer_id);
                    if ($p?->is_active) {
                        $printer = $p;
                    }
                }
            }

            if (!$printer) {
                $printer = Printer::where('branch_id', $sale->branch_id)
                    ->where('is_active', true)
                    ->whereIn('print_role', ['kot', 'both'])
                    ->where('is_default', true)
                    ->first()
                    ?? Printer::where('branch_id', $sale->branch_id)
                        ->where('is_active', true)
                        ->whereIn('print_role', ['kot', 'both'])
                        ->first();
            }

            if ($printer) {
                $key = $printer->id;
                if (!isset($printerGroups[$key])) {
                    $printerGroups[$key] = ['printer' => $printer, 'line_ids' => []];
                }
                $printerGroups[$key]['line_ids'][] = $line->id;
            }
        }

        return array_values($printerGroups);
    }
}
