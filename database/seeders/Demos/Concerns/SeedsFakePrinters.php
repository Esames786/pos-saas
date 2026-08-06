<?php

namespace Database\Seeders\Demos\Concerns;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\CategoryPrinterMapping;
use App\Models\Tenant\Printer;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\TerminalPrinterSetting;

/**
 * Demo helper: wire the local Fake Printer (127.0.0.1:9100 — run the FakePrinter / fake-printer.js
 * on the tester's machine, reached via the Print Agent) so a restaurant demo can print Receipt, KOT
 * AND Reminder out of the box. Idempotent (updateOrCreate) so it is safe on every demo reseed.
 */
trait SeedsFakePrinters
{
    protected function seedFakePrintersAndReminderRouting(): void
    {
        $kot = Printer::updateOrCreate(
            ['code' => 'FAKE-KOT'],
            [
                'name' => 'Fake Kitchen Printer', 'printer_type' => 'network',
                'print_role' => 'kot', 'supports_reminder' => true,
                'ip_address' => '127.0.0.1', 'port' => 9100, 'is_active' => true,
                'notes' => 'Fake printer for local testing — run the FakePrinter on 127.0.0.1:9100 via the Print Agent.',
            ]
        );

        $receipt = Printer::updateOrCreate(
            ['code' => 'FAKE-RECEIPT'],
            [
                'name' => 'Fake Receipt Printer', 'printer_type' => 'network',
                'print_role' => 'receipt', 'supports_reminder' => false,
                'ip_address' => '127.0.0.1', 'port' => 9100, 'is_active' => true,
                'notes' => 'Fake printer for local testing — run the FakePrinter on 127.0.0.1:9100 via the Print Agent.',
            ]
        );

        // KOT + Reminder routing for the FOOD categories only (kitchen prints food; drinks are skipped)
        // on every branch. Reminder is ONE per-order document routed to this printer — routing several
        // food categories does NOT print multiple reminders, it just ensures the order's reminder
        // reaches the kitchen printer whatever food was rung.
        $drinkKeywords = ['drink', 'beverage', 'bev', 'juice', 'soda', 'cold ', 'hot drink'];
        $foodCategories = Category::all()->reject(function ($category) use ($drinkKeywords) {
            $haystack = strtolower(($category->name ?? '') . ' ' . ($category->code ?? ''));
            foreach ($drinkKeywords as $kw) {
                if (str_contains($haystack, $kw)) {
                    return true;
                }
            }
            return false;
        });

        foreach (Branch::all() as $branch) {
            foreach ($foodCategories as $category) {
                CategoryPrinterMapping::updateOrCreate(
                    ['branch_id' => $branch->id, 'category_id' => $category->id, 'printer_id' => $kot->id, 'print_role' => 'kot', 'order_type' => 'all'],
                    ['is_active' => true]
                );
                CategoryPrinterMapping::updateOrCreate(
                    ['branch_id' => $branch->id, 'category_id' => $category->id, 'printer_id' => $kot->id, 'print_role' => 'reminder', 'order_type' => 'all'],
                    ['reminder_confirm_on_addition' => true, 'is_active' => true]
                );
            }
        }

        // Auto-print settings per terminal (receipt + KOT to the fake printers).
        foreach (Terminal::all() as $terminal) {
            TerminalPrinterSetting::updateOrCreate(
                ['terminal_id' => $terminal->id],
                [
                    'receipt_printer_id' => $receipt->id, 'kot_printer_id' => $kot->id,
                    'auto_print_receipt' => true, 'auto_print_kot' => true,
                ]
            );
        }
    }
}
